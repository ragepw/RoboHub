# Eureka E20 Evo Plus: Offline-Prüfung und späterer Lesetest

Alle automatischen Eureka-Prüfungen laufen ohne Hersteller-Cloud:

```sh
php tests/eureka/token-test.php
php .tools/test-robohub.php
find . -name '*.php' -print0 | xargs -0 -n1 php -l
git diff --check
```

`token-test.php` prüft mit deterministischen Fiber-Interleavings die tatsächlichen Konto-Schreibpfade. Die Semaphore-Nachbildung hält getrennte Sperren und lässt konkurrierende Refreshs gezielt warten. Abmeldung und Anmeldung laufen während eines angehaltenen Cloud-Transports. Geprüft werden auch identische Tokens einer neuen Sitzung, verspätete Auth-Fehler, Rotation und Sperr-Timeouts. Alle Zugangsdaten sind erfundene Offline-Fixtures.

`.tools/test-eureka.inc.php` wird vom Gesamtprüfstand eingebunden. Er prüft Treiberauswahl, Herstellerregistrierung, Kontoanmeldung, Passwortverwerfen, Token-Persistierung, Geräteerkennung für Modell 750Y0015, Konfigurator mit Virtual IO, Statusübernahme durch das Gerätemodul und die Abweisung von Steuerbefehlen. Nur der Transport wird ersetzt.

`cloud-probe.php` und `driver-probe.php` sind manuelle Live-Proben und gehören nicht zum Offline-Prüfstand. Sie wurden im Rahmen dieses Auftrags nicht ausgeführt.

## Vorbereitung des späteren kontrollierten IP-Symcon-Lesetests

Erst nach gesonderter Freigabe in einer Testinstanz mit diesem Arbeitsstand testen. Keine produktive Installation ist Teil dieses Auftrags.

1. Konto mit Hersteller Eureka und Region EU einrichten. Nach der Anmeldung prüfen, dass das Passwortfeld leer ist und das Konto aktiv wird.
2. Geräte suchen: Modell 750Y0015 muss im gemeinsamen Konfigurator erscheinen. Die Kette muss Gerät → Eureka-Konto → Virtual IO verwenden; Eureka benötigt kein MQTT.
3. Das Gerät über den Konfigurator anlegen und ausschließlich „Aktualisieren“ verwenden. Akku, Erreichbarkeit und Zustand mit der unveränderten Hersteller-App vergleichen.
4. Automatische Erneuerung beobachten. Anschließend Abmeldung und erneute Anmeldung kontrolliert prüfen: kein Wiedererscheinen eines alten Token-Bündels. Keine Token-Inhalte in Screenshots oder Logs übernehmen.
5. Start, Pause, Docking, Wartungsreset und Kartensteuerung nicht verwenden. Die Eureka-Steuerpfade bleiben gesperrt. Firmware und Hersteller-App bleiben unverändert.

Die Statusabbildung ist noch begrenzt: `cleaning` und `sleep` sind zugeordnet; andere Zustände bleiben unbekannt. Ein erfolgreicher Offline-Prüfstand ersetzt nicht die Prüfung der IP-Symcon-Laufzeit, des echten Cloud-Protokolls und der weiteren App-Zustände. Ein Timeout beim Speichern einer Cloud-Rotation kann eine erneute Anmeldung erfordern; veraltete Ergebnisse werden dabei niemals gespeichert.

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
