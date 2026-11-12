# Private lokale Bereitstellung auf dem Symcon-Pi

## Ermittelter Iststand am 12.11.2026

Nur lesend per SSH `admin@10.10.70.6` untersucht; kein JSON-RPC, kein Cloudzugriff.
Symcon-Paket `9.1-832`, Dienst `symcon`, sudo ohne Passworteingabe verfügbar.
RoboHub liegt in `/var/lib/symcon/modules/RoboHub`, kein Symlink, sauberer
Git-Checkout `517d682` auf `feature/eureka-e20-evo-plus`. Origin verweist auf
`https://github.com/ragepw/RoboHub.git`. Bibliotheks-ID:
`{F4D2E22D-E5F0-444D-A2FF-359C105E74FB}`. Datenumfang etwa 738 MiB,
freier Speicher etwa 108 GB. Der Modulordner ist derzeit Modus 0777.
Konfiguration liegt in `/var/lib/symcon/settings.json`; Inhalte wurden nicht ausgegeben.

Die [offizielle Module-Control-Dokumentation](https://www.symcon.de/de/service/dokumentation/modulreferenz/kern-instanzen/module-control/)
beschreibt Git-URL, Branch und Update. Ein Klick auf Aktualisieren würde hier
weiterhin den GitHub-Ursprung verwenden; damit wird der private lokale Commit
nicht übertragen. Symcon unterstützt auch das erneute Laden lokaler Module per
`MC_ReloadModule`, dokumentiert in den
[offiziellen Versionshinweisen](https://www.symcon.de/de/service/dokumentation/installation/migrationen/v40-v41-q4-2016/).
Dessen Ausführung wurde hier nicht geprüft. Ein neues Modul-Control-Repository
oder eine Änderung der Bibliotheks-ID ist für diesen Auftrag unnötig.

## Vorbereiteter Weg

Das Skript `.tools/deploy-eureka-local.sh` verwendet ein inkrementelles Git-Bundle
vom bestehenden Basiscommit bis exakt `6fefd79`. Git-Bundles und temporäre
Arbeitskopien bleiben außerhalb des Repositories in `~/private`, Ordner 0700,
Bundle 0600. Die Gerätekennung wird weder als Argument noch als Ausgabe verwendet.
Ein Bundle ist vertraulich wie der Quelltext und darf nicht veröffentlicht werden.
Kein HTTP-Server, GitHub-Push, Download, Cloud-Aufruf oder Roboterbefehl ist eingebaut.

Vorbereitung auf codex-dev (bereits ausgeführt):

```bash
cd ~/projekte/robohub-eureka
bash .tools/deploy-eureka-local.sh --prepare
```

Erst beim später ausdrücklich beauftragten Produktiveinsatz:

```bash
cd ~/projekte/robohub-eureka
bash .tools/deploy-eureka-local.sh --apply
```

`--apply` erzeugt das private Bundle erneut und überträgt es mit bekanntem SSH-
Hostschlüssel an den Pi. Vor jeder Änderung verlangt es unverändert sauberen
Basiscommit und Branch. Dort wird es unter `/root/robohub-deploy.*` abgelegt.
Der Dienst wird gestoppt und `/var/lib/symcon` vollständig als privates Archiv
`/root/robohub-deploy.*/symcon-before.tar` gesichert. Danach werden die Modul-
und Gitdateien vor dem Import ausschließlich für root zugänglich gemacht.
Der lokale Bundle-Import und Fast-forward verändern die bestehende Bibliothek;
Instanzen, Objekt-IDs, Konfiguration, Attribute und Skripte werden nicht ersetzt.
Git-Hooks sind deaktiviert, Git-Netzwerkprotokolle gesperrt. Anschließend startet
Symcon neu; Module Control muss nicht auf Aktualisieren geklickt werden.
Währenddessen ist die gesamte Symcon-Steuerung kurz unterbrochen. Nach dem Start
können bestehende Lesetimer reguläre Cloudabfragen ausführen; dies gehört erst
zum späteren Produktiveinsatz, nicht zur jetzigen Vorbereitung.

Bei einem Fehler nach dem Stoppen bleibt der Dienst bewusst gestoppt. Keine
blinde Wiederholung: privaten Sicherungspfad und Gitstand prüfen. Für Rückkehr
zum vorherigen Stand den Dienst gestoppt lassen, den fehlgeschlagenen RoboHub-
Ordner privat unter `/root` weglegen und das gesicherte `symcon/modules/RoboHub`
aus dem Archiv unter `/var/lib` wiederherstellen. Falls die neue Laufzeit bereits
Konfiguration geschrieben hat, auch die Konfiguration aus derselben vollständigen
Sicherung zurückholen. Erst danach Symcon wieder starten. Keine Instanzen löschen.

Origin bleibt GitHub; der individuelle Commit darf auch vom Pi aus nicht gepusht
werden. Spätere normale GitHub-Updates können diesen lokalen Zweig nicht ersetzen:
vorher private Gerätebindung und Aktualisierungsweg erneut prüfen. Sicherungen und
Importprotokolle liegen root-exklusiv außerhalb von Webverzeichnissen; keine
ungefilterten Quelltext-, Diff- oder Logausgaben erstellen.

## Offlineprüfung und anschließende Bedienung

Shellsyntax geprüft, Bundle verifiziert und Fast-forward von `517d682` nach
`6fefd79` in einer privaten lokalen Wegwerfkopie erfolgreich durchgeführt;
der resultierende Arbeitsbaum war sauber. Kein produktiver Import, Reload oder
Dienstneustart wurde ausgeführt. Der vorhandene vollständige Prüfstand
`bash tests/eureka/run-control-offline.sh` bestand mit Netzisolierung:
Exitcode 0, keine fehlgeschlagenen Prüfungen, 92 PHP-Lints bestanden.

Nach der späteren Bereitstellung Konto 45174, Gerät 22708 und Variable 22284
kontrollieren; Einmalverbrauch und Befehlsverbrauch niemals zurücksetzen.
Startparameter bewusst wählen und vor jeder manuellen Aktion Liste und Zustand
frisch abrufen. Beaufsichtigte Folge und Abbruchkriterien stehen in der
[Bedienanleitung](eureka-regulaere-steuerung.md#spätere-aktualisierung-und-funktionstest--jetzt-nicht-ausführen).

## Kombinierte Bereitstellung und Store-Bereinigung am 12.11.2026

Für den jetzt ausdrücklich beauftragten Vorgang gilt Basis `6fefd79` und als Ziel
der neue lokale Commit mit der Eureka-Parent-Korrektur. `--prepare` bündelt nun
den vollständig committeten Branch-HEAD inkrementell ab dieser Basis. Es wird
kein Git-Remote verwendet. `--apply` behält seine Abbruchsperre bei doppelten
Registrierungen; für die bekannte Doppelinstallation wird das vorbereitete
Bundle im folgenden kontrollierten Ablauf privat per SSH übertragen.

Vor Moduländerungen werden Live-Inventar, Instanz-/Virtual-IO-IDs, Eigenschaften,
Verbindungen und vorhandene Sitzungs-/Verbrauchsattribute privat aufgenommen.
Der aktuelle Checkout muss sauber auf der Basis stehen und beide Bibliotheken
müssen genau den bekannten Pfaden und Metadaten entsprechen. Die vertrauliche
Gerätebindung wird nur intern auf Gleichheit geprüft. Ohne gespeichertes
Eureka-Tokenbündel kann der vorhandene Abruf-/Erneuerungspfad keine authentisierte
Cloudaktion auslösen; der vorhandene Socket muss geschlossen sein. Keine
Anmeldung, Aktualisierung, Hersteller-Suche oder Roboteraktion wird aufgerufen.

Vor dem Stoppen entsteht unter einem root-exklusiven Verzeichnis außerhalb der
Modulsuchpfade ein vollständiges Archiv von `/var/lib/symcon` sowie der
zugehörigen Dienst-/PHP-Konfiguration. Archivlesbarkeit und Prüfsumme werden
verifiziert. Nach kontrolliertem Stoppen entsteht zusätzlich die maßgebliche
konsistente Vollsicherung; ein vollständiger Archivvergleich mit dem Dateisystem
und eine private Probeextraktion verifizieren den Rückweg. Erst danach erfolgen
Bundle-Fast-forward bei deaktivierten Hooks/Netzwerkprotokollen und die
Verschiebung ausschließlich von `modules/.store/eu.dg.robohub` als ganzem
Verzeichnis in denselben privaten Sicherungsbereich. Die übrigen Module bleiben
unverändert. Vor Start müssen genau eine RoboHub-Bibliothek und ihre drei Module
vorliegen; nach Start werden geladene Version, Konto-/Geräteverbindung,
Befehlsvariable, Instanzbestand, Token-/Eigenschaftserhalt und Eureka-Formauswahl
ohne Cloudprobe geprüft. Der Migrationstimer muss auch nach 15 Sekunden aus sein.

Rückweg bei einem Fehler: Symcon kontrolliert gestoppt halten bzw. erneut stoppen.
Das aktuelle `/var/lib/symcon` als Ganzes privat weglegen, die konsistente
Vollsicherung unter `/var/lib` vollständig wiederherstellen und vor Start gegen
das Archiv prüfen. Die separat ausgelagerte Store-Kopie und fehlgeschlagene
Installation bleiben privat erhalten. Erst den nachgewiesenen ursprünglichen
Datei-/Registrierungszustand wieder starten; keine Instanzen oder Zugangsdaten
manuell reparieren. Schlägt die verifizierte Wiederherstellung fehl, bleibt
Symcon gestoppt. Keine ungesicherten Folgeschritte.
