# Sleep-Pause: lokaler Befund und begrenzte Resume-Zuordnung

Stand 13.11.2026. Ausschließlich lokale Analyse und Offlineprüfungen.

## Beleg und Grenze

Der Nutzer meldet den echten Shadow `state=sleep_pause`, `parentState=sleep`,
`cleanMissionState=have_cleaning_mission`, `deviceFault=[]`: Nach Pause während
Reinigung folgte automatisch Schlaf; die Hersteller-App behält den Auftrag und
bietet Play an. Dies ist ein Nutzerbeleg, keine eigene Cloudmessung.

Lokal erneut geprüft: Originalarchiv `../eureka-plugin-analyse/T0xB8_750Y0015.zip`,
Version 2.5.4, SHA256
`0a275bbd2ee8c076aeb8e1d314a4bbe98fa5c3c6aeb3750414765adc399f24c6`.
Alle JavaScript-Dateien wurden statisch durchsucht, kein Plugin-Code ausgeführt.

- `static/js/1717.56df39ab132cf7deb9d6.js`, nullbasierter Byteoffset 54166:
  `startClean` führt bei aktiviertem `is_task_continue`, Arbeitsstatus `sleep`
  und Untertyp `pause_sleep` zu `cmdStopWork(STOP_ACTION.continue)`.
  Die Akkuschranke >15 liegt davor. Der andere Untertyp `pause_charge_sleep`
  benötigt zusätzlich `supportReturnDustByTime` und wird hier nicht freigegeben.
- `static/js/192.f5b85c85acde7c6c892e.js`, Byteoffset 1328156:
  `cmdStopWork` baut Nachrichtentyp 2, Kommando 34 (0x22), Stop-Aktion.
  `STOP_ACTION_CODE` bei Byteoffset 1562478 ff. ordnet 28 (0x1C) Continue zu.
  Daraus folgt der vorhandene Continue-Rahmen `AA0DB800000000000002221C00FB`.
- Derselbe Chunk, Byteoffset 1493996: Schlaf mit `pause_sleep` zeigt Continue;
  Byteoffset 1505514: dieser Untertyp erhält den Auftrag (`has_task`).

**Die Zeichenfolge `sleep_pause` kommt im Archiv nicht vor.** Die Zuordnung des
Cloudstrings zu pausiertem Schlaf ist eine Schlussfolgerung aus dem exakten
Live-Befund, dem beobachteten Ablauf und der dazu passenden Herstellerbedienung,
keine im Plugin explizit enthaltene Übersetzungstabelle. Die Befehlssemantik
Continue für pausierten Schlaf ist dagegen direkt im Plugin belegt.

## Korrektur und Schutzgrenzen

`RoboHubEurekaZustand` erkennt ausschließlich die vier oben genannten Shadowwerte
zusammen als konsistenten Schlaf mit pausiertem Auftrag. Fehlende, typfalsche,
abweichende Parent-/Auftrags-/Fehlerwerte bleiben unbekannt. Anzeige und
`schlafAktion()` teilen dieselbe Kombination. Für die Resume-Entscheidung sind
zusätzlich ein lokaler Stand bis 120 Sekunden ohne Zukunftszeit sowie ein
bestätigter ganzzahliger Akku 16–100 erforderlich.

Der vorhandene Start-Bedieneinstieg baut dafür ausschließlich Continue, ohne
Neuauftragsparameter. Pause und Station erhalten keine neue Freigabe. Die
vorhandenen Identitäts-, Sitzungs-, Online-, Sperr-, Verbrauchs- und erneuten
Kontextprüfungen bleiben bestehen. Die ERP-/B8-Entscheidung wird beibehalten;
für diesen konkreten Shadow ist kein B8-Zusatz nötig. Unbekannte Schlafstrings
und `pause_sleep` als Cloudstring werden nicht ergänzt.
Die native Lesediagnose darf `sleep_pause` jetzt als bekannten Wert ausgeben.

## Offlineprüfung und verbleibende Unsicherheit

`sleep-pause-cases.php` wird von `command-test.php` ausgeführt: Anzeige,
Resume-Rahmen, genau ein Transport über einen Double, keine Wiederholung,
Parent/Auftrag/Fehler-Typen und fehlende Felder, unbekannte Schlafwerte,
Akku- und Frischegrenzen, Offline, Kennungswechsel und unerwartete Parameter.
Die vollständige netzisolierte Suite `bash tests/eureka/run-control-offline.sh`
bestand mit Exitcode 0 einschließlich ERP-/B8-Regressionen, 403 Befehlsprüfungen,
PHP-Lint aller PHP-Dateien und `git diff --check`.

Es wurde kein Roboterbefehl gesendet und keine Gerätebewegung bestätigt.
Ob der reale Roboter Continue aus diesem Cloudzustand ausführt, bleibt ohne
separat autorisierten Liveversuch offen. Keine Produktivinstallation, kein Commit,
Push oder Deployment.
