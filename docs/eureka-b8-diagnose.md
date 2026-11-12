# Administrative B8-Einmaldiagnose: Vorbereitung, kein Live-Test

Aktueller Folgestand ab `a5980e0`: [signierte administrative Bereitstellung](eureka-b8-bereitstellung.md).
Die folgenden Aussagen zum fehlenden Bereitstellungsweg beschreiben den historischen Stand.

Stand 12.11.2026, Branch `feature/eureka-e20-evo-plus`, Basis `2f034c6`.
Es wurde ausschließlich lokal implementiert und mit gesperrtem externem Netzwerk
geprüft. Keine produktive Installation und keine echte Testfreigabe wurden vorgenommen.

## Architektur

Die vier neuen öffentlichen Skriptfunktionen gehören ausschließlich zu
`RoboHubKonto`. Sie akzeptieren nur Konto 45174, Hersteller Eureka und Region EU.
Das Ziel ist fest auf die Instanz 22708 begrenzt. Modul-GUID, Parent-Verbindung
und deren `GeraetID` werden zur Laufzeit geprüft. Der Cloud-Gerätewert muss genau
einmal im vorhandenen Kontocache vorkommen; im Code steht keine individuelle
Cloud-Kennung. Tokens stammen ausschließlich aus dem gespeicherten Kontobündel.

`RoboHubEurekaDiagnose` prüft exakt Modell `E20 Evo Plus`, SN8 `750Y0015`,
Protokollversion 0, Plattform `robozone`, Station `dust_station` sowie
Plugin `T0xB8_750Y0015.zip`, Version `2.5.4`. Doppelte Metadaten müssen
übereinstimmen. Die bestehende Normalisierung der Protokollversion erlaubt 0 und "0".

Es gibt keine neue Verbindung zu Start, Aktionsvariablen, Visualisierung,
ForwardData, Timern, MQTT oder automatischen Statusabfragen. Normale Eureka-
Befehle bleiben mit `BEFEHLE_FREIGEGEBEN=false`, `BEFEHLS_ZUORDNUNGEN=[]` und
Transportsperren gesperrt. Andere Hersteller verwenden ihre bisherigen Pfade.

## Zusätzliche Laufzeitsperre (aktueller Stand)

Ohne separat bereitgestellte Laufzeitfreigabe sind Ticketausstellung und
Diagnosetransport gesperrt. Die folgenden Skriptaufrufe allein können den Pfad
nicht mehr freischalten. Architektur, Herstellerbelege, Grenzen und Ergebnisse:
[eureka-b8-laufzeitsperre.md](eureka-b8-laufzeitsperre.md).
Die Bereitstellung einer konkreten Laufzeit ist nicht implementiert und benötigt
einen eigenen Auftrag. Bereits die erste Ticketausstellung verbraucht die
Ausstellungsbefugnis; Widerruf oder Ablauf gestatten kein neues Ticket.

## Exakter späterer administrativer Ablauf

Die folgenden Aufrufe sind nur dokumentiert. Sie wurden nicht am produktiven
IP-Symcon ausgeführt. Installation und Live-Test benötigen einen eigenen Auftrag.

1. Lokal vorprüfen: `ROBOHUB_EurekaB8Vorpruefen(45174, false)`.
   Das prüft ausschließlich bestehende lokale Daten, ohne Netzverkehr.
2. Falls die Liste lokal älter als 120 Sekunden ist: erst nach ausdrücklicher
   Freigabe `ROBOHUB_EurekaB8Vorpruefen(45174, true)` aufrufen. Dieser Aufruf prüft
   das bestehende Zielmodell und die Instanzbindung und lädt dann genau einmal
   `/app/devices?version=1&isShared=false`. Kein Refresh, Retry oder Shadow-GET.
   Eine fehlende/alte Shadow-Antwort muss über den bestehenden Statuslesepfad
   aktualisiert werden; die neue Vorprüfung startet ihn nicht automatisch.
3. Nur wenn der konkrete einmalige POST ausdrücklich genehmigt wurde, separat
   `ROBOHUB_EurekaB8Freigeben(45174, 60, 'B8-Arbeitszustand einmalig abfragen')`
   aufrufen. Rückgabe ist JSON mit `freigabe` und `gueltigBis`.
4. Innerhalb der Frist in einem separaten administrativen Aufruf
   `ROBOHUB_EurekaB8Testen(45174, '<Wert aus freigabe>')` ausführen.
   Die Ausgabe enthält ausschließlich die getrennten Validierungsbefunde und
   belegte numerische Statusfelder. Freigabewert nicht protokollieren.
5. Jeder Transportversuch beendet die Einmalmöglichkeit endgültig. Keine
   Wiederholung, keine automatische Statusnachfrage und kein Folgebefehl.

Ein erfolgreicher Listen- oder Shadow-GET erteilt niemals eine Freigabe.
Freigeben und Testen sind getrennte Funktionen; es gibt keinen kombinierten
Aufruf, der bei fehlender oder abgelaufener Freigabe selbst eine neue erzeugt.

## Freigabe, Verbrauch und Widerruf

Freigaben gelten 1 bis höchstens 60 Sekunden. Wandzeit und monotone Uhr werden
geprüft; ein Rückstellen der Systemuhr verlängert die Frist nicht. Ein zufälliger
Freigabewert wird nur als SHA-256-Hash gespeichert. Ein separater Bindungshash
umfasst Konto, Geräteinstanz, Cloud-Gerät, exakten Befehl, Kontonamen,
Sitzungsrevision, komplettes Tokenbündel und Laufzeitbindung.

Die Laufzeitbindung besteht aus Kernelstartzeit und einem flüchtigen Zufallswert
im Modulbuffer. Das ist ausschließlich die **Istkennung**, keine Genehmigung.
Die erwartete Kennung liegt getrennt im Buffer `EurekaB8Laufzeitfreigabe` und
wird vom Modul niemals erzeugt, erneuert oder an die Istkennung angepasst.
Neustart oder Verlust der Buffer schließen den Pfad ohne automatische Erneuerung.
Abmeldung, Anmeldung mit identischen Tokens und jede Tokenrotation invalidieren
ebenfalls die vorhandene Freigabe. Der Diagnosepfad erneuert keine Tokens.
Der lokal gelesene JWT muss noch mehr als 15 Sekunden gültig sein.

Vorprüfung, Freigabe und Test benutzen die Diagnosesemaphore und anschließend
die vorhandene Bündelsemaphore. Sie halten beide bis zum Ende. Dadurch können
parallel aufgerufene Skripte weder denselben Versuch senden noch die Sitzung
während des Versuchs ändern. Die Bündelsemaphore wird hier bewusst bis zum
Transportende gehalten; das HTTPS-Zeitlimit bleibt 15 Sekunden.

Vor dem ersten Transport wird `zustand=verbraucht` im persistenten Kontoattribut
`EurekaB8Diagnose` gespeichert und zurückgelesen. Fehler beim Speichern verhindern
den Versand. Ein erhaltener verbrauchter Eintrag sperrt auch neue Freigaben,
einschließlich nach Neustart. Zurücklesen ist kein Nachweis für einen
absturzfesten Datenträgerschreibvorgang; siehe [Persistenzbewertung](eureka-b8-transportsicherheit.md). Es existiert kein Reset-Einstieg.

Mit `ROBOHUB_EurekaB8Widerrufen(45174)` lässt sich eine Freigabe auch bei
abgelaufener Sitzung oder aktiver Cloud-Sperre widerrufen. Widerruf,
Freigabeänderungen, Verbrauch und Transportbeginn werden mit einer zusätzlichen
kurzen Freigabesemaphore serialisiert. Der Widerruf benötigt weder die lange
Diagnose- noch die Bündelsperre und kann daher auch nach Reservierung noch den
Versand verhindern. Unmittelbar vor `curl_exec()` wird unter dieser kurzen
Semaphore nach erneuter Prüfung `transportBegonnenAm` persistent gespeichert.
Das ist der gegen Widerruf serialisierte lokale Transportbeginn; danach wird
Widerruf abgewiesen. Damit wird keine atomare Transaktion mit Datenträger oder
HTTP-Gegenstelle behauptet.

Widerruf schließt auch die separate Laufzeitfreigabe endgültig. Eine neue
Ticketausstellung ist auch vor Reservierung ausgeschlossen. Nach Reservierung
bleibt zusätzlich der persistente Verbrauch erhalten. Ein Attributrollback kann
den unabhängigen Bufferzustand nicht zurücksetzen.

## Vorbedingungen und Transport

Gerätelistenstand, lokaler Listenempfang des Zielgeräts und Shadow-Empfang müssen
höchstens 120 Sekunden alt sein und dürfen nicht in der Zukunft liegen.
Shadow und Liste müssen derselben Geräteidentität zugeordnet sein. Die Liste
muss explizit `connected=true` melden. Ein fehlendes Shadow-`connected` bleibt
unverändert unbekannt; der positive Verbindungsnachweis stammt dann ausschließlich
aus der frischen Liste. Das bisher daraus abgeleitete `stand.online=false` ist
ohne explizites Shadow-Signal kein Offline-Nachweis. Explizites Shadow-Offline,
widersprüchliche Metadaten, unbekannte/widersprüchliche Zustände und Gerätefehler
sperren die Diagnose. `deviceFault=[]` im Shadow ist erforderlich.

`WarteBis` wird bei Vorprüfung, Freigabe und Transport berücksichtigt. HTTP 429
setzt mindestens 60 Sekunden Kontosperre. Ein HTTP 401 verändert keine Tokens
und löst keinen Refresh aus. Bei 500, Timeout oder unklarem Ergebnis bleibt der
Versuch verbraucht.

Der Diagnoseadapter benutzt die gemeinsame Eureka-HTTPS-Implementierung, keine
zweite cURL-Implementierung. Ausschließlich zulässig:

```text
POST https://euprod.mzrobo.com/devices/commands/<Laufzeit-Gerätekennung>
{"data":"AA0CB800000000000003320106"}
```

Die unterste gemeinsame Transportgrenze prüft unmittelbar vor Headern/cURL noch
einmal Kontext, Sitzung, Freigabefrist und den exakt gespeicherten Verbrauch.
Redirects sind explizit aus, es gibt keinen alternativen Endpunkt oder Transport.
Connect-Timeout 8 Sekunden, Gesamt-Timeout 15 Sekunden, TLS-Prüfung unverändert.
Nur die Diagnose erzwingt `CURL_HTTP_VERSION_1_1`, eine frische Verbindung ohne
Wiederverwendung, leeres `Expect`, deaktivierte HTTP-/Proxy-Authentifizierung
und einen direkten Transport ohne Umgebungsproxy. Ein erfolgreiches Ergebnis
muss zusätzlich `CURLINFO_HTTP_VERSION=CURL_HTTP_VERSION_1_1` melden. Diese
nachträgliche Prüfung beweist keine Einmalzustellung. `curl_setopt_array()` muss
`true` liefern; bei `false` oder Exception erfolgt kein `curl_exec()`, und das
Handle wird im `finally` geschlossen. Verbrauch bleibt bestehen. Normale
Eureka-Anfragen bekommen keine dieser zusätzlichen Diagnoseoptionen; auch sie
brechen bei einer fehlerhaften cURL-Konfiguration vor I/O ab.
Es werden keine Tokens, Refresh-URLs, vollständigen Header, Rohantworten oder
Gerätekennungen geloggt. Fehlermeldungen enthalten keine Transport-Freitexte.

## Antwortvalidierung

`RoboHubEurekaB8` ist ein kleiner separater Parser anhand der bereits
[dokumentierten Offsets](eureka-zustaende.md#erwartete-arbeitsstatusantwort).
Er prüft Hexformat, Rahmenbeginn AA, Längenbyte, B8-Kennung, reservierte
Kopfbytes, additive Prüfsumme, Antworttyp 03 und Bodypräfix 32 01.
Mindestens 37 Bytes einschließlich Prüfsumme sind erforderlich: die dokumentierten
Grundfelder bis Byte 35 müssen vollständig vorliegen. Kürzere Antworten und ein
Echo des 13-Byte-Abfrageframes gelten nicht als gültige Arbeitsstatusantwort.

Dekodiert werden nur Arbeitsstatus (Offset 12), Akku (22), Fehlerklasse (25)
und Fehlercode (26). Unbekannte/optionale Felder werden nicht interpretiert.
Plausibel bedeutet: belegter Arbeitswert 1–18 oder 22–24 und Akku 0–100.
Fehlerbytes werden als numerische Werte erhalten, ohne unbekannte Codes zu deuten.

HTTP-Erfolg, expliziter Cloud-Erfolgscode 0/"0", gültiger B8-Arbeitsframe und
plausibler Inhalt sind getrennte Ergebnisse. Nicht geprüfte Folgestufen liefern
`null`. Fehlender Frame trotz Cloud-Erfolg bleibt ein fehlender Nachweis.
Die Antwort aktualisiert weder normale Statusvariablen noch den Shadow-Cache.

## Lokale Prüfung und Grenzen

Reproduzierbarer vollständiger Offline-Lauf:

```sh
bash tests/eureka/run-offline.sh
```

Der Runner verlangt `bwrap`, erstellt einen eigenen Netzwerk-Namespace und
führt die fünf PHP-Eureka-Prüfstände, die nativen HTTPS-Gegenversuche,
den gesamten RoboHub-Prüfstand, PHP-Lint
und `git diff --check` aus. Die nativen cURL-Funktionen werden beim zusätzlichen
Transportprüfstand deaktiviert und durch lokale Prüf-Doubles ersetzt. Die
nativen Gegenversuche verwenden zusätzlich echte PHP-libcurl und einen
ephemeren, nur im isolierten Loopback erreichbaren TLS-Gegenserver. HTTP/2
`REFUSED_STREAM` sowie HTTP/1.1-Verbindungswiederverwendung dienen als positive
Kontrollen für interne Mehrfach-POSTs. Test-CA und Schlüssel werden nach dem
Prüfprozess gelöscht. Die manuellen Live-Proben gehören nicht zum Runner. Fixtures enthalten nur erfundene
individuelle Kennungen und Zugangsdaten.

Offen bleiben das tatsächliche HTTPS-Antwortformat und die Unterstützung des
vermuteten Endpunkts am E20, native IP-Symcon-Semaphoren und Persistenz beim
Absturz sowie die tatsächliche Gerätefrische. Lokale Empfangszeit ist kein
Gerätezeitstempel. Die additive Prüfsumme ist keine Authentisierung. Hersteller-
App und andere Cloudclients unterliegen nicht der lokalen Semaphore. Ein reiner Attributrollback öffnet die separate Laufzeitsperre nicht. Eine
Wiederherstellung des gesamten laufenden Arbeitsspeichers oder erneute externe
Bereitstellung liegt außerhalb dieses Schutzes. Verlorene Persistenz kann der
Code nicht rekonstruieren. Der sichere Standard bei jeder
Unklarheit ist Abbruch ohne Wiederholung.
