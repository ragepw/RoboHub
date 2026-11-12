# E20 Evo Plus: reguläre manuelle IP-Symcon-Bedienung

Stand 12.11.2026, Branch `feature/eureka-e20-evo-plus`. Ausschließlich lokal
entwickelt und mit Transportdoubles geprüft. Keine Cloudaufrufe, produktiven
Objektänderungen, Roboterbefehle, Bereitstellung oder Pushs.
Die Quellcodefreigabe und der Commit erfolgen ausschließlich lokal.
Dieses Dokument ersetzt für den normalen Steuerungsweg die älteren Aussagen
in [Produktivvorbereitung](eureka-produktivvorbereitung.md) und
[Steuerungsbericht](eureka-steuerung.md). Die separaten B8-/Dock-Genehmigungen
werden weder ersetzt noch erweitert.

## Lokaler Fertigstellungsstand ab 517d682

Das genaue E20-Profil ist im regulären Treiber hinterlegt. Die bestehende
Bedienung über Konto 45174, Gerät 22708 und Befehlsvariable 22284 ist mit
Transportdoubles für Start, Pause, Fortsetzen und Dock geprüft.
Die individuelle Cloud-Gerätekennung ist fest im lokalen Treiber gebunden.
Laut Betreiber wurde sie erfolgreich gegen die beiden historischen Dock- und
Gerätebefunde abgeglichen und eingetragen. Dieser Auftrag prüft die vorhandene
Bindung und das Profil statisch; er wiederholt keinen historischen Liveversuch.
`BEFEHLE_FREIGEGEBEN=true` erlaubt ausschließlich Start, Pause, Fortsetzen und
Dock über den manuellen Bedienweg der exakt gebundenen Instanz.
Stopp, Orten, andere Geräte und automatische Einstiege bleiben gesperrt.

Die separate [Pause-Einmalprüfung](eureka-pause-einmaltest.md), B8 und Dock
behalten ihre Berechtigungen, Verbräuche, Fristen und Widerrufsprüfungen.
Die [vertrauliche lokale Eingabe](eureka-vertrauliche-geraetebindung.md) dokumentiert
den vorausgegangenen Abgleich. Bereitstellung und Live-Funktionstest bleiben offen.

## Bestätigter Befund und Transport

Laut Nutzerbericht war der genehmigte Dock-Einmalversuch erfolgreich: Die Cloud
meldete `angenommen=true`, `ausgefuehrt=false`, `antwortVorhanden=false`.
Vor Ort endete die Reinigung; der Roboter fuhr zur Station und entleerte sich.
Das ist der praktische Nachweis für den Dock-Transport, keine neue lokale Messung.
Die Entleerung war ein beobachteter Folgeablauf und ist kein zusätzlicher
Absaugbefehl. Der aktuelle Nutzerbericht bestätigt außerdem Pause über
`RequestAction(22284, 2)` am realen Gerät und den gemeinsamen HTTPS-Transport.
Start und Fortsetzen sind herstellerbelegt und offline geprüft; ihr realer
Funktionstest bleibt ausstehend. Hier wurde kein Liveversuch wiederholt.

Normaler Weg und Dock verwenden dieselbe Methode
`RoboHubEureka::httpAusfuehren`: EU-Host `https://euprod.mzrobo.com`,
`POST /devices/commands/<gebundene ID>`, Bearer-Sitzung, JSON
`{"data":"<B8-Hexrahmen>"}` und dieselben App-/Plattformheader. Es gibt keinen
zweiten cURL-Code. Der normale Adapter übergibt ausschließlich den zuvor
geprüften Plan und seinen lokalen Prüfrückruf an diese Methode.

Alle Steuer-POSTs übernehmen die erprobten Dock-Transportgrenzen: HTTP/1.1,
frische Verbindung, keine Wiederverwendung, keine Weiterleitung, kein Proxy,
keine HTTP-/Proxy-Authentifizierungsaushandlung, leeres `Expect`, 8 s
Verbindungs- und 15 s Gesamtzeitlimit. Teilweise fehlgeschlagene cURL-Konfiguration
sendet nichts. Nach der Vorbereitung folgt unmittelbar vor `curl_exec` eine
erneute lokale Kontext-/Frische-/Verbrauchsprüfung. Ein Versionsfehler, Timeout,
HTTP-/Cloud-/Parserfehler oder unklarer Ausgang löst keinen weiteren POST aus.
Die bisherige zentrale Tokenrotation nach POST-401 darf keinen POST wiederholen.

Der bereits von Dock verwendete Parser
`RoboHubEurekaBefehlsweg::antwortLesen` bleibt gemeinsam: HTTP 2xx,
`errorCode` exakt 0/"0", optional formal gültiger Frame in `data` oder `data.data`.
Fehlende Antwortdaten sind zulässig. `angenommen=true` bedeutet Cloudannahme;
`ausgefuehrt` bleibt false, auch bei vorhandenem Frame. Eine beobachtete Bewegung
wird nicht aus dem ACK abgeleitet. B8-Diagnoseantworten haben weiterhin ihren
separaten Parser. Unbelegte aktionsspezifische ACKs werden nicht erfunden.

## Herstellerbelegte Aktionen und Startparameter

Beleg: vorhandenes Pluginarchiv 2.5.4 und lokale statische Auszüge,
insbesondere Module 16153 (`cmdStartWork`, `cmdStopWork`), 71825 (`stop_type`),
81717 (`startClean`, `startCharge`). Archivhash, Byteoffsets und Parameterbelege
stehen in [Produktivvorbereitung](eureka-produktivvorbereitung.md#herstellerbelege-und-konkrete-befehle).
Die vorhandenen Originalauszüge wurden erneut lokal gelesen; kein Download
und keine Ausführung des Herstellerplugins.

| Bedienung | Zugelassener Shadowzustand | Rahmen/Parameter |
| --- | --- | --- |
| Neuer Start | `stop`, `charging`, `charging_finish` | `22 02 00 02 00 08 FF 00 WW 00 00`; Fan 1/2/3, Wasser 0–3, Ganzhausmodus 8; Akku als Integer 16–100 |
| Start/Fortsetzen | ausschließlich `pause` | `AA0DB800000000000002221C00FB`, keine neuen Startparameter |
| Pause | ausschließlich `cleaning` | `AA0DB800000000000002221B00FC`, keine Parameter |
| Station | `stop`, `pause`, `cleaning` | `AA0DB80000000000000222010016`, keine Parameter |

Die sechs reservierten Nullbytes, Längenprüfung und additive Prüfsumme bleiben
unverändert. `charging_pause`, `wiping_pause`, Schlaf, Rückfahrt, Stationsarbeit,
Update und unbekannte/widersprüchliche Zustände bleiben für Steuerung gesperrt.
Alle Aktionen benötigen `deviceFault=[]`; ein vorhandener negativer oder
unklarer Listenfehlerbefund sperrt ebenfalls. Die zusätzliche Start-Akkuschwelle
gilt nicht für Pause, Continue oder Dock.

Zwei lokale Konfigurationseigenschaften der bestehenden Geräteinstanz ermöglichen
die bewusste Auswahl: `EurekaStartSaugstufe` und `EurekaStartWasserstufe`, beide
anfangs -1 = nicht gewählt. Das Formular zeigt die Auswahl beim E20 Evo Plus.
Es entstehen keine Steuerungsvariablen. Modus 8 ist der belegte Ganzhausmodus;
es gibt keine Raum-/Modusheuristik und keinen Wasserdefault. Wasser 0 muss
ausdrücklich gewählt werden. Die Werte werden nach Erwerb der Gerätesperre
bei einem zulässigen Ruhezustand aus den bestehenden Eigenschaften gelesen.
Für einen daraus erzeugten **neuen** Start wird die Auswahl bis unmittelbar vor
I/O erneut verglichen. Eine Änderung sperrt den bereits verbrauchten Versuch.
Start aus Pause ignoriert diese Konfiguration
und erzeugt Continue. Fehlende/falsche Werte verhindern den neuen Auftrag.
Neue Startparameter werden nicht aus unbestätigten Shadowfeldern übernommen.

## Verbindung und Frische

| Bewertung | Nachweis und Bedienfolge |
| --- | --- |
| ausdrücklich offline | Shadow meldet boolesch false oder eine höchstens 120 s alte Liste meldet boolesch false; offline hat Vorrang, Steuerung gesperrt |
| Cloud meldet verbunden | positiver Shadowbefund oder frisches Listen-`connected=true`; ohne Shadow-`connected` lautet die Anzeige „Cloud meldet verbunden (Gerätebestätigung fehlt)“ |
| Verbindung unbekannt | kein verwendbarer expliziter Befund, ungültige/fehlende Zeit, veraltete Liste oder Abruffehler; Steuerung gesperrt |

`erreichbarkeit.bewertung` enthält `offline`, `cloud-verbunden` oder `unbekannt`.
Quellen, Rohbefunde und Cachealter bleiben im vorhandenen Erreichbarkeitsattribut
und in der vorhandenen Rohdatenansicht nachvollziehbar. `Erreichbar`,
`stand.online`, Shadowrohdaten und `GetActivity()` werden nicht auf online
umgeschrieben. Ohne Shadow-Nachweis bleibt die Aktivität für Automationen
unbestätigt. Die Anzeigen beschreiben den letzten Abruf; sie bekommen keinen
zusätzlichen Hintergrundtimer.

Nur die ausdrücklich als Bedienaktion übergebene `RequestAction('Befehl', …)`
darf bei **vollständig fehlendem** Shadow-`connected` eine frische positive
Cloudliste verwenden. Ein vorhandenes ungültiges oder negatives Shadowfeld
wird nicht übergangen. Beide Listenzeiten und der zugeordnete Shadow müssen
höchstens 120 s alt, positiv und nicht zukünftig sein. Der Shadow muss aus dem
erfolgreichen Einzelziel-GET derselben Sitzung, Modullaufzeit und Kernelstartzeit
stammen. IDs und vorhandene Modell-/Pluginmetadaten dürfen nicht widersprechen.
Der bestehende Einzelziel-GET ohne rohe ID bleibt durch sein Abfrageziel gebunden;
das ist keine zusätzliche vom Gerät gelieferte Identitätsbestätigung.

Native Skriptmethoden wie `ROBO_Starten`/`ROBO_Anhalten` markieren keine manuelle
Bedienung und bleiben auch bei positivem Shadow-`connected` gesperrt. Symcon kann innerhalb
von `RequestAction` nicht den menschlichen Ursprung beweisen: Ein Ereignis, das
bewusst dieselbe Bedienaktion auslöst, darf daher nicht als Umgehung der
Automationsregel eingerichtet werden. Der Bedienmarker ist kein Zugriffsschutz;
es gelten weiterhin Symcons Zugriffsrechte und sämtliche Geräte-/Sitzungsprüfungen.

Frische bedeutet lokales Empfangsalter. Ein Gerätezeitstempel ist weiterhin
unbelegt; ein frisch empfangener, cloudseitig alter Shadow ist nicht sicher
erkennbar. Diese Grenze bleibt sichtbar und muss bei den separaten Live-Tests
mit einer aktuellen Beobachtung von Gerät und Original-App abgeglichen werden.
Es gibt keine automatische Listenauffrischung beim Befehl und keinen B8-Fallback.

## Vorhandene IP-Symcon-Bedienung und Einmalversand

Feste lokale Bindung: Konto 45174, Geräteinstanz 22708, deren vorhandener Ident
`Befehl` mit Variablen-ID 22284. Modul-GUID, Kontoverbindung, Geräte-ID,
Deaktivierung, EU-Sitzung samt Revision, JWT-Restgültigkeit über 15 s und
Cloud-Wartezeit werden unter der geräteweiten Semaphore geprüft und direkt
vor I/O erneut gelesen. Ein exakt bestätigtes Modellprofil und eine separat
im Treiber fest gebundene individuelle Cloud-ID sind zusätzlich erforderlich.
Das feste Profil lautet `E20 Evo Plus` / SN8 `750Y0015` / Proto 0 /
`robozone` / `T0xB8_750Y0015.zip` Version `2.5.4`; die Kontoprüfung verlangt
zusätzlich `stationType=dust_station` und weist widersprüchliche Metadaten ab.
Das Profil ist gegen die dokumentierte Livecache-Struktur und den lokalen
Herstellerbericht abgeglichen. Der Archivhash wurde erneut lokal bestätigt:
`0a275bbd2ee8c076aeb8e1d314a4bbe98fa5c3c6aeb3750414765adc399f24c6`.
Die anonymisierte Fixture `offline-e20` ist ausdrücklich keine Produktivkennung.

Der spätere native Weg lautet: vorhandene WebFront-Schaltfläche →
`RequestAction(22284, Wert)` → `RoboHubGeraet::RequestAction('Befehl', Wert)` →
Konto/ForwardData → normaler Eureka-Befehlsweg → gemeinsame HTTPS-Methode.
Die bestehenden Werte sind 1 = Start/Fortsetzen, 2 = Pause, 4 = Station.
3 = Stopp und 5 = Orten bleiben lokal abgewiesen und erreichen keinen Cloud-POST.
Die Beispiele sind Dokumentation für einen später freigegebenen Betrieb,
keine heute auszuführenden Testskripte. Keine neue Instanz, Variable oder
externer Laufzeitdienst ist erforderlich.

Ein Befehlsobjekt verbraucht seinen Versuch vor Transportbeginn. Zusätzlich
persistiert das Konto `EurekaBefehlsversuch` mit einem SHA-256-Fingerabdruck aus
Gerät, vollständigem Shadowstand und Sitzung. Derselbe Stand kann auch durch
eine zweite RequestAction, einen anderen Befehlsaufruf oder ein neues
Treiberobjekt keinen zweiten POST auslösen. Ein neuer regulärer Versuch benötigt
einen neu gelesenen, zulässigen Stand. Ein identischer GET innerhalb derselben
Sekunde kann konservativ weiter als verbraucht gelten. Fehlerhafte Verbrauchsdaten
sperren; sie werden nicht automatisch repariert. Auch Timeout/Transportfehler
behalten den Verbrauch. Kein Warteschlangeneintrag und kein Startup-Code sendet
Befehle. Alte Snapshots verlieren nach Neustart ihre Laufzeitbindung, selbst
wenn persistente Attribute erhalten bleiben. Es gibt keine Zusicherung gegen
manuelles Attribut-Rollback, Dateisystemverlust oder mehrere Symcon-Server.

## Spätere Aktualisierung und Funktionstest — jetzt nicht ausführen

Der inzwischen konkret vorbereitete private SSH-/Bundle-Weg für den Pi steht
in der [lokalen Bereitstellungsanleitung](eureka-lokale-bereitstellung.md).
Er verwendet den bestehenden Checkout und einen Dienstneustart ohne GitHub.

Der geprüfte Stand enthält die individuelle Gerätebindung und den Freigabeflag
`true`. Vor einer späteren Übertragung die Eigenschaft `GeraetID` von 22708
vertraulich gegen die feste Bindung prüfen; keine Kennung in Protokolle übernehmen.
Eine allgemeine Modellfreigabe, dynamische ID-Übernahme oder Wildcards sind unzulässig.

1. Vor dem später separat beauftragten Aktualisieren den bisherigen Modulstand,
   die Eigenschaften und Attribute sichern. Der lokale Commit enthält die
   individuelle Gerätekennung und darf nicht zu einem Remote gepusht werden.
   Vor jeder späteren Remote-Veröffentlichung muss die Kennung aus sämtlichen
   zu übertragenden Commits entfernt und die Bindung vertraulich bereitgestellt
   werden; ein nachträglicher Löschcommit allein genügt nicht.
   Module Control kann nur einen dort erreichbaren Git-Stand beziehen: Solange
   dieser ausschließlich lokal vorliegt, steht er dort noch nicht als Update bereit.
   Sobald ein vertraulich bereitgestellter, exakt gebundener Stand erreichbar ist:
   in IP-Symcon unter Module Control die bestehende RoboHub-Bibliothek auswählen,
   Branch `feature/eureka-e20-evo-plus` prüfen und „Aktualisieren“ ausführen.
   Anschließend den tatsächlich übernommenen Quellstand und die lokale Bindung
   vertraulich vergleichen. Keine Instanzen löschen oder neu anlegen.
2. In Symcon 9.1 Konto 45174 und Gerät 22708 übernehmen und die Verbindung sowie
   die bestehende Variable 22284 mit Ident `Befehl` kontrollieren. Beim Übernehmen
   und Modul-/Kernelstart darf **kein Steuer-POST** entstehen. Alte Snapshots
   sind nach dem Laufzeitwechsel gesperrt. Einmalverbräuche von B8, Dock und
   Pause sowie `EurekaBefehlsversuch` erhalten; nichts zurücksetzen.
3. Im vorhandenen Geräteformular `EurekaStartSaugstufe` bewusst auf 1, 2 oder 3
   und `EurekaStartWasserstufe` bewusst auf 0 bis 3 setzen und übernehmen.
   Kein Wert wird vorgegeben; Wasser 0 ist eine ausdrückliche Auswahl.
   Der Test erfolgt beaufsichtigt am Gerät, mit für die Wasserwahl geeignetem
   Boden. Hersteller-App und reale Bewegung dienen dem Funktionsabgleich.
4. Vor **jedem** der vier Schritte ausdrücklich `ROBOHUB_GeraeteSuchen(45174)`
   und danach `ROBO_Aktualisieren(22708)` verwenden. Liste, Listenempfang und
   gültiger Shadow müssen in derselben Sitzung/Laufzeit jeweils höchstens
   120 Sekunden alt, eindeutig zugeordnet und fehlerfrei sein. Die Aufrufe
   sind hier ausschließlich dokumentiert; sie wurden nicht ausgeführt.
5. Die folgende Bedienfolge über die vorhandenen WebFront-Schaltflächen
   durchführen. Die RequestAction-Werte erläutern nur deren vorhandene Belegung:

   | Schritt | Vorbedingung nach frischem Abruf | Bestehende Aktion | Getrennt prüfen |
   | --- | --- | --- | --- |
   | Start | `stop`, `charging` oder `charging_finish`, Akku 16–100 %, gültige Startwahl | Start, Wert 1 | Cloudannahme; wirklicher Reinigungsbeginn; nachgelesenes `cleaning` |
   | Pause | `cleaning` | Pause, Wert 2 | Cloudannahme; wirkliche Pause; nachgelesenes `pause` |
   | Fortsetzen | `pause` | dieselbe Start-Schaltfläche, Wert 1 | Continue ohne neue Startwerte; wirkliche Fortsetzung; nachgelesenes `cleaning` |
   | Dock | `cleaning` (allgemein auch `stop` oder `pause`) | Station, Wert 4 | Cloudannahme; wirkliche Rückfahrt; später Stationszustand |

6. Nach jeder Aktion zunächst die reale Reaktion beobachten und danach den
   Shadow aktualisieren. Keine nächste Aktion auf dem bereits verbrauchten
   Snapshot auslösen. Eine fehlende Cloudantwort, Fehler, Timeout oder unklare
   Bewegung beendet die Testfolge; kein automatischer oder blinder manueller
   Neuversuch. Erst neu bewerten und einen frischen gültigen Stand lesen.
   Ein ACK beweist keine Ausführung. Eine automatische Entleerung nach Dock
   ist ein möglicher Stationsfolgeablauf, kein zusätzlich gesendeter Befehl.
7. Die Anzeige muss bei fehlendem Shadow-`connected` weiterhin auf die fehlende
   Gerätebestätigung hinweisen; `Erreichbar`, `stand.online` und `GetActivity()`
   werden nicht künstlich positiv. Frische positive Cloudliste plus gültiger
   Shadow reichen nur für die ausdrückliche Bedienaktion. Automatische Methoden
   bleiben auch bei positivem Shadow-Verbindungsbefund gesperrt. Stopp (3), Orten (5)
   und andere Funktionen erhalten keine Befehlsfreigabe. Es werden keine Timer,
   Ereignisse, Hintergrundjobs oder externen Laufzeitdienste zur Steuerung angelegt.

Nach Eintragen der exakten Gerätefreigabe verwendet die normale Bedienung ihre
regulären Kontext- und Verbrauchssperren. Ein alter Pause-Testmarker wird dabei
weder gelöscht noch erneut benutzt. Er verhindert auch keine reguläre
Sitzungserneuerung. Die separaten Einmalmethoden und ihre Verbrauchsprüfungen
bleiben erhalten; ein alter Test wird dadurch nicht wiederholbar. Für Geräte
außerhalb der exakten regulären Bindung bleibt der bisherige Pause-Testweg
unverändert. Auch ein regulärer Versuch darf denselben Shadowstand nicht erneut
verwenden, einschließlich eines zuvor im Pause-Einmaltest verbrauchten Stands.

## Offlineprüfung

`bash tests/eureka/run-control-offline.sh` bündelt die vollständige Regression
in einem eigenen Netzwerk-Namensraum, ohne Fallback auf externe Erreichbarkeit.
Alle Steuertransporte verwenden Doubles. Der gemeinsame HTTPS-Code läuft gegen
ersetzte cURL-Funktionen; native libcurl-Gegenproben erreichen nur isolierte
Loopback-Nachbauten. Die vorhandenen einzelnen Runner bleiben verfügbar.
Temporäre Testschlüssel und Importfixtures sind rein synthetisch und werden
nirgends produktiv bereitgestellt.

`control-driver-fixture.php` ersetzt im PHP-Prüfprozess nur Freigabeflag und
Gerätekennung durch die Testfreigabe für `offline-e20`. Das **echte** einzelne
E20-Profil bleibt unverändert. Produktivdateien werden dazu nicht verändert.
Die native Integration nutzt echte Konto-Kontext-/Verbrauchsprüfungen und
nachgebildete Instanzen 45174/22708 samt bestehender Befehlsvariable 22284.

Zusätzlich zu den bisherigen Prüfungen werden die zusammenhängende Folge
Start → Pause → Fortsetzen → Dock ohne Verbrauchsrücksetzung, sämtliche 36
Startwert-/Ruhezustandskombinationen, 42 Zustands-/Aktionsgrenzen, ungültige
Startwerte und fehlender Akku bei Nicht-Startaktionen sowie Auswahländerungen
vor I/O geprüft. Historische geschlossene, widerrufene und verbrauchte
Pause-Testmarker bleiben bei regulären Versuchen unverändert; Doppelbedienung
bleibt auch dann gesperrt. Die alte Pause-Testkennung wird durch die neue
reguläre Gerätefreigabe nicht geöffnet.

Die bestehende native Automations-Gegenprobe verlangt jetzt auch bei explizitem
Shadow-`connected=true` null Transporte. Drei bestehende Integrationsprüfungen
verwenden den Fehlertext der manuellen Sperre statt des früheren globalen
Freigabetexts; ihre Transport-, Zustands- und Timerprüfungen bleiben erhalten.
Es wurde kein zusätzlicher Testmechanismus angelegt.

Abschlussprüfung mit PHP 8.2.35:
`bash tests/eureka/run-control-offline.sh` beendet mit Exitcode 0,
3.547 Einzelprüfungen bestanden, keine Fehler im abschließenden Gesamtlauf.

| Prüfstand | Bestanden |
| --- | ---: |
| Modulstruktur-Gegenproben | 22 |
| Pause-Einmaltest | 114 |
| Dock-Einmaltest | 244 |
| Gemeinsamer HTTPS-Code mit cURL-Doubles | 97 |
| B8-Diagnose | 444 |
| B8-Signierer / Import / Dateisystem-Import | 16 / 19 / 39 |
| Dock-Kryptografie | 49 |
| Native libcurl-Gegenversuche im isolierten Loopback | 21 |
| Befehlsplanung und Sperren | 344 |
| Zustandsbewertung | 683 |
| Token-/GET-Regressionen | 72 |
| Vollständiger RoboHub-Prüfstand | 1.383 |
| PHP-Lint | 92 Dateien |

Gegenüber dem Basisstand wurden 102 native Bedienprüfungen ergänzt.
`git diff --check` und `bash -n tests/eureka/run-control-offline.sh` sind
ebenfalls fehlerfrei. Ein separater früherer RoboHub-Lauf hatte einen
sporadischen Timeout des lokalen iRobot-UDP-Nachbaus; im abschließenden
netzisolierten Gesamtlauf bestanden auch diese Gegenproben.

Die Dateien `RoboHubEurekaDock.php`, `RoboHubEurekaDiagnose.php`,
`RoboHubEurekaGenehmigung.php`, `RoboHubEurekaPauseTest.php` und
`RoboHubEurekaProtokoll.php` bleiben gegenüber 517d682 unverändert.
Die individuelle Produktivkennung wird im Offlineprüfstand nicht verwendet;
die positive Bedienregression nutzt die ausdrücklich
erfundene Offlinekennung. Kein Offlineergebnis ersetzt den späteren realen
Start-/Fortsetzen-Funktionsnachweis.
