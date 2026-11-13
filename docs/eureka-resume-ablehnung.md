# Ablehnung nach automatischem Refresh (13.11.2026)

Lokale Basis: Branch `feature/eureka-e20-evo-plus`, HEAD `fec4cbb` mit
vorhandenen uncommitteten B8-Änderungen. Keine Cloudabfragen oder Roboterbefehle.

## Befund

Die Meldung „Zustand unbekannt, widersprüchlich oder fehlerhaft“ stammt aus
`RoboHubEurekaBefehlsweg::vorbereiten()`. Sie umfasst genau drei Bedingungen:
nicht belegte Befehlsgruppe, inkonsistente Shadow-Zustandskombination oder
fehlendes/nicht explizit leeres `deviceFault`. Sie stammt weder von der
Akkuprüfung noch von der Cloud. Bei dieser Ablehnung wird kein POST ausgelöst.

`pause / pause` ist konsistent und wird beim Bedienbefehl `start` ausdrücklich
zu `resume` mit dem bestehenden Fortsetzen-Rahmen. Ein neuer Auftrag verwendet
einen anderen Rahmen und benötigt gültige Startparameter sowie Integer-Akku
über 15 bis 100. Resume verlangt nach bestehendem Herstellervertrag keinen
Akku-Mindestwert und keine neuen Saug-/Wasserwerte. `cleanMissionState` ist für
diese normale Pause kein zusätzliches Freigabekriterium; für die gesonderte
ERP-Schlafauswertung ist es dagegen zusammen mit frischen B8-Daten erforderlich.
Die belegten Sonderpausen bleiben für reguläres Resume gesperrt.

Der automatische Weg liest nach einer angenommenen Pause und anschließender
anderer Bedienaktion einmal Shadow nach. `zustand()` reicht den durch `ziel()`
zugeordneten GET-Eintrag unverändert als `roh` weiter. `zustandLesen()` versieht
ihn mit lokalen Empfangs-/Sitzungs-/Laufzeitdaten; `schnappschussMerken()` ersetzt
den gesamten Eintrag. `eurekaSteuerKontext()` liest diesen neu. Es findet kein
Merge alter und neuer Shadowfelder statt. Geräteliste und Shadow bleiben
getrennte Quellen; beide behalten ihre eigenen Vetos.

Ein partielles GET-Objekt kann als erfolgreich empfangen gespeichert werden.
Das bedeutet keine Vollständigkeit oder Befehlsfreigabe: fehlender Zustand oder
Fehlernachweis wird anschließend abgewiesen. Ein altes `deviceFault=[]` wird
nicht ergänzt. Die lokale Empfangszeit beweist weiterhin keine Gerätefrische.

Der tatsächlich um 08:31:07 ausgewertete Shadow liegt für diese Untersuchung
nicht vor. Der zuvor beobachtete Pausezustand beweist nicht den Inhalt des
späteren GET. Falls dieser ebenfalls exakt `pause / pause` enthielt, muss die
konkrete Sammelablehnung an fehlendem oder nicht explizit leerem `deviceFault`
gelegen haben. Welche Variante live vorlag, ist offline nicht nachweisbar.
Eine Änderung der Sicherheitsbedingungen wäre daher unbegründet.

## Änderung und Diagnose

Die identischen Bedingungen geben nun zusätzlich ausschließlich feste lokale
Gründe aus: `zustandsgruppe-nicht-belegt`, `state-parent-unklar`,
`deviceFault-fehlt`, `deviceFault-nicht-leer-oder-ungueltig`. Mehrere Gründe
bleiben gemeinsam sichtbar. Keine Rohwerte, Kennungen oder Cloudfreitexte
werden dazu ausgegeben; es gibt keinen zusätzlichen Abruf oder Diagnosebefehl.
Start-/Resume-Auswahl, erlaubte Zustände und Transportprüfungen sind unverändert.

## Offlineprüfung und nächster Schritt

Der native Prüfstand ergänzt 16 Varianten der Folge Pause → automatischer
Refresh → Start/Fortsetzen, einschließlich vollständiger Pause, fehlender und
ungültiger Pflichtfelder, widersprüchlichem Parent, Akku-/Zusatzstatuslücken
sowie sleep, cleaning, charging und stop. Er vergleicht den gespeicherten
Shadow exakt mit dem neuen GET und kontrolliert ausbleibende Befehle bei
Ablehnung sowie Diagnose ohne simulierte vertrauliche Werte oder Kennungen.
Ein separater Planertest prüft vollständiges `pause / pause` und den Resume-Rahmen.

Nächster Schritt außerhalb dieses lokalen Auftrags: den festen Ablehnungsgrund
eines gesondert autorisierten Versuchs auswerten. Keine pauschale Freigabe und
keine Ergänzung fehlender Felder aus alten Daten. Bereitstellung oder Live-Test
wurden hier weder vorgenommen noch freigegeben.

Validierung: `bash tests/eureka/run-control-offline.sh` vollständig mit Exitcode
0 und Netzisolierung bestanden, einschließlich Gesamtprüfstand, 365 Befehls-,
683 Zustands-, 72 Token-/GET-Prüfungen sowie B8-/HTTPS-/nativen Gegenproben.
Die 32 neuen Refresh-Assertions und der neue vollständige Pause-Planertest
bestanden. 94 PHP-Dateien ohne Syntaxfehler; `git diff --check` fehlerfrei.
Vorhandene B8-Änderungen unverändert erhalten; keine Produktivänderung,
Freischaltung, Commit oder Push.
