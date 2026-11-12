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

## Nachprüfung des vollständigen sleepType-Datenwegs

Sämtliche JavaScript-Dateien desselben Originalarchivs wurden nach `sleepType`
und `sleep_type` durchsucht; Fundstellen liegen in den Chunks 1717, 192 und
7521. Die ursprünglichen Bytes wurden nur gelesen, niemals ausgeführt.

| Schritt | Herstellerfundstelle (nullbasierter Byteoffset) | Befund |
| --- | --- | --- |
| UI-Eigenschaft | Chunk 1717, 51824 | `sleepType` liest `DeviceStatus.deviceStatus.sleep_type` |
| Arbeitsstatusdecoder | Chunk 192, 1346760 und 1346839 | `sleep_type` aus Antwortbyte 48, über `SLEEP_TYPE_CODE`, nur bei ausreichender Länge |
| Status-Push-Decoder | Chunk 192, 1643010 | Separater Rahmen, Schlafuntertyp aus Byte 46 |
| Wertetabelle | Chunk 192, 1566324 | 1 charge_sleep, 2 pause_sleep, 3 pause_charge_sleep, 4 stop_sleep |
| Startentscheidung | Chunk 1717, 54139 | Bei aktiviertem is_task_continue: pause_sleep → Continue; pause_charge_sleep → Continue nur mit supportReturnDustByTime |
| Buttonbeschriftung | Chunk 192, 1492517 | Gleiche Unterscheidung Start/Continue wie beim ausführenden Pfad |
| Auftragserkennung | Chunk 192, 1505245 | has_task wird u.a. aus Arbeitsstatus, Schlafuntertyp und cleaning_type abgeleitet; kein unabhängiger Ersatz für sleep_type |

Die beiden identischen Zuweisungen des Arbeitsstatusdecoders sind doppelte
Zugriffe auf dasselbe Byte, keine zwei unabhängigen Statusquellen. Bei fehlendem
optionalen Byte ist dessen Aktualität nicht gesichert. Der initial leere
Pluginwert und der allgemeine Start-Fallthrough beweisen daher keinen
startfähigen Cloudzustand. `supportReturnDustByTime` stammt aus den
Gerätefähigkeiten (`32 06`, Byte 14 Bit 4), nicht aus dem Schlafbyte.

Zusätzlich wurden ausschließlich vorhandene Symcon-Caches per lokalem
`IPS_GetSnapshot` gelesen: Kontoattribute `Geraete` und `Schnappschuss` sowie
Geräteattribut `LetzteRohdaten`. Kein Statusabruf, Formular mit Nachladepfad,
Login, Refresh oder Befehl wurde aufgerufen. Ausgegeben wurden nur Feldnamen
und freigegebene Zustandswerte, keine individuellen Kennungen oder Sitzungsdaten.

Die gespeicherten ERP-Zustände enthalten `state`, `parentState` (im Shadow),
`autoType`, `cleaningType`, `cleanMissionState`, `cleanProgress`, `battery`,
`batteryLevel`, `fanLevel`, `waterLevel` und `deviceFault` sowie ein hier nicht
wiedergegebenes Identitätsfeld. In keinem dieser drei Cachebereiche gibt es
`sleep_type`, `sleepType`, einen Schlafuntertyp oder
`robot_available.supportReturnDustByTime`. Auch die vorhandenen sechs lokalen
Shadow-Originalbeobachtungen enthalten keinen Schlafuntertyp. Die lokale
HA-Referenz liefert keine belegte Cloudfeld-Zuordnung dafür.

**Exakt fehlender Entscheidungsbefund:** ein aktueller, demselben Gerät und
derselben Sitzung zugeordneter Schlafuntertyp mit belegter Cloudfeld-Semantik
(entsprechend Hersteller `sleep_type` bzw. B8-Wert 1–4). Für Untertyp 3 fehlt
zusätzlich eine bestätigte Fähigkeit `supportReturnDustByTime`. Unter den
E20-Konfigurationsbedingungen führen Untertyp 2 bzw. Untertyp 3 mit dieser
Fähigkeit zu Continue; Untertyp 1/4 sind Kandidaten für den neuen Auftrag nach
allen übrigen Prüfungen. Das Vorhandensein des Feldes in einer zukünftigen
Antwort allein genügt ohne belegte Zuordnung und Aktualität nicht.

Der bestehende Shadowpfad kann den fehlenden Befund deshalb nicht zuverlässig
lesen oder rekonstruieren. `ERP_model/sleep`, Standort Ladestation,
`cleanMissionState=no_cleaning_mission`, Akku oder Startparameter ersetzen ihn
nicht. Es wird kein Fix und kein neues Bereitstellungsbundle freigegeben.
Ein späterer Auftrag muss zuerst einen bestehenden Originalbefund mit diesem
Entscheidungsfeld nachweisen; diese Prüfung autorisiert keine aktive B8-Abfrage
und keinen weiteren Einmaltestmechanismus.

Weitere Abgrenzung des Arbeitszustands: `WORK_STATE_CODE` im Chunk 192,
Byteoffset 1561214, unterscheidet 12=`sleep` und 18=`sleep_in_station`.
`startClean.deviceStatus` liest unverändert `deviceStatus.work_status`
(Chunk 1717, 50894). Der besondere Resume-Zweig prüft genau `sleep`;
`sleep_in_station` wird dort nicht mit diesem gleichgesetzt. Die
Stationsanzeige akzeptiert dagegen entweder `sleep_in_station` oder
`sleep` mit `charge_sleep` (Chunk 192, 2883841). Allein die Beobachtung
„schläft an der Station“ legt deshalb weder Arbeitsbyte noch Schlafuntertyp
eindeutig fest. Die Cloudnormalisierung auf Anzeigezustand 8 verwirft diese
Unterscheidung absichtlich nicht in eine Befehlsentscheidung.

Das E20-Plugin setzt `is_task_continue:!0`. Für seinen konkreten
Arbeitszustand `sleep` ist die Entscheidung somit:

| Schlafuntertyp | Pluginentscheidung nach der Akkuschranke |
| --- | --- |
| 1 charge_sleep | Neuer Auftrag im gewählten Reinigungsmodus |
| 2 pause_sleep | Continue, kein neuer Start |
| 3 pause_charge_sleep | Continue bei bestätigtem supportReturnDustByTime, andernfalls neuer Auftrag |
| 4 stop_sleep | Neuer Auftrag im gewählten Reinigungsmodus |
| fehlend/unbekannt | Plugin hat einen allgemeinen Fallthrough; kein belastbarer RoboHub-Nachweis |

Für einen sicheren Cloudadapter fehlt somit auch die belegte Zuordnung von
`ERP_model/sleep` zum konkreten Hersteller-`work_status` 12 oder 18. Weder
`autoType`, `cleaningType`, `cleanProgress` noch der Missionsstring sind im
Originalplugin als Ersatz für diese Entscheidung belegt. Die vorhandenen
Cachefelder ermöglichen keinen gezielten Fix; es bleiben alle Sperren bestehen.

Auch nach dieser Nachprüfung wurde der vollständige netzisolierte Offline-
Prüfstand erneut ausgeführt: Exitcode 0, 93 PHP-Lints und sämtliche vier
Regressionen für konsistentes `ERP_model/sleep` erfolgreich. `git diff --check`
ist fehlerfrei. Nur dieser Belegbericht wurde ergänzt; mangels belegtem Fix
wurden weder ein neuer Fixcommit noch ein Bereitstellungsbundle erzeugt.

## Vorbereitung eines kontrollierten Statusabrufs

Der vorhandene Statusweg ist der administrative Konto-Diagnosepfad:
`EurekaB8Vorpruefen(false)` → separat signierte Laufzeitbereitstellung →
`EurekaB8Freigeben` → `EurekaB8Testen` → `RoboHubEureka::diagnoseB8` →
`RoboHubEurekaDiagnose::ausfuehren` → `RoboHubEurekaB8::antwort/lesen`.
Dies beschreibt den Ablauf, ist kein ausführbares Aufrufskript und keine
Freigabe für seine Ausführung. Kontobindung 45174, Zielinstanz 22708,
Modell-/Geräteprüfung und vorhandenes Tokenbündel bleiben maßgeblich.
Es wird weder ein Konto angelegt noch eine Authentifizierung ersetzt.

Der einzige freigegebene B8-Abfragebody ist `32 01` (Arbeitsstatus). Er wird
technisch als HTTPS-POST an den bestehenden Gerätebefehlsendpunkt gesendet,
verlangt aber keine Start-/Pause-/Continue-/Dock-Aktion. Das ist kein passiver
Cloud-Shadow-GET. Der bestehende Pfad setzt vor Transport seinen Diagnoseverbrauch
im Kontoattribut; auch Freigabe und Bereitstellung verändern lokale Zustände.
Unter dem jetzigen Verbot produktiver Änderungen wird deshalb keiner dieser
Aufrufe ausgeführt. `Vorpruefen(true)` wäre außerdem eine Listenabfrage mit
Cacheänderung und gehört ausdrücklich nicht zu dieser Vorbereitung.

### Bereits vorhanden und konkret fehlend

| Information | Belegter Statusweg | Tatsächlicher RoboHub-Stand |
| --- | --- | --- |
| Arbeitsstatus | `32 01`, Antworttyp 03, Byte 12 | Parser gibt `arbeit` aus; 12=sleep, 18=sleep_in_station |
| Akku und Fehler | Gleiche Antwort, Byte 22 und 25/26 | Parser gibt `akku`, `fehlerklasse`, `fehlercode` aus |
| Schlafuntertyp | Gleiche Antwort, optionales Byte 48 | Herstellerdecoder vorhanden; RoboHub-B8-Parser liest dieses Byte noch nicht |
| Benannter Schlafuntertyp | Werte 1–4 → charge_sleep/pause_sleep/pause_charge_sleep/stop_sleep | Reine Funktion `RoboHubEurekaZustand::b8(arbeit, schlaf)` vorhanden, aber ohne Verbindung zum Antwortparser |
| supportReturnDustByTime | Separate Fähigkeitsabfrage `32 06`, Antworttyp 03, Byte 14 Bit 4 | Kein Abfrage-/Antwortpfad in RoboHub implementiert; nicht aus `32 01` ableitbar |
| Status-Push | Typ 04, Bodypräfix 42, Schlafuntertyp Byte 46 | Kein Eureka-Pushparser/-Empfangspfad; kein Ersatz für die vorhandene Diagnose |

Der aktuelle B8-Parser prüft Rahmen, Länge, Prüfsumme, Typ 03 und Präfix `32 01`
und benötigt mindestens 37 Bytes. Damit ist ein Arbeitsstatus gültig, ohne dass
das optionale Schlafbyte enthalten sein muss. Eine spätere Erweiterung muss
Byte 48 nur lesen, wenn es vor der Prüfsumme liegt und die belegte optionale
Längenbedingung erfüllt ist (Hersteller: Gesamtlänge >49). Fehlende oder andere
Untertypen bleiben unbekannt; eine kurze Antwort darf keinen Schlafwert erfinden.

Für `32 06` fehlt konkret: ein fest begrenzter Fähigkeitsabfragebody samt
separater Zulassung im bestehenden signierten Diagnose-/Transportvertrag,
Antwortzuordnung Typ 03/Präfix `32 06` und Auswertung von Byte 14 Bit 4 nur bei
belegter Länge. Der aktuelle Diagnosevertrag und die unterste HTTPS-Grenze
akzeptieren ausschließlich die feste Arbeitsstatusabfrage. Ein Austausch des
Abfragecodes oder die Wiederverwendung ihrer Genehmigung für Fähigkeiten wäre
keine zulässige Vorbereitung. Es wird kein generischer Telegramm- oder neuer
Einmaltestmechanismus benötigt bzw. angelegt.

### Nächster konkreter Schritt

Zuerst lokal den bestehenden `32 01`-Antwortparser um den optionalen,
datensparsamen Schlafbefund erweitern und mit vorhandenen Offlinefixtures gegen
kurze, ungültige und nicht zugeordnete Antworten prüfen. Danach kann ein separat
beauftragter, kontrollierter Arbeitsstatusabruf genau Arbeitsstatus, Schlafuntertyp,
Akku, Fehler und Validierungsergebnis ausgeben, niemals vollständige Telegramme,
Kennungen oder Tokens. Die bestehende Laufzeit-/Verbrauchslage ist vor einer
solchen Durchführung ausschließlich lesend zu prüfen; verbrauchte Genehmigungen
werden nicht zurückgesetzt. Die bisherigen Diagnosesperren bleiben bestehen.

Nur falls für die Entscheidung Untertyp 3 festgestellt wird, ist zusätzlich die
Fähigkeit nötig; dafür muss zuvor die fest begrenzte `32 06`-Unterstützung im
bestehenden Vertrag implementiert, offline geprüft und gesondert genehmigt sein.
Untertyp 1/4 bzw. 2 kann die Entscheidung dagegen ohne diese zusätzliche
Fähigkeitsabfrage liefern, sofern alle übrigen Sicherheitsprüfungen bestehen.
Diese Vorbereitung enthält keine Ausführung, Produktionseingriffe oder
Bereitstellung und erteilt keine neue Start-/Fortsetzen-Freigabe.

## Lokale Umsetzung des optionalen Schlafbefunds

Der nachfolgende Stand ersetzt die oben beschriebenen Implementierungslücken
für `32 01`; `32 06` bleibt unverändert unimplementiert. Keine produktive
Bereitstellung oder Abfrage ist erfolgt.

`RoboHubEurekaB8::lesen()` ergänzt `felder.sleep_type` ausschließlich bei einem
vollständig validierten `32 01`-Rahmen mit mindestens 50 Bytes und Byte 48 in
1–4. Bei fehlendem bzw. unbekanntem Byte bleibt das Feld weg. Rahmenvalidierung,
Prüfsumme, Mindestlänge für Grundfelder und Plausibilitätsbewertung bleiben
erhalten. Insbesondere ist Byte 48 eines 49-Byte-Rahmens die Prüfsumme und
kein Schlafuntertyp.

Ein erfolgreicher bestehender administrativer Diagnoseabruf darf den Befund
als `b8={zeit,felder}` ausschließlich am unveränderten vorhandenen
`Schnappschuss[id].p` ergänzen. Sitzung und Tokenbündel werden erneut verglichen.
Ein alter Zusatz wird bei fehlgeschlagenem/ungültigem Abruf entfernt. Nur
bekannte freigabefähige Schlafbefunde werden für die Bedienung aufbewahrt;
Typ 3 bleibt in der Diagnose sichtbar, erteilt aber keine Bedienberechtigung.
Der normale Shadowabruf ersetzt den gesamten Statusstand einschließlich dieses
Zusatzes. Es existiert weder ein separater Schlafcache noch ein Import für
frei eingegebene Untertypen. Ein Cloudfeld `sleep_type` allein wird nicht vertraut.

Die reine Funktion `schlafAktion()` erkennt nur die bestehende konsistente
Kombination `ERP_model/sleep/no_cleaning_mission` mit ergänzendem B8-Arbeitswert
12/18, fehlerfreien B8-Bytes und gültigem Akku >15 in beiden Quellen. Der
B8-Empfang darf nicht älter als der zugehörige Shadow, nicht zukünftig und nicht
älter als 120 Sekunden sein. Die vorhandenen Shadow-/Listenfristen,
Sitzungsrevision, Kernelstart, Modullaufzeit, Gerätebindung und manueller
Bedienkontext bleiben zusätzlich erforderlich.

Nur die Aktionswahl `start` verwendet diesen ergänzenden Nachweis:
1/4 erzeugen einen neuen Start mit den bestehenden expliziten Fan-/Wasserwerten
und Modus 8; 2 erzeugt ausschließlich Continue ohne neue Startparameter.
3, fehlende, ungültige oder widersprüchliche Werte bleiben gesperrt.
Pause und Dock erhalten dadurch keine zusätzliche Zustandsfreigabe.
Die Anzeige bleibt Schlaf; der Shadow wird nicht in Stop/Pause umgeschrieben.
Der gesamte ergänzte Stand geht unverändert in den bestehenden Verbrauchs-
fingerprint und die erneute Prüfung unmittelbar vor Transport ein.

Die bestehende administrative Einmalfreigabe, Signaturprüfung und deren
Verbrauch bleiben unverändert. Diese Änderung richtet keinen dauerhaften
B8-Abruf, keinen Timer und kein neues Freigabesystem ein. Ein neuer Shadow
oder Sitzungs-/Laufzeitwechsel verlangt einen neuen gültigen Befund;
verbrauchte Diagnosebefugnisse werden nicht zurückgesetzt.

Für die spätere private Bereitstellung wird der lokal geprüfte Commit über den
bestehenden `--prepare`-Bundleweg gepackt. Dessen `--apply` erwartet weiterhin
die historische Basis `6fefd79`; vor einem späteren gesonderten Produktivauftrag
muss der Ablauf auf den dann verifizierten produktiven Basiscommit abgestimmt
werden. Erst nach einer solchen beauftragten Bereitstellung kann ein separat
beauftragter vorhandener `32 01`-Diagnoseabruf die realen Schlafdaten liefern.
Ein Bundle oder eine lokale Regression ist kein realer Schlafbefund und löst
keinen Roboterbefehl aus.

Validierung dieses lokalen Umsetzungsstands: vollständiger Lauf
`bash tests/eureka/run-control-offline.sh` in eigener Netzwerk-Namespace,
Exitcode 0. Darunter 714 Diagnoseprüfungen, 364 Befehlsprüfungen, alle
gebundenen Start-/Continue-/Sitzungs-/Verbrauchsfälle und 93 PHP-Lints.
Alle bestehenden übrigen Prüfstände und `git diff --check` sind erfolgreich.
Die vertrauliche Treiberbindung, das Gerätemodul und die Bibliotheksmetadaten
bleiben gegenüber dem vorherigen Commit unverändert.
