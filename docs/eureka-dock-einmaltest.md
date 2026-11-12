# Eureka E20 Evo Plus: separater Dock-Einmaltest

Lokaler Entwicklungsstand, 12.11.2026, Branch `feature/eureka-e20-evo-plus`,
Basis `c763b5c` plus vorhandene lokale Dokumentation/Tests. Keine Bereitstellung, Cloud-Verbindung, reale RequestAction,
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

- Zweck ohne Ausnahme: `robohub/eureka/dock/runtime/v1`.
  Für die explizite Ausnahmeeinwilligung: `robohub/eureka/dock/runtime/v2`.
- Public Key: `/etc/robohub/eureka-dock.pub`, Base64, 32 Bytes nach Dekodierung.
  Prüfung von Typ, Root-Eigentum, Rechten und Symlinks für alle Pfadkomponenten
  wie beim bisherigen Vertrauensanker. Dieser Pfad wurde nicht angelegt.
- Genehmigungshülle: exakt `nutzlast`, `signatur`, beide Base64. Signatur:
  Ed25519 über die unveränderten JSON-Nutzlastbytes, 64 Bytes.
- Nutzlastfelder in dieser Reihenfolge: `zweck`, `konto` (45174), `instanz`
  (22708), `cloudGeraetId` (extern bestätigte individuelle Kennung), `modell`
  (`E20 Evo Plus`), `befehl` (obiger Dock-Frame), `erwartet`, `von`, `bis`.
  v2 verlangt danach zusätzlich `ausnahmeVerbindungUnbekannt` als echten
  JSON-Boolean. Ausschließlich `true` erlaubt die Ausnahme; `false` und v1
  erlauben nur den bisherigen positiven Shadow-Nachweis. Ein an v1 angehängtes
  Ausnahmefeld, fehlende v2-Felder und andere Typen werden abgewiesen.
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
Die zurückgegebene `gueltigBis` ist das frühere Ende von Ticket und Signatur.

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
| `EurekaDockVorpruefen()` | Lokalen Kontext prüfen; fehlender Shadow-Nachweis wird als „Verbindung unbekannt“ und nur bedingt bestanden gemeldet; keine Freigabe, kein GET |
| `EurekaDockLaufzeitAnzeigen()` | Vorhandene Laufzeitkennung anzeigen, keine Initialisierung oder Genehmigung |
| `EurekaDockBereitstellen(string $genehmigung)` | Späteres extern signiertes Dock-Dokument prüfen und einmalig übernehmen |
| `EurekaDockFreigeben(int $sekunden, string $bestaetigung, string $vorOrtBestaetigung)` | Ein Ticket für 1–60 Sekunden; Bestätigung exakt `E20 Evo Plus einmalig zur Station fahren`; ohne Ausnahme dritter Parameter `''`, mit Ausnahme nur die unmittelbare Vor-Ort-Bestätigung, siehe unten |
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

Die [Nachprüfung der Online-Beleglage](eureka-dock-online-nachweis.md)
erklärt die verbleibende Nachweislücke: Shadow-`connected` fehlt in den
beobachteten Antworten; Semantik und Signalalter des Listenwerts sind durch
die vorhandenen Herstellerartefakte nicht ausreichend belegt. Die Ausnahme
akzeptiert dieses Restrisiko ausschließlich für den beaufsichtigten Docktest;
sie schafft keinen alternativen zuverlässigen Online-Nachweis.

Lokales Listenalter, Verbindungsempfang und Shadow-Empfang dürfen jeweils
höchstens 120 Sekunden alt und nicht in der Zukunft sein. Modul-GUID, Parent,
Gerätekennung und eindeutiger Listeneintrag müssen stimmen; `Deaktiviert` muss
explizit false sein. Identifikation ist exakt E20 Evo Plus / SN8 750Y0015 /
B8 v0 / robozone / Plugin 2.5.4 / dust_station. Widersprüchliche Shadow-Metadaten
und Fehlerbefunde sperren. Die rohe Listen-Gerätekennung muss die eindeutige
lokale und signierte Zielbindung bestätigen. Die Liste muss ausdrücklich
`connected=true` melden. Ohne Ausnahme muss auch der Shadow ausdrücklich
`connected=true` enthalten.

### Eng begrenzte Ausnahme „Verbindung unbekannt“

Nur vollständig **fehlendes** Shadow-`connected` ist ausnahmefähig. Vorhandene
Werte `false`, `null` oder falsche Typen bleiben gesperrt. Ein explizites
`online=false` in rohen Listen-/Shadowdaten sperrt ebenfalls. Fehlende,
veraltete, zukünftige oder widersprüchliche Listen-/Shadowdaten sperren.
Der Shadow muss aus einem erfolgreich abgeschlossenen GET im bestehenden
Lesebetrieb stammen: `stand.statusquelle.quelle=shadow-get`, passende
`deviceId`, gültige Empfangszeit und vollständiger fehlerfreier Zustandsbefund.
Der Dockpfad liest diese lokal gespeicherten Daten; er ruft selbst nichts ab.

Bei fehlendem `connected` speichert der vorhandene Treiber `stand.online=false`
als „kein positiver Nachweis“. Nur dieser abgeleitete Wert darf im Ausnahmezweig
unbeachtet bleiben; rohes `connected=false`/`online=false` niemals.
Ein abgeleitetes `stand.online=true` ohne rohen Nachweis ist widersprüchlich
und sperrt ebenfalls. Die Prüfung ergänzt kein `connected`, ändert keine
Gerätedaten und meldet in Ticket-/Versuchsergebnis `verbindung=unbekannt`.
Die bestehende separate Listenanzeige bleibt eine Cloudmeldung mit Quellenangabe.

Zusätzlich zu einer gültigen separaten v2-Docksignatur mit
`ausnahmeVerbindungUnbekannt=true` muss der angemeldete Administrator bei der
Ticketausgabe den Roboter **jetzt vor Ort** als betriebsbereit, den Rückweg als
frei und die Beaufsichtigung bestätigen. Dritter Parameter, wortgetreu wie
`RoboHubEurekaDock::VOR_ORT_BESTAETIGUNG`:

> Verbindung unbekannt: Ich habe den E20 Evo Plus jetzt vor Ort als betriebsbereit und den Rückweg als frei geprüft und beaufsichtige den einmaligen Docktest

Dies ist eine inhaltliche Erklärung über eine unmittelbar vorgenommene
Beobachtung, keine dauerhaft gespeicherte Einstellung. Eine Checkbox, eine
Vorprüfung oder die Signatur allein ersetzt diese Erklärung nicht. Die Software
kann die Wahrheit und tatsächliche Anwesenheit des Erklärenden nicht beweisen.

Die Ticketausgabe speichert die Erklärung unter `beaufsichtigung.vorOrt` mit
serverseitiger Wanduhr-/Monotonzeit und nativ erkanntem Benutzer; die Entscheidung
`beaufsichtigung.ausnahmeVerbindungUnbekannt` wird damit zusammen im persistenten
Versuch und im flüchtigen Laufzeitpuffer an denselben Tickethash gebunden.
Vor dem Versuch und unmittelbar vor dem Transportbeginn müssen beide Kopien
übereinstimmen, derselbe Administrator angemeldet und die Erklärung genau vom
Ausstellungszeitpunkt sein. Sie gilt höchstens bis zum Ticketende (maximal
60 Sekunden), zusätzlich begrenzt durch die Signaturfrist und Laufzeitbindung.
Die persistierte Beobachtung ist danach nur Verbrauchsnachweis und kann kein
neues Ticket autorisieren. Ein Ticket ohne Ausnahme darf bei später fehlendem
Shadow-Nachweis nicht auf den Ausnahmeweg wechseln.

Normale RequestAction-/ForwardData-Befehle, Start, Pause, Fortsetzen, Stopp,
Station und Orten erhalten daraus keinerlei Freigabe. Der normale Befehlsplaner
und seine Online-Prüfung bleiben unverändert. Nur `RoboHubEurekaDock` beurteilt
im Ausnahmezweig die erlaubten Dock-Ausgangszustände separat.

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

Aktuelles Ergebnis siehe [Online-Nachweis und Offline-Prüfung](eureka-dock-online-nachweis.md#offline-gegenproben).
Der Lauf erfolgt mit deaktivierten Ed25519-Signatur-/Schlüsselgeneratoren.

Die neuen Dock-Tests verwenden ausschließlich erfundene Konten/IDs und im
Testspeicher simulierte genehmigte Zustände. Es wird kein signiertes Dokument
importiert. Die Dokumentprüfung läuft zusätzlich mit ersetzter Ed25519-Grenze:
Zweck, Dock-Frame, Zeitfenster, Konto, Instanz und Laufzeit werden im echten
Parser geprüft. Die native Ed25519-Grenze weist die fiktiven Signaturbytes ab.
Die v2-Gegenproben prüfen explizite Einwilligung, fehlende/ungültige Felder,
Zwecktrennung und eine ungültige Signatur. Erfolgreiche Genehmigungen werden
ausschließlich im Testspeicher simuliert, ohne Importfunktion oder echte Signatur.
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
   Listen-/Shadow-Daten aus dem bestehenden Lesebetrieb nachweisen. Bei fehlendem
   Shadow-`connected` den Status ausdrücklich unbekannt belassen und alle oben
   beschriebenen Ausnahmebedingungen einschließlich v2-Einwilligung erfüllen.
5. Vor Ort freien Rückweg und zulässigen Ausgangszustand bestätigen; Beobachter
   bereitstellen. Vorprüfung/Anzeige durchführen, dann extern ausschließlich
   Dock für die aktuelle Laufzeit und maximal 60 Sekunden signieren lassen;
   gegebenenfalls die Ausnahme ausdrücklich einschließen. Nach Bereitstellung
   unmittelbar vor Ticketausgabe erneut vor Ort prüfen und die Beobachtung
   ausdrücklich als dritten Parameter bestätigen. Ticketausgabe und einmaligen
   Aufruf separat administrativ ausführen. Keine normale Steuerung freischalten.
6. Cloud-Antwort, beobachtete Bewegung und spätere Zustands-/Stationsbefunde
   getrennt bewerten. Bei unklarem Ergebnis keinen automatischen zweiten
   Versuch auslösen. Ein weiterer Versuch erfordert einen neuen Auftrag und
   ein Review des dauerhaft verbrauchten Testzustands.

## Verbleibende Risiken

Frischer Listen-/Shadowempfang beweist keine aktuelle Roboterverbindung oder
Gerätezeit. Auch nach Vor-Ort-Bestätigung kann die Verbindung ausfallen; eine
Cloud-Annahme beweist weder Bewegung noch Ankunft. Der Roboter kann verzögert
auf einen bereits versandten Befehl reagieren. Ein Widerruf nach Transportbeginn
stoppt den Roboter nicht; normale Stoppbefehle bleiben gesperrt. Die Beaufsichtigung
muss deshalb bis zum geklärten Ergebnis bestehen bleiben. Native Symcon-Laufzeit,
reales Geräteverhalten und ein positiver kryptografischer Dock-Import wurden
hier nicht geprüft. Die oben beschriebenen Root-/Rollback-/fsync-Grenzen gelten
weiter. Der nächste Schritt ist ein Review dieses lokalen Stands; Bereitstellung,
Schlüsselverwaltung und realer Funktionstest erfordern einen separaten Auftrag.
