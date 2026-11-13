# Eureka E20 Evo Plus: Reinigungsparameter

Stand: 13.11.2026. Ausschließlich lokale Analyse und Offline-Implementierung.
Keine produktiven Symcon-Änderungen, Cloud-Abfragen oder Roboterbefehle.

## Herstellerbelege

Lokale Quelle: `../eureka-plugin-analyse/T0xB8_750Y0015.zip`, Version 2.5.4,
Zweig `release_e20`, SHA-256
`0a275bbd2ee8c076aeb8e1d314a4bbe98fa5c3c6aeb3750414765adc399f24c6`.
Der Herstellercode wurde als Text gelesen, nicht ausgeführt.
Die nachstehenden Links zeigen auf die lokalen statischen Modulauszüge unter
[analyse-2.5.4/modules](../../eureka-plugin-analyse/analyse-2.5.4/modules/).
Sie gehören zum lokalen Analysebestand und werden nicht mit RoboHub ausgeliefert.

| Beleg | Fundstelle und Bedeutung |
|---|---|
| Modellkonfiguration | [192-56489.txt](../../eureka-plugin-analyse/analyse-2.5.4/modules/192-56489.txt), Zeilen 98–124: `suction` ausschließlich soft/normal/high; `waterList` low/soft/normal/high aus der gemeinsamen Konfiguration. `has_flow` und `has_lifting_mop` sind im E20-Build wahr. |
| Bezeichnungen | [192-98085.txt](../../eureka-plugin-analyse/analyse-2.5.4/modules/192-98085.txt), Zeilen 694–750: Saugschlüssel soft/normal/high; Wasser-Konfigurationsname `low` enthält **key off**, `soft` enthält **key low**, normal/high entsprechend. Nicht Konfigurationsnamen als Bytewerte lesen. |
| Bytewerte | [192-71825.txt](../../eureka-plugin-analyse/analyse-2.5.4/modules/192-71825.txt), Zeilen 16–44: `auto=8`; fan soft=1, normal=2, high=3; water off=0, low=1, normal=2, high=3. |
| Start | [192-16153.txt](../../eureka-plugin-analyse/analyse-2.5.4/modules/192-16153.txt), `cmdStartWork`, Originaloffset 1328389 im Bundle `192.f5b85c85acde7c6c892e.js`: Byte 15 work_mode, 16 fan_level, 18 water_level. |
| Hauptbedienung | [1717-81717.txt](../../eureka-plugin-analyse/analyse-2.5.4/modules/1717-81717.txt), `cmdStartWork(WORK_MODE_NAME.auto, DeviceStatus.deviceStatus.fan_level, DeviceStatus.deviceStatus.water_level)`; Fortsetzen separat über `cmdStopWork(STOP_ACTION.continue)`. Neuer Auftrag verlangt Akku >15 %. |
| Separate Änderung | Hersteller-Chunk `6590.7a7acdd058185065bb9f.js`, Modul **90527**, Funktionen a/c: `cmdSetControlMode(aktuellerFan, neuerWasserwert)` bzw. `(neuerFan, aktuellerWasserwert)`. Sender in Modul 16153, Originaloffset 1329977. |

## Unterstützte Auswahl und Kombinationen

| Reinigungsart in RoboHub | fan | water | mode beim Start |
|---|---|---|---|
| Nur saugen | 1, 2, 3 | 0 | 8 |
| Saugen und wischen | 1, 2, 3 | 1, 2, 3 | 8 |
| Nur wischen | nicht belegt, gesperrt | — | — |

Saugstufen: **1 Sanft, 2 Standard, 3 Stark**.
Wasserstufen: **0 Aus, 1 Niedrig, 2 Standard, 3 Hoch**.
Alle zwölf Kombinationen fan 1–3 × water 0–3 sind im E20-Auswahlpfad
unabhängig auswählbar; dort ist keine weitere gegenseitige Sperre sichtbar.

Der allgemeine Enum enthält zusätzlich fan off=0, low=4 und max=5. Diese
Werte sind keine E20-Auswahl und bleiben gesperrt. Insbesondere wird aus fan=0
kein vermeintlich unterstütztes reines Wischen konstruiert. Das Archiv beweist
keine darüber hinausgehenden Firmwarefähigkeiten oder Sperren.

Die Reinigungsart ist eine lokale Bedienabstraktion aus Saug- und Wasserwert.
Es gibt im belegten Start keinen eigenen Schalter „Saugen/Wischen“.
`mode=8` bedeutet Ganzhaus/auto, nicht Saugen und Wischen.
Wasser aus belegt abgeschalteten Wasserfluss; eine bestimmte mechanische
Moppstellung wird damit nicht zugesichert. Räume, Karten und Rechtecke bleiben
außerhalb dieser Implementierung.

## Befehlsformat und Startsequenz

Der neue Ganzhausauftrag enthält alle drei Integerparameter gemeinsam:

```text
Body: 22 02 00 02 00 MM FF 00 WW 00 00
MM=08; FF=01..03; WW=00..03
Rahmen: AA 15 B8 00 00 00 00 00 00 02 <Body> CS
CS = (-Summe aller Bytes nach AA vor CS) & FF
Beispiel fan=2, water=0:
AA15B800000000000002220200020008020000000001
```

Die App kann Saug-/Wasserwerte zuvor separat einstellen:
`22 04 FF 00 WW 01`, Kontrolltyp 02. Beispiel fan=2, water=0:
`AA10B8000000000000022204020000010D`.
Ein separater Einstellbefehl ist für den Start nicht erforderlich: Der
Startsender überträgt beide Werte erneut. RoboHub speichert Variablenänderungen
nur lokal und sendet ausschließlich beim ausdrücklichen Start den Startframe.
Der optionale vierte Parameter von `cmdStartWork` würde Byte 12 auf 03 setzen;
die normale Hauptbedienung nutzt ihn nicht. RoboHub lässt Byte 12 auf 00.

## Native Symcon-Bedienung

`RoboHubGeraet` legt bei Modell E20 Evo Plus und verbundenem Eureka-Konto drei
Integer-Variablen mit nativen Assoziationsprofilen und RequestAction an:

- `EurekaReinigungsart`: Nur Saugen / Saugen und Wischen.
- `EurekaStartSaugstufe`: Sanft / Standard / Stark.
- `EurekaStartWasserstufe`: Aus / Niedrig / Standard / Hoch.

Initialwert jeweils -1 („Bitte auswählen“), kein Startdefault. Bei Wahl „Nur
saugen“ wird Wasser lokal auf 0 gesetzt; beim Wechsel zur Kombination wird die
Wasserwahl auf -1 zurückgesetzt und muss ausdrücklich gewählt werden. Andere
Werte, falsche Typen, Wasser >0 im Saugmodus, Wasser 0 im Kombimodus und reines
Wischen werden abgewiesen. Eine fehlende oder direkt manipulierte Auswahl sperrt
auch den Start im Konto. Die historischen gleichnamigen Properties bleiben nur
zur Kompatibilität registriert; ihre alten Formularfelder und ihre Verwendung
als Startquelle entfallen. Nach Update ist eine native Auswahl erforderlich.

Start erfolgt über die vorhandene Befehlsvariable. Einstellungen werden bei
ApplyChanges und Shadow-Refresh erhalten. Sie ändern keinen laufenden Auftrag.
Die separaten Istvariablen Saugstufe/Wassermenge/Reinigungsmodus zeigen den Shadow
an, ohne die Auswahl für den nächsten Auftrag zu überschreiben.

## Shadow: nachgewiesener lokaler Stand

Ein aktueller Live-Shadow wurde gemäß Auftrag **nicht abgefragt**.
Die zuletzt lokal dokumentierte Beobachtung vom 12.11.2026 in
`tests/eureka/observed-shadow-fixtures.php` enthält:

| Shadow-Feld | Beobachteter Wert | Aussage |
|---|---|---|
| fanLevel | String `3` | Saugstufe high/Stark |
| waterLevel | String `2` | Wasser normal/Standard |
| autoType | `room_cleaning` | Raumbezogener Auftrag, kein Reinigungsart-Schalter |

Aus fan/water lässt sich eine Kombination mit Wasser ableiten. `autoType`
wird als Rohwert angezeigt und nicht in einen unbelegten numerischen Modus
umgerechnet. Fehlende oder typfalsche Parameter werden als „Unbekannt“ angezeigt;
es gibt weder Übernahme alter Istwerte noch automatische Startdefaults.

## Mopp, Wassertank und Teppichmodus

Die Wisch-Auswahl ist eine Modellfunktion und bleibt auch bei momentan
entferntem Mopp vollständig verfügbar. Sie wird nicht aus dem Zubehörzustand
oder aus fehlenden Shadow-Feldern abgeleitet. Modul 25030 im Chunk 6590 zeigt
bei `has_elevator && no_mop && !orderMode` einen Warntext an. Modul 39832,
Zeilen 734–740, wertet dafür `DeviceStatus.deviceStatus.has_mop` aus.
Das ist ein App-Statusfeld; eine zuverlässige Zuordnung zu einem Feld des hier
verwendeten HTTPS-Shadows ist bislang nicht belegt.

Auch ein leerer Wassertank ist im vorliegenden Shadow-Vertrag nicht zuverlässig
als eigenes Feld nachgewiesen. Fehlende Felder bedeuten weder „Mopp fehlt“ noch
„Tank leer“. Daher keine zusätzliche Zubehörsperre oder erfundene Tankanzeige.
Die bestehende Prüfung auf explizit leeres `deviceFault` bleibt erhalten:
Ein gemeldeter Fehler blockiert weiterhin den neuen Auftrag, ohne aus einem
unbekannten Fehlercode eine bestimmte Zubehörursache abzuleiten.
Die Auswahl selbst bleibt unabhängig davon bedienbar.

**Ergänzung Teppichmodus:** Der binäre Hersteller-Schalter ist inzwischen durch
`cmdCarpetSwitch`, den App-Aufruf und das B8-Feld `carpet_switch` belegt.
Native lokale Auswahl und ausdrückliches Anwenden, Formate, Grenzen und
fehlende HTTPS-Statuszuordnung sind in
[eureka-grundfunktionen.md](eureka-grundfunktionen.md) dokumentiert.

## Bestehende Sicherheitsgrenzen

Unverändert bleiben individuelle Geräte-/Instanz-/Befehlsbindung, Sitzung,
120-Sekunden-Frischeprüfungen, manueller kontrollierter Refresh, Gerätesperre,
Verbrauchsmarkierung vor Transport, HTTP-Schutz und Verbot automatischer Retries.
Neue Aufträge nur aus stop/charging/charging_finish oder eindeutigem sleep-start,
mit konsistentem, fehlerfreiem Shadow und Akku 16–100. Unbekannter Shadow sperrt.
Pause/sleep-resume erzeugen ausschließlich `22 1C 00`, ohne Startparameter.
Ungültige Startauswahl blockiert weder Fortsetzen noch Pause oder Dock.
Kein Cloud-Befehl durch ApplyChanges oder Änderung der Auswahl.
Vorhandene B8-Diagnoseänderungen bleiben erhalten.

## Offline-Prüfung und offene Punkte

`tests/eureka/cleaning-selection-cases.php` ergänzt den nativen Prüfstand um
alle zwölf Kombinationen über RequestAction, unabhängige Startbytevektoren,
Typ-/Kombinationsfehler, Modellgrenze, lokale Änderungen, Persistenz bei
ApplyChanges/Refresh sowie beobachtete und unbekannte Shadow-Parameter.
Der bestehende Prüfstand prüft zusätzlich alle Startzustände, Schlaf/Fortsetzen,
Gerätebindung, Frische, Auswahländerung vor Transport und Einmalverbrauch.

Vollständiger Lauf: `bash tests/eureka/run-control-offline.sh` in eigener
Netzwerk-Namespace, inklusive bestehender Offline-Suite, PHP-Lint und
`git diff --check`: am 13.11.2026 vollständig erfolgreich (Exitcode 0).
Netzwerk-Doubles verwenden ausschließlich isoliertes Loopback.
Reale Parameterakzeptanz, Moppmechanik und native produktive Symcon-Laufzeit
bleiben ungeprüft. Keine produktive Bereitstellung Bestandteil dieses Auftrags.

## Korrektur für das klassische WebFront (13.11.2026)

Die bisherige Registrierung verwendete ausschließlich die neue Darstellung
`VARIABLE_PRESENTATION_ENUMERATION` mit JSON-Optionen. Für die gemeldete
Zahlenfeldeingabe im klassischen WebFront werden die drei Variablen jetzt über
`MaintainVariable` mit eigenen Integer-Profilen `RoboHub.<Ident>` registriert.
Die Profile enthalten die oben genannten deutschen Assoziationen einschließlich
-1 = „Bitte auswählen“ und Schrittweite 0. Das entspricht der dokumentierten
[WebFront-Aufzählung mit Legacy-Profil](https://www.symcon.de/de/service/dokumentation/komponenten/objekt-darstellung/aufzaehlung/).
Bestehende Variablen-IDs und lokale Werte bleiben bei der Profilzuweisung erhalten;
es entstehen keine Ersatzvariablen und keine automatischen Startwerte.
Nicht belegte Assoziationen dieser Modulprofile werden beim Aktualisieren entfernt.

| RequestAction-Ident | Zulässige Integerwerte | Bindung |
|---|---|---|
| `EurekaReinigungsart` | 0 = Nur Saugen, 2 = Saugen und Wischen | Geräteinstanz |
| `EurekaStartSaugstufe` | 1 = Sanft, 2 = Standard, 3 = Stark | gemeldete Variable 41381 unter Instanz 22708 |
| `EurekaStartWasserstufe` | 0 = Aus im Saugmodus; 1–3 = Niedrig/Standard/Hoch im Kombimodus | Geräteinstanz |

Die gemeldete Ablehnung von Integer 3 ist mit Modell `E20 Evo Plus`, verbundenem
Eureka-Konto und aktiver Geräteinstanz offline nicht reproduzierbar, auch ohne
gewählte Reinigungsart. Der Wert gehört schon im Stand 9cfa39e zur erlaubten Liste.
Die gemeldete allgemeine Ausnahme stammt aus der vorgelagerten Prüfung von
Modell/Kontozuordnung, Deaktivierung oder Werttyp; sie stammt nicht aus der
Werteliste oder dem Shadow. Diese Ursachen werden jetzt getrennt ausgegeben.
Ohne lokalen Laufzeitnachweis wird keine davon als tatsächliche Live-Ursache
behauptet und keine Sicherheitsprüfung gelockert. Für die weitere Eingrenzung
reichen die genaue neue Meldung beziehungsweise ein lokaler, rein lesender
Nachweis von Modellproperty, Kontoverbindung/Hersteller, Deaktivierung, Variablen-
Ident/Standardaktion und übergebenem Werttyp; kein Cloud-Abruf ist erforderlich.

Die Offline-Fälle bilden Variable 41381 als vorhandene Integer-Variable nach,
prüfen alle zwölf Kombinationen über die Variablenaktion, die exakten deutschen
Profiltexte, Profilaktualisierung, Integer 3 ohne Reinigungsart, sämtliche
vorgelagerten Sperren und ungültige Typen/Werte ohne lokale Nebenwirkungen.
Auswahländerungen bleiben ohne Konto-/Cloud-Aufruf. Pause, Fortsetzen, Dock und
die vorhandenen B8-/Diagnoseänderungen werden nicht geändert.
Ein realer WebFront-Renderingtest bleibt außerhalb dieser Offline-Umgebung offen.
