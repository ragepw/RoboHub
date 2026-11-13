# Teppichverstärkung: Schlaf und Speicherung

Lokale Entwicklung und Textprüfung vom 13.11.2026 auf `feature/eureka-e20-evo-plus`,
Basis `f568b89`. Keine Cloudabfrage und kein echter Roboterbefehl.
Transport und bestehende B8-/Shadow-/Diagnosearbeiten bleiben erhalten.

Quelle: Hersteller-Plugin 2.5.4, lokale Auszüge unter
`../../eureka-plugin-analyse/analyse-2.5.4/modules/` (Archiv und Hash siehe
[Grundfunktionen](eureka-grundfunktionen.md)). Herstellercode wurde nicht ausgeführt.

## Belege und ihre Grenzen

- `7582-77582.txt:1413–1420` delegiert den Einstellungsschalter an
  `7582-54356.txt:589–610`: `rugHance` ruft unmittelbar `cmdCarpetSwitch`
  mit `off`/`on` auf. In diesem sichtbaren Handler gibt es keine Schlafprüfung.
- `192-16153.txt:2620–2647` baut Kontrolltyp 02, `22 0C 00/01` und ruft
  `x(o).then(e.requestWorkState)` auf. Kein vorgeschalteter Aufweckbefehl,
  keine Schlafuntertypprüfung und kein gesonderter Speicherbefehl sind hier sichtbar.
  `requestWorkState` (`:4023 ff.`) fragt anschließend `32 01` ab; dieser
  zusätzliche Befehl wird in RoboHub weiterhin nicht automatisch übernommen.
- Der gemeinsame Sender `192-16153.txt:1115 ff.` übergibt im regulären
  Appbetrieb an `window.bridge.startCmdProcess` (`:1600 ff.`).
  Der native Bridge-/Firmwarecode liegt in diesen JavaScript-Auszügen nicht vor.
  Daher beweist die fehlende JavaScript-Sperre weder unveränderte Zustellung im
  Schlaf noch, dass die Bridge kein internes Aufwecken benötigt. Ein expliziter
  Nachweis für `ERP_model`/`sleep_pause` über RoboHubs HTTPS-Weg fehlt.
- `192-16153.txt:2273–2338` überträgt beim Start mode/fan/water, keinen
  Teppichschalter. Das ist ein Beleg für eine separate Geräteeinstellung,
  keine Zusicherung ihrer Lebensdauer. Ein erneutes Setzen bei jeder Reinigung
  ist in diesem Startbauer nicht vorgesehen. Fortbestand über Reinigung,
  Schlaf und insbesondere Stromverlust/Neustart ist damit nicht nachgewiesen.
- `192-71825.txt:254–261` ordnet Statusbyte 28 `carpet_switch` zu:
  0=`no`, 1=`yes`. Die App liest diesen Wert (`6590-39832.txt:727–728`;
  `7582-77582.txt:1270–1282`). Das ist ein B8-Statusbeleg, keine bestätigte
  Zuordnung im HTTPS-Shadow. Ein belastbarer Teppich-Istwert im Shadow ist
  weder durch diese Stellen noch durch die vorhandenen lokalen Shadowfixtures
  belegt. Fehlender Nachweis bedeutet nicht, dass das Feld grundsätzlich fehlt.

## Ergänzender bestätigter Praxisbefund

Der Nutzer bestätigt für `ERP_model / sleep`: Die Hersteller-App erlaubt
Ein/Aus ohne vorheriges Aufwecken; die Einstellung bleibt beim Schließen der
App erhalten, aktuell Ein. Dieser Befund ergänzt den statischen Herstellerbeleg.
Er bestätigt keine Haltbarkeit über Stromverlust und keinen live geprüften
RoboHub-HTTPS-Versand. Die bisherige Entscheidung ohne Schlaf-Freigabe ist damit
für den hier ausdrücklich beauftragten regulären Schlaf überholt.

## Eng begrenzte lokale Umsetzung

Nur `teppichmodus` mit exakt einem Integerparameter 0/1 erhält eine eigene
Zustandsgruppe für `state=ERP_model`, `parentState=sleep`,
`cleanMissionState=no_cleaning_mission`, `deviceFault=[]`. Fehlende,
widersprüchliche oder unbekannte Zustände und insbesondere `sleep_pause`
bleiben für den Teppichbefehl gesperrt. Der vorhandene Protokollbauer beschränkt
weiter auf `22 0C 00/01`. Start, Pause, Resume und Dock wurden nicht erweitert.

Geräte-/Profilbindung, Onlineprüfung, maximal 120 Sekunden alte Daten,
vorhandener kontrollierter manueller Refresh, Semaphore, lokale Nachprüfung
unmittelbar vor Transport, Verbrauch und Retryverbot bleiben erhalten.
Sollwertänderung, Modulstart und Timer lösen keinen Teppich-POST aus; ausschließlich
ausdrückliches manuelles Anwenden verwendet den bestehenden Steuertransport.

## Istwertanzeige vorbereitet, Quelle noch unbelegt

Die getrennte schreibgeschützte native Variable `EurekaTeppichIstwert`
(„Teppichverstärkung (Istwert)“) zeigt vorerst immer „Unbekannt“.
Weder die Nutzerangabe „aktuell Ein“, der Sollwert noch ein Cloud-ACK werden
als vom Gerät zurückgemeldeter Istwert verwendet. Die Anzeige ist ausdrücklich
noch nicht an eine verifizierte Statusquelle angebunden.

Die vorhandenen Shadowfixtures enthalten keinen belegten Teppichwert.
Der vorhandene B8-Parser prüft Arbeitsstatusframes und extrahiert Arbeit,
Akku, Fehler und optional Schlafuntertyp; `carpet_switch` ist dort noch nicht
extrahiert und es gibt keinen bestehenden automatischen Übernahmepfad in die
Geräteanzeige. Der dokumentierte frühere B8-Abruf lieferte keinen nutzbaren
Antwortframe (siehe [Antwortwarteverhalten](eureka-b8-antwortwarteverhalten.md)).
Byte 28 ist als Herstellerformat bekannt, ein aktueller realer Istwert liegt
hier jedoch nicht vor. Es wurde kein Shadow-Alias erfunden und kein zusätzlicher
GET-/B8-Abruf oder automatischer Diagnosepfad implementiert.

## Offline-Verifikation

`bash tests/eureka/run-control-offline.sh` führt die vorhandene Suite vollständig
netzisoliert aus, inklusive PHP-Lint und `git diff --check`.
Ergänzt sind Ein/Aus bei regulärem Schlaf, unbekannte Schlafzustände,
widersprüchliche Mission/Parent, fehlende Fehlerdaten, Fehler, Offline,
Gerätebindung, alte Daten und kontrollierter Refresh. Native Gegenproben prüfen
rein lokale Auswahl/ApplyChanges, genau einen POST bei Anwenden, gesperrten
Doppelversand und den getrennten unbekannten Istwert ohne Bedienaktion.

Ergebnis am 13.11.2026: vollständige netzisolierte Suite mit Exitcode 0,
einschließlich vorhandener Start-/Pause-/Resume-/Dock-, B8-/Shadow-/Diagnosetests,
neuer Teppichgegenproben, PHP-Lint aller PHP-Dateien und `git diff --check`.

## Nächster Schritt (hier nicht ausgeführt)

Nach gesondertem Bereitstellungs- und Testauftrag einmaliges explizites Anwenden
im belegten regulären Schlaf über RoboHub prüfen; bei unklarem Ausgang nicht
wiederholen. Für den Istwert zunächst bereits vorhandene, eindeutig zugeordnete
Statusdaten auf einen realen Teppichwert untersuchen. Erst nach Nachweis von
Quelle, Gerätebindung und Alter einen Übernahmepfad ergänzen; die Sollwertauswahl
bleibt dabei unabhängig. Keine zusätzlichen Befehle zur bloßen Bestätigung.

Vorhandene datensparsame lokale Hilfen:
`.tools/eureka-shadow-struktur-lesediagnose.php` für Struktur/Schlafzustand und
`.tools/eureka-parameter-lesediagnose.php` für Auswahl/Cachealter. Beide lesen
nur den lokalen Snapshot, bestätigen aber keinen Teppich-Istwert. Die bestehende
Symcon-Warnung zum fehlgeschlagenen `teppichmodus` enthält den lokalen Sperrgrund;
sie ist vor weiteren Versuchen auszuwerten. Keine Rohsnapshots, Tokens oder
Gerätekennungen protokollieren.
