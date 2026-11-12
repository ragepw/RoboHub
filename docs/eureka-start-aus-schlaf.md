# Prüfung: neuer Start aus ERP_model/sleep

Nur lokale statische Analyse und Offlineprüfungen. Keine produktive Abfrage,
Cloudaktion, Befehlsausführung, Bereitstellung oder Änderung der Freigaben.

## Vorhandener Herstellerbeleg

Originalarchiv `../eureka-plugin-analyse/T0xB8_750Y0015.zip`, Version 2.5.4,
SHA256 `0a275bbd2ee8c076aeb8e1d314a4bbe98fa5c3c6aeb3750414765adc399f24c6`,
erneut lokal geprüft. Datei `static/js/1717.56df39ab132cf7deb9d6.js`,
Modul 81717, `startClean` ab Byteoffset 52660:

- Im allgemeinen Startzweig wird zuerst Akku <=15 abgewiesen.
- Bei `is_task_continue` und Arbeitszustand `sleep` führt `pause_sleep`
  unmittelbar zu Continue. `pause_charge_sleep` tut dies zusätzlich abhängig
  von `supportReturnDustByTime`.
- Erst andernfalls wird abhängig vom Bearbeitungsmodus ein neuer Auftrag
  gesendet, im Ganzhauszweig über `cmdStartWork(auto, fan, water)`.

Damit belegt das Plugin keinen einheitlichen neuen Start aus beliebigem Schlaf.
Die vier Untertypen stehen in Modul 16914: `charge_sleep`, `pause_sleep`,
`pause_charge_sleep`, `stop_sleep`. Eine eindeutige Zuordnung von Cloud-Shadow
`ERP_model/sleep` zu einem dieser Untertypen ist in den vorhandenen Befunden
nicht nachgewiesen. Dass der Roboter an der Station steht, unterscheidet die
Auftragssituation ebenfalls nicht.
Die Begriffe `ERP_model` und `no_cleaning_mission` kommen in keiner JavaScript-
Datei des geprüften Herstellerarchivs vor; eine Gleichsetzung mit dessen
Schlafuntertypen lässt sich daraus nicht ableiten.

## Bewertung des vorhandenen Shadowbefunds

Die Normalisierung erkennt ausschließlich `ERP_model`, `parentState=sleep`,
`cleanMissionState=no_cleaning_mission` als konsistenten Anzeigezustand Schlaf.
Dies ist keine Befehlsfreigabe. Wie in [Zustände](eureka-zustaende.md) dokumentiert,
wurde `no_cleaning_mission` auch während Reinigung beobachtet; das Feld belegt
daher keinen sicher fehlenden bzw. fortsetzbaren Auftrag. Akku 100 sowie die
explizite Auswahl Fan 1/Wasser 0 beheben diese fehlende Unterscheidung nicht.

Ergebnis: kein ausreichender Nachweis für die angefragte neue Startfreigabe.
Produktiver Code und sämtliche Schutzprüfungen bleiben unverändert. Vier
Regressionen prüfen die exakt konsistente Schlafkombination mit gutem Akku,
frischem Online-Stand und manueller Bedienung; Start mit Fan 1/Wasser 0 sowie
Pause, Resume und Station erreichen keinen Transport.

Vollständige Offlineprüfung `bash tests/eureka/run-control-offline.sh` mit
Netzwerkisolierung: Exitcode 0, alle vier neuen Regressionen und sämtliche
bestehenden Prüfstände bestanden, 93 PHP-Lints erfolgreich; `git diff --check`
fehlerfrei. Es wurden keine produktiven Quelldateien verändert.

Für eine spätere private Bereitstellung ist zuerst ein belastbarer vorhandener
Herstellerbeleg zur Zuordnung des Cloudzustands zu einem startfähigen Schlaf-
bzw. Auftragszustand erforderlich. Bis dahin gibt es keinen freigegebenen
Startfix und kein dafür vorbereitetes Deployment-Bundle. Keine B8-Abfrage oder
andere Liveprobe wird durch diese Analyse autorisiert.
