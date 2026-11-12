# Lokale B8-Laufzeitanzeige (12.11.2026)

Ausgangsbasis: Branch `feature/eureka-e20-evo-plus`, HEAD `66787e6`.
Der gemeldete Fehler entstand, weil `EurekaB8LaufzeitAnzeigen()` sowohl
`eurekaB8Kontext()` als auch `RoboHubEurekaDiagnose::pruefen()` aufrief.
Damit verlangte die reine Anzeige gültige Tokens, lokale Geräte-/Shadow-Daten
und Frische innerhalb 120 Sekunden. Der Kontext initialisierte außerdem bei
leerem Buffer die zufällige Laufzeitkennung.

Die Anzeige liest jetzt ausschließlich Kernelstart und den bestehenden
Laufzeitbuffer. Konto 45174, Hersteller Eureka, Region EU, Kernelbereitschaft
und die lokale Modul-/Kontozuordnung von Instanz 22708 bleiben geprüft.
Die Zuordnungsprüfung ist als gemeinsamer Helfer extrahiert; der vollständige
Diagnosekontext verwendet dieselbe Prüfung weiter. Die Anzeige verlangt jetzt
zusätzlich einen nativ bestätigten angemeldeten Administrator, vor und innerhalb
der vorhandenen Sperren. Fehlende APIs, unbekannter Benutzer oder fehlende
Administratorrechte sperren. Diese Prüfung war bisher nur beim Import vorhanden.

Der Buffer muss eine bestehende 64-stellige hexadezimale Kennung enthalten.
Fehlt er oder ist er ungültig, folgt eine neutrale Fehlermeldung ohne
Initialisierung. Damit bleibt die Anzeige auch im Fehlerfall rein lesend.
Eine Erstinitialisierung ist weiterhin nur über die schon vorhandenen separaten
Kontextwege möglich; die Anzeige führt diese nicht selbst aus. Sie erzeugt
weder Genehmigung noch Ticket oder Freigabe und protokolliert keine Kennung.
Die vollständige Laufzeitkennung wird ausschließlich als angeforderter Rückgabewert
an den Administrator geliefert.

Import, Ticketausstellung und Transport verwenden unverändert den vollständigen
Kontext samt Frischeprüfung. Die normalen Eureka-Befehle bleiben gesperrt.
Keine Cloud-Anfrage, produktive Änderung, Roboteraktion, Commit, Push oder
Bereitstellung wurde durchgeführt. Native IP-Symcon-9.1-Ausführung bleibt ungeprüft.

Die neuen Offline-Fälle prüfen alte, fehlende und defekte Cache-/Shadow-Daten,
fehlende Sitzung, Cloud-Sperre, fehlende/ungültige Laufzeitbuffer,
Administratorrechte, unbekannte Benutzer, Konto/Hersteller/Region, Kernelstart,
Kernelbereitschaft und lokale Gerätezuordnung. Erfolgs- und Fehlerfälle prüfen
unveränderte Attribute/Buffer sowie leere Logs und Transportlisten.
Die vorhandenen Import-Unterprozesse prüfen auch fehlende native APIs für die
Anzeige. Signatur-Fixtures bauen die erwartete Kennung direkt aus Testdaten,
damit ihre Vorbereitung keine Administratorprüfung des getesteten Benutzers auslöst.

Ein Zwischenlauf scheiterte im allgemeinen iRobot-UDP-Koppeltest
(Loopback-Port 5678), während zwei Suite-Läufe überlappten. Der erste Lauf
bestand; wegen möglicher Konkurrenz der lokalen Testserver wurde anschließend
die gesamte Suite nochmals allein ausgeführt. Keine produktive Transportdatei
wurde für diesen Befund verändert.

Abschließender einzelner Lauf: `bash tests/eureka/run-offline.sh`, Exitcode 0.
192 Diagnose-/Bereitstellungsprüfungen, 19 Import-Kontextprüfungen,
18 HTTPS-Double-Prüfungen, 21 native HTTPS-Gegenversuche, 233 Befehlsprüfungen,
683 Zustandsprüfungen und 72 Token-/GET-Prüfungen bestanden. Auch der vollständige
allgemeine RoboHub-Prüfstand bestand. Alle 77 PHP-Dateien sind syntaxfehlerfrei;
`git diff --check` ist fehlerfrei. Die neuen lokalen Anzeigenfälle umfassen
17 zusätzliche Prüfungen gegenüber der Ausgangsbasis. Verbleibende Grenze:
native Ausführung unter IP-Symcon 9.1 wurde auftragsgemäß nicht durchgeführt.
Nächster Schritt: Review des lokalen Diffs; kein Deployment Bestandteil dieses Auftrags.
