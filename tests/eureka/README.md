# Eureka E20 Evo Plus: Offline-Prüfung und späterer Lesetest

## B8-Parser und Steuerungsvorbereitung, 12.11.2026

Aktuelle Beleglage, Formatvertrag und Grenzen:
[Produktivvorbereitung](../../docs/eureka-produktivvorbereitung.md).
`b8-response-cases.php` wird vom bestehenden `diagnostic-test.php` eingebunden;
keine eigene Infrastruktur und keine Live-Probe. Der vollständige Lauf bleibt
`bash tests/eureka/run-offline.sh`, einschließlich PHP-Lint und Diffprüfung.
Alle Befehls-, Zuordnungs- und Transportsperren bleiben geschlossen.

## B8-Import ohne POSIX (12.11.2026, Basis 178c56b)

[Änderung und Sicherheitsgrenzen](../../docs/eureka-b8-bereitstellung.md#aktualisierung-ip-symcon-91-ohne-posix-12112026).
Der feste B8-Import behandelt eine fehlende POSIX-EUID als unbekannt.
Eigentümer, Modus, Dateityp und Symlinks werden weiter nativ geprüft.
Root kann Schlüsseldatei, Modulcode und Laufzeitdaten manipulieren; ohne POSIX
wird auch fehlender effektiver Schreibzugriff (z. B. ACLs) nicht nachgewiesen.
Normale Befehle bleiben gesperrt; keine automatische Freigabe.

`root-import-test.php` enthält 39 Fälle: jeweils 19 mit nativer EUID 0 und
mit tatsächlich ungeladenem POSIX (`php -n -d extension=ctype`), dazu ein
Testdouble für EUID 1000 mit nativem Schreibzugriffs-Veto. Kein Testschalter
im Produktivcode. Zusätzliche Gegenproben prüfen falsches Konto, falsche
Laufzeit und einen defekten Schlüssel. Neustart und doppelter Import werden
in beiden Laufzeitvarianten geprüft. Nur isoliertes tmpfs-`/etc`, alle
Transporte sind Doubles. Ausführung: `bash tests/eureka/run-offline.sh`.

Abschlusslauf: Exitcode 0; alle bisherigen Offline-Prüfstände einschließlich
39 B8-Importfällen bestanden, 80 PHP-Dateien ohne Syntaxfehler und
`git diff --check` fehlerfrei. Keine native Symcon-Prüfung durchgeführt.

Die folgenden Prüfzahlen und Root-Aussagen dokumentieren den vorherigen Stand.

## Root-Kompatibilität des B8-Imports (12.11.2026, Basis aeb7656)

[Änderung, Sicherheitsbewertung und Testgrenzen](../../docs/eureka-b8-bereitstellung.md#aktualisierung-kontrollierter-import-unter-euid-0-12112026).
Der feste B8-Import akzeptiert EUID 0 bei weiterhin geschütztem Vertrauensanker.
Root kann Vertrauensanker, Modulcode und Laufzeitdaten manipulieren; diese
Grenze wird für den kontrollierten Diagnoseversuch bewusst akzeptiert.
Der private Signaturschlüssel bleibt auf codex-dev. Keine automatische Freigabe.

`root-import-test.php` läuft im Offline-Runner in getrennten User-/Mount-/
Netznamespaces mit echter EUID 0 und flüchtigem tmpfs-`/etc`; kein Host-root
und keine Schlüsseldatei im Host-`/etc`. Native POSIX-/Dateisystemprüfungen und
der Importkörper bleiben unverändert geladen; Symcon und Transport sind Doubles.
16 zusätzliche Fälle umfassen Dateisicherheit und den gesamten Importablauf.
Vollständiger Runner: **2.453 Prüfungen und 22 Struktur-Gegenproben bestanden**,
**0 Fehler**, **79 PHP-Dateien** syntaxfehlerfrei; native lokale HTTPS-
Gegenversuche eingeschlossen. Native Symcon-Prüfung bleibt offen.

## Symcon-Modulstruktur (12.11.2026)

Das externe Signierwerkzeug liegt unter `.tools/eureka-b8-signieren.php`.
Laut [Symcon-Strukturvorgaben](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/struktur/)
ignoriert die Modulerkennung Punktordner sowie `libs/`, `docs/`, `imgs/`,
`tests/` und `actions/`. Der bisherige Ordner `tools/` gehört nicht dazu und
wurde entfernt. Die drei RoboHub-Modulordner enthalten jeweils `module.json`
und `module.php`; weitere ungültige Modulverzeichnisse wurden nicht gefunden.
Der neue Offline-Test `php tests/structure-test.php` prüft alle Verzeichnisse
direkt im Bibliotheksstamm auf diese Regeln, vorhandene Moduldateien und
syntaktisch gültige JSON-Objekte. Er enthält Gegenproben für den ursprünglichen
Fehler, weitere unzulässige Verzeichnisse und die dokumentierten Ausnahmen.
Er läuft im isolierten Runner und in der CI, ohne Symcon oder Netzwerk.

Die externe Schlüsseltrennung bleibt unverändert: Das Werkzeug wird nur auf
dem separaten Administrationsrechner verwendet und liest den privaten Schlüssel
über FD 3. Es wurden keine Schlüssel oder Freigaben bereitgestellt.
`runtime-display-cases.php` gehört zu HEAD `5a2c3dc`: Der dort versionierte
Diagnoseprüfstand bindet die Datei bereits ein, und der zugehörige Bericht
beschreibt ihre 17 Anzeigenfälle. Sie wird als bisher fehlender Bestandteil
dieser Änderung mit aufgenommen.

Abschlussprüfung: `bash tests/eureka/run-offline.sh`, Exitcode 0.
22 Struktur-Gegenproben, alle bestehenden Offline-Prüfstände und PHP-Lint
für 78 Dateien bestanden; Bash-/Python-Syntax und `git diff --check` fehlerfrei.
Keine native Symcon-Prüfung oder produktive Änderung durchgeführt.

## Administrative Bereitstellung (lokal, 12.11.2026)

[Aktueller Abschlussbericht und späterer Ablauf](../../docs/eureka-b8-bereitstellung.md).
Extern signierter Import mit festem externen Vertrauensanker und v2-Nutzlast
mit konkreter Cloud-Gerätebindung. Der öffentliche Moduleinstieg verlangt
zusätzlich einen nativ bestätigten angemeldeten Administrator; fehlende APIs
und unbekannte Kontexte sperren. Standardmäßig weiter geschlossen, keine echte
Bereitstellung durchgeführt. Schlüssel und Dokumente entstehen nur im RAM bzw.
in anonymen Prozesspipes. Native Benutzer-/Authentifizierungsprüfung unter
IP-Symcon 9.1 sowie Rechte-/Buffer-/Semaphore-Prüfung bleiben Voraussetzungen
für eine spätere Freischaltung.

Aktueller Prüfstand: **175 Diagnose-/Bereitstellungs-, 19 Import-Kontext-,
18 HTTPS-Double-, 21 native, 233 Befehls-, 683 Zustands-, 72 Token-/GET- und
1.199 RoboHub-Prüfungen**, insgesamt **2.420**, keine Fehler;
**76 PHP-Dateien** syntaxfehlerfrei. Vollständiger isolierter Runner:
`bash tests/eureka/run-offline.sh`, Exitcode 0. Python-Syntax und Diff-Whitespace
zusätzlich isoliert geprüft. Details und Grenzen im Abschlussbericht.

`import-test.php` prüft fehlende Benutzer-APIs in getrennten Prozessen und den
Moduleinstieg mit echten Signaturen. Nur die externe Public-Key-Dateigrenze
wird für Positivfälle in einer RAM-Kopie der Modulquelle ersetzt; keine native
Authentifizierungsprüfung und keine Schlüsseldatei auf dem Host. Sämtliche
Testunterprozesse bleiben im gesperrten äußeren Netznamespace.

Die folgenden Abschnitte dokumentieren frühere Arbeitsstände.

## Zusätzliche Laufzeitsperre (12.11.2026)

Aktuelle Architektur, Herstellerbelege und Grenzen im
[Abschlussbericht](../../docs/eureka-b8-laufzeitsperre.md).
Der separate flüchtige Buffer `EurekaB8Laufzeitfreigabe` fehlt standardmäßig.
Der Modulcode erzeugt oder erneuert die erwartete Laufzeitkennung niemals.
Bereits die Ticketausstellung verbraucht die einmalige Ausstellungsbefugnis;
Reservierung, Transportbeginn und Widerruf sind endgültige Übergänge.
Attributrollback allein ermöglicht keine erneute Freigabe, auch nach Neustart.
Es gibt keine konkrete administrative Bereitstellung in diesem Arbeitsstand.

Finaler isolierter Offline-Lauf: **130 Diagnose-, 18 HTTPS-Double-, 21 native,
233 Befehls-, 683 Zustands-, 72 Token-/GET- und 1.194 RoboHub-Prüfungen**,
insgesamt **2.351**, keine Fehler. **72 PHP-Dateien** ohne Syntaxfehler;
Python-Syntax und `git diff --check` fehlerfrei. Zusätzlich Diff-Whitespace
aller neuen Dateien geprüft. Transportquelle gegenüber dem vorgefundenen
Arbeitsstand bytegleich erhalten. Native Symcon-Eigenschaften bleiben ungetestet.

Die folgenden Transportergebnisse dokumentieren den vorherigen Stand;
dessen Rollback-Lücke ist mit der zusätzlichen Laufzeitsperre geschlossen.

## B8-Transportkorrektur: native Gegenversuche (12.11.2026)

[Ausführlicher Sicherheits- und Persistenzbericht](../../docs/eureka-b8-transportsicherheit.md).
Die bestehende Einmaldiagnose bleibt administrativ begrenzt, normale Befehle
bleiben gesperrt. Der gemeinsame Transport bricht bei fehlerhafter
cURL-Konfiguration vor I/O ab und schließt das Handle. Nur die Diagnose erzwingt
HTTP/1.1, eine frische Verbindung, keine Wiederverwendung, kein Expect und keine
HTTP-/Proxy-Authentifizierungsverhandlung bzw. Umgebungsproxys.

`bash tests/eureka/run-offline.sh` führt zusätzlich **21 native HTTPS-Gegenversuche**
aus. `diagnostic-native-test.py` verlangt den vom Runner gesetzten äußeren
Netznamespace und ein isoliertes `lo`-Interface; ein unsicherer Fallback fehlt.
Der temporäre TLS-Gegenserver nutzt eine eigene Test-CA, echte TLS-Prüfung und
nur Standardbibliotheken. `diagnostic-native-client.php` instrumentiert die
cURL-Systemgrenze derselben Produktivquelldatei; sämtliche exec-Aufrufe sind nativ.
CA, CONNECT_TO-Umleitung und unsichere Kontrolloptionen existieren nur im Test.
Keine zweite produktive Transportimplementierung, kein zusätzlicher Dienst.

Mit PHP 8.2.35 / libcurl 7.88.1 / OpenSSL 3.0.22 reproduziert die HTTP/2-Kontrolle
**zwei vollständige POSTs aus einem exec**. Nach Korrektur: **ein POST**, ALPN
`http/1.1`, `CURLINFO_HTTP_VERSION=2` (Enum für HTTP/1.1). Auch HTTP/1.1 allein
kann bei einer absichtlich wiederverwendeten Verbindung zwei vollständige POSTs
senden; `FRESH_CONNECT` verhindert diesen reproduzierten Pfad. Bei nativem
Options-Teilfehler: **0 exec, 0 TCP-Verbindungen, 0 POSTs**, Handle geschlossen.
Abbrüche vor/nach Body, 15-Sekunden-Timeout, abgeschnittene Antwort, Expect/417
und HTTP 401/429/500 sowie Redirectantworten sind abgedeckt.

Vorheriger Stand: **115 Diagnose-, 18 HTTPS-Double-, 21 native, 233 Befehls-, 683 Zustands-,
72 Token-/GET- und 1.189 RoboHub-Prüfungen**, insgesamt **2.331**, keine Fehler.
**72 PHP-Dateien** syntaxfehlerfrei; Python-Syntax und Diff-Whitespace geprüft.
Die Persistenzmodelle unterscheiden ein altes Ticket von einer neuen ausdrücklich
administrativen Freigabe bei verlorenem Verbrauch. Native Symcon-Absturzpersistenz
bleibt unbestätigt. HTTP/1.1 und die Testzahlen garantieren keine genau einmalige
Verarbeitung in Cloud oder Gerät. Keine externen Anfragen, Produktivänderungen,
Commits, Pushs oder Deployments.

Die folgenden Ergebnisse beschreiben den ursprünglichen Implementierungsstand.

## B8-Einmaldiagnose (12.11.2026, Basis 2f034c6)

Der neue ausschließlich administrative Konto-Einstieg ist in
[eureka-b8-diagnose.md](../../docs/eureka-b8-diagnose.md) beschrieben. Keine echte
Freigabe, kein Live-Test und kein Deployment wurden durchgeführt. Normalbefehle
bleiben gesperrt. Der spätere Test erlaubt einen lokalen Arbeitsstatus-Transportversuch;
die oben beschriebenen Zustellgrenzen gelten.

Vollständige lokale Prüfung mit blockiertem externem Netzwerk:

```sh
bash tests/eureka/run-offline.sh
```

`diagnostic-test.php` prüft den echten Konto-Einstieg einschließlich Freigaben,
persistenten Verbrauch, Parallelität, Neustart, Widerruf, Tokenrotation,
Cache-/Shadow-Bedingungen, HTTP-/Cloudfehler und B8-Parser. Der zusätzliche
`diagnostic-http-test.php` führt den echten gemeinsamen HTTPS-Code mit ersetzten
cURL-Funktionen aus und prüft Host, POST, Body, Timeouts und Redirects. Er muss
mit `-d disable_functions=curl_init,curl_setopt_array,curl_exec,curl_getinfo,curl_close`
gestartet werden; ohne diese Absicherung bricht er ab.

Abschlussprüfung unter PHP 8.2.35 mit `bwrap --unshare-net`:

| Prüfstand | Bestanden | Fehler |
| --- | ---: | ---: |
| Neue Konto-/Diagnoseprüfungen | 111 | 0 |
| Neue gemeinsame HTTPS-/cURL-Prüfungen | 13 | 0 |
| Bestehende Befehlsprüfungen | 233 | 0 |
| Bestehende Zustandsprüfungen | 683 | 0 |
| Bestehende Token-/GET-Prüfungen | 72 | 0 |
| Vollständiger RoboHub-Prüfstand, einschließlich anderer Hersteller | 1.189 | 0 |

Gesamt: **2.301 Prüfungen bestanden**. **71 PHP-Dateien** ohne Syntaxfehler;
`git diff --check` fehlerfrei. Die cURL-Prüfungen verwenden ausschließlich lokale
Doubles. Kein Zugriff auf Cloud, produktives IP-Symcon oder Geräte; kein Commit,
Push oder Deployment. Native Laufzeit, Gerätefrische und tatsächlicher Endpunkt
bleiben unbestätigt.

Alle automatischen Eureka-Prüfungen laufen ohne Hersteller-Cloud:

```sh
php tests/eureka/command-test.php
php tests/eureka/state-test.php
php tests/eureka/token-test.php
php .tools/test-robohub.php
find . -name '*.php' -print0 | xargs -0 -n1 php -l
git diff --check
```

Der neue, weiterhin produktiv gesperrte Befehlsweg ist in
[docs/eureka-steuerung.md](../../docs/eureka-steuerung.md) beschrieben:
live bestätigter Gerätecache, Trennung von B8-Familie und Befehlsfreigabe,
Rahmen, Start/Resume-Entscheidung, POST-Parser, Gerätesemaphore und Token-Anbindung.
`e20-fixture.php` bildet die laut Auftrag live bestätigte Struktur anonymisiert
ab: `product.sn8=750Y0015`, verschachteltes `product.plugin` und `panel` Version
2.5.4. IDs und Statuswerte sind erfunden. `command-fixtures.php` enthält nur eine
simulierte Referenzzuordnung, kein produktives Geräteprofil.

## Statuskorrektur mit realen Beobachtungen (12.11.2026, Basis 0a68f09)

`observed-shadow-fixtures.php` enthält die sechs vom Nutzer gelieferten
E20-Evo-Plus-Statuskombinationen samt gemeinsamen Statusfeldern, ohne Kennungen
und ohne erfundenes `connected`. Die älteren Geräte-/Befehlsfixtures bleiben
synthetische Gegenproben. [Zuordnung und Grenzen](../../docs/eureka-zustaende.md)
trennen reale Messungen von statischen Annahmen.

`state-test.php` prüft die realen Kombinationen über den GET-Weg, Anzeige,
Rohdatenerhalt, unbekannte Erreichbarkeit und Befehlssperren. Weitere Gegenproben
verwerfen nicht belegte Parent-/Mission-Kombinationen der neuen Namen.
Die Geräteintegration prüft alle sechs Fälle durch Konto und Gerätemodul,
Variablen, Abrufintervall und jeweils Start/Pause/Station ohne Transport.
Ein aktuelles boolesches Shadow-Verbindungssignal bestimmt die Anzeige auch bei
altem negativem Gerätecache; ohne Signal bleibt der Befund unbekannt. Der
vorbereitete Befehlsweg behält seine strengeren Prüfungen einschließlich Cache-Veto.

Lokale Prüfung auf `codex-dev`, PHP 8.2.35, unter
`bwrap --unshare-net --dev-bind / / -- …` (externe Netzzugriffe unterbunden):

- Zustandsprüfstand: **668 bestanden, 0 Fehler**.
- Befehlsprüfstand: **233 bestanden, 0 Fehler**.
- Token-/GET-Prüfstand: **72 bestanden, 0 Fehler**.
- Gesamtprüfstand einschließlich Parent-Verwaltung und 24 neuer
  E20-Integrationsprüfungen: **1.175 bestanden, 0 Fehler**.
- PHP-Lint: **67 Dateien ohne Syntaxfehler**; `git diff --check` fehlerfrei.

Produktionsfreigabe weiterhin false, produktive Gerätezuordnung leer,
Transportsperre vor cURL unverändert. Keine produktive Installation, Cloud-Anfrage,
Roboterbefehle, proprietären Dateien, individuellen Kennungen, Commits oder Pushs.
Die fehlende Gerätezeit und die native Symcon-Laufzeit bleiben ungeprüfte Grenzen.

## Statuskorrektur nach statischer Pluginanalyse (11.11.2026, Basis 124a86f)

[Zustands- und Protokollbericht](../../docs/eureka-zustaende.md): konkrete
Shadow-Unterzustände vor Sammelgruppen, strenge Zielzuordnung, Erreichbarkeit nur
aus booleschem Shadow-`connected` mit konservativem Cache-Veto, Rohfehlererhalt
und getrennte B8-Offline-Auswertung. `state-test.php` prüft die vollständige
Shadow-/Parent-Matrix auch am Befehlsplaner, alle 256 B8-Arbeitsbytes und
Schlafuntertypen sowie beide Abfragerahmen als Daten. Keine aktive Abfrage.

Prüfung mit PHP 8.2.35; alle vier Laufzeitprüfstände unter
`bwrap --unshare-net --dev-bind / / -- …` ohne externe Netzwerkverbindung:

- Zustandsprüfungen: **575 bestanden, 0 Fehler**.
- Befehlsprüfungen: **233 bestanden, 0 Fehler**.
- Token-/GET-Prüfungen: **72 bestanden, 0 Fehler**.
- RoboHub-Gesamtprüfstand: **1.151 bestanden, 0 Fehler**.
- PHP-Lint: **66 Dateien ohne Syntaxfehler**; `git diff --check` fehlerfrei.

Die Geräteintegration prüft Zustand, Erreichbarkeit, Rohzustand, Fehler,
Schnappschuss, 30-Sekunden-/Basistakt und weiter gesperrte Befehle. Tokenlogik,
Timerimplementierung, Modul-IDs und Variablentypen bleiben erhalten.
`on_station` erlaubt mangels eindeutigen Befunds keinen vorbereiteten Neustart
mehr. Fehlendes Shadow-`connected` oder fehlende Fehlerentwarnung sperren
ebenfalls. Produktionsfreigabe false, produktive Zuordnung leer und
Transportsperre vor cURL bleiben erhalten. Kein Commit, Push oder Deployment.

Grenzen: Die Shadow-Namen sind Referenzwissen, die E20-Cloudkombinationen wurden
nicht live beobachtet. Ohne belegten Gerätezeitstempel ist die Gerätefrische
unbekannt; ein aktueller GET-Empfang beweist sie nicht. Symcon und Roboter wurden
ausschließlich lokal nachgebildet. `32 01` und `32 06` benötigen für diese
Korrektur keinen zusätzlichen aktiven Transport. Historische Ergebnisse folgen.

## Früherer lokaler Prüfstand (11.11.2026, Basis bb8a926)

Die drei Prüfstände laufen auf `codex-dev` mit
`bwrap --unshare-net --dev-bind / / -- …`. Externe Netzzugriffe sind damit
unterbunden; Loopback bleibt für die lokalen Roboter-Nachbauten verfügbar.
Die manuellen Cloud-Proben werden nicht ausgeführt.

- Eureka-Befehlstests: **230 bestanden, 0 Fehler**.
- Token-/GET-Tests: **72 bestanden, 0 Fehler**.
- RoboHub-Gesamttests: **1.140 bestanden, 0 Fehler** (einschließlich Parent-Regressionen).
- PHP-Lint: **64 Dateien ohne Syntaxfehler**.
- `git diff --check`: fehlerfrei, ebenso die separate Prüfung der neuen Datei.

Die neuen Fiber-Interleavings prüfen zwei unabhängige Anforderungen an dasselbe
Gerät (nur ein POST) und den gleichzeitigen Versand an ein anderes Gerät.
Weitere Prüfungen sichern Sperrtimeout, Freigabe auf Fehlerpfaden, Kontextänderung
nach Sperrerwerb sowie die echten lokalen Konto-Attribute ab. Der Konto-401-Race
verwendet zwei unterschiedliche Geräte, um weiterhin die gemeinsame zentrale
Token-Rotation bei unabhängigen Befehlen zu prüfen.

Unverändert: Freigabekonstante `false`, produktive Gerätezuordnung leer,
Transportsperre vor cURL und keine zusätzlichen Symcon-Aktionen. Start, Pause,
Resume und Station bleiben produktiv gesperrt. Keine Cloud-Anfrage, produktiver
IP-Symcon-Zugriff, Bereitstellung, Commit oder Push. Historische Prüfstände
weiter unten beziehen sich auf frühere Stände.

`token-test.php` prüft mit deterministischen Fiber-Interleavings die tatsächlichen Konto-Schreibpfade. Die Semaphore-Nachbildung hält getrennte Sperren und lässt konkurrierende Refreshs gezielt warten. Abmeldung und Anmeldung laufen während eines angehaltenen Cloud-Transports. Geprüft werden auch identische Tokens einer neuen Sitzung, verspätete Auth-Fehler, Rotation und Sperr-Timeouts. Alle Zugangsdaten sind erfundene Offline-Fixtures.

`.tools/test-eureka.inc.php` wird vom Gesamtprüfstand eingebunden. Er prüft Treiberauswahl, Herstellerregistrierung, Kontoanmeldung, Passwortverwerfen, Token-Persistierung, Geräteerkennung für Modell E20 Evo Plus, Konfigurator mit Virtual IO, Statusübernahme durch das Gerätemodul und die Abweisung von Steuerbefehlen. Nur der Transport wird ersetzt.

`cloud-probe.php` und `driver-probe.php` sind manuelle Live-Proben und gehören nicht zum Offline-Prüfstand. Sie wurden im Rahmen dieses Auftrags nicht ausgeführt.

## Vorbereitung des späteren kontrollierten IP-Symcon-Lesetests

Erst nach gesonderter Freigabe in einer Testinstanz mit diesem Arbeitsstand testen. Keine produktive Installation ist Teil dieses Auftrags.

1. Konto mit Hersteller Eureka und Region EU einrichten. Nach der Anmeldung prüfen, dass das Passwortfeld leer ist und das Konto aktiv wird.
2. Geräte suchen: Modell E20 Evo Plus muss im gemeinsamen Konfigurator erscheinen. Die Kette muss Gerät → Eureka-Konto → Virtual IO verwenden; Eureka benötigt kein MQTT.
3. Das Gerät über den Konfigurator anlegen und ausschließlich „Aktualisieren“ verwenden. Akku, Erreichbarkeit und Zustand mit der unveränderten Hersteller-App vergleichen.
4. Automatische Erneuerung beobachten. Anschließend Abmeldung und erneute Anmeldung kontrolliert prüfen: kein Wiedererscheinen eines alten Token-Bündels. Keine Token-Inhalte in Screenshots oder Logs übernehmen.
5. Start, Pause, Docking, Wartungsreset und Kartensteuerung nicht verwenden. Die Eureka-Steuerpfade bleiben gesperrt. Firmware und Hersteller-App bleiben unverändert.

Die Statusabbildung wurde inzwischen anhand der statischen Pluginanalyse erweitert,
siehe aktueller Prüfstand oben. Ein erfolgreicher Offline-Prüfstand ersetzt nicht
die Prüfung der IP-Symcon-Laufzeit und des echten Cloud-Protokolls. Ein Timeout
beim Speichern einer Cloud-Rotation kann eine erneute Anmeldung erfordern;
veraltete Token-Ergebnisse werden dabei niemals gespeichert.

## Prüfergebnis dieses Arbeitsstands (11.11.2026)

- Vollständiger RoboHub-Prüfstand: **1.021 bestanden, 0 fehlgeschlagen**, einschließlich 9 Eureka-Integrationsprüfungen und 49 zentralen MQTT-Prüfungen. Auch die weiteren Hersteller-MQTT-Prüfungen bestehen.
- Eureka-Token-Prüfstand: **55 bestanden, 0 fehlgeschlagen**.
- Syntax: **57 PHP-Dateien fehlerfrei**. `git diff --check`: fehlerfrei.
- Vor der Korrektur trat „Station mitten in der Reinigung“ auch im unveränderten Git-Stand auf. Ursache: Der Roomba-Nachbau behielt die extern entfernte `.nurfunk`-Steuerdatei im PHP-Stat-Cache. `clearstatcache()` im Nachbau behebt das Problem bei unveränderten Prüfaussagen und unverändertem iRobot-Treiber.
- Die zwei zuvor gemeldeten MQTT-Fehler waren im anfänglichen Vergleichslauf nicht reproduzierbar. Ohne damalige Testnamen/Protokolle lässt sich deren historische Ursache nicht eindeutig zuordnen. Aktuell gibt es keinen MQTT-Fehlschlag und keinen Hinweis auf eine Eureka-bedingte MQTT-Regression.
- Es wurden keine Live-Cloud-Proben, produktiven Installationen oder Zugriffe auf den produktiven IP-Symcon-Server durchgeführt. Kein Commit wurde erstellt.

Beide Token-Races hatten dieselbe Grundlage: Nur Refresh-Aufrufe verwendeten eine Sperre, andere Konto-Schreibpfade nicht. Alle Bündeländerungen laufen jetzt unter einer kurzen Attributsperre. Atomarer Vergleich von Bündel und Sitzungsrevision verhindert das Speichern nach Abmeldung oder Neuanmeldung, einschließlich einer Neuanmeldung mit identischen Tokens. Auch verspätete Auth-Fehler dürfen eine neue Sitzung nicht löschen. Die bestehende Refresh-Sperre serialisiert rotierende Refresh-Tokens; fehlgeschlagene Sperrübernahmen brechen sicher ab. Echtzeit-Neukonfiguration erfolgt erst nach Freigabe der Refresh-Sperre.

Zusätzlich musste die Eureka-Statusantwort an den bestehenden RoboHub-Vertrag angepasst werden: normalisierte Werte stehen unter `werte`, damit das Gerätemodul sie tatsächlich übernimmt.

**Einschätzung:** Der Arbeitsstand ist für den späteren kontrollierten, ausschließlich lesenden IP-Symcon-Test vorbereitet. Die verbleibenden Laufzeit- und Cloud-Risiken sowie die begrenzte Statusabbildung sind oben beschrieben.

## Parent-Korrektur: lokale Prüfung auf codex-dev (11.11.2026)

Konten ohne Konto-Echtzeit verwenden Virtual IO auch dann, wenn ihr bisheriger Client Socket von der Konsole benannt wurde. Konto und Konfigurator verwenden dieselbe Auswahlmethode `ROBOHUB_VerbindungsModul`. Modul-GUIDs, `module.json`, Adaptervertrag und Eureka-HTTPS-/Token-/Statuscode bleiben unverändert; Eureka-Steuerbefehle bleiben gesperrt.

Die Migration bereitet den Ersatz vor dem Trennen vor und erhält bestehende Parents unabhängig von deren Namen. Passende aktuelle, zuvor am Konto verwendete sowie freie aktive Virtual I/Os werden wiederverwendet. Bei Verbindungsfehlern wird die alte Verbindung wiederhergestellt. Scheitert auch der Rückfall, bleibt dessen Instanz-ID für die Wiederherstellung gespeichert. Nur ein gerade selbst erzeugter und unverbundener Fehlversuch wird aufgeräumt. Nach insgesamt drei fehlgeschlagenen Versuchen endet der Timer; nach Behebung der Ursache startet „Übernehmen“ einen neuen Versuch. Gemeinsam genutzte Echtzeit-I/Os werden nicht umkonfiguriert; das Konto erhält bei der Nachprüfung einen eigenen Parent.

`FM_CONNECT`/`FM_DISCONNECT` aktualisieren die Statusbeobachtung. Hersteller- und Parent-Wechsel verwerfen MQTT-/WebSocket-Laufzeitdaten; ein wiederverwendeter offener Echtzeit-Parent wird neu verbunden. Ohne Konto-Echtzeit werden eingehende Socket-Daten und Ping-Aufrufe ignoriert.

`.tools/test-parent.inc.php` ergänzt 49 Prüfungen: Migration ohne Namenspräfix, Wiederverwendung, Erhalt fremder/geteilter Parents, fehlgeschlagenes Erzeugen/Trennen/Verbinden, Exceptions und Rückfallfehler, begrenzte Wiederholungen, Sperrfehler, Konsolenrennen, Kernelstart, Nachrichtenbeobachtung, Herstellerwechsel Eureka ↔ Dreame/MOVA, WebSocket-Wechsel und Konfigurator-Auswahl. Bestehende iRobot-/Husqvarna-Prüfungen erwarten jetzt den Erhalt alter Parents statt deren Löschung oder Umbenennung.

Ausgeführt mit PHP 8.2.35. Die beiden Testläufe wurden mit `bwrap --unshare-net --dev-bind / / -- …` in einem eigenen Netzwerk-Namespace ausgeführt. Loopback blieb für lokale Roboter-Nachbauten verfügbar; externe Cloud- und Produktivsysteme waren unerreichbar.

- `php .tools/test-robohub.php`: **1.070 bestanden, 0 fehlgeschlagen**.
- `php tests/eureka/token-test.php`: **55 bestanden, 0 fehlgeschlagen**.
- PHP-Lint: **58 Dateien ohne Syntaxfehler**.
- `git diff --check`: fehlerfrei.

Grenzen: Der native IP-Symcon-9.1-Lebenszyklus wurde nicht live geprüft. Bei dauerhaft abgewiesenem Rückverbinden kann erst die gespeicherte Rückfall-ID beziehungsweise ein späterer erfolgreicher Versuch die Verbindung wiederherstellen. Alte I/Os und deren Konfiguration bleiben absichtlich erhalten. Ein Herstellerwechsel ersetzt keine Anmeldung für das neue Herstellerkonto. Die bestehende CI verwendet PHP 8.1; lokal wurde PHP 8.2 geprüft. Kein Deployment, Commit oder Push wurde ausgeführt.

## Verbleibende Kompatibilitätswarnung: lokale Korrektur (11.11.2026)

Die bestätigte Live-Diagnose zeigt einen aktiven, datenflusskompatiblen Eureka-Parent, während `IPS_GetCompatibleParents` ausschließlich Client Socket meldet. Im Stand `85a1781` ruft `Create()` bereits `RequireParent($this->elternModul())` auf. Bei der Erstinstallation ist der Hersteller allerdings noch Dreame: Die Vorgabe bleibt dadurch Client Socket, auch wenn die spätere Migration bereits Virtual IO verbunden hat. Laut [SDK-Dokumentation zu IPS_GetCompatibleParents](https://www.symcon.de/de/service/dokumentation/befehlsreferenz/instanzenverwaltung/verbindungen/ips-getcompatibleparents/) liefert ein eigener Callback die angebotenen Parent-Typen; andernfalls gilt die hinterlegte RequireParent-Vorgabe.

`RoboHubKonto::GetCompatibleParents()` verwendet jetzt direkt `elternModul()`: Virtual IO für Eureka und alle übrigen Hersteller ohne Konto-Echtzeit, Client Socket für Dreame/MOVA, WebSocket Client für Husqvarna/GARDENA. Der Typ bleibt `require`. Die [SDK-Struktur `modules` mit `initial`](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/getcompatibleparents/) setzt bei einem neu in der Konsole erzeugten Virtual IO `Open=true`, ohne vorhandene Instanzen zu verändern oder deren Konfiguration zu erzwingen.

Die RequireParent-Vorgabe in `Create()` entfällt. Das Modul verlangt bereits Symcon 9.0, der Callback ist ab 8.2 verfügbar. Die Konsole erhält damit auch nach einer Herstellerauswahl die passende Vorgabe. Ohne Konsolen-Parent planen `ApplyChanges()` beziehungsweise die Kernelstart-Nachricht weiterhin den bestehenden verzögerten Fallback. Dieser erzeugt, aktiviert und prüft einen neuen Virtual IO vor dem Trennen des bisherigen Parents. Bei Aktivierungsfehlern bleibt die alte Verbindung erhalten, nur der neue unverbundene Kandidat wird aufgeräumt, und die vorhandene begrenzte Wiederholung greift.

Die Regressionen ergänzen die Erstinstallation mit und ohne Konsole, die Herstellerauswahl nach `Create()`, alle 22 Hersteller mit echten Treibern, die Übereinstimmung von angebotenen und verbundenen Parent-Typen nach Herstellerwechseln, die einmalige Aktivierung sowie abgewiesene, geworfene und wirkungslose Aktivierungsversuche. Der Virtual-IO-Nachbau startet jetzt wie die native Instanz mit `Open=false` und Status 104. Vorhandene fremde oder geteilte Virtual I/Os werden weiterhin nicht ungefragt aktiviert.

Ergebnis auf `codex-dev`, PHP 8.2.35, beide Prüfstände mit `bwrap --unshare-net --dev-bind / / -- …` gegen externe Netzzugriffe isoliert:

- Vollständiger RoboHub-Prüfstand: **1.138 bestanden, 0 fehlgeschlagen**, davon **68 neue Regressionen** zusätzlich zu den bisherigen 1.070 Prüfungen.
- Eureka-Token-Prüfstand: **55 bestanden, 0 fehlgeschlagen**.
- PHP-Lint: **58 Dateien ohne Syntaxfehler**; `git diff --check`: fehlerfrei.

Die Datenfluss-GUIDs und sämtliche `module.json` sowie Eureka-HTTPS und Token-Verwaltung bleiben unverändert. Die native IP-Symcon-9.1-Konsole wurde nicht ausgeführt; die Übernahme von `initial` und das Verschwinden der Warnung sind dort noch zu bestätigen. Ein bereits vorhandener inaktiver Virtual IO bleibt zum Schutz bestehender Konfigurationen inaktiv. Keine Cloud-Probe, Änderung produktiver Instanzen, Bereitstellung, Commit oder Push.

### Erreichbarkeitsergänzung

`state-test.php` prüft zusätzlich die dreistufige Anzeige aus explizitem Shadow-
oder maximal 120 Sekunden altem Listenbefund (beide Vorzeichen, Grenzalter und
ungültige Zeiten). `.tools/test-eureka.inc.php` prüft die zwei neuen Variablen,
Abruffehler, unverändertes GetActivity und die Herstellerisolation im echten
Konto-/Gerätepfad mit ersetztem Transport. Bedeutung und Grenzen:
[Statusdokumentation](../../docs/eureka-zustaende.md#erreichbarkeit-gerätebindung-und-alter).
