# Eureka E20 Evo Plus: lokal vorbereiteter Befehlsweg

Aktueller Stand 12.11.2026: [Produktivvorbereitung](eureka-produktivvorbereitung.md)
mit erneutem Herstellerbeleg für Start/Pause/Station, engeren Startparametern
(Fan 1–3, Akku über 15 %), erweitertem B8-Antwortparser und nativen
Aktions-/Neustarttests. Alle Produktiv- und Transportsperren bleiben geschlossen.

Aktueller Zusatz ab Basis `2f034c6`: Die separat autorisierte
[B8-Einmaldiagnose](eureka-b8-diagnose.md) bereitet ausschließlich eine spätere
Arbeitsstatusabfrage vor. Sie ersetzt den untenstehenden historischen Plan
eines Pause-Tests. Sämtliche normalen Steuerbefehle bleiben gesperrt.

Stand: 11.11.2026, `codex-dev`, Branch `feature/eureka-e20-evo-plus`, Ausgangscommit
`bb8a926`. Ausschließlich Offline-Implementierung. Kein Zugriff auf produktive Konten,
Geräte oder den produktiven IP-Symcon-Server; keine Cloud-Proben.

Die anschließende statische E20-Prüfung und Statuskorrektur ist in
[eureka-zustaende.md](eureka-zustaende.md) dokumentiert, einschließlich
`32 01`/`32 06`, Antwortoffsets, konservativer Erreichbarkeit und Nachweisgrenzen.
Die dortigen Statusregeln gelten für den aktuellen Stand; Produktivbefehle
bleiben gesperrt.

## Gerätekompatibilität und Beleglage

Die folgenden Metadaten sind laut Auftrag vom 11.11.2026 live im gespeicherten
Gerätecache bestätigt: `ROBOHUB_GeraeteListe(45174)`, Pfad
`geraete[0].roh.product` beziehungsweise `geraete[0].roh.panel`.
Die Instanznummer bezeichnet die übermittelte Quelle; während dieser Entwicklung
wurde weder diese Instanz noch die Cloud angesprochen. Die lokale Referenz
`~/projekte/eureka-protokoll-referenz`, Commit `ce933e6`, bleibt die Quelle der
Offline-Befehlsrahmen, nicht der Nachweis ihrer E20-Befehlssemantik.

| Cache-Feld unter `geraete[0].roh` | Live bestätigter Wert |
| --- | --- |
| `product.productModel` | `E20 Evo Plus` |
| `product.sn8` | `750Y0015` |
| `product.protoVersion` | `0` |
| `product.platform` | `robozone` |
| `product.stationType` | `dust_station` |
| `product.productName` | `Eureka_E20 Evo Plus_750Y0015` |
| `product.plugin.name` und `panel.name` | `T0xB8_750Y0015.zip` |
| `product.plugin.versionName` und `panel.versionName` | `2.5.4` |

`750Y0015` ist nachweislich die Produkt-SN8, keine individuelle Seriennummer.
Es wird kein zusätzliches API-Feld für eine „Produktkennung“ eingeführt.
`tests/eureka/e20-fixture.php` bildet diese verschachtelte Struktur anonymisiert
ab. Geräte-ID und Zustand sind synthetisch; die Fixture ist kein Original-Export
und kein Freigabeprofil. GET- und Integrationstests nutzen dieselbe Fixture.
`x-platform: Android29` ist ein Client-Header und keine Geräteplattform.

`T0xB8_…` zusammen mit expliziter Protokollversion `0` stützt die B8-Familie,
auch ohne SN8. Alternativ bleiben die drei belegten Referenz-SN8 mit Version 0
Familienindizien. Ein vorhandenes fremdes oder ungültiges Plugin widerspricht der
B8-Zuordnung und wird abgewiesen. Fehlende Versionen werden nicht ergänzt;
widersprüchliche Werte in den unterstützten Metadatenpfaden werden abgewiesen.
Auch ein Widerspruch zwischen Plugin-Suffix und angegebener SN8 blockiert.
Eine Familienzuordnung ist niemals eine Gerätefreigabe. `pruefeZuordnung` verlangt
weiterhin vollständige Identifikation inklusive SN8 und eine
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
| `RoboHubKonto/module.php` | Bewahrt ausschließlich für Eureka vorhandene GET-Rohdaten mit Geräte-ID, lokaler Empfangszeit, effektiver Erreichbarkeit und Statusquelle im Schnappschuss auf |
| `libs/RoboHubEurekaZustand.php` | Gemeinsame Shadow-Konsistenzprüfung für Anzeige/Planer, Zielauswahl und strikt getrennte Offline-Auswertung der B8-Zustände |
| `tests/eureka/command-fixtures.php` | Simulierte Referenzzuordnung für Offline-Tests, kein produktives Geräteprofil |
| `tests/eureka/e20-fixture.php` | Anonymisierte live bestätigte E20-Cachestruktur mit produktbezogener SN8 und Herkunft |
| `tests/eureka/command-test.php` | Rahmen, Validierung, Zustand, Transportfehler, Einmaligkeit, beide Produktionssperren |
| `tests/eureka/token-test.php` | Bestehende Token-Races plus Befehlsweg mit echter Konto-Persistierung und GET-Regressionen |
| `.tools/test-eureka.inc.php` | Integration von Konto, Konfigurator und Gerät; Schnappschuss und unveränderte Lesefreigaben |

Der allgemeine Herstellervertrag, Parent-Verwaltung, Modul-GUIDs,
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
Schnappschuss und explizites `connected=true` im Shadow. Die 120 Sekunden sind eine bewusst konservative
lokale Sicherheitsgrenze, kein Protokollstandard. Bekannte widersprüchliche
Geräte-IDs, Offline-Meldungen, Zustandswidersprüche und Gerätefehler sperren.
Ein fehlender Fehlerbefund reicht ebenfalls nicht aus. Die lokale Empfangszeit
ist kein bestätigter Gerätezeitstempel; die Frische des Shadow selbst bleibt offen.

- `start` bei `pause` → ausschließlich Fortsetzen; keine neuen Parameter.
- `start` bei `stop`, `charging`, `charging_finish` → neuer Auftrag;
  explizite Integerparameter `fan`, `water`, `mode` erforderlich, keine Defaults.
  Aktuell: fan 1–3 (E20-Pluginauswahl), water 0–3, ausschließlich mode 8
  (Ganzhaus), bestätigter Akku als Integer 16–100. Frühere Referenzgrenze fan
  0–5 wurde bewusst auf die belegte E20-Auswahl beschränkt.
- `pause` ausschließlich bei `cleaning`.
- `station` ausschließlich bei `cleaning`, `pause` oder `stop`.
- Unbekannte Zustände, `sleep`, Update, Rückfahrt, `charging_pause` und
  `wiping_pause`, `on_station` erlauben keine automatische Entscheidung. Bereits laufende
  Reinigung und bereits gedocktes Gerät erlauben keinen redundanten Start bzw.
  Stationsbefehl.
- Stoppen, Orten, Saug-/Wasserstufe, Räume, Zeitpläne und Wartungsreset bleiben
  gesperrt. Die Startparameter sind keine freigeschalteten Einstellaktionen.

Diese Zustandsnamen und Parameterbereiche sind Referenzwissen; ihre Bedeutung am
E20 muss vor einer Aktivierung mit echten Daten bestätigt werden. Die korrigierte
Statusanzeige unterscheidet unter anderem Pause, Rückfahrt, Laden und Schlaf.
Anzeige und Planer teilen dieselbe Parent-Konsistenzprüfung; die Anzeige weiterer
Zustände erweitert nicht die erlaubten Befehlsentscheidungen.

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

## Gerätebezogene Serialisierung

`ausfuehren()` erwirbt `IPS_SemaphoreEnter` mit 1 ms Wartezeit. Der globale
Schlüssel `RoboHubEureka_Geraet_` plus SHA-256 über den festen EU-/robozone-Namensraum
und die validierte Cloud-Geräte-ID hängt weder von Token, Konto-Instanz noch
Befehlsobjekt ab. Dasselbe Gerät wird daher auch über mehrere Konten serialisiert;
unterschiedliche Geräte haben getrennte Sperren. Schlüssel und IDs werden nicht
protokolliert. Ohne native Semaphore-Funktionen oder bei belegter Sperre wird
vor jedem Transport abgebrochen, ohne Warteschlange oder automatische Wiederholung.

Innerhalb von `try/finally` liest der produktive Konto-Rückruf ausschließlich
lokale Attribute erneut: Hersteller, Token-Bündel mit Sitzungsrevision,
Gerätecache und Schnappschuss. Entfernte Geräte werden ohne Cloud-Nachladen
abgewiesen. Gerätebindung, exakte Befehlszuordnung, Zustand, Aktualität und Token
werden erst danach für den Versand geprüft. Der produktive Treiber verlangt
diesen Rückruf; Offline-Tests können statische Snapshots verwenden. Der Rückruf
wird pro Treiber erzeugt; seine gemerkte Sitzung muss beim Aufruf noch gelten.

Die Gerätesperre umfasst den POST, Antwortparser und gegebenenfalls den zentralen
401-Refresh; Reihenfolge: Gerätesperre → Konto-Refresh → kurze Bündelsperre.
Es gibt keinen Pfad, der unter einer Kontosperre eine Gerätesperre erwirbt.
`finally` gibt jede erworbene Gerätesperre auch bei Kontextfehlern, Transportfehler,
Geräte-Timeout, ungültiger Antwort oder fehlgeschlagenem Refresh frei. Die
Verbrauchsmarkierung verhindert weiterhin einen zweiten POST desselben Versuchs.
Ein späterer ausdrücklich neuer Versuch bleibt möglich; es gibt keine globale
Idempotenzgarantie für nacheinander eingehende Anforderungen.

Der deterministische Fiber-Test hält einen simulierten POST an, startet eine
zweite unabhängige Befehlsinstanz für dasselbe Gerät (abgewiesen, kein zweiter
POST) und eine dritte für ein anderes Gerät (eigener POST vor Freigabe des ersten).
Zusätzliche Tests ändern den Kontext nach Sperrerwerb und prüfen sämtliche
Fehlerpfade auf Freigabe. Die Kontotests verwenden den echten Attribut-Rückruf
und prüfen auch Abmeldung, neue Sitzung mit identischen Tokens, entfernte Geräte
und Timeout der Bündelsperre, jeweils ohne Cloud-Aufruf.

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

Offene Risiken vor einer späteren Aktivierung trotz statischer Analyse 2.5.4:
reale E20-Cloudzustände und Gerätefrische, Parameterakzeptanz und reales Antwortformat; native
IP-Symcon-9.1-Laufzeit einschließlich Semaphoren über unabhängige Skripte.
Die Metadaten bestätigen die B8-Familie, keine Befehlsfreigabe.
Ein frischer Schnappschuss ist keine atomare Gerätegarantie: Cloud-Schatten,
Hersteller-App und externe Clients liegen außerhalb der lokalen Sperre.
Die Sperre gilt innerhalb einer IP-Symcon-Laufzeit, nicht über mehrere Server.
Das reale Parallelverhalten der Hersteller-App wurde nicht getestet.

## Minimaler Plan für einen später einzeln freigegebenen Live-Befehl

1. Die vorliegende statische Analyse des App-Steuerplugins 2.5.4 und die
   bestätigten Metadaten beim Review berücksichtigen; exakte Gerätezuordnung und den vorgesehenen Rahmen separat reviewen.
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
