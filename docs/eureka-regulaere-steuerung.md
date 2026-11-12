# E20 Evo Plus: Vorbereitung der regulären IP-Symcon-Bedienung

Stand 12.11.2026, Branch `feature/eureka-e20-evo-plus`. Ausschließlich lokal
entwickelt und mit Transportdoubles geprüft. Keine Cloudaufrufe, produktiven
Objektänderungen, Befehle, Aktivierung, Bereitstellung, Commits oder Pushs.
Dieses Dokument ersetzt für den normalen Steuerungsweg die älteren Aussagen
in [Produktivvorbereitung](eureka-produktivvorbereitung.md) und
[Steuerungsbericht](eureka-steuerung.md). Die separaten B8-/Dock-Genehmigungen
werden weder ersetzt noch erweitert.

## Bestätigter Befund und Transport

Laut Nutzerbericht war der genehmigte Dock-Einmalversuch erfolgreich: Die Cloud
meldete `angenommen=true`, `ausgefuehrt=false`, `antwortVorhanden=false`.
Vor Ort endete die Reinigung; der Roboter fuhr zur Station und entleerte sich.
Das ist der praktische Nachweis für den Dock-Transport, keine neue lokale Messung.
Die Entleerung war ein beobachteter Folgeablauf und ist kein zusätzlicher
Absaugbefehl. Start, Pause und Continue sind noch nicht real erprobt.

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
ausdrücklich gewählt werden. Die Werte werden erst nach Erwerb der Gerätesperre
für einen **neuen** Start gelesen. Start aus Pause ignoriert diese Konfiguration
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
Bedienung und verlangen weiterhin positives Shadow-`connected`. Ein unbekannter
Status berechtigt niemals zur automatischen Steuerung. Symcon kann innerhalb
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
im Treiber festzulegende individuelle Cloud-ID sind zusätzlich erforderlich.

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

## Spätere Tests und Aktivierung — heute nicht autorisiert

1. Änderungen und Offlineergebnisse reviewen. Die individuelle Cloud-ID der
   bestehenden Instanz separat bestätigen; Profil vollständig gegen Modell
   E20 Evo Plus, SN8 750Y0015, Proto 0, Plattform robozone, Plugin 2.5.4 und
   Staubstation abgleichen. Keine Freigabe allein aus dem Modellnamen ableiten.
2. **Pause separat freigeben:** genau ein Versuch, genau diese Gerätebindung,
   kurzes Zeitfenster, Reinigung zuvor über die Hersteller-App starten und vor
   Ort beobachten. Eine später dafür zu reviewende Aktions-/Zeit-/Einmalbegrenzung
   vor den normalen Weg setzen; keine allgemeine Freigabe aller Befehle nur für
   den Test. Den hier vorbereiteten Transport weiterverwenden; vorhandene
   B8-/Docktickets und deren Verbrauch nicht ändern oder zurücksetzen.
3. Unmittelbar zuvor die vorhandene Liste ausdrücklich aktualisieren und den
   normalen Shadow lesen; binnen 120 s Zustand `cleaning` und Fehlerfreiheit
   prüfen. Einmal die bestehende Pauseaktion auslösen. Cloudannahme, tatsächliches
   Pausieren und nachfolgender Shadow sind drei getrennte Beobachtungen.
   Bei unklarem Ausgang keine Wiederholung, kein automatisches Fortsetzen.
   Testfreigabe anschließend wieder schließen.
4. **Neuen Start unabhängig freigeben:** gleicher begrenzter Ablauf, zulässiger
   Ruhezustand und Akku über 15 %. Vorher Fan und Wasser ausdrücklich wählen,
   Wasser-/Bodenbedingungen vor Ort prüfen. Einmal Start, Beginn der Reinigung
   und korrekte Zustandsänderung beobachten; danach Testfreigabe schließen.
   Continue aus echter Pause benötigt ebenfalls einen eigenen Beobachtungsnachweis.
5. Erst nach Auswertung und ausdrücklicher Produktivfreigabe das exakte Profil
   in `BEFEHLS_ZUORDNUNGEN`, die individuell bestätigte ID in
   `BEFEHLS_GERAET_ID` und `BEFEHLE_FREIGEGEBEN=true` zusammen reviewen und
   bereitstellen. **Aktuell bleiben false, [] und leere ID unverändert.**
   Eine einzelne Konfigurationsänderung öffnet die Steuerung nicht. Nach späterer
   Bereitstellung Liste/Shadow in der neuen Laufzeit erneut lesen, Startwerte
   setzen und vorhandene WebFront-Schaltflächen verwenden. Keine externen
   Steuerungsskripte und keine neuen Steuerungsvariablen anlegen.

Native IP-Symcon-Ausführung, reale Start-/Pause-/Continue-Reaktionen,
cloudseitige Shadowverzögerung und aktionsbezogene Antworten bleiben offen.
Der erfolgreiche Dockversuch schließt diese Lücken nicht. Reguläre manuelle
Bedienung braucht den Cloudlistenbefund plus frischen Shadow; automatische
Bedienung ohne Shadow-Verbindungsnachweis bleibt gesperrt.

## Offlineprüfung

`bash tests/eureka/run-offline.sh` und
`bash tests/eureka/run-dock-offline.sh` erzwingen getrennte Netz-Namensräume.
Alle Eureka-Transporte sind Doubles; native Protokollgegenproben nutzen nur
isolierte Loopback-Nachbauten. Der gemeinsame cURL-Prüfstand deaktiviert die
echten cURL-Funktionen. Keine Verbindung zu Cloud, Roboter oder Produktiv-Symcon.

`control-driver-fixture.php` erzeugt ausschließlich im PHP-Prüfprozess eine
umbenannte Kopie des echten Treibers mit Testprofil und erfundener ID
`offline-e20`. Produktivdateien werden dafür nicht verändert. Die native
Integration behält die echten Konto-Kontext-/Verbrauchsprüfungen und bildet
22708/22284 mit lokalen Doubles nach; der gesonderte HTTP-Prüfstand benutzt
den echten gemeinsamen HTTPS-Code mit ersetzter cURL-Systemgrenze.

Geprüft werden die vier Aktionen, fehlende/falsche Startwerte, Akku,
Online-/Offline-/Unbekannt-Kombinationen, Zeitgrenzen, Geräte-/Sitzungsbindung,
Zustands-/Fehlerbefunde, Änderungen vor I/O, Doppelbetätigung und unabhängige
Befehlsobjekte, HTTP-/Cloud-/Transportfehler, Neustart ohne Wiederholung sowie
die vorhandenen B8-/Dock-Genehmigungs-, Widerrufs- und Verbrauchssperren.
Abschluss mit PHP 8.2.35: beide vollständigen Runner Exitcode 0, keine Fehler.

| Prüfstand | Bestanden |
| --- | ---: |
| Modulstruktur-Gegenproben | 22 |
| B8-Diagnose einschließlich Parserformaten | 444 (Dockrunner ohne Signaturgeneratoren: 399) |
| B8-Signierer / Import / Dateisystem-Import | 16 / 19 / 39 |
| Dock-Kryptografie / Dock-Sicherheit | 49 / 244 |
| Gemeinsamer HTTPS-Code mit cURL-Doubles | 77 |
| Native libcurl-Gegenversuche gegen isoliertes Loopback | 21 |
| Befehle einschließlich manueller Listenverbindung | 344 |
| Zustandsbewertung | 683 |
| Token-/GET-Regressionen | 72 |
| RoboHub-Gesamtprüfstand | 1.266 |
| PHP-Lint | 88 Dateien |

Die vollständigen Runner enthielten zunächst 1.262 RoboHub-Einzelprüfungen.
Vier abschließend ergänzte Gegenproben prüfen den Verbrauch über verschiedene
Befehle hinweg, den nächsten gültigen Shadowstand, dessen erneuten Verbrauch
und den automatischen Einstieg mit explizitem Shadow-Nachweis. Der danach
erneut vollständig ausgeführte RoboHub-Prüfstand bestand alle 1.266 Prüfungen,
darunter insgesamt 56 neue native Steuerungsprüfungen. Abschließender PHP-Lint
des ergänzten Prüfstands und `git diff --check` ebenfalls fehlerfrei.
`RoboHubEurekaDock.php`, `RoboHubEurekaDiagnose.php` und
`RoboHubEurekaGenehmigung.php` wurden nicht verändert. Eine Dock-Gegenprobe
passt ausschließlich ihre Anzeigeerwartung an den Offlinevorrang an;
ihre Freigabe-/Transporterwartungen bleiben erhalten.
