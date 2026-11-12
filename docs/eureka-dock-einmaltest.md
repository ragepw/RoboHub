# Eureka E20 Evo Plus: separater Dock-Einmaltest

Lokaler Entwicklungsstand, 12.11.2026, Branch `feature/eureka-e20-evo-plus`,
Basis `c697fcf`. Keine Bereitstellung, Cloud-Verbindung, reale RequestAction,
Geräteansteuerung, Signaturerzeugung, Signaturimport, Commits oder Pushs.

## Umsetzung

`RoboHubEurekaDock` ist eine eigene finale Zustandsmaschine mit eigenen
Freigaben, Tickets und Verbrauchsmarkierungen. Die Mechanik entspricht dem
vorhandenen B8-Einmalpfad, bleibt aber absichtlich separat: spätere Änderungen
an B8 dürfen keinen Dock-Verbrauch zurücksetzen oder eine Dock-Berechtigung
schaffen. Gemeinsam bleiben die bestehenden Modell-/Frische-/JWT-Prüfungen,
die Ed25519-Dokumentprüfung und die HTTPS-Implementierung.

Einziger Frame ist `AA0DB80000000000000222010016` (Station/Dock).
`BEFEHLE_FREIGEGEBEN=false`, `BEFEHLS_ZUORDNUNGEN=[]` und die normale
Transportsperre bleiben erhalten. Die unterste HTTPS-Grenze erhält eine enge
zusätzliche Alternative: Dock-Frame ausschließlich mit Dock-Kennzeichnung und
Einmalprüfcallback. Ohne Callback, im normalen Transport oder im B8-Pfad bleibt
der Dock-Frame gesperrt. Start/Fortsetzen/Pause/Stopp/Orten sind darüber
nicht zugelassen. Es gibt keinen zusätzlichen Netzwerkdienst.

## Genehmigung und Zustand

Das bisherige Ed25519-Prüfverfahren wird mit getrenntem Zweck und festem
Vertrauensanker verwendet:

- Zweck: `robohub/eureka/dock/runtime/v1`.
- Public Key: `/etc/robohub/eureka-dock.pub`, Base64, 32 Bytes nach Dekodierung.
  Prüfung von Typ, Root-Eigentum, Rechten und Symlinks für alle Pfadkomponenten
  wie beim bisherigen Vertrauensanker. Dieser Pfad wurde nicht angelegt.
- Genehmigungshülle: exakt `nutzlast`, `signatur`, beide Base64. Signatur:
  Ed25519 über die unveränderten JSON-Nutzlastbytes, 64 Bytes.
- Nutzlastfelder in dieser Reihenfolge: `zweck`, `konto` (45174), `instanz`
  (22708), `cloudGeraetId` (extern bestätigte individuelle Kennung), `modell`
  (`E20 Evo Plus`), `befehl` (obiger Dock-Frame), `erwartet`, `von`, `bis`.
- `erwartet`: Kernelstartzeit plus Doppelpunkt plus eigene 64-stellige
  Dock-Laufzeitkennung; unabhängig von B8. `von`/`bis`: ganze Unixsekunden,
  höchstens 60 Sekunden Abstand, `von <= jetzt < bis`.

Es wurde kein Dock-Signierwerkzeug ausgeführt oder ergänzt. Der bestehende
B8-Signierer bleibt auf B8 beschränkt. Ein echtes Dock-Dokument muss später
außerhalb von Symcon von der autorisierten Schlüsselverwaltung erstellt werden.

Attribute: `EurekaDockVersuch`; Buffer: `EurekaDockLaufzeit` und
`EurekaDockLaufzeitfreigabe`. B8 verwendet weiterhin ausschließlich seine
bisherigen Speicher. Die Bereitstellung verlangt leere Dock-Zustände und
vergleicht das signierte Cloud-Ziel mit der aktuellen Zuordnung. Die
Ticketausstellung verbraucht bereits das einmalige Ausstellungsrecht.
Ticket und Genehmigung werden sowohl gegen Wanduhr als auch monotone Zeit
geprüft. Das Ticket bindet zusätzlich Kontoanmeldung, Tokenbündel und
Sitzungsrevision. Die Signaturfrist begrenzt auch ein später ausgestelltes Ticket.

Vor einem Transportversuch wird der persistente Verbrauch geschrieben und
zurückgelesen; ein separater Buffer verhindert Wiederverwendung nach einfachem
Attributrollback. Nach cURL-Konfiguration, unmittelbar vor `curl_exec`, werden
Kontext und Berechtigung erneut geprüft und der Transportbeginn gespeichert.
Widerruf und Transportbeginn sind durch dieselbe kurze Freigabesemaphore
serialisiert. Unbekannte oder teilweise gespeicherte Zustände sperren.
Ein Timeout oder unklarer Fehler verbraucht den Versuch ebenfalls.

Persistierter Verbrauch überlebt einen gewöhnlichen Neustart; die flüchtige
Genehmigung überlebt ihn nicht. Ein Neustart erlaubt keine Wiederverwendung
des alten signierten Laufzeitkontexts. Root/Codeänderungen, vollständiger
Speicherrollback und Festplattendauerhaftigkeit ohne fsync liegen außerhalb
dieser Zusage. Kein automatischer Reset und kein erneutes Ticket nach Verbrauch.

## Native IP-Symcon-Integration

Neue öffentliche Methoden der Konto-Instanz, von Symcon mit Präfix `ROBOHUB_`
als Skriptfunktionen bereitgestellt. Instanzargument ist jeweils **45174**:

| Methode | Funktion |
| --- | --- |
| `EurekaDockVorpruefen()` | Nur lokalen Kontext prüfen; eigene Laufzeitkennung initialisieren, keine Freigabe, kein GET |
| `EurekaDockLaufzeitAnzeigen()` | Vorhandene Laufzeitkennung anzeigen, keine Initialisierung oder Genehmigung |
| `EurekaDockBereitstellen(string $genehmigung)` | Späteres extern signiertes Dock-Dokument prüfen und einmalig übernehmen |
| `EurekaDockFreigeben(int $sekunden, string $bestaetigung)` | Ein Ticket für 1–60 Sekunden ausstellen; Bestätigung exakt `E20 Evo Plus einmalig zur Station fahren` |
| `EurekaDockTesten(string $freigabe)` | Genau einen Dock-Versuch mit dem Ticket ausführen |
| `EurekaDockWiderrufen()` | Vor Transportbeginn endgültiges Veto setzen |

Alle Einstiege verlangen native Administratorberechtigung, einen bekannten
angemeldeten Benutzer, passendes Konto/Region und einen bereiten Kernel.
`RequestAction`, `ForwardData`, Gerätemodul, WebFront, Hook und Timer erhalten
keinen Dock-Test- oder Importeinstieg. Die reguläre Befehlsvariable bleibt wie
bisher. ApplyChanges und Kernelstart erzeugen keine Genehmigung und senden
keinen Dock-Befehl. Es gibt keine optimistische Änderung von Gerätezuständen.

B8 und Dock teilen die Operationssemaphore; danach gilt die vorhandene
Bündelsemaphore gegen Tokenrotation/Abmeldung. Beim Versuch kommt die globale
Gerätesemaphore des normalen Befehlswegs hinzu. Freigabezustände haben eigene
kurze Dock-Semaphoren. Der Widerruf benötigt nur diese kurze Semaphore.

## Prüfungen vor dem Versand

Lokales Listenalter, Verbindungsempfang und Shadow-Empfang dürfen jeweils
höchstens 120 Sekunden alt und nicht in der Zukunft sein. Modul-GUID, Parent,
Gerätekennung und eindeutiger Listeneintrag müssen stimmen; `Deaktiviert` muss
explizit false sein. Identifikation ist exakt E20 Evo Plus / SN8 750Y0015 /
B8 v0 / robozone / Plugin 2.5.4 / dust_station. Widersprüchliche Shadow-Metadaten
und Fehlerbefunde sperren. Liste und Shadow müssen ausdrücklich verbunden sein;
ein fehlendes Shadow-`connected` genügt für diesen Bewegungsbefehl nicht.

Zulässige Zustände: `stop`, `pause`, `cleaning`, jeweils konsistent und
fehlerfrei. Bereits angedockt, Rückfahrt, Sonderpausen und unbekannte Zustände
bleiben gesperrt. Dock benötigt keine neue Start-Akkuschwelle. Sitzung muss lokal
noch für die Transportdauer gültig sein; kein Refresh, kein vorgeschaltetes GET,
kein automatisches Nachladen oder Wiederholen. Eine Cloud-Sperre verweigert den
Versuch; HTTP 429 setzt die bestehende Konto-Wartezeit.

Genau ein `curl_exec` mit maximal 15 Sekunden Laufzeit, Redirects aus,
HTTP/1.1, frische Verbindung, keine Wiederverwendung, keine HTTP-/Proxy-
Authentifizierungsaushandlung, keine Umgebungsproxys und leeres `Expect:`.
TLS-Zertifikats-/Hostprüfung bleibt aktiv. Fehler und Transportausnahmen werden
ohne Tokens, URLs oder Cloud-Freitext gemeldet. Eine Cloud-Annahme meldet immer
`ausgefuehrt=false`; sie beweist keine Roboterbewegung und keine erfolgreiche
Ankunft an der Station.

## Offline-Nachweise

Ausführung: `bash tests/eureka/run-dock-offline.sh`. Der Runner erzwingt
`bwrap --unshare-net` ohne Fallback. Signatur- und Schlüsselgeneratoren sind
zusätzlich per PHP deaktiviert. Er führt den Dock-Prüfstand, die gemeinsamen
HTTPS-Doubles, die B8-Diagnose ohne signaturerzeugende Provisionierungsfälle,
Befehls-, Zustands-, Token- und Gesamtintegrationstests sowie alle PHP-Lints
und `git diff --check` aus.

Ergebnis des isolierten Laufs: **65 Dock-, 30 gemeinsame HTTPS-, 399 B8-Diagnose-,
284 Befehls-, 683 Zustands- und 72 Token-Prüfungen ohne Fehler**; Strukturprüfung
und Gesamtintegration ebenfalls bestanden. Alle PHP-Dateien syntaxfehlerfrei,
`git diff --check` fehlerfrei. Der Lauf wurde mit deaktivierten Ed25519-
Signatur-/Schlüsselgeneratoren durchgeführt.

Die neuen Dock-Tests verwenden ausschließlich erfundene Konten/IDs und im
Testspeicher simulierte genehmigte Zustände. Es wird kein signiertes Dokument
importiert. Die Dokumentprüfung läuft zusätzlich mit ersetzter Ed25519-Grenze:
Zweck, Dock-Frame, Zeitfenster, Konto, Instanz und Laufzeit werden im echten
Parser geprüft. Die native Ed25519-Grenze weist die fiktiven Signaturbytes ab.
Ein positiver kryptografischer Dock-Import bleibt wegen des ausdrücklichen
Verbots von Signaturerzeugung/-import in dieser Arbeit ungeprüft.
Die bestehenden Signierer-/Import-/Root-Import-Prüfstände wurden deshalb nicht
ausgeführt. Der unveränderte native libcurl-Gegenprüfstand wurde in diesem
Auftrag nicht erneut ausgeführt; der verwendete gemeinsame HTTPS-Code wird
hier mit vollständig ersetzter cURL-Systemgrenze geprüft.

## Voraussetzungen des späteren realen Funktionstests

1. Separates Review und ausdrücklicher Auftrag zur Bereitstellung und Bewegung;
   diesen lokalen Entwicklungsstand dafür erst gesondert deployen.
2. Geschützten Dock-Vertrauensanker durch die Administration einrichten,
   externe Schlüsselverwaltung und unabhängige Freigabe für exakt dieses Ziel.
3. In der realen Symcon-Laufzeit native Administrator-/Benutzererkennung,
   Semaphore-/Buffer-Verhalten und verwendete PHP-libcurl-Version prüfen.
4. Gerät 22708 eindeutig an Konto 45174 gebunden, nicht deaktiviert; aktuelle
   Gerätekennung extern abgleichen. Gültige lokale EU-Sitzung und frische
   Listen-/Shadow-Daten einschließlich ausdrücklichem Shadow-`connected=true`
   aus dem bestehenden Lesebetrieb nachweisen. Fehlende Befunde nicht durch
   erfundene Defaults ersetzen.
5. Vor Ort freien Rückweg und zulässigen Ausgangszustand bestätigen; Beobachter
   bereitstellen. Vorprüfung/Anzeige durchführen, dann extern ausschließlich
   Dock für die aktuelle Laufzeit und maximal 60 Sekunden signieren lassen.
   Bereitstellung, Ticketausgabe und einmaligen Aufruf separat administrativ
   ausführen. Keine normale Steuerung freischalten.
6. Cloud-Antwort, beobachtete Bewegung und spätere Zustands-/Stationsbefunde
   getrennt bewerten. Bei unklarem Ergebnis keinen automatischen zweiten
   Versuch auslösen. Ein weiterer Versuch erfordert einen neuen Auftrag und
   ein Review des dauerhaft verbrauchten Testzustands.
