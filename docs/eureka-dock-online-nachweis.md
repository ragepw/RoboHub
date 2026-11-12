# Online-Nachweis des Dock-Einmaltests

Lokale Nachprüfung vom 12.11.2026 auf Basis `c763b5c`. Keine Cloud-Abfrage,
kein Roboterbefehl und keine produktive Änderung. Ergebnis: Die vorhandenen
Artefakte belegen keinen hinreichend aktuellen Geräteverbindungsnachweis aus
Listen-`connected`. Diese Nachweislücke besteht weiterhin. Der aktuelle lokale
Stand ergänzt ausschließlich für Dock eine ausdrücklich genehmigungspflichtige
Ausnahme. Voraussetzungen und Restrisiken stehen im
[Dock-Einmaltest](eureka-dock-einmaltest.md#eng-begrenzte-ausnahme-verbindung-unbekannt).
Es wurde keine echte Genehmigung erstellt oder importiert.

## Herstellerartefakte und Aussagegrenzen

Geprüft wurde das lokal vorhandene Herstellerpaket
`~/projekte/eureka-plugin-analyse/T0xB8_750Y0015.zip`, Version 2.5.4,
SHA-256 `0a275bbd2ee8c076aeb8e1d314a4bbe98fa5c3c6aeb3750414765adc399f24c6`.
Die folgenden Zeilen beziehen sich auf die daraus extrahierten Module unter
`analyse-2.5.4/modules/` im selben Verzeichnis:

| Artefakt | Statischer Befund | Nachweisgrenze |
| --- | --- | --- |
| `192-72985.txt:827–848` | Initialisierung liest `getCurrentHomeInfo().deviceList`, ordnet über `deviceId` zu und übernimmt `1 == isOnline`. | Kein sichtbarer Bezug zu REST-`connected`, keine Gerätezeit oder maximale Signallatenz. Die lokale Variable startet optimistisch mit true. |
| `192-83093.txt:814–823` | `getCurrentHomeInfo()` delegiert an die native Bridge; ohne Offline-Einstieg liefert der Wrapper den Literalwert `online`. | Dieser Zweig enthält überhaupt keinen Verbindungsnachweis. Die native Implementierung ist im Paket nicht enthalten. |
| `192-43149.txt` (`deviceInfo.isOnline`) | Der Store startet mit `isOnline: true`. | Die Vorbelegung darf nicht als Herstellerbestätigung übernommen werden. |
| `192-72985.txt:3621–3646` | App-MQTT verbunden/getrennt wird separat beobachtet. | Eine App-Broker-Verbindung ist kein Nachweis der Roboterverbindung. |

Der bekannte reale Listenbefund `connected=true` ist eine gerätebezogene
Cloudmeldung. Ob sie eine aktuelle Roboter-Broker-Verbindung, einen zuletzt
bekannten Zustand oder eine andere Cloudbewertung repräsentiert, ist mit
diesen Artefakten nicht entscheidbar. Insbesondere ist auch die Behauptung
„nur Cloudstatus“ nicht belegt. Die lokale Protokollreferenz ist Fremdsoftware;
deren optimistischer Default für fehlendes `connected` ist kein Herstellerbeleg.

Die beobachteten E20-Shadows enthalten Status, Akku und Fehlerbefund, aber kein
`connected` (anonymisierte Beobachtungen in
`tests/eureka/observed-shadow-fixtures.php`). Ein erfolgreicher Shadow-GET kann
gespeicherte Daten liefern. Er beweist weder eine Geräteantwort auf diesen GET
noch deren Alter. Die Zeit in `Schnappschuss` ist lokale Empfangszeit.

## Drei Zustände und bestehende Anzeige

| Befund | Verbindungsaussage | Dockfolge |
| --- | --- | --- |
| Explizites boolesches true einer geeigneten Quelle | Online bestätigt im Rahmen dieser Quelle | Alle übrigen Sicherheitsprüfungen bleiben erforderlich. |
| Explizites boolesches false | Offline gemeldet; kein fehlender Wert | Sperre, kein positiver Ersatz durch andere Cachewerte. |
| Fehlendes Shadow-`connected`, alle anderen Daten vollständig/frisch | Verbindung unbekannt | Nur Dock: separate signierte Ausnahmeeinwilligung und unmittelbar bestätigte Vor-Ort-Beobachtung können ein einzelnes kurzlebiges Ticket erlauben. |
| Ungültiger Wert oder unzureichender Frische-/Zuordnungsnachweis | Kein ausreichender Nachweis | Sperre, auch mit Ausnahmeeinwilligung. |

Die bestehende Anzeige `Verbindungsstatus` verwendet explizites Shadow-
`connected`, ersatzweise die höchstens 120 Sekunden alte Listenmeldung.
„Online bestätigt“/„Offline bestätigt“ bezeichnet dort die explizite
Cloudmeldung, keinen aktuellen Gerätehandshake. Die Quellen sind unter
`EurekaErreichbarkeit` beziehungsweise `robohubErreichbarkeit` sichtbar.
120 Sekunden begrenzen nur das lokale Empfangsalter. Die Anzeige ist deshalb
keine Dock-Freigabe und darf nicht als Sicherheitsnachweis verwendet werden.
Fehlt Shadow-`connected`, bleibt dessen Einzelbefund null, auch bei positiver
Listenanzeige. `Erreichbar=false` und `stand.online=false` bedeuten dabei
„nicht positiv belegt“, nicht zwingend „bestätigt offline“.

Diese bestehende Trennung bleibt erhalten: Aus der Anzeige entsteht kein
zusätzliches `connected=true`. Eine positive Listenanzeige allein berechtigt
keinen Dockversuch. Die neue Dockausnahme kennzeichnet den fehlenden
Online-Nachweis weiterhin ausdrücklich als unbekannt.

## Bestehende Schranke und bewusste Dockausnahme

Der normale Befehlsplaner `libs/RoboHubEurekaBefehlsweg.php` verlangt weiterhin
`stand.roh.connected === true` und, sofern vorhanden, `stand.online === true`.
Das fehlende E20-Feld verletzt diese Bedingung; der Treiber leitet daraus
zusätzlich `online=false` ab (`libs/RoboHubEureka.php:366`). Die Meldung
„Kein aktueller, zugeordneter Online-Zustand“ ist kein bestätigter Offlinebefund.

Für einen alternativen zuverlässigen Verbindungsnachweis fehlen weiterhin
eine Herstellerdefinition oder native Implementierung des Listenfeldes und
ein belastbarer Nachweis seiner Aktualität. Erst diese Belege könnten eine
Verwendung der Liste statt des nicht gelieferten Shadowfeldes rechtfertigen.
Ein erneuter Listen-/Shadowabruf, Status 102, Arbeitszustandswechsel oder die
Übernahme optimistischer App-Defaults schließen diese Lücke nicht.

Die ausdrücklich beauftragte Ausnahme ersetzt diesen Nachweis nicht. Sie setzt
eine frische positive Listenmeldung, einen erfolgreich empfangenen und frischen
Shadow, widerspruchsfreie Geräte-/Modellbindung, Fehlerfreiheit, gültige Sitzung,
eine separate v2-Ed25519-Docksignatur mit Ausnahmeeinwilligung und eine bei der
Ticketausgabe unmittelbar bestätigte Vor-Ort-Beobachtung voraus. Konto 45174 und
Geräteinstanz 22708 bleiben fest gebunden. Rohes `connected=false` oder
`online=false` sperrt immer. Nur vollständig fehlendes Shadow-`connected` ist
ausnahmefähig. Das Ergebnis meldet `verbindung=unbekannt`; der Snapshot und die
regulären Online-Schranken werden nicht verändert.

Die Erklärung ist mit Benutzer, Zeitpunkt und Tickethash in der höchstens
60 Sekunden gültigen Freigabe gebunden; eine persistierte Checkbox genügt nicht.
Die Software kann eine tatsächliche Beobachtung nicht beweisen. Clouddaten können
trotz frischem Empfang alt sein; Verbindung und Betriebsbereitschaft können nach
der Erklärung wechseln. Keine automatische Wiederholung bei Fehlern, Timeout
oder unklarer Antwort; der Versuch wird vor Transport endgültig verbraucht.

## Offline-Gegenproben

`tests/eureka/dock-test.php` enthält den beobachteten Fall sowie 36 Kombinationen
aus Listen-/Shadow-`connected` true, false oder fehlend und frischen/veralteten
Listen-/Shadow-Empfangszeiten. Jede Vorprüfung bleibt ohne Transport und ohne
Dockfreigabe. Die Matrix prüft außerdem die Ticket-/Versuchsentscheidung mit
simulierter Ausnahmeeinwilligung. Synthetische Positivfälle prüfen ausschließlich
den Softwarevertrag, nicht die Eignung realer Herstellerdaten. Gegenproben
sperren fehlende/falsche Einwilligung, fehlende oder veränderte Vor-Ort-Erklärung,
Benutzerwechsel, fremde/doppelte Gerätekennungen, fehlende GET-Provenienz,
Offlinewerte, Fehler und Widersprüche auch unmittelbar vor I/O. Weitere Fälle
prüfen exakt 120/121 Sekunden Datenalter, beide Ablaufuhren, Signaturfrist,
Widerruf, Neustart, Verbrauch und normale Befehlssperren. Der echte gemeinsame
HTTPS-Code läuft zusätzlich mit cURL-Doubles für den Ausnahmeweg.

Validierung: `bash tests/eureka/run-dock-offline.sh` mit Exitcode 0:
230 Docktests, 41 HTTPS-Double-Tests, 399 Diagnoseprüfungen ohne Signaturerzeugung,
284 Befehls-, 683 Zustands- und 72 Tokenprüfungen sowie vollständiger
RoboHub-Prüfstand, 22 Struktur-Gegenproben, 83 PHP-Lints und `git diff --check`
bestanden. Signatur-/Schlüsselgeneratoren waren deaktiviert; keine Importtests.
Historischer Nachweis
der vorherigen lokalen Nachprüfung: `diagnostic-native-test.py` in eigenem
`bwrap --unshare-net` mit äußerer
Netznamespace-Kennung: 21 native HTTPS-Gegenversuche, 0 Fehler. Es wurden nur
lokale Nachbauten erreicht; keine Herstellerverbindung und keine Freischaltung.
