# B8-Transportkorrektur und Grenzen der Einmalgarantie

12.11.2026, `feature/eureka-e20-evo-plus`, Basis `2f034c6`.
Der vorgefundene uncommittete Arbeitsstand wurde erhalten. Ausschließlich lokale
Offline-Prüfung im vorhandenen `bwrap --unshare-net`-Prüfrahmen, kein Live-Test,
keine Cloud-Anfrage, kein produktives IP-Symcon, keine Geräteansteuerung,
kein Commit, Push oder Deployment. Der TLS-Gegenserver ist Teil des beendeten
Prüfprozesses; es wurde kein Hintergrunddienst eingerichtet.

## Aktueller Nachtrag: zusätzliche Laufzeitsperre

Der nachfolgende Bericht dokumentiert den vorherigen Transportstand und seine
historischen Ergebnisse. Die Transportkorrekturen bleiben unverändert.
Die damalige Möglichkeit eines neuen Tickets nach Verbrauchsrollback ist jetzt
durch eine separate, standardmäßig fehlende Laufzeitfreigabe geschlossen.
Die damaligen Aussagen „keine Architekturänderung“ und „neue Freigabe möglich“
gelten für den historischen Stand, nicht für den aktuellen Code.
Aktuelle Bewertung und Tests: [Laufzeitsperre](eureka-b8-laufzeitsperre.md).

## Exakte Korrektur

`libs/RoboHubEureka.php`, bestehende gemeinsame Methode `httpAusfuehren()`:

- Der `try/finally`-Bereich beginnt direkt nach erfolgreichem `curl_init()`.
  Auch JSON-, Options- und Berechtigungsfehler schließen dadurch das Handle.
- `curl_setopt_array()` muss strikt `true` zurückgeben. `false` oder eine
  Exception bricht vor letzter Transportmarkierung und vor `curl_exec()` ab.
  Konfigurationsfehler erhalten einen festen Text ohne cURL-Freitexte,
  Tokens, Header oder Antwortdaten. Es gibt keinen Wiederholungsversuch.
- Nur bei vorhandener Diagnoseprüfung werden die folgenden Optionen ergänzt:

| Option | Wert | Zweck |
| --- | --- | --- |
| `CURLOPT_HTTP_VERSION` | `CURL_HTTP_VERSION_1_1` | HTTP/2-REFUSED_STREAM-Pfad ausschließen |
| `CURLOPT_FRESH_CONNECT` | `true` | Keine bereits verwendete Verbindung übernehmen |
| `CURLOPT_FORBID_REUSE` | `true` | Diagnoseverbindung anschließend schließen |
| `CURLOPT_HTTPAUTH` / `CURLOPT_PROXYAUTH` | `CURLAUTH_NONE` | Keine Authentifizierungsverhandlung |
| `CURLOPT_PROXY` | `''` | Keine Umgebungsproxys im Einmalpfad |
| `CURLOPT_HTTPHEADER` | zusätzlich `Expect:` | Automatisches 100-continue unterdrücken |

Nach erfolgreichem `curl_exec()` wird zusätzlich die tatsächliche Version mit
`CURLINFO_HTTP_VERSION` geprüft. Ein abweichender Wert verwirft das Ergebnis,
ohne Retry. Diese Prüfung findet nach Versand statt und ist keine präventive
Zustellgarantie. Bei fehlender Antwort kann der Info-Wert 0 bleiben; dafür
liefern ALPN und die am Server empfangene HTTP-Anfrage zusätzliche Belege.

Der normale GET-/Auth-Pfad erhält keine zusätzlichen Diagnoseoptionen.
Der Abbruch bei fehlerhafter Konfiguration gilt bewusst für die gemeinsame
Methode. Andere Hersteller wurden nicht geändert. `BEFEHLE_FREIGEGEBEN=false`,
`BEFEHLS_ZUORDNUNGEN=[]` sowie die Sperren in `transport()` und an der untersten
Befehlsgrenze bleiben erhalten. Es gibt keine zweite produktive Transportmethode.
In `RoboHubEurekaDiagnose.php` wurde nur der Kommentar zur Persistenz präzisiert;
der Freigabe-/Speicheralgorithmus blieb unverändert.

## Native Gegenversuche und Verhalten vorher/nachher

PHP **8.2.35**, PHP-libcurl **7.88.1** (Debian `7.88.1-10+deb12u15`),
OpenSSL **3.0.22**. Entscheidend ist `curl_version()` aus PHP, nicht ein eventuell
anderes `curl`-Kommando. Die produktive Symcon-libcurl-Version ist unbestätigt.

`diagnostic-native-test.py` erweitert den vorhandenen isolierten Prüfrahmen um
einen ephemeren HTTPS-Gegenserver. Es prüft einen anderen Netzwerknamespace
und ausschließlich Interface `lo`. Keine Downloads oder externen Zugriffe.
Die Test-CA authentisiert den ursprünglichen Hostnamen; TLS-Verifikation bleibt
aktiv. `CURLOPT_CONNECT_TO` leitet ausschließlich in der Testinstrumentierung
auf den isolierten Loopback-Port um. Diese Option und die Test-CA gelangen
nicht in den Produktivcode.

`diagnostic-native-client.php` führt dieselbe Eureka-Quelldatei in einem
Testnamespace aus. Die cURL-Systemgrenze wird instrumentiert, `curl_exec()`
ruft echte native libcurl auf. Für positive Kontrollen werden ausschließlich
im Test die neuen Optionen entfernt bzw. eine Vorverbindung oder `Expect`
erzwungen. Das sind absichtliche Gegenmodelle, keine zusätzlichen
Produktivpfade. Die lokale nachträgliche Versionsprüfung bleibt auch in der
HTTP/2-Kontrolle aktiv; sie verhindert deren schon erfolgten Doppelversand nicht.

Alle nachstehenden Fälle haben genau **einen gespeicherten Freigabeverbrauch**.
Außer beim Konfigurationsfehler gibt es genau **ein diagnostisches `curl_exec()`**.
Gezählt werden am Server vollständig gelesene POST-Bodies mit exakt geprüftem
B8-Inhalt, getrennt von empfangenen Request-Headern und TCP-Verbindungen.

| Fall | Vollständige POSTs | Ergebnis / Versionsbeleg |
| --- | ---: | --- |
| Ursprüngliche HTTP/2-Optionen, `RST_STREAM/REFUSED_STREAM` nach vollständigem Body | **2** | 2 TLS-Verbindungen, ALPN `h2`, curl-Info **3 = HTTP/2** |
| Korrektur, derselbe HTTP/2-fähige Gegenserver | **1** | 1 TLS-Verbindung, ALPN `http/1.1`, curl-Info **2 = HTTP/1.1**; kein HTTP/2-Stream |
| Nur HTTP/1.1, absichtlich zuvor verwendete Verbindung; Abbruch nach vollständigem POST | **2** | 2 TLS-Verbindungen, ein diagnostisches exec; beide POSTs HTTP/1.1 |
| Korrektur trotz eingeschleuster Vorverbindung; gleicher Abbruch | **1** | Neue Diagnoseverbindung, keine Wiederholung; errno 52, curl-Info 0; Server/ALPN HTTP/1.1 |
| `Expect: 100-continue`, 417 vor Body; Kontrolle mit `MAXREDIRS=50` | **1** | **2 Request-Header** innerhalb eines exec: interner Retry auch unter HTTP/1.1 |
| Gleiche Expect-Kontrolle mit bestehendem `MAXREDIRS=0` | **0** | 1 Request-Header, errno 47; diese Grenze verhindert hier den Retry |
| 100 Continue, vollständiger Body, anschließend 417 | **1** | Kein Retry auf dieser libcurl-Version |
| Korrektur ohne Expect, 417 | **1** | Kein Retry, HTTP/1.1 |
| Abbruch unmittelbar nach TLS | **0** | Kein vollständiger POST, errno 52; ALPN HTTP/1.1 |
| Abbruch nach halbem Body | **0** | 1 Request-Header, errno 52; Server liest HTTP/1.1 |
| Abbruch nach vollständigem Body, ohne Antwort | **1** | errno 52, curl-Info 0, ALPN/Server HTTP/1.1 |
| Timeout nach vollständigem Body | **1** | Originales 15-Sekunden-Limit, errno 28; kein Retry |
| Antwortbody abgeschnitten | **1** | errno 18, curl-Info HTTP/1.1; kein Retry |
| Native Options-Teilkonfiguration liefert `false` | **0** | **0 exec, 0 TCP-Verbindungen**, Handle geschlossen, keine Transportmarkierung |
| Je HTTP 200, 401, 429, 500, 302, 307, 417 | **je 1** | Je 1 Verbindung, ALPN und curl-Info HTTP/1.1; kein Retry |

Gesamt **21 native Fälle, 0 Fehler**. In jedem Fall bleiben zweite
Ticketverwendung und erneute Freigabe nach erhaltenem Verbrauch gesperrt.
Die Vorverbindungskontrolle enthält zusätzlich einen separaten nativen GET zum
Aufwärmen des Testhandles; der Diagnoseaufruf selbst führt nur einmal exec aus.
Der reguläre Produktivcode erzeugt bereits ein neues Handle; die zusätzliche
Option `FRESH_CONNECT` sichert diese Voraussetzung ausdrücklich ab.

Damit ist auch experimentell belegt: HTTP/1.1 allein garantiert keine
Einmalzustellung. Die Expect-Kontrolle mit verändertem Redirect-Limit beweist
nicht, dass der ursprüngliche kurze B8-Body diesen Pfad benutzt hätte.
Für die Testmatrix wurden die nativen Retry-Pfade nachgewiesen; eine vollständige
Quellcodeprüfung sämtlicher libcurl-Versionen ist damit nicht erfolgt.

## Konfigurationsfehler und Regressionen

Der native Fehlerfall setzt zuerst erfolgreich `CURLOPT_CUSTOMREQUEST=POST`
und danach absichtlich die ungültige HTTP-Version 999. Das **native**
`curl_setopt_array()` liefert `false`; das verbleibende Teilhandle wird
geschlossen, ohne exec, TCP-Verbindung oder Transportbeginnmarkierung.
Zusätzlich simulieren die deaktivierten cURL-Doubles `false` und eine Exception
für Diagnose und normalen GET. Unerwartete HTTP-Versionen werden ebenfalls
geprüft. Eine erneute Freigabe erfolgt auch nach einem Konfigurationsfehler nicht.
Die Produktionsliste wird nur bei vollständig erfolgreichem Optionsaufruf
verwendet; ein zusätzliches einzelnes Setzen ist dafür nicht erforderlich.

Reproduzierbar: `bash tests/eureka/run-offline.sh`. Der Runner führt weiterhin
sämtliche bestehenden Offline-Prüfstände aus; Live-Proben bleiben ausgeschlossen.
Die Konto-/Diagnoseprüfungen enthalten zweite Verwendung, Fiber-Parallelität,
Widerruf vor und nach Reservierung, Tokenrotation, HTTP 401/429/500, Timeout,
Sperrtimeouts sowie den normalen gesperrten Befehlsweg.

| Prüfstand | Bestanden | Fehler |
| --- | ---: | ---: |
| Konto-/Diagnose einschließlich 4 zusätzlicher Persistenz-Fehlermodelle | 115 | 0 |
| Gemeinsamer HTTPS-Code mit cURL-Doubles | 18 | 0 |
| Native HTTPS-/libcurl-Gegenversuche | 21 | 0 |
| Bestehende Befehlsprüfungen | 233 | 0 |
| Bestehende Zustandsprüfungen | 683 | 0 |
| Bestehende Token-/GET-Prüfungen | 72 | 0 |
| Vollständiger RoboHub-Prüfstand einschließlich anderer Hersteller | 1.189 | 0 |

Gesamt **2.331 bestandene Prüfungen**, keine Fehler. Die native Testmatrix wurde
nach Ergänzung der Namespace-Startprüfung und des echten nativen Optionsfehlers
zusätzlich separat erfolgreich wiederholt. **72 PHP-Dateien** sind syntaxfehlerfrei; auch Python-Syntax und Whitespace
aller geänderten/neuen Dateien wurden geprüft. `git diff --check` ist fehlerfrei.

## Absturzpersistenz: Untersuchung, keine Architekturänderung

**Im Code nachweisbar:** `RoboHubKonto` registriert `EurekaB8Diagnose` als
Stringattribut. `RoboHubEurekaDiagnose::persistieren()` ruft den Writer auf und
vergleicht unmittelbar mit dem Reader. Ohne identische Rückgabe gibt es keinen
Transport. Verbrauch und Transportbeginn werden unter der Freigabesemaphore
vor exec gespeichert. Ein erhaltener Verbrauchsmarker verhindert auch später
`freigeben()`, einschließlich eines widerrufenen Eintrags mit `verbrauchtAm`.
Es existiert kein Reset-Einstieg. Weder dieser Code noch seine Test-Doubles
führen einen Datenträger-Flush mit überprüfbarer Haltbarkeitsbestätigung aus.

**Dokumentationsstand:** Die lokale Projektdokumentation bezeichnet
Symcon-Attribute als persistent und den Modulbuffer als flüchtig. Das ist keine
belegte Zusage über synchrones Schreiben, `fsync`, atomare Dateiersetzung,
Schreibreihenfolge oder Stromausfallsicherheit von `WriteAttributeString()`.
Eine native Symcon-Implementierung bzw. entsprechende Herstellerunterlagen
liegen diesem Prüfstand nicht bei. Wegen des Verbots externer Netzwerkzugriffe
wurden keine Herstellerseiten nachgeladen. Es gibt damit hier **keinen
verifizierten Herstellerbeleg für Absturzfestigkeit vor dem HTTP-POST**.
Ein Read-after-write und die in PHP-Arrays nachgebildeten Attribute ersetzen
solch einen Beleg nicht. Native Symcon-Persistenz wurde nicht getestet.

**Altes Ticket:** Der Bindungshash enthält `IPS_GetKernelStartTime()` und einen
Zufallswert aus `GetBuffer('EurekaB8Laufzeit')`. Bei geändertem Kernelstart oder
neuem Buffer erzeugt der Kontext eine andere Bindung. Ein vor dem Absturz
ausgegebenes Ticket wird deshalb selbst bei zurückgerolltem `bereit`-Attribut
abgewiesen, sofern die Laufzeitbindung tatsächlich erneuert wird. Leeres oder
ungültiges Attribut autorisiert ein altes Ticket ebenfalls nicht. Erhalt und
Neuerzeugung des Buffers über echte native Crash-Szenarien sind noch zu prüfen.
Eine vollständige VM-/Speicherzustandswiederherstellung kann auch flüchtige
Bindungen zurücksetzen und liegt außerhalb dieser Annahme.

**Spätere neue administrative Freigabe:** Geht der Verbrauch verloren und kommt
nach Neustart ein älteres `bereit`, `widerrufen` ohne Verbrauch oder `{}` zurück,
ist dem Code der schon erfolgte Versuch nicht mehr bekannt. Ein Administrator
kann bei erneut erfüllten Vorbedingungen ausdrücklich ein **neues Ticket**
anfordern. Das ist keine Wiederverwendung des alten Tickets und kein
automatischer Retry, widerlegt aber eine unbedingte lebenslange Einmalgarantie
bei Verlust des Markers. Bei erhaltenem Verbrauch wird auch eine neue
administrative Freigabe verweigert.

Vier zusätzliche Offline-Fehlermodelle spielen `bereit` oder `{}` nach einem
abgeschlossenen Versuch zurück und erzeugen einen neuen Modulbuffer, jeweils
mit gleicher bzw. geänderter Kernelzeit. Altes Ticket: abgewiesen, keine weitere
Übertragung. Neue explizite Freigabe: möglich; der Test widerruft sie, ohne einen
weiteren Transport auszuführen. Das sind Modellprüfungen, **keine nativen
Absturzversuche**. Es wurde keine Persistenzarchitektur ergänzt.

## Entwurf eines sicheren späteren nativen Persistenztests

Nur in einer separat freizugebenden, wegwerfbaren Symcon-Testinstanz mit eigener
Datenablage und gesperrtem externem Netzwerk. Keine produktiven Volumes,
Zugangsdaten, Instanzen oder Cloud-Hostweiterleitung übernehmen.

1. Einen Testadapter für dieselbe Diagnoseklasse mit synthetischem Kontext,
   nativen `WriteAttributeString`/`ReadAttributeString`, Semaphoren und Buffer
   verwenden. Als Transport ausschließlich einen lokalen Receipt-Recorder oder
   isolierten HTTPS-Gegenserver eintragen; keinen Eureka-Cloud-Aufruf zulassen.
2. Frisches Datenvolume pro Fall. Administrative Freigabe erzeugen, deren Ticket
   nur im privaten Testcontroller halten. Checkpoints vor Verbrauch, nach Writer,
   nach Read-back, nach Transportmarkierung, nach vollständigem lokalem Empfang
   und nach Antwort unterscheiden. Nur Hashes und Zustände im Bericht erfassen.
3. Den ausschließlich dafür gestarteten Testkernel an den Checkpoints von außen
   hart beenden, etwa per SIGKILL. Keine zusätzlichen Save-Aufrufe in den
   untersuchten Pfad einbauen. Ein explizites Speichern höchstens als separat
   gekennzeichnete Kontrollgruppe prüfen. Neustart mit demselben Datenvolume.
4. Nativen Attributzustand, Kernelzeit und Erneuerung des Buffers prüfen. Zuerst
   altes Ticket testen: keine neue lokale Quittung erwartet. Erst danach separat
   prüfen, ob eine neue administrative Freigabe möglich ist. Fehlenden Verbrauch
   ausdrücklich als Verlust des dauerhaften Einmalschutzes erfassen.
5. SIGKILL untersucht Prozessabsturz, keinen Stromausfall. Für letzteren wäre
   eine gesonderte wegwerfbare VM mit abruptem Ausschalten und dokumentiertem
   Datenträger-/Cacheverhalten erforderlich. Einzelne erfolgreiche Versuche
   ergeben keine allgemeine Haltbarkeitsgarantie.

Dieser Entwurf wurde nicht ausgeführt und verändert keine produktive Instanz.

## Verbleibende Grenzen

Die behobenen bekannten nativen Retry-Pfade sind in der aufgeführten lokalen
libcurl-Version experimentell abgesichert. Andere Versionen, TLS-Backends,
Symcon-Semaphoren und native Speicherhaltbarkeit sind damit nicht zertifiziert.
Es gibt keine allgemeine libcurl-Option, aus deren Setzen allein eine
Ende-zu-Ende-Einmalzustellung abgeleitet werden könnte. Eine fehlende Antwort
kann sowohl einen nicht angekommenen als auch einen schon verarbeiteten POST
bedeuten. Deshalb bleibt jeder Versuch verbraucht und wird nicht wiederholt.

HTTP/1.1, ein einzelnes exec, ein einzelner gespeicherter Verbrauch und selbst
ein einzelner serverseitiger POST belegen keine genau einmalige Verarbeitung
im Herstellerbackend oder Gerät. Es fehlt ein nachgewiesener serverseitiger
Idempotenzvertrag. Hersteller-App und andere Cloudclients liegen außerhalb der
lokalen Sperren. Gerätefrische und tatsächliches Antwortformat des echten
Cloud-Endpunkts bleiben ungeprüft. Verlorene Attribute, wiederhergestellte
Backups oder vollständige Laufzeitsnapshots werden nicht als beherrschte
Persistenzfälle dargestellt.

## Git-Diff-Statistik

Gegenüber dem bei Auftragsbeginn gesicherten Arbeitsbaum: 10 Dateien,
739 Einfügungen, 25 Löschungen.
Gesamter uncommitteter Arbeitsbaum gegenüber `2f034c6`, einschließlich neuer
ungetrackter Dateien: 14 Dateien, 1978 Einfügungen, 17 Löschungen.
Die Gesamtzahlen enthalten die bereits vorgefundenen Änderungen.
