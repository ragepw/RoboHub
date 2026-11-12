# Fehlende Eureka-Auswahl nach lokaler Bereitstellung

Am 12.11.2026 ausschließlich lesend per SSH untersucht. Der saubere Checkout
`/var/lib/symcon/modules/RoboHub` steht auf `6fefd79`. Konto-, Formular- und
Eureka-Treiberdatei sind per SHA256 identisch zum lokalen Stand. Herstellerliste,
Formularaufbau und Modulregistrierung unterscheiden sich gegenüber `517d682`
nicht. Beide Stände bieten den gespeicherten Stringwert `eureka` an.

Zusätzlich existiert `/var/lib/symcon/modules/.store/eu.dg.robohub`, Version
0.35.2, Build 93, mit derselben Bibliotheks-ID und Konto-Modul-ID. Dort fehlen
Eureka-Auswahl und Eureka-Treiberzuordnung. Das erklärt die gemeldete Auswahl:
Sie entspricht dem alten Store-Code. Die tatsächlich geladene Laufzeit wurde
nicht per RPC abgefragt; die Reihenfolge doppelter Registrierungen wird nicht
als verlässlicher Vertrag angenommen.

Die gespeicherte Konfiguration enthält weiterhin `Hersteller=eureka`, die
Verbindung von Gerät 22708 zu Konto 45174 und eine Gerätekennung. Werte und
Zugangsdaten wurden nicht ausgegeben oder verändert. Persistierte Einstellungen
sind kein aktueller Laufzeitstatus; aus ihnen wird kein Tokenzustand abgeleitet.

## Minimaler Reparaturweg für einen späteren, gesonderten Auftrag

Keine Änderung am Hersteller-JSON oder an Modul-/Bibliotheks-IDs erforderlich.
Nach vollständiger privater Sicherung und bei gestopptem Symcon die alte
Store-Kopie als Ganzes außerhalb sämtlicher Symcon-Modulsuchpfade sichern.
Den vorhandenen Checkout `modules/RoboHub` als einzige Registrierung erhalten.
Keine Store-Deinstallation, keine Instanzen löschen, keine Eigenschaften ändern,
keine Cloud-ID ersetzen und keine Tokenattribute zurücksetzen. Anschließend
Registrierungsprüfung ausführen und vor einem späteren Neustart berücksichtigen,
dass bestehende Timer Cloudzugriffe auslösen können. Dieser Auftrag erlaubt
weder diese Reparatur in Produktion noch Neustart oder Deployment.

Die lokale Bereitstellung bricht nun bei doppelten RoboHub-IDs einschließlich
des versteckten Store-Verzeichnisses vor produktiven Änderungen ab. Nur lesend:
`sudo python3 /pfad/zur/check-robohub-installation.py /var/lib/symcon/modules`.
Die aktuelle Produktionsinstallation muss dabei mit Exitcode 1 erkannt werden.

## HTTP 401 getrennt bewerten

Der alte Store-Code fällt bei unbekanntem `eureka` auf
`new RoboHubDreame($hersteller)` zurück. Deshalb ist ein 401 unter dieser
Doppelinstallation kein Nachweis eines abgelaufenen Eureka-Refresh-Tokens.
Vor einem erneuten Login muss die geladene Registrierung eindeutig sein.
Der aktuelle Eureka-Code erneuert nach einem GET-401 zentral und persistiert
die Rotation vor genau einem erneuten GET. Erst ein Auth-Endpunkt-401 kann
die zugehörige Sitzung invalidieren. Ein weiterer Daten-401 löscht keine Tokens;
Befehls-POSTs werden nicht automatisch wiederholt. Offline-Tokenregressionen
prüfen diese Unterscheidung. Die konkrete produktive 401-Ursache bleibt ohne
Live-Probe offen; es wurde keine durchgeführt und kein Login ausgelöst.
Login-, Refresh- und GET-401-Behandlung wurden zwischen `517d682` und
`6fefd79` nicht geändert. Ein durch diesen Commit eingeführter Authentifizierungs-
Regressionsfehler ergibt sich aus dem Vergleich nicht.

## Offlineprüfung

`bash tests/eureka/run-control-offline.sh` erzwingt Netzwerkisolierung und nutzt
vorhandene Transport-/Symcon-Doubles. Ergänzt sind echte JSON-Auswahlprüfung,
Erhalt der Konto-Eigenschaften/-Attribute beim Formularabruf und Fixtures für
Einzelinstallation sowie versteckte doppelte Registrierung. Kein neues Framework.

Abschlusslauf: Exitcode 0, alle Prüfstände bestanden, darunter 72 Tokenfälle,
22 Struktur-Gegenproben und beide neuen Formularprüfungen; 92 PHP-Lints und
`git diff --check` fehlerfrei. Die ausschließlich lesende Registrierungsprüfung
erkannte produktiv die doppelte Bibliothek und alle drei doppelten Module.
Keine produktiven Dateien, Instanzen oder Sitzungen wurden verändert.

## Lokale Korrektur der automatischen Virtual-IO-Migration

Der anschließende produktive Bereinigungsauftrag wurde vor Änderungen
abgebrochen: Konto 45174 hängt am vorhandenen Client Socket 37512; ein Virtual IO
existiert nicht. Das alte Store-Modul bleibt geladen. Es wurde weder eine neue
Sicherung angelegt noch etwas verschoben oder neu gestartet.

Der genaue Auslöser im Checkout `6fefd79` ist:

1. `RoboHubKonto::ApplyChanges()` läuft bei `KR_READY`, auch über `MessageSink`
   nach dem Kernelstart. `RoboHubEureka::echtzeitMoeglich()` liefert `false`.
2. `echtzeitArt()` ergibt daher den leeren String; `elternModul()` wählt Virtual IO.
   Der vorhandene Client Socket entspricht diesem Solltyp nicht. `ApplyChanges()`
   aktiviert deshalb den Timer `Verbindung` mit 15000 ms. Auch eigene
   `FM_CONNECT`-/`FM_DISCONNECT`-Nachrichten können diesen Timer einplanen.
3. `VerbindungPruefen()` ruft `verbindungSicherstellen()` auf. Ohne bekannten oder
   freien aktiven Virtual IO erreicht es `IPS_CreateInstance($modul)`, aktiviert
   den neuen I/O und hängt das Konto um. Der alte Socket wird dabei erhalten.

Für Eurekas HTTPS-Grundsteuerung ist dieses I/O kein Transportbestandteil.
`RoboHubGeraet::frageKonto()` adressiert das vorhandene Konto, dessen `ForwardData()`
den Eureka-Treiber verwendet. Status und Befehle laufen dort über HTTPS;
`SendDataToParent()` des Kontos wird nur für Echtzeitdaten verwendet. Der Virtual IO
dient der vollständigen Kette bei der expliziten Konto-/Geräteneuanlage in der
Konsole. Die bestehende Verbindung Gerät 22708 → Konto 45174 benötigt dafür
keine Migration und keine zusätzliche Instanz.

Die lokale Korrektur unterbindet ausschließlich für `Hersteller=eureka` die
automatische Parent-Migration: `ApplyChanges()` und eigene Flow-Nachrichten
schalten `Verbindung` aus; `VerbindungPruefen()` beendet sich auch bei einem
alten Timer oder direkten Aufruf vor jeder Migration. Vorhandene Parents,
deren Konfiguration und die Geräteverbindung bleiben erhalten. Ohne Parent wird
ebenfalls keiner automatisch erzeugt. Andere Hersteller behalten den bisherigen
Pfad einschließlich Wiederverwendung, Aktivierung, Rückfall und begrenztem Retry.
Die deklarative Parent-Vorgabe und die Konfigurator-Kette für ausdrücklich neue
Instanzen bleiben unverändert; diese Neuanlage gehört nicht zur Bestandsreparatur.
Tokenbehandlung, individuelle Gerätebindung, Befehlsfreigaben und Einmalverbräuche
werden nicht verändert.

Die Offline-Regression verwendet die bestehenden Objekt-IDs mit ausschließlich
erfundenen Geräte-/Sitzungsdaten und prüft einen alten inaktiven Socket, einen
geteilten Socket, einen vorhandenen inaktiven Virtual IO und ein Konto ohne Parent.
Geprüft werden `Create`, Start vor/nach `KR_READY`, wiederholtes `ApplyChanges`,
Flow-Nachrichten, alte Migrationstimer, sämtliche sechs registrierten Konto- und
Gerätetimer sowie Konto-/Geräte-/Konfiguratorformulare. Instanzbestand,
Erzeugungs-/Löschaufrufe, Verbindungen und I/O-Konfiguration werden verglichen.
Der Statusabruf funktioniert im Double ohne aktives I/O; Sitzung, Properties,
Einmalverbrauch und fehlende administrative Laufzeitfreigaben bleiben erhalten.
Die generischen Migrationsregressionen laufen weiterhin mit Roborock als anderem
Hersteller ohne Echtzeit. Ein nativer Symcon-Lauf mit der Korrektur steht noch aus.

Der vollständige Lauf `bash tests/eureka/run-control-offline.sh` in eigener
Netzwerk-Namespace endete mit Exitcode 0: alle Prüfstände bestanden, darunter
65 neue Eureka-Parent-Prüfungen und 93 PHP-Lints. Vor der Korrektur schlugen die
neuen Regressionen gegen den bisherigen Start-/Timerpfad erwartungsgemäß fehl.
Die bestehenden B8-, Dock-, Pause-, Steuerungs-, Token- und Installationsprüfungen
bleiben Bestandteil desselben vollständigen Laufs; `git diff --check` ist sauber.

Nächster Produktivschritt in einem gesonderten Auftrag: den korrigierten lokalen
Stand für eine private Übertragung festlegen und den Bereinigungsablauf darauf
ausrichten. `6fefd79` allein enthält diese Korrektur nicht; das bisherige
Bereitstellungsskript zielt fest auf diesen alten Commit und ist dafür unverändert
nicht geeignet. Nach erneutem Istabgleich und verifizierter privater Vollsicherung
mit Rückweg im kontrollierten Wartungsfenster Symcon kontrolliert stoppen,
den korrigierten Code übernehmen,
die alte Store-Kopie als Ganzes außerhalb aller Modulsuchpfade sichern und genau
eine Bibliothek mit drei Modulen nachweisen. Vor dem Start aktive Timer und
mögliche Cloudzugriffe erneut berücksichtigen. Danach Dienst, native
Instanzanzahl, Verbindungen, Eigenschaften, Eureka-Auswahl und geladenen Stand
prüfen, ohne Instanzen anzulegen oder Anmelde-/Befehlsaktionen auszuführen.
Ein möglicher Loginbedarf ist erst mit eindeutig geladenem Eureka-Code zu bewerten.
