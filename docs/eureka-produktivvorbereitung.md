# Eureka E20 Evo Plus: lokale Vorbereitung der Steuerung

Fortgeschriebener Stand nach dem erfolgreichen Dock-Livebefund:
[Reguläre IP-Symcon-Steuerung](eureka-regulaere-steuerung.md).
Die folgenden Herstellerbelege gelten weiter; historische Aussagen zu
unbestätigtem Docktransport, Startparameterübergabe und fehlendem Shadow-Online
werden durch den neuen Bericht ersetzt. Keine Produktivfreigabe.

Stand: 12.11.2026, Branch `feature/eureka-e20-evo-plus`. Nur lokale statische
Analyse und Offline-Tests. Keine Cloud-Abfrage, Robotersteuerung, Änderung der
Instanz 22708, Bereitstellung, Schlüssel-/Genehmigungsänderung, Commits oder Pushs.
Dieser Bericht aktualisiert die Parametergrenzen im älteren
[Steuerungsbericht](eureka-steuerung.md). Er erteilt keine Produktivfreigabe.

## B8-Antwortauswertung im vorhandenen Diagnosepfad

Der Weg bleibt Konto `EurekaB8Testen` → Treiber `diagnoseB8` →
`RoboHubEurekaDiagnose::ausfuehren` → `RoboHubEurekaB8::antwort`. Nur die lokale
Antwortverarbeitung wurde erweitert. Keine neuen Diagnosefunktionen, Variablen,
Attribute, Timer, Dateien zur Rohdatenablage oder Verbindungen wurden eingeführt.
HTTP 2xx und ein äußerer JSON-Objektbefund mit `errorCode` exakt 0 oder "0"
bleiben Voraussetzung. Fehlercodes und Freitextwerte gelangen nicht in die Ausgabe.

Zulässiger Frame-Ort ist ausschließlich `$.data`, mit beliebig wiederholten
`data`-Objekthüllen innerhalb der unten genannten Grenzen. JSON-Listen an diesen
Orten dürfen Hüllen oder Kandidaten enthalten; genau ein Kandidat ist erforderlich.
Ein ganzzahliges JSON-Array ist stattdessen ein Bytearray. Objekt und Liste werden
beim JSON-Decoding unterschieden. Ein Objekt mit numerischen Schlüsseln ist kein
Bytearray. Fremde Felder wie `result`, `payload`, `messageBody`, `token` oder
`response` werden niemals nach Frames durchsucht. Sie sind kein Ersatz für `data`.
Ein vorhandener verschachtelter `errorCode` muss ebenfalls 0/"0" sein.

| Eingabe unter einem zulässigen data-Pfad | Behandlung |
| --- | --- |
| Zusammenhängendes Hex, Groß-/Kleinschreibung, optional einmal `0x` | Vollständige gerade Anzahl Hexstellen |
| Getrennte Hexbytes | Exakt zwei Stellen je Byte; Leerraum, Tab, CR/LF, Doppelpunkt, Bindestrich, Komma oder Semikolon zwischen Bytes |
| Getrennte `0xHH`-Bytes | Alle Bytes mit Präfix, dieselben Trenner; keine gemischten Präfixformen |
| Bytearray | Nur echte JSON-Integer 0–255, höchstens 256 Bytes; keine Strings, Floats, booleschen Werte oder Umwandlungen |
| Base64 | Kanonisches Standard-Base64 binärer Framebytes mit erforderlichem Padding, ohne inneren Leerraum; erstes dekodiertes Byte muss AA sein |
| Eingebettetes JSON | Der gesamte String ist ein JSON-Objekt, eine Liste oder ein weiterer JSON-String; danach dieselben Orts-/Formatregeln |

Äußerer ASCII-Leerraum in Frame-Strings ist erlaubt. Es gibt keine Suche nach
JSON-Fragmenten im Freitext, keine URL-/JWT-Base64-Variante, kein Base64 von
Hextext und keinen Versuch, bei fehlgeschlagener Frameprüfung eine andere
Kodierung auszuprobieren. Ein Hexstring mit gültiger Syntax wird als Hex geprüft.
Die AA-Signatur grenzt binäres Base64 von beliebigen Textwerten ab; sie ersetzt
keine der folgenden Rahmenprüfungen.

Alle Kandidaten passieren weiterhin `RoboHubEurekaProtokoll::pruefeRahmen`:
12–256 Gesamtbytes, Hexbytes, AA/B8, exaktes Längenbyte, sechs reservierte Nullbytes,
Typ 02/03 und unveränderte additive Prüfsumme. Die Fehlermeldungen sind jetzt je
Bedingung getrennt; keine bisher abgewiesene Rahmenklasse wurde zugelassen.
Anschließend verlangt `lesen` weiterhin Typ 03, Präfix `32 01` und mindestens
37 Gesamtbytes. Abfrageecho, Kontrollantwort und `32 06` sind keine Arbeitsantwort.
Die bestehenden vier numerischen Statusfelder und ihre Plausibilitätsprüfung
bleiben erhalten. Diagnose und normaler Shadow-Lesepfad werden nicht vermischt.

Mehrere zulässige Kandidaten werden auch bei identischen Bytes abgewiesen;
ein gültiger neben einem ungültigen Kandidaten wird nicht herausgepickt.
Doppelte JSON-Schlüssel werden vor Interpretation verworfen, einschließlich
escaped gleicher Namen und Duplikaten in eingebetteten Strings. Dadurch kann
`json_decode` keine widersprüchlichen Befunde durch Überschreiben verbergen.
Grenzen: 64 KiB je JSON-Dokument, JSON-Tiefe 32, Extraktionstiefe 12 und höchstens
128 besuchte Extraktionsknoten; kodierte Frame-Strings höchstens 2048 Zeichen.
Grenzüberschreitung bricht ab und ermöglicht keinen weiteren Transport.

Die bestehende JSON-Rückgabe bekommt nur `diagnose` dazu:

- `struktur`: sichere Pfade und PHP-Datentypen (`object`, `array`, `string`,
  `int`, `float`, `bool`, `null`), maximal 48 Einträge und acht Ebenen je
  Strukturaufnahme. Nur `data` und `errorCode` werden als Feldnamen ausgegeben.
  Alle anderen Namen werden durch ihre Position `.[feldN]` ersetzt, da auch
  dynamische Feldnamen Geheimnisse enthalten können. `<json>` markiert eine
  lokal dekodierte eingebettete JSON-Hülle. Abgeschnittene Strukturansichten
  sind keine vollständige Feldinventur und verändern die Extraktion nicht.
- `kodierung`: ausschließlich feste Bezeichner `hex`, `hex-getrennt`,
  `bytearray`, `base64` und gegebenenfalls vorangestellte `json`-Schritte.
- `frameLaenge`: Anzahl der dekodierten Bytes, soweit erkennbar; keine Payload.
- `validierungsfehler`: eigene feste Fehlermeldung, etwa falsche Länge,
  reservierte Bytes, falscher Antworttyp, fehlerhafte Prüfsumme, Mehrdeutigkeit
  oder fehlender Cloud-Erfolg; sonst null.

Kein Wert, unbekannter Feldname, Frame, Token, individuelles Gerät oder
Cloud-Freitext wird in diesen Metadaten ausgegeben. Die vorhandenen separat
zurückgegebenen vier numerischen Statusfelder werden nicht in Logs oder
Statusvariablen geschrieben. Die komplette Transportantwort existiert nur lokal
während des Aufrufs. Der Kontoeinstieg gibt das reduzierte Ergebnis zurück,
persistiert es aber nicht; Gerätevariablen und Shadow-Cache bleiben unverändert.
Die bestehenden Verbrauchs-/Bindungsattribute und ihre Genehmigungen bleiben
unverändert. Die Parsererweiterung autorisiert keinen Diagnoseaufruf.

## Herstellerbelege und konkrete Befehle

Ausschließliche Quelle dieser erneuten Analyse: vorhandenes lokales Archiv
`../eureka-plugin-analyse/T0xB8_750Y0015.zip` (Version 2.5.4), zugehörige statische
Modulauszüge und [Analysebericht](../../eureka-plugin-analyse/analyse-2.5.4/E20-Analysebericht.md).
Keine Downloads, Ausführung von Plugin-JavaScript oder Cloudabfragen. Der aktuelle
Auftrag verwendet keine Fremdimplementierung als neuen Befehlsnachweis.
Die bereits vorhandenen PHP-Implementierungen wurden gegen das Originalarchiv
statisch abgeglichen. Originaldateien werden nicht in RoboHub übernommen.

Archiv-SHA-256: `0a275bbd2ee8c076aeb8e1d314a4bbe98fa5c3c6aeb3750414765adc399f24c6`.
Pfadangaben in der folgenden Tabelle sind relativ zu `T0xB8_750Y0015/` im Archiv;
Offsets zählen Bytes ab Dateianfang.

| Herstellerquelle | SHA-256 / konkrete Fundstelle |
| --- | --- |
| `static/js/192.f5b85c85acde7c6c892e.js` | `642eb1206b62bfabfb602b73f2c3e95def4986cc81011a1199ed5c4d7c03bf4b`; Modul 16153: `cmdStopWork` 1328156, `cmdStartWork` 1328389; Modul 71825: `stop_type` 1529377; Modul 95680: `createMessage` 2380556 |
| `static/js/1717.56df39ab132cf7deb9d6.js` | `ca92a042e9ebfb62d2e6ff11415584feb9766f413dd485c7f8ad522b19a29333`; Modul 81717: `startClean` 52660, `startCharge` 55774 |

Für alle drei Steueraktionen belegt: Protokoll B8/184, Nachrichtentyp 02,
Rahmen `AA LL B8 00 00 00 00 00 00 02 BODY CS`, `LL=0A+Bodylänge`.
`CS=(-Summe ab LL bis letztes Bodybyte)&FF`; kein CRC8. Der Herstellerhelfer
lässt Headerbyte 6 bei der Summe aus, hier stets null. Deshalb ist die vorhandene
Prüfsummenbildung identisch. Die sechs reservierten Bytes bleiben strikt null.

| Aktion | Herstellerbeleg: Payload und Parameter | Vollständiger Beispielrahmen |
| --- | --- | --- |
| Neue Ganzhausreinigung | `cmdStartWork(auto, fan, water)`: `22 02 00 02 00 08 FF 00 WW 00 00`; explizit Fan 1/2/3 und Wasser 0–3; vierter optionaler Herstellerparameter nicht gesetzt | Fan 2/Wasser 0: `AA15B800000000000002220200020008020000000001` |
| Pause | `cmdStopWork(pause)`, `stop_type.pause=27`: `22 1B 00`; keine Parameter | `AA0DB800000000000002221B00FC` |
| Station | `cmdStopWork(charge)`, Enumwert 1 bzw. UI-Aktion `recharging`: `22 01 00`; keine Parameter | `AA0DB80000000000000222010016` |
| Fortsetzen als Start aus Pause | `cmdStopWork(continue)`, Enumwert 28: `22 1C 00`; keine neuen Startparameter | `AA0DB800000000000002221C00FB` |

Start/Fortsetzen sind unterschiedliche Bytes. Das Plugin bietet im E20-Profil nur
Fan 1/2/3 an; allgemeine Enumwerte 0/4/5 belegen deren E20-Unterstützung nicht.
Die neue lokale Vorbereitung verweigert diese Werte deshalb. Wasser 0 bedeutet
eine explizite Wahl und ist kein Default. Nebenwirkung eines neuen Auftrags:
Fahrt/Reinigung mit gesetzten Fan-/Wasserwerten, mögliche Wasserabgabe bei WW>0.
Auswirkungen auf alte Aufträge, Karten und aktivierte Raumpräferenzen sind nicht
abschließend belegt. Pause erhält in der E20-Bedienung einen fortsetzbaren Auftrag;
die genaue Fortsetzungsfähigkeit nach Fehlern/Neustarts bleibt unbekannt. Station
löst Rückfahrt aus; Laden, Absaugen oder anderer Stationsbetrieb sind damit weder
beauftragt noch bestätigt. Solche Folgeabläufe können gerätekonfiguriert sein und
sind unbekannt. Keine dieser Aktionen garantiert durch ihre Antwort eine Bewegung.

| Aktion | Herstellerbeleg zu Zustand und Antwort | Konservative lokale Vorbereitung |
| --- | --- | --- |
| Neuer Start | Akku höchstens 15 % wird abgewiesen; kabelgebundenes Laden blockiert; bestimmte Stationsvorgänge blockieren. Start übernimmt aktuelle Fan-/Wasserwerte. | Nur `stop`, `charging`, `charging_finish`, Akku als Integer 16–100; explizite Parameter; keine Defaults und kein automatisches Übernehmen unbestätigter Statuswerte |
| Start aus Pause | Normaler Pausezweig führt vor der neuen Akkuschwelle zu Continue; bestimmte weitere Pausen-/Schlafuntertypen sind gesondert behandelt. | Nur `pause` → Continue; weitere Pausen-/Schlaffälle bleiben gesperrt |
| Pause | UI verwendet Pause aus `work`, `relocate`, `environmental_exploration`; E20 setzt `is_task_continue=true`. | Nur belegter Cloudzustand `cleaning`, keine Erweiterung um unbestätigte Übersetzungen |
| Station | Während Laden/voll/kabelgebundenem Laden kein Rückfahrtsauftrag; erneutes Betätigen während Rückfahrt sendet Stop. | Nur `cleaning`, `pause`, `stop`; kein Stop als versteckte Umschaltung |
| Antwort aller Aktionen | API übergibt Bytes an `window.bridge.startCmdProcess(success,failure)` und ruft nach Erfolg `requestWorkState` auf. | Keine automatische Folgeabfrage durch die Vorbereitung und kein Retry; Annahme ist keine Ausführungsbestätigung |

**Belegt:** Byteaufbau, Prüfsumme, Plugin-Parameterauswahl, UI-Zweige und
Bridge-Callbackfolge. **Plausibel, unbestätigt:** Verwendung des bereits
vorbereiteten RoboHub-HTTPS-Vertrags durch die reale E20-App-Bridge.
**Unbekannt:** externe `../base/iosbridge.js` und `../base/base.js` fehlen im
Archiv; damit fehlen der native normale Endpunkt, seine Cloudverpackung und
aktionsspezifische Antwort-/ACK-Formate. Das Plugin belegt keine erwartete konkrete
Cloudantwort für Start, Pause oder Dock. Erfolgscallback ist kein Bewegungsnachweis.
Der Device-Twins-Sonderpfad ist kein Beleg für den normalen Versand.

Der bestehende, weiterhin gesperrte RoboHub-Transportplan erwartet für jede Aktion
`POST /devices/commands/<id>` mit `{"data":"<Hexrahmen>"}` und HTTP 2xx plus
`errorCode=0/"0"`, optional formal geprüftem Frame in `data`/`data.data`.
Dieser Vertrag bleibt eine vorhandene Transportannahme und wird hier nicht als
Hersteller-Cloudbeleg ausgegeben. Der Befehlsantwortparser bleibt getrennt vom
B8-Diagnoseparser. Selbst sein positives Ergebnis lautet weiterhin
`angenommen=true, ausgefuehrt=false`. Unbelegte ACK-Bytes wurden nicht ergänzt.

## Einbindung in die bestehende Gerätearchitektur

Die spätere Zielinstanz bleibt 22708. Lokal wird kein produktives Objekt geändert.
Die bestehende Gerätemodulklasse bringt bereits die nativen Funktionen
`ROBO_Starten`, `ROBO_Anhalten`, `ROBO_ZurStation` und `RequestAction('Befehl', …)`
mit. Sie verwenden Konto/ForwardData → Eureka-Treiber → Befehlsweg, ohne externe
PHP-Steuerskripte. Die internen Befehlsnamen sind `start`, `pause`, `station`.
Die gemeinsame Variable `Befehl` ist ein Taster; Zustand, Akku, Fehler und
Erreichbarkeit stammen weiterhin ausschließlich aus dem vorhandenen Lesepfad.
Es gibt weder einen zweiten Gerätezustand noch eine optimistische Zustandssetzung.

Die nachgewiesenen Rahmen waren bereits vorbereitet und bleiben hinter allen
bisherigen Sperren: private feste `BEFEHLE_FREIGEGEBEN=false`, leere
`BEFEHLS_ZUORDNUNGEN`, normale Transportsperre und unterste HTTPS-Sperre, die nur
die separat genehmigte feste Diagnose zulässt. Keine Zuordnung, Freigabe,
Genehmigung oder Schlüsseldatei wurde geändert. Ein Diagnoseerfolg schaltet keine
Steuerung frei. Geräteseitige Semaphore, Kontextprüfung unmittelbar unter Sperre,
Einmalversuch, keine Warteschlange, keine automatische POST-Wiederholung und keine
Steuerung beim Kernelstart bleiben unverändert.

Zusätzlich zum bestehenden Online-/Identitäts-/Frische-/Shadow-/Fehlernachweis
verweigert die Vorbereitung jetzt bekannte negative oder unbekannte Fehlerbefunde
im Gerätecache, selbst wenn der Shadow leer/fehlerfrei ist. Neuer Start braucht
einen bestätigten Akkustand über 15 %; Pause/Continue/Dock bekommen diese neue
Startschranke nicht. Alle bisherigen Zustandsbeschränkungen gelten weiter.
Ein fehlendes Shadow-`connected` bleibt für Steuerung unzureichend; Diagnose und
Statusanzeige haben ihre bereits dokumentierten gesonderten Regeln.

Die generische native Startaktion hat keine Parameter. Sie kann deshalb lokal
nur den schon vorgesehenen Continue-Fall planen; für einen neuen Auftrag braucht
`vorbereiten` explizit `fan`, `water`, `mode=8`. Es wurden weder Wasserwerte
erfunden noch neue Aktions-/Statusvariablen oder eine Bedienfreigabe hinzugefügt.
Vor Produktivbetrieb muss eine gesondert entschiedene Parameterübergabe im
bestehenden nativen Gerätepfad umgesetzt/reviewt werden. Diese Einschränkung ist
absichtlich sichtbar und wird nicht durch Defaults versteckt.

## Tests und verbleibende Blocker

`tests/eureka/b8-response-cases.php` erweitert ausschließlich den vorhandenen
Diagnoseprüfstand. Er prüft Formate samt verschachtelten Hüllen, eingebettetem JSON,
Bytearrays, kanonischem Base64 und Hextrennzeichen; für jedes Format dieselben
Rahmenfehler einschließlich Prüfsumme, reservierter Bytes, Typ, Familienkennung,
Länge, falscher Abfrage und Mindestlänge. Weitere Gegenproben betreffen doppelte
Schlüssel, mehrere (auch identische) Kandidaten, gemischte/fehlerhafte Bytes,
Nicht-Datenfelder, Format-/Ressourcengrenzen, sichere Metadaten und fehlende
Persistenz im echten Kontopfad mit ersetztem Transport.

Der Befehlsprüfstand ergänzt Akkugrenzen und ungültige Akkutypen für jeden
Neustartzustand, Fan 0/4/5, widersprüchlichen Fehlercache, zusätzliche ungeeignete
Betriebszustände und null Transport bei Abweisung. Die native Geräteintegration
prüft Methoden und Aktionsvariable für Start/Pause/Station sowie Kernelstart mit
erhaltenem Aktionswert: kein Transport, keine Statusmutation, kein Freigabeimport.
Die dortigen Instanznummern sind lokale Doubles; die bestehende gesonderte
Diagnosebindung 22708 wird zusätzlich im Diagnoseprüfstand geprüft.

Vollständige Prüfung: `bash tests/eureka/run-offline.sh`, mit `bwrap --unshare-net`,
einschließlich vorhandener Prüfstände, aller PHP-Lints und `git diff --check`.
Prüfumgebung: PHP 8.2.35. Abschlussbefunde:

| Prüfstand | Bestanden | Fehler |
| --- | ---: | ---: |
| Modulstruktur-Gegenproben | 22 | 0 |
| Konto-/Diagnoseprüfungen einschließlich Antwortformate | 444 | 0 |
| Signierer | 16 | 0 |
| Import | 19 | 0 |
| Dateisystem-Import mit/ohne POSIX | 39 | 0 |
| Gemeinsamer HTTPS-/cURL-Pfad mit Doubles | 18 | 0 |
| Befehlsvorbereitung und Sperren | 284 | 0 |
| Zustände | 683 | 0 |
| Token-/GET-Regressionen | 72 | 0 |
| RoboHub-Gesamtprüfstand einschließlich nativer Aktionen | 1.205 | 0 |

Zusätzlich bestanden die bestehenden nativen libcurl-Gegenversuche ausschließlich
gegen Loopback-Nachbauten im isolierten Netzwerk-Namespace. **81 PHP-Dateien**
ohne Syntaxfehler, `git diff --check` fehlerfrei. Keine externe Verbindung.

Blocker bis zu produktiver Steuerung: reale E20-Cloudverpackung und
aktionsbezogene Antwortformate; nachgewiesene aktuelle Gerätezustände einschließlich
Online-Signal und Gerätefrische; bewusste Startparameterübergabe; exakte gesondert
reviewte Gerätezuordnung und ausdrückliche spätere Befehlsfreigabe; native
IP-Symcon-Laufzeit-/Nebenläufigkeitsprüfung und beobachtete Zustandsübergänge.
Die lokale frische Empfangszeit beweist keinen frischen Gerätezeitstempel.
Die heutigen Offline-Tests und Herstellerbytes schließen diese Lücken nicht.
