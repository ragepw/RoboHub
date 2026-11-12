# Eureka E20: Zustände und lesende Protokollabfragen

Stand: 12.11.2026. Ergänzt um die vom Nutzer gelieferten real beobachteten
E20-Evo-Plus-Shadowkombinationen; keine eigene Cloud-Abfrage. Statische Basis: Originalplugin 2.5.4 (`release_e20`, Produktkennung
`750Y0015`). Grundlage ist der lokale
[E20-Analysebericht](../../eureka-plugin-analyse/analyse-2.5.4/E20-Analysebericht.md),
ergänzt durch erneutes statisches Lesen der dort verzeichneten Bundles. Die
Shadow-Namen stammen aus `const.py` der lokalen HA-Referenz, Commit `ce933e6`.
Es wurden keine Originaldateien in RoboHub übernommen und kein Plugin-Code
ausgeführt. Die B8-Tabellen beschreiben statische Protokollfakten; die ältere Shadowmatrix
enthält abgeleitete Kombinationen. Die folgende Messmatrix und
`tests/eureka/observed-shadow-fixtures.php` enthalten dagegen die vom Nutzer
bestätigten Statusfelder ohne individuelle Kennungen.

## Real bestätigte Shadowkombinationen (12.11.2026)

| state | parentState | cleanMissionState | Vorher | Nachher |
| --- | --- | --- | --- | --- |
| `cleaning` | `cleaning` | `no_cleaning_mission` | Reinigt (1) | Reinigt (1) |
| `pause` | `pause` | `have_cleaning_mission` | Pausiert (2) | Pausiert (2) |
| `charging_back` | `charging_back` | `no_cleaning_mission` | Rückfahrt (3) | Rückfahrt (3) |
| `charging` | `charging` | `no_cleaning_mission` | Lädt (4) | Lädt (4) |
| `collecting_dust` | `collecting_dust` | `no_cleaning_mission` | Unbekannt (-1) | Stationsarbeit (6), Herstellerstatus „Entleert Staub“ |
| `ERP_model` | `sleep` | `no_cleaning_mission` | Unbekannt (-1) | Schläft (8) |

„Rückfahrt“ verwendet die bestehende Profilbeschriftung „Fährt zur Station“.
Staubentleerung passt zu `ZUSTAND_STATIONSARBEIT` (6), das bereits Absaugen
umfasst. Die genaue Tätigkeit erscheint in `ZustandRoh` als
`Entleert Staub (collecting_dust / collecting_dust)` und damit auch im
Herstellerstatus der Kachel. Das gemeinsame Zahlenprofil bleibt unverändert;
ein eigener Zahlenwert würde das bestehende herstellerneutrale Modell erweitern.

Alle sechs Beobachtungen enthalten `batteryLevel=100`, `deviceFault=[]`,
`fanLevel="3"`, `waterLevel="2"`, `autoType="room_cleaning"` und **kein**
`connected`. Akku und Saugstufe werden wie bisher übernommen; Wasser/AutoType
bleiben Rohdaten ohne neue Einstellungen. Die neuen Zuordnungen für
`collecting_dust` und `ERP_model` gelten nur bei der exakt belegten
Parent-/Mission-Kombination. Fehlende, unbekannte oder widersprüchliche Werte
bleiben unbekannt. Für die vier schon bekannten Zustände bleibt die bisherige
Konsistenzregel bestehen. `no_cleaning_mission` ist nach der Reinigungsmessung
kein Beleg gegen Reinigung; `have_cleaning_mission` allein beweist keine Pause.
Auch Akku 100 ändert `charging` nicht in „Geladen“. Andere ERP-Kombinationen
werden nicht pauschal als Schlaf, Pause oder Laden interpretiert.

Nur diese sechs Kombinationen sind real bestätigt. Alle übrigen bisherigen
Cloudzuordnungen, Parent-Gruppierungen und B8-Übersetzungen bleiben statisch
abgeleitet; insbesondere existiert kein Live-Beleg für die Gleichsetzung der
neuen Cloudnamen mit bestimmten B8-Bytes, für Gerätefrische oder Befehlsfähigkeit.

## Statusnormalisierung

`RoboHubEurekaZustand::shadow()` wertet ausschließlich den Cloud-Namensraum aus.
Der konkrete `state` hat Vorrang vor dem groben `parentState`:

| Shadow-state | RoboHub-Zustand |
| --- | --- |
| `cleaning` | reinigt 1 |
| `pause`, `charging_pause`, `wiping_pause` | pausiert 2 |
| `charging_back`, `wiping_back` | zurück 3 |
| `charging` | lädt 4 |
| `charging_finish` | geladen 5 |
| `stop` | bereit 0 |
| `sleep` | Schlaf 8 |
| `upgrading` | Aktualisierung 10 |
| `error` | Fehler 7 |

Ein fehlender/leerer Parent oder ein Parent gleich dem Unterzustand ist
verträglich. Als gröbere Sammelgruppen akzeptiert die Anzeige:

- `cleaning` mit Reinigung, den drei Pausen, beiden Rückfahrten oder Fehler.
- `sleep` mit Schlaf, Laden, geladen, Stop oder Fehler.

Damit werden insbesondere `pause/cleaning`, `charging_back/cleaning`,
`charging/sleep`, `charging_finish/sleep` und `error/cleaning` korrekt dargestellt.
Diese Kombinationen sind aus Referenzsemantik abgeleitete Testfälle. Ihre
tatsächliche Ausgabe durch die E20-Cloud ist weiter unbestätigt.

Andere Parent-Kombinationen ergeben unbekannt -1. Ein konkreter `error` bleibt
auch bei widersprüchlichem Parent als Fehler sichtbar; die Konsistenzbewertung
bleibt dann negativ. Ein Parent allein ist zu grob: `cleaning` kann Pause und
Rückfahrt enthalten, `sleep` Laden. Fehlender oder unbekannter Unterzustand wird
deshalb nicht durch den Parent ersetzt. Unbekannte Zahlen, Strings, ungültige
Typen und B8-Namen werden nicht als Cloudstatus interpretiert. Es wird kein alter
Aktivitätszustand in eine neue unvollständige Antwort übernommen.

`on_station` beweist weder Laden noch vollgeladen. `positioning`, `discovering`
und `manual_control` sind im HA-Cloudvokabular vorhanden, ohne eindeutige
E20-Zuordnung zu einer RoboHub-Aktivität; sie bleiben unbekannt. Die dortige
pauschale Gruppierung als Reinigung wird nicht übernommen. Auch die Zuordnung
der übrigen Cloudnamen zu E20-B8-Werten ist eine semantische Referenzzuordnung,
kein Live-Nachweis. Insbesondere Wischzustände belegen keine zusätzliche
E20-Funktion oder Bedienfreigabe.

## E20-B8-Zustände und Schlaf

`RoboHubEurekaZustand::b8()` ist eine separate reine Offline-Auswertung, ohne
Frameparser und ohne Aufruf aus dem Shadow-Lesebetrieb. Quelle: Bundle `192`,
Modul 71825 ab Byteoffset 1530156 sowie Modul 16914, `WORK_STATE_CODE` ab
1561214. Alle Offsets sind UTF-8-Bytes ab Bundleanfang.

| B8 dezimal | Pluginname | RoboHub |
| --- | --- | --- |
| 1 | `charge` | zurück 3, **kein Laden** |
| 2 | `work` | reinigt 1; Modus/Funktion bleiben zusätzlich relevant |
| 3, 5 | `stop`, `reserve_task_finished` | bereit 0 |
| 4, 7 | `charging_on_dock`, `charging_with_wire` | lädt 4 |
| 6 | `charge_finish` | geladen 5 |
| 8, 10 | `pause` | pausiert 2; Rohwerte bleiben unterscheidbar |
| 9 | `updating` | Aktualisierung 10 |
| 11 | `fault` | Fehler 7 |
| 12, 18 | `sleep`, `sleep_in_station` | Schlaf 8 |
| 13 | `charge_pause` | pausiert 2; Rückfahrt abgebrochen, kein Resume-Beleg |
| 14 | `relocate` | unbekannt -1; keine eindeutige Zielkategorie |
| 15 | `waiting_water_making` | unbekannt -1; allgemeiner Enum, E20-Funktion deaktiviert |
| 16 | `dusting` | Stationsarbeit 6 |
| 17, 22 | `re_dusting`, `dust_docking` | zurück 3, noch keine aktive Absaugung |
| 23 | `environmental_exploration` | Kartierung 9 |
| 24 | `environmental_exploration_pause` | pausiert 2 |
| alle anderen | unbelegt | unbekannt -1 |

Die vier Schlafuntertypen lauten `1 charge_sleep`, `2 pause_sleep`,
`3 pause_charge_sleep`, `4 stop_sleep` (Modul 16914, Byteoffset 1566324).
Die Offline-Auswertung erhält Arbeitsbyte und Schlafbyte unverändert und liefert
den bekannten Untertyp für Schlafzustände separat. Ein unbekannter/fehlender
Untertyp wird nicht als Stop oder neuer Auftrag behandelt. Der Code führt
keinen Auftragsspeicher und löscht oder setzt bei Schlaf keinen Auftrag fort.
Die Plugin-UI erlaubt Resume aus bestimmten Schlafuntertypen, teils abhängig
von `supportReturnDustByTime`; daraus entsteht keine RoboHub-Befehlsfreigabe.

## Erreichbarkeit, Gerätebindung und Alter

Die drei Ebenen bleiben getrennt. Ein erfolgreicher HTTP-/Cloud-/Parser-Abruf
bestätigt den Cloud-Statusabruf, niemals eine aktuelle Geräteverbindung. Fehler
werden weiter als Fehler zum Gerät transportiert und nicht als `connected=false`
erfunden. Nur explizite boolesche `connected`-Werte werden ausgewertet; fehlende,
null, Strings und Zahlen bedeuten unbekannt.

Die Anzeige bewertet zuerst `shadow.connected` (true/false). Fehlt ein gültiger
Shadowwert, darf `roh.connected` der Geräteliste einspringen, wenn deren lokale
Empfangszeit `verbindungEmpfangen` positiv, nicht zukünftig und höchstens 120
Sekunden alt ist. Diese konservative Anzeigegrenze ist eine lokale Regel, kein
belegtes Cloud-Frischeversprechen. Sie gilt gleichermaßen für true und false.
Ein älterer, fehlender oder ungültiger Zeitstempel ergibt unbekannt. Alte
Cacheeinträge ohne Zeitstempel werden nicht beim Lesen nachträglich verjüngt.
Die Empfangszeit wird ausschließlich beim ohnehin stattfindenden Geräteabruf
im Eureka-Treiber ergänzt und mit der Liste unverändert gespeichert.

Es gibt **keinen zusätzlichen Listenabruf**. Die Kontoaktion `geraete` lädt
bisher bei leerer/über einer Stunde alter Liste oder ausdrücklichem `Neu` nach;
`findeGeraet()` lädt für ein unbekanntes Ziel einmal nach. Bekannte Ziele können
einen Cache vom Vortag behalten. Ein Shadowabruf erneuert diesen Cache nicht.
Der globale `GeraeteStand` dient weiterhin der Listenverwaltung und wird nicht
als Gerätezeit oder nachträglicher Einzelgeräte-Verbindungsbeleg verwendet.
Der Nutzerbericht belegt `roh.connected=true` in der gespeicherten Liste;
Tests belegen den Parser für beide booleschen Werte. Ob der Endpunkt bei einer
frischen Abfrage zuverlässig die tatsächliche Verbindung meldet, wurde ohne
Cloudzugriff nicht verifiziert. Auch ein frischer Listenbefund ist lediglich
eine aktuelle Cloudmeldung mit unbekanntem Alter des zugrunde liegenden Signals.

`erreichbarkeit` enthält `verbindung` (true/false/null, nur Anzeige), `shadow`
und `geraetecache` (jeweils expliziter Rohbefund oder null), `cachealter`
(Sekunden oder null bei unbekannter/ungültiger Zeit), `quelle`
(`shadow.connected`, `geraetecache.connected`, `unbekannt`) und `empfangen`
(lokale Shadow-Empfangszeit). Im Geräteattribut `EurekaErreichbarkeit` und in
`ROBO_Rohdaten()` unter `robohubErreichbarkeit` ist die Bewertung nachvollziehbar.
Bei Abruffehler wird die Bewertung entfernt; die alten Herstellerrohdaten
bleiben der letzte erfolgreich empfangene Befund. Eureka-Rohantworten werden
weiter gespeichert, aber nicht mehr per `SendDebug` protokolliert, da sie
individuelle Gerätekennungen enthalten können.

| IP-Symcon-Ident | Typ | Bedeutung und Grenze |
| --- | --- | --- |
| `Erreichbar` | Boolean, unverändert | Nur Shadow-`connected=true` ergibt true. false bedeutet offline **oder nicht belegt**, niemals zwingend offline. Auch ein positiver frischer Cache ändert diese Altvariable nicht. |
| `CloudStatusAbruf` (neu, nur Eureka) | Boolean | Letzter Statusabruf vollständig erfolgreich; bei Konto-/Cloud-/Antwortfehler false. Keine Aussage über Geräteverbindung oder Gerätezeit. |
| `Verbindungsstatus` (neu, nur Eureka) | String | „Online bestätigt“, „Offline bestätigt“, „Verbindung unbekannt“ nach obiger Quellenregel. Bestätigt bedeutet explizite Cloudmeldung, kein direkter Gerätehandshake. Abruffehler ergibt unbekannt. |

Die neuen Variablen entstehen beim ersten erfolgreichen Eureka-Statusabruf;
auch wenn `connected` fehlt. Es werden keine Aktionen freigegeben. Alle Anzeigen
beschreiben den letzten Abfrageversuch, sie altern nicht durch einen neuen
Hintergrundtimer. Nach einem gestoppten Abruf sind Werte daher weiterhin letzte
Befunde; Empfangszeit/Cachealter dokumentieren den Auswertungszeitpunkt.

`online` im normalisierten Treiberstatus und im Konto-Schnappschuss bleibt
streng `shadow.connected===true`, unabhängig vom dreistufigen Anzeige-Fallback.
Damit bleiben die bestehende boolesche Variable, Kachel und `GetActivity()`
rückwärtskompatibel: Ohne positiven Shadow-Nachweis meldet `GetActivity()`
weiter state=-1, moving=false, outsideStation=false, validUntil=0. Der gelesene
`Zustand` selbst bleibt sichtbar. Ein erfolgreicher Shadowabruf ohne connected
macht die Aktivität ausdrücklich nicht für Automationen verlässlich. Der
Vertrag 1.0 bleibt unverändert; GetActivity fragt nur lokale Kontoinformationen
ab, keine Cloud. Unbekannte Verbindung wird niemals zur Steuerfreigabe.

Andere Hersteller behalten vollständig ihre bisherige Semantik: `uebernimm()`
nutzt `online`, bei fehlendem Feld weiterhin true; Abruffehler setzen Erreichbar
false. Beispielsweise Worx übernimmt den Listen-Onlinewert, andere Normalisierer
liefern keinen Onlinewert. Eine herstellerübergreifend einheitliche
Verbindungsdefinition existiert bisher nicht und wird hier nicht eingeführt.
Weder deren Variablentypen noch GetActivity-Regeln werden geändert.

Mehrere Shadow-Einträge werden über `deviceId`/`id` zugeordnet, nicht über
Position null. Doppelte Ziele, widersprüchliche Identitäten, fehlendes Ziel,
ungültige Einträge und unbeschriftete Mehrgerätelisten werden abgewiesen. Ein
einziger unbeschrifteter Status bleibt für den bestehenden Einzelziel-GET
verträglich; seine Zuordnung folgt allein dem angefragten Ziel. Das ist kein
zusätzlicher vom Gerät gelieferter Identitätsbeleg.

Ein belegtes Gerätezeitstempelfeld ist weder im Plugin noch in der
Shadow-Referenz vorhanden. `statusquelle.geraetezeit` bleibt deshalb null.
Lokale Empfangszeit und bestätigte Gerätezeit werden nicht gleichgesetzt.
Die Anzeige beschreibt den letzten gelieferten Shadowstatus, nicht einen
garantiert aktuellen Roboterzustand. Eine erfolgreich neu gelesene, aber schon
cloudseitig veraltete Antwort ist ohne Gerätezeit nicht zuverlässig erkennbar.
Es werden keine geratenen Zeitstempelnamen oder Einheiten interpretiert.

Der vorbereitete Befehlsweg verwirft weiterhin lokale Schnappschüsse über
120 Sekunden, Zukunftszeiten und fehlende/ungültige Zeiten. Zusätzlich verlangt
er jetzt `connected===true` im Shadow selbst, beachtet ein effektives
`stand.online=false` und fordert einen explizit leeren Fehlerbefund. Allein ein
neues lokales `zeit` nach GET beweist weiterhin keine Gerätefrische; vor späterer
Steuerfreigabe muss diese Grenze zusätzlich gelöst werden.

## Symcon-Variablen und vorbereiteter Befehlsweg

Idents, Variablentypen und Zustandsnummern bleiben erhalten. `Zustand` erhält
die differenzierte Abbildung. `ZustandRoh` behält für Strings das bisherige
Format `state / parent`. Nur bei ungültigen Typen wird stattdessen JSON mit den
gelieferten `state`-/`parentState`-Feldern angezeigt. Vollständige Rohantworten
bleiben erhalten.
`Fehler` erhält nicht leere `deviceFault`-Daten als JSON, einen explizit leeren
Befund als „Keine Fehler“ und bei fehlendem Befund „Unbekannt“. Warnungen werden
nicht pauschal zum Arbeitszustand Fehler hochgestuft. Ein konkreter Fehlerstatus
wird umgekehrt nicht durch einen leeren Fehlercode verborgen.

`Akku` wird nur bei Integern von 0 bis 100 übernommen. Saugwerte werden ohne
Umnummerierung als Rohwert angezeigt, ungültige Typen ausgelassen. Wie bisher
behält Symcon optionale Variablen bei fehlenden Werten; etwa ein vorheriger
Akkustand bleibt der letzte bekannte Messwert. Es wird keine Nullmessung erfunden.
Abgesehen von den zwei Eureka-Anzeigevariablen entstehen keine neuen
Variablen, Auswahloptionen oder Schreibaktionen.
Nur beim bestätigten Staubentleeren ergänzt ein lesbarer Herstellerstatus das
bisherige Rohtextformat; die vollständige Rohantwort bleibt unverändert.

Der automatische Abruf bleibt bestehen: Reinigung/Rückfahrt nutzen den schon
vorhandenen aktiven 30-Sekunden-Takt, Pause/Laden/Schlaf das konfigurierte
Basisintervall. Die bestehenden Timer und die Token-Erneuerung werden nicht
umgebaut. GET-401 rotiert und persistiert weiterhin zentral, mit genau einem
Lese-Retry. Token-Tests prüfen die bestehenden Konkurrenz- und Abmeldepfade.

Anzeige und Befehlsplaner teilen nun die Parent-Konsistenzprüfung. Der Planer
behält zusätzlich sein Cache-Veto und bleibt enger: nur `cleaning`, `pause`, `stop`, `charging`, `charging_finish`;
`on_station` ist mangels eindeutigen Lade-/Auftragsbefunds nicht länger als
Neustartgrund zugelassen. Spezialpausen, Schlaf, Rückfahrt, Staubentleerung und unbekannte
Zustände bleiben gesperrt. Die neuen Anzeigezustände erweitern keine
Befehlsgruppen. Die sechs gemessenen Antworten scheitern bereits am fehlenden
Shadow-`connected`; ein erfolgreicher GET und eine lesbare Aktivität sind keine
Online- oder Befehlsfreigabe. Die Freigabekonstante bleibt false, die produktive
Zuordnung leer und die Transportsperre vor cURL bestehen. Keine neue
Live-Abfrage, kein Befehl und keine automatische POST-Wiederholung.

## Lesende B8-Abfragen aus Plugin 2.5.4

Der Rahmenbauer `C` in Modul 16153 legt für die Abfragefamilie `32` zwei
Bodybytes an und ruft `mdSmart.message.createMessage(184, 3, body)` auf.
`requestWorkState` steht ab Byteoffset 1345510,
`requestRobotAvailable` ab 1347445. Der enthaltene `createMessage`-Helfer in
Modul 95680 steht ab 2380556. Vollständige Rahmen:

| Abfrage | Hexrahmen | Länge / CS |
| --- | --- | --- |
| Arbeitsstatus `32 01` | `AA0CB800000000000003320106` | 13 Bytes, LL=0C, CS=06 |
| Fähigkeiten `32 06` | `AA0CB800000000000003320601` | 13 Bytes, LL=0C, CS=01 |

Schema: `AA LL B8 00 00 00 00 00 00 03 BODY CS`;
`LL = Gesamtlänge - 1`; `CS = -Summe(Byte 1 bis vor CS) & FF`.
Dies ist eine additive Prüfsumme, keine CRC8 oder Authentisierung. Die Tests
berechnen die Rahmen ausschließlich als lokale Daten mit dem vorhandenen
allgemeinen Rahmenbauer. Es gibt keine neuen transportfähigen Abfragefunktionen.

### Erwartete Arbeitsstatusantwort

Der Decoder erwartet ein Bytearray des vollständigen Rahmens, Typ 03 mit
Bodypräfix `32 01`: `AA LL B8 00 00 00 00 00 00 03 32 01 ... CS`.
Belegt sind die Leseoffsets, nicht ein einziges fixes Antwortpaket oder eine
vollständige Liste zulässiger Firmwarelängen. Absolute, nullbasierte Offsets:

| Byte | Inhalt |
| --- | --- |
| 12 | Arbeitsstatus gemäß B8-Tabelle |
| 13 | Funktionstyp |
| 14, 15 | Steuerart, Bewegungsrichtung |
| 16, 17 | Arbeitsmodus, Saugstufe |
| 18, 19, 20 | Fläche low, Wasserstufe, Lautstärke |
| 21, 22, 23 | reservierter Auftrag, Akku, Arbeitszeit low |
| 25, 26 | Fehlerklasse, Fehlercode |
| 27 Bits 0/1 | Mopp vorhanden, Vibration vorhanden |
| 28 | Teppichschalter |
| 30, 31, 32 | Geschwindigkeit, Bereichsstatus, Reinigungstyp |
| 33 | Fläche high im unteren, Zeit high im oberen Nibble; Faktor jeweils 255 |
| 34, 35 | Vibrationsmodus, Vibrationsschalter |
| 36, 37 | Wasseraufbereitungsschalter/-status |
| 38 Bits 0/1 | Moppheber-Schalter/-Position |
| 39, 41, 42 | Absauganzahl, Stationstyp, Absaugzeit |
| 43 Bits 0/1 | BLE gekoppelt/Koppelstatus |
| 45 Bit 0, 46 | Laserflag, Kindersicherung |
| 48 | Schlafuntertyp |
| 52, 53 | zeitabhängige Absaugungsrückkehr: Schalter, Zeit |
| 55, 57 | Ausstellungsmodus, personalisierte Reinigung |

24, 29, 40, 44, 47, 49–51, 54, 56 sind im verfolgten Arbeitsstatusdecoder
nicht als Nutzfelder belegt. Optionale Zugriffe hängen von der Rahmenlänge ab;
zusätzliche Felder ab 36 sind außerdem in eine äußere `length>38`-Prüfung
eingeschlossen. Kindersicherung wird bei `length>47`, Schlaf bei `length>49`,
das letzte Feld bei `length>58` verarbeitet. Die allgemeine Enum-Übersetzung
und die gesonderten Zugriffe prüfen nicht durchgehend sauber auf kurze Pakete.
Nicht übernommene Werte können deshalb in der Plugin-UI alt bleiben.

Statuspush ist ein anderer Rahmen: Typ 04, Präfix `42`, Arbeitsstatus an Byte 11,
Modus 15, Fan 16, Akku 21, Erweiterungsnibbles 31, Schlafuntertyp 46. Fehlerpush
verwendet Präfix `A3`, Klasse/Code an 11/12. Diese Formate dürfen nicht mit der
`32 01`-Antwort verwechselt werden. Ein neuer Push-/Antwortparser wird nicht
implementiert. Fehlerklassen: 0 ohne Fehler, 1 behebbar, 2 Neustart, 3 Warnung.

### Erwartete Fähigkeitsantwort

Das Antwortarray wird als Typ 03, Body `32 06` interpretiert:
`AA LL B8 00 00 00 00 00 00 03 32 06 FLAGS12 FLAGS13 [FLAGS14 [FLAGS15]] ... CS`.
Die Präfix-/Typzuordnung folgt dem Queryschema und der zugeordneten
Callback-Auswertung; der Callback selbst validiert diese Bytes nicht.
Die bekannten Fähigkeiten sind vollständig wie folgt dekodiert:

| Byte | Bits und Bedeutung |
| --- | --- |
| 12 | 0 `showRoomMode`; 1 **invertiert** `needBorderEmbellish`; 3 `roomMerge`; 5 `mopForbbiden`; 6 `spliteRoomOneKey`; 7 `multfloorsupport` |
| 13 | 5 `mapsavesupport`; 6 `mqttSupport`; 7 `childLockSupport` |
| 14, nur length>15 | 1 `autoSyncFloorName`; 3 `supportMapBackUp`; 4 `supportReturnDustByTime`; 6 `quickCreateMapSupport`; 7 `supportManualSaveMap` |
| 15, nur length>16 | 1 `supportCustomClean`; 3 `supportBatteryCalibration`; 4 `supportMapBackUpV2` |

Andere Bits sind im verfolgten Decoder unbelegt. `forbidenRotate` und
`supportSoftBatteryMeter` werden fest false gesetzt, nicht aus Antwortbits
gelesen. Fehlende optionale Bytes werden im Plugin als null/false behandelt;
das ist kein positiver Nachweis einer Gerätefähigkeit. Bytes 12/13 werden ohne
ausreichende eigene Längenprüfung gelesen. Aus diesen Zugriffen folgt für ein
formal vollständiges Basispaket mindestens 15 Bytes einschließlich Prüfsumme,
mit beiden optionalen Bytes mindestens 17 Bytes; dies ist eine Strukturfolgerung,
keine vom Hersteller bestätigte Längenspezifikation. Weitere Bytes sind offen.

### Vergleich mit Shadow-GET und Entscheidung

Die App schickt beide Abfragen über `window.bridge.startCmdProcess`, den
Arbeitsstatus zusätzlich im 15-Sekunden-Polling. Der Erfolgs-Callback übergibt
das Bytearray unverändert an die Decoder (Modul 16153, Byteoffset 1319443).
Die externen nativen Bridge-Dateien fehlen. Vollständige Header-/Längen-/
Prüfsummenvalidierung durch die Bridge ist nicht nachgewiesen. Das Plugin
belegt auch keinen `/devices/commands`-Transport für den regulären E20-Pfad.

RoboHub liest dagegen `GET /shadow/device/state?devices=<id>` und normalisiert
JSON-Namen. Das benötigt keine aktive B8-Abfrage und liefert bereits Akku,
Saugwert, Arbeitsstatus und gegebenenfalls `connected`/Fehler. `32 01` könnte
später Arbeitsbyte, Schlafuntertyp, Modus und Einstellungen zur Gegenprüfung
liefern; `32 06` zusätzliche Fähigkeiten, die Shadow derzeit nicht auswertet.
Beide sind im Inhalt lesend, werden aber aktiv an das Gerät adressiert und
sind nicht einfach ein anderer passiver HTTP-GET.

**Entscheidung:** Für die Statuskorrektur und den bisherigen Lesebetrieb ist kein
zusätzlicher aktiver Leseweg erforderlich. Fehlende Fähigkeiten und unsichere
Unterzustände werden konservativ nicht freigegeben. Für eine spätere
Steuerfreigabe wäre ein separat autorisierter Vergleich mit frischen,
bereinigten E20-Daten hilfreich; statische Analyse allein beweist weder
Transportkompatibilität noch Gerätefrische oder reale Antwortlänge. In diesem
Arbeitsstand wird kein zusätzlicher Transport implementiert oder ausgeführt.

## Offline-Prüfung

`tests/eureka/state-test.php` prüft die Cloud-/Parent-Matrix, unbekannte und
falsche Typen, alle 256 B8-Arbeitsbytes, die vier Schlafuntertypen und ungültige
Untertypen, verbindliche Erreichbarkeit, Zielauswahl, Fehlererhalt, fehlende
Werte, die lokale 120-Sekunden-Grenze und beide goldenen Queryrahmen.
Die Integration in `.tools/test-eureka.inc.php` prüft echte Konto-/Gerätepfade,
Variablen, aktiven/Basistakt, Erreichbarkeit und den gesperrten Befehlseinstieg.
Der bestehende Befehlsprüfstand und sämtliche Token-Races bleiben Teil der
Regression. Ausführung ausschließlich offline; native Symcon-Laufzeit und
reales E20-/Cloudverhalten bleiben ungeprüft.

Ergebnis auf PHP 8.2.35, alle vier Laufzeitprüfstände mit extern gesperrtem Netz
(`bwrap --unshare-net --dev-bind / / -- …`): **575 Zustandsprüfungen,
233 Befehlsprüfungen, 72 Token-/GET-Prüfungen und 1.151 Gesamtprüfungen bestanden,
jeweils 0 Fehler**. PHP-Lint: 66 Dateien ohne Syntaxfehler; Diff-Whitespaceprüfung
einschließlich neuer Dateien fehlerfrei. Die Gesamtprüfungen verwenden nur lokale
Nachbauten, keine produktive Symcon-Instanz. Kein Commit oder Push.

Geänderte Dateien:

- `libs/RoboHubEureka.php`, `libs/RoboHubEurekaZustand.php` (neu),
  `libs/RoboHubEurekaBefehlsweg.php`: Status, Erreichbarkeit, Zuordnung, Planung.
- `RoboHubKonto/module.php`: Herkunft und effektive Erreichbarkeit im Schnappschuss.
- `tests/eureka/state-test.php` (neu), `tests/eureka/command-test.php`,
  `tests/eureka/command-fixtures.php`, `tests/eureka/e20-fixture.php`,
  `.tools/test-eureka.inc.php`: deterministische Offline- und Integrationstests.
- `.github/workflows/check-style.yml`: Zustands-/Befehlsprüfungen in CI aufgenommen.
- `docs/eureka-zustaende.md` (neu), `docs/eureka-steuerung.md`,
  `tests/eureka/README.md`: Beleglage, Grenzen und Prüfergebnisse.

## Prüfung der Erreichbarkeitsergänzung (12.11.2026)

Die zusätzlichen Regressionen prüfen Shadow true/false/fehlend, frische und alte
Listenwerte beider Vorzeichen, exakt 120/121 Sekunden, Zukunfts-/fehlende/falsche
Zeitstempel, Vorrang des Shadows, einmaligen Status-GET ohne Listen-Nachfrage,
Befehlssperren trotz positivem Cache sowie die echte Konto-/Geräteübergabe,
GetActivity, Cloudfehler und das Ausbleiben neuer Variablen anderer Hersteller.
Die native IP-Symcon-9.1-Darstellung und frische reale Listenantworten bleiben
bis zu einer gesondert freigegebenen Prüfung offen. Keine Produktivänderung,
Firmwareänderung, Commit, Push oder Deployment.

Prüfergebnis dieser Ergänzung (PHP 8.2.35): **1185 Gesamtprüfungen,
683 Zustandsprüfungen, 233 Befehlsprüfungen und 72 Token-/GET-Prüfungen bestanden,
jeweils 0 Fehler**. Alle Laufzeitprüfstände liefen mit
`bwrap --unshare-net --dev-bind / / -- php …`, ausschließlich gegen Fixtures
und lokale Nachbauten. PHP-Lint: **67 Dateien ohne Syntaxfehler**;
`git diff --check`: fehlerfrei. Die gemeinsame Sprachdatei wurde mit dem
bestehenden Generator in alle drei Module übernommen. Ein zwischenzeitlicher
lokaler iRobot-UDP-Fehler bei überlappenden Gesamtprüfständen trat im abschließenden
einzeln ausgeführten Gesamtprüfstand nicht erneut auf.
