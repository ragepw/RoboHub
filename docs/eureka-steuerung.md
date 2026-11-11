# Eureka E20 Evo Plus: lokal vorbereiteter Befehlsweg

Stand: 11.11.2026, `codex-dev`, Branch `feature/eureka-e20-evo-plus`, Ausgangscommit
`100f6d6`. Ausschließlich Offline-Implementierung. Kein Zugriff auf produktive Konten,
Geräte oder den produktiven IP-Symcon-Server; keine Cloud-Proben.

## Gerätekompatibilität und Beleglage

Untersucht wurden die vorhandenen Projektdateien, Tests und Dokumentation sowie
die lokale Referenz `~/projekte/eureka-protokoll-referenz`, Commit `ce933e6`.
Ein gespeicherter vollständiger E20-Gerätedatensatz lag in diesen Quellen nicht vor.
Es wurden keine zusätzlichen Cloud-Abfragen ausgeführt.

| Merkmal | E20-Wert / Herkunft | Lokaler Belegstatus |
| --- | --- | --- |
| Produktmodell (`productModel`) | `E20 Evo Plus`, frühere Cloud-Untersuchung laut Auftrag | E20-Wert nicht durch Original-JSON belegt; Feldstruktur in `coordinator.py` |
| Produktkennung | `750Y0015`, frühere Cloud-Untersuchung laut Auftrag | Ursprünglicher JSON-Feldpfad unbekannt; separat beobachtete Metainformation, kein erfundenes API-Feld |
| Protokollversion (`protoVersion`) | `0`, frühere Cloud-Untersuchung laut Auftrag | Referenz unterstützt Version 0; kein lokaler E20-Response |
| Plattform | `robozone`, frühere Cloud-Untersuchung laut Auftrag | Kein E20-JSON-Beleg; `platform` ist die bisherige lokale Parserstruktur, keine durch die Referenz belegte Feldzuordnung |
| Stationstyp | `dust_station`, frühere Cloud-Untersuchung laut Auftrag | Ursprünglicher JSON-Pfad nicht belegt; separate Metainformation |
| Steuerplugin | `T0xB8_750Y0015.zip`, frühere Cloud-Untersuchung laut Auftrag | `panel.name` bzw. `product.plugin.name` als Struktur in `coordinator.py`; E20-Plugin selbst nicht lokal analysiert |
| Pluginversion | `2.5.4`, frühere Cloud-Untersuchung laut Auftrag | `versionName` als Struktur in `coordinator.py`; E20-Wert nicht lokal belegt |
| SN8 | Unbekannt | Nur Referenzwerte `610VR510`, `620VR520`, `9A0VR870` in `const.py`; keine davon wird dem E20 zugewiesen |

`tests/eureka/e20-fixture.php` rekonstruiert diese Angaben ausdrücklich als
Testdatensatz, nicht als gespeicherten Cloud-Response. Produktkennung und
Stationstyp stehen außerhalb der simulierten API-Daten. Geräte-ID und Zustand
sind erfunden. Gemeinsame Nutzung in GET- und Integrationstests sichert Modellname,
Konfigurator und den bestehenden Lesebetrieb ab; sie liefert keinen neuen E20-Beleg.
`x-platform: Android29` ist ein Client-Header und keine Geräteplattform.

`T0xB8_…` zusammen mit expliziter Protokollversion `0` stützt die B8-Familie,
auch ohne SN8. Alternativ bleiben die drei belegten Referenz-SN8 mit Version 0
Familienindizien. Ein vorhandenes fremdes oder ungültiges Plugin widerspricht der
B8-Zuordnung und wird abgewiesen. Fehlende Versionen werden nicht ergänzt;
widersprüchliche Werte in den unterstützten Metadatenpfaden werden abgewiesen.
Eine Familienzuordnung ist niemals eine Gerätefreigabe. `pruefeZuordnung` verlangt
weiterhin vollständige Identifikation inklusive separat belegter SN8 und eine
exakte Zuordnung mit Beleg. Die produktive Zuordnungstabelle bleibt leer.

Die Referenz setzt fehlende SN8 auf `610VR510` und fehlende Versionen auf `0`;
diese Defaults werden nicht übernommen. `protocol.py` dokumentiert Rahmen aus
`T0xB8_610VR510` Version `2.2.46`; `const.py` nennt zusätzlich
`T0xB8_9A0VR870.zip`. Die uneinheitlichen E10s-Praxisaussagen der Referenz
bestätigen keine E20-Kompatibilität.

Der Stationstyp ist für die bestehenden Offline-Rahmen Start, Pause, Fortsetzen
und Rückkehr zur Station kein Eingabeparameter. In der lokalen Referenz hängen
diese Rahmen nicht von `stationType` ab. Deshalb keine zusätzliche Steuerlogik
oder Freigabebedingung dafür; `dust_station` belegt insbesondere keine
Stationsfunktionen wie Absaugen. Die tatsächliche E20-Semantik bleibt offen.

## Dateien und Funktionen

| Datei | Aufgabe |
| --- | --- |
| `libs/RoboHubEurekaProtokoll.php` | Reine Funktionen `rahmen`, `pruefeRahmen`, `start`, `pause`, `fortsetzen`, `station`, `identifikation`, `referenzFamilie`, `pruefeZuordnung` |
| `libs/RoboHubEurekaBefehlsweg.php` | Offline-Planung `vorbereiten`, eigener POST-Parser `antwortLesen`, einmaliger Versuch `ausfuehren`, Klartext-Vertrag `befehl`; Transport ausschließlich als Callback |
| `libs/RoboHubEureka.php` | Bestehender `RoboHubTreiber::befehl()`-Einstieg mit Sperre und vorbereitetem Anschluss an Transport/Token-Rückruf; zweite Sperre vor cURL |
| `RoboHubKonto/module.php` | Bewahrt ausschließlich für Eureka vorhandene GET-Rohdaten mit Geräte-ID und Zeitstempel im Schnappschuss auf |
| `tests/eureka/command-fixtures.php` | Simulierte Referenzzuordnung für Offline-Tests, kein produktives Geräteprofil |
| `tests/eureka/e20-fixture.php` | Rekonstruierte E20-Metadaten mit Herkunft; keine erfundene SN8 oder API-Produktkennung |
| `tests/eureka/command-test.php` | Rahmen, Validierung, Zustand, Transportfehler, Einmaligkeit, beide Produktionssperren |
| `tests/eureka/token-test.php` | Bestehende Token-Races plus Befehlsweg mit echter Konto-Persistierung und GET-Regressionen |
| `.tools/test-eureka.inc.php` | Integration von Konto, Konfigurator und Gerät; Schnappschuss und unveränderte Lesefreigaben |

Der allgemeine Herstellervertrag, Parent-Verwaltung, GET-Leseparser, Modul-GUIDs,
`module.json`, Bedienoberflächen und Variablenaktionen wurden nicht geändert.
Eureka bleibt bei HTTPS ohne eigene MQTT-Verbindung.

## Rahmen und Befehlsentscheidung

Referenzrahmen: `AA <0A + Bodylänge> B8 00 00 00 00 00 00 <Typ> <Body> <Prüfsumme>`.
Das Längenbyte zählt alle Bytes außer `AA`, einschließlich Prüfsumme. Die Summe
ab dem Längenbyte einschließlich Prüfsumme muss modulo 256 null ergeben.
Kontrolltyp ist `02`, Abfragetyp `03`. Ungültige Bytes, reservierte Felder,
Längen, Typen und Prüfsummen werden abgewiesen.

Goldene Testvektoren wurden unabhängig mit der lokalen Python-Referenz erzeugt:

| Aktion | Vollständiger Rahmen |
| --- | --- |
| Start, fan=2, water=0, mode=8 | `AA15B800000000000002220200020008020000000001` |
| Pause | `AA0DB800000000000002221B00FC` |
| Fortsetzen | `AA0DB800000000000002221C00FB` |
| Station | `AA0DB80000000000000222010016` |

Die Planung benötigt einen dem Zielgerät zugeordneten, maximal 120 Sekunden alten
Schnappschuss und Online-Meldung. Die 120 Sekunden sind eine bewusst konservative
lokale Sicherheitsgrenze, kein Protokollstandard. Bekannte widersprüchliche
Geräte-IDs, Offline-Meldungen, Zustandswidersprüche und Gerätefehler sperren.

- `start` bei `pause` → ausschließlich Fortsetzen; keine neuen Parameter.
- `start` bei `stop`, `charging`, `charging_finish`, `on_station` → neuer Auftrag;
  explizite Integerparameter `fan`, `water`, `mode` erforderlich, keine Defaults.
  Referenzwerte: fan 0–5, water 0–3, ausschließlich mode 8 (Ganzhaus).
- `pause` ausschließlich bei `cleaning`.
- `station` ausschließlich bei `cleaning`, `pause` oder `stop`.
- Unbekannte Zustände, `sleep`, Update, Rückfahrt, `charging_pause` und
  `wiping_pause` erlauben keine automatische Entscheidung. Bereits laufende
  Reinigung und bereits gedocktes Gerät erlauben keinen redundanten Start bzw.
  Stationsbefehl.
- Stoppen, Orten, Saug-/Wasserstufe, Räume, Zeitpläne und Wartungsreset bleiben
  gesperrt. Die Startparameter sind keine freigeschalteten Einstellaktionen.

Diese Zustandsnamen und Parameterbereiche sind Referenzwissen; ihre Bedeutung am
E20 muss vor einer Aktivierung belegt werden. Die bestehende Statusanzeige bleibt
absichtlich auf ihrer bisherigen Zuordnung; insbesondere wird `pause` dort noch
nicht als live bestätigter E20-Zustand ergänzt.

## HTTPS, Token und unklare Ergebnisse

Vorbereitet wird `POST /devices/commands/<deviceId>` mit Bearer-Token und exakt
`{"data":"<vollständiger Hexrahmen>"}`. IDs mit Pfad-/Queryzeichen werden gesperrt.
Es gibt kein zusätzliches `waitTimeoutInSeconds` mit unbelegtem Default.

Das Konto erneuert nötigenfalls vor dem Versuch und persistiert rotierende Tokens
über seine vorhandenen Sperren und Sitzungsrevisionen. Ein Befehls-401 ruft
denselben `beiUnauthorized`-Rückruf auf. Der POST wird auch dann nicht wiederholt.
Erst ein später ausdrücklich angeforderter neuer Versuch kann das gespeicherte
Bündel verwenden. Das ist strenger als der automatische 401-Retry der Referenz.

Der eigene Befehlsparser erwartet HTTP 2xx plus explizit `errorCode` 0 bzw. "0".
Null-Daten sind erlaubt; ein optionaler Antwortframe direkt in `data` oder in
`data.data` wird formal validiert. Das Ergebnis bleibt immer
`angenommen=true, ausgefuehrt=false`. Auch eine formale Roboterantwort beweist
keine ausgeführte Aktion; der Klartext sagt dies ausdrücklich.

401, 429 (60 Sekunden Wartehinweis), 5xx, andere HTTP-Fehler, ungültiges JSON,
Cloud-Fehler und Geräte-Timeout `0080005` brechen ab. Bei Timeout, Transportfehler
oder nicht verwertbarer Antwort ist die Ausführung unklar. Kein Fehlerpfad
wiederholt den POST. Meldungen enthalten keinen Cloud-Freitext, Token oder
ungeschützte Transport-Ausnahme. Jede Befehlsweg-Instanz wird vor ihrem einzigen
Transportaufruf verbraucht, auch bei Reentranz, Timeout oder Fehlern.

## Technisch erzwungene Sperren und Grenzen

1. `RoboHubEureka::befehl()` ist final und prüft eine private, feste Freigabe `false`.
   Parameter, Eigenschaften und Test-Unterklassen können sie nicht einschalten.
2. Der echte `transport()` verweigert `/devices/commands…` vor Headeraufbau/cURL,
   unabhängig vom ersten Einstieg und unabhängig von der HTTP-Methode.
3. Die private produktive Gerätezuordnung ist leer. Ein Referenzkandidat allein
   reicht nicht für die Planung.

Keine neue bedienbare Symcon-Funktion wurde freigeschaltet. Die vorhandenen
generischen Steuerflächen erhalten weiterhin die Sperrmeldung. Die Tests nutzen
einen separaten injizierten Fixture-Transport; es gibt keinen Simulationsschalter,
der den echten Adapter zur Laufzeit freischalten könnte.

Offene Risiken vor einer späteren Aktivierung: lokaler Beleg der E20-Familie,
SN8, Original-JSON-Feldzuordnung, Analyse des Plugins 2.5.4, Zustandssemantik, Parameterwerte und reales
Antwortformat; native IP-Symcon-9.1-Laufzeit; Verzögerung des Cloud-Schattens und
Zustandsänderung zwischen Lesen und Befehl. Ein frischer Schnappschuss ist keine
atomare Gerätegarantie. Die Einmaligkeit gilt pro Befehlsversuch; für eine spätere
breite Produktivbedienung ist eine gerätebezogene persistente Sperre gegen
parallele unabhängige Anforderungen zusätzlich zu prüfen. Aktuell verhindern die
Produktionssperren sämtliche solchen Versände. Die Hersteller-App wurde weder
angesprochen noch verändert; ihr reales Parallelverhalten wurde nicht getestet.

## Minimaler Plan für einen später einzeln freigegebenen Live-Befehl

1. Zuerst vorhandene echte Metadaten und das passende App-Steuerplugin lokal
   belegen; exakte Gerätezuordnung und den vorgesehenen Rahmen separat reviewen.
   Keine Aktivierung nur aufgrund von `750Y0015` oder eines Referenz-SN8.
2. Spätere ausdrückliche Freigabe auf **genau einen Pause-Befehl an das separat bestätigte Testgerät**
   begrenzen, während eine über die Original-App begonnene Reinigung vor Ort
   eindeutig läuft. Aktualität und Semantik von `cleaning` vorher bestätigen.
3. Erst in diesem späteren Auftrag einen separaten, zeitlich und auf Gerät/Aktion
   begrenzten Einmalpfad implementieren/reviewen; weiterhin keine allgemeine
   Symcon-Steuerung öffnen. Die heutigen Sperren bleiben bis dahin unverändert.
4. Einen POST ausführen. Cloud-Annahme protokollieren und tatsächliches Pausieren
   getrennt anhand der App, Beobachtung und des normalen Lesebetriebs bestätigen.
   Bei unklarem Ergebnis weder wiederholen noch automatisch fortsetzen.
5. Einmalpfad schließen; Fortsetzen, Start und Station benötigen jeweils eine
   eigene spätere Freigabe. Kein Firmware-/App-Eingriff.

## Referenzherkunft und Lizenzhinweis

Grundlage sind `custom_components/eureka_robot/protocol.py`, `api.py`, `const.py`
und ergänzend `coordinator.py`/`vacuum.py` der lokalen Referenz, Commit `ce933e6`.
Die Rahmenkonstruktion ist in PHP nachgebildet. Der folgende Hinweis bewahrt die
MIT-Bedingungen der Referenz; er betrifft deren übernommenen Protokollanteil und
ändert nicht die Lizenz des übrigen RoboHub-Projekts.

```text
MIT License

Copyright (c) 2024 TSOrest
Copyright (c) 2026 LadasFaras

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```
