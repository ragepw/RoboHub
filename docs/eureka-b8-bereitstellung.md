# Administrative B8-Bereitstellung – lokaler Abschlussbericht

12.11.2026 · Branch `feature/eureka-e20-evo-plus` · Ausgangscommit der Root-Anpassung `aeb7656`.
Dieser Stand wurde ausschließlich lokal implementiert und offline getestet.
Keine konkrete Laufzeit, kein Schlüssel und kein Diagnoseversuch wurden auf
IP-Symcon bereitgestellt. Keine Cloud-Aufrufe, Roboterbefehle, Deployments,
Commits oder Pushs. Synthetische Testgenehmigungen und Schlüssel existieren nur
im RAM bzw. in anonymen Prozesspipes. Alle vorgefundenen uncommitteten
Änderungen bleiben erhalten.

## Aktualisierung: IP-Symcon 9.1 ohne POSIX (12.11.2026)

Basis dieser lokalen Korrektur ist HEAD `178c56b` auf
`feature/eureka-e20-evo-plus`. Der gemeldete Symcon-Prozess läuft als Root,
lädt aber kein POSIX und bietet kein `posix_geteuid()`. Die bisherige zwingende
POSIX-Abfrage verhindert deshalb bereits das Lesen des Vertrauensankers.

Nur `RoboHubEurekaGenehmigung::vertrauensanker()` wird angepasst: Ohne die
native Funktion wird die EUID als `null` (nicht nachgewiesen) behandelt.
Es gibt keine Ermittlung über `getmyuid()`, Dateieigentümer, Benutzernamen,
Shell, `/proc` oder die Administratorrolle. `IPS_IsAdministrator()` bestätigt
die Symcon-Berechtigung, keine Betriebssystemidentität. Der ausschließlich
feste B8-Import toleriert diese unbekannte Prozessidentität ebenso wie bisher
nachgewiesene EUID 0 beim zusätzlichen `is_writable()`-Veto. Bei nachgewiesener
EUID ungleich 0 bleibt dieses Veto zwingend erhalten.

Die Eigentümer-/Modus-/Typprüfung braucht keine POSIX-Erweiterung: PHPs
natives `lstat()` liefert UID, Modusbits und Dateityp auch ohne diese Erweiterung.
`clearstatcache()`, `is_link()`, Größen-/Lesbarkeitsprüfung und Dateilesen bleiben
aktiv. `/etc`, `/etc/robohub` und die feste Datei `/etc/robohub/eureka-b8.pub`
müssen weiterhin root-eigene echte Verzeichnisse bzw. eine reguläre Datei sein,
ohne Gruppen-/Weltschreibrechte und ohne Symlinks. Fehlende oder fehlerhafte
Dateisystemprüfungen sperren; es gibt keinen Ersatzschlüssel oder variablen Pfad.
Native Administrator-/Benutzerprüfung vor und innerhalb der Sperre, gültige
Ed25519-Signatur, Konto-/Geräte-/Laufzeitbindung und Einmalverbrauch bleiben
unverändert zwingend. Normale Eureka-Befehle bleiben gesperrt.

**Root-Risiko und Grenze der Ausnahme:** Root kann Vertrauensanker, Modulcode
und Laufzeitdaten verändern; die externe Signatur schützt nicht vor
kompromittiertem Root. Ohne POSIX ist weder Root noch ein unprivilegierter
Prozess nachgewiesen. Die Ausnahme beweist auch keinen fehlenden effektiven
Schreibzugriff, etwa durch zusätzliche ACL-Rechte eines unprivilegierten
Prozesses. UID und klassische Modusbits werden trotzdem vollständig geprüft.
Es erfolgt keine pauschale Sicherheitsabschaltung und keine Freischaltung.
Der private Signaturschlüssel bleibt außerhalb von Symcon.

Der erweiterte Offline-Prüfstand nutzt weiter getrennte User-/Mount-/
Netznamespaces und tmpfs-`/etc`. Er führt dieselben Dateisystem- und Importfälle
mit nativer POSIX-EUID 0 und mit `php -n -d extension=ctype` aus; letzteres
prüft ausdrücklich, dass sowohl Erweiterung als auch EUID-Funktion fehlen.
CTYPE wird nur für den unveränderten Protokollparser geladen. Ein weiterer
Fall ersetzt ausschließlich die deaktivierte EUID-Funktion durch ein Testdouble
mit EUID 1000 und bestätigt das native Schreibzugriffs-Veto. Das ist kein
Nachweis eines echten unprivilegierten Symcon-Prozesses. Administrator und
Transport sind weiterhin Offline-Doubles. Alle Schreibzugriffe betreffen nur
das wegwerfbare tmpfs; keine Host-Schlüsseldatei, kein Zugriff auf Produktion.
Native IP-Symcon-Authentifizierung und Persistenz bleiben ungeprüft.

Abschlussprüfung: `bash tests/eureka/run-offline.sh`, Exitcode 0, vollständig
netzisoliert. Alle bisherigen Offline-Prüfstände und die 39 B8-Importfälle
bestanden; 80 PHP-Dateien syntaxfehlerfrei, `git diff --check` fehlerfrei.
Nach Ergänzung der expliziten POSIX-Prüfstandsvoraussetzung wurden die 39 Fälle
nochmals vollständig erfolgreich ausgeführt. Keine Cloud-/Produktivzugriffe,
Freischaltungen, Commits, Pushs oder Deployments. Nächster Schritt ist die
lokale Prüfung des Diffs; eine native Symcon-Validierung bleibt separat offen.

Die nachfolgende Aktualisierung dokumentiert den vorherigen Root-Arbeitsstand.

## Aktualisierung: kontrollierter Import unter EUID 0 (12.11.2026)

Basis `aeb7656`, Branch `feature/eureka-e20-evo-plus`. Nur lokale Änderungen;
kein Zugriff auf die produktive Installation, keine Cloud-Anfragen,
Roboterbefehle, Commits, Pushs oder Deployments. Die gemeldete Produktion läuft
mit IP-Symcon 9.1 als EUID 0; `/etc/robohub/eureka-b8.pub` ist laut Auftrag ein
validierter Ed25519-Public-Key, root:root/0644, sein Verzeichnis root:root/0755.
Diese Angaben wurden nicht durch Zugriff auf den Produktionshost überprüft.

Die einzige Änderung am Produktivcode liegt in
`libs/RoboHubEurekaGenehmigung.php::vertrauensanker()`: Die pauschale Root-Sperre
entfällt; `is_writable()` bleibt für unprivilegierte Laufzeiten verpflichtend.
Pfad und Signaturcode bleiben unverändert, der Dateityp wird zusätzlich direkt
am `lstat`-Ergebnis geprüft. Keine wählbare Schlüsseldatei, kein neuer
Moduleinstieg und kein automatisches Genehmigen. Native Administratorprüfung,
Konto-/Instanz-/Cloud-Geräte-/Befehls-/Laufzeitbindung, Einmalverbrauch,
Widerruf und sämtliche HTTP-Retry-Sperren bleiben unverändert. Normale
Eureka-Steuerbefehle bleiben gesperrt. Andere Hersteller und Module werden
nicht verändert.

**Bewusst akzeptierte Sicherheitsgrenze:** Root kann grundsätzlich
Vertrauensanker, Modulcode und Laufzeitdaten manipulieren. Der öffentliche
Schlüssel schützt nicht vor kompromittiertem Root. Diese Einschränkung wird
für den kontrollierten Diagnoseversuch bewusst akzeptiert. Der private
Signaturschlüssel bleibt ausschließlich auf codex-dev.

Der neue Prüfstand `tests/eureka/root-import-test.php` startet getrennte
Bubblewrap-User-/Netz-/Mountnamespaces mit echter EUID 0 und flüchtigem
`/etc` (tmpfs). Vor Schreibzugriffen prüft er die UID-Abbildung auf einen
unprivilegierten Hostbenutzer, den Netznamespace und den tmpfs-Mount.
Nur synthetische Public-Keys werden dort geschrieben; Testprivatschlüssel
und signierte Dokumente bleiben im RAM. Der Host erhält keine Schlüsseldatei.
Ein read-only eingebundenes Host-`/etc/passwd` stellt einen echten, im
Usernamespace fremden Eigentümer dar. Kein Produktivcode wird ersetzt.
Symcon-Benutzer, Buffer und Transport bleiben die bestehenden Offline-Doubles;
dies beweist keine native Symcon-Authentifizierung oder Persistenz.

16 Root-Fälle prüfen gültigen Vertrauensanker, falschen Eigentümer,
Gruppen-/Weltschreibrechte, Datei-/Verzeichnissymlink, unsichere
Verzeichnisse einschließlich `/etc`, falschen Dateityp, fehlende Datei,
ungültige Signatur, Nichtadministrator, falsches Cloud-Gerät, Neustart,
Attributrollback und Widerruf. Positivfälle prüfen den zweiten Import schon
vor Ticketausstellung, wiederholten Transport und gesperrte normale
Start-/Pause-/Stationsbefehle trotz erfolgreichem Import.

`bash tests/eureka/run-offline.sh`: Exitcode 0, vollständig netzisoliert.
192 Diagnose-, 19 Import-Kontext-, 16 Root-Import-, 18 HTTPS-Double-,
21 native HTTPS-, 233 Befehls-, 683 Zustands-, 72 Token-/GET- und
1.199 RoboHub-Prüfungen: **2.453 bestanden, 0 Fehler**, zusätzlich
**22 Struktur-Gegenproben**. **79 PHP-Dateien** ohne Syntaxfehler;
`git diff --check` fehlerfrei. Die normalen Befehlswege wurden anschließend
zusätzlich innerhalb der erfolgreichen Root-Importfälle geprüft; auch diese
16 Fälle bestehen vollständig. Bash- und Python-Syntax separat geprüft.
Die Zahlen in Abschnitt G dokumentieren den früheren Bereitstellungsstand.

Die folgenden Abschnitte beschreiben die bestehende Bereitstellungsarchitektur;
die Aussagen zur root-Laufzeit sind an diese Aktualisierung angepasst.

## A. Gewählte Bereitstellungsarchitektur

Die standardmäßig fehlende flüchtige Laufzeitfreigabe bleibt die unabhängige
Sperre neben dem Diagnoseattribut. Neu ist ein **signierter externer Import**:

1. `ROBOHUB_EurekaB8LaufzeitAnzeigen(45174)` liefert ausschließlich die lokale
   Istkennung. Keine Netzabfrage, keine Genehmigung.
2. Ein separater Administrationsrechner prüft und genehmigt die erwartete
   Kennung und die konkrete Cloud-Gerätekennung ausdrücklich. Nur dort liegt
   der private Ed25519-Schlüssel.
3. `.tools/eureka-b8-signieren.php` signiert die manuell genehmigte Nutzlast.
   Es liest JSON von stdin, den Base64-Privatschlüssel ausschließlich über FD 3.
   Es ermittelt niemals selbst eine Symcon-Kennung und schreibt keine Dateien.
4. `ROBOHUB_EurekaB8Bereitstellen(45174, $dokument)` prüft
   unmittelbar am Moduleinstieg zuerst den nativ bestätigten angemeldeten
   Administrator, dann die Signatur gegen `/etc/robohub/eureka-b8.pub`,
   Gerätebindung, Kontext und leere Ausgangszustände unter
   denselben Diagnose-/Bündel-/Freigabesemaphoren wie die Einmaldiagnose.

Der feste externe Vertrauensanker ist keine Moduleinstellung und kann nicht
als Aufrufparameter gewählt werden. Datei und Elternverzeichnisse müssen
root gehören, dürfen keine Symlinks und nicht gruppen-/weltbeschreibbar sein.
Der Import erlaubt jetzt auch EUID 0, ausschließlich an dieser festen
B8-Vertrauensankergrenze; ohne POSIX bleibt die EUID unbekannt
(siehe Aktualisierung oben).
Die `lstat`-Dateitypprüfung verlangt reguläre Datei bzw. echte Verzeichnisse;
root als Eigentümer und das Verbot von Gruppen-/Weltschreibrechten bleiben
unverändert. Modus 0644 für die Datei und 0755 für das Verzeichnis sind zulässig.
Bei nachgewiesener EUID ungleich 0 bleibt zusätzlich der effektive Schreibzugriff über
`is_writable()` ein Veto (auch bei beispielsweise per ACL gewährten Rechten).
Bei EUID 0 oder unbekannter EUID entfällt nur dieses Veto: Root kann
grundsätzlich auch korrekt root-eigene Dateien mit Modus 0644 schreiben. Die unten beschriebene
privilegierte Manipulierbarkeit wird für den kontrollierten Diagnoseversuch
bewusst akzeptiert.
Es gibt keinen mitgelieferten Schlüssel, keinen Schlüsselerzeuger im Modul,
keinen Reset und keinen automatischen Import. Fehlende Sodium-Unterstützung,
fehlender/defekter Vertrauensanker oder ungültige Dokumente sperren.

**Zwei unabhängige Voraussetzungen am öffentlichen Moduleinstieg:**
`EurekaB8Bereitstellen()` verlangt vor Semaphore, Bufferzugriff und
Vertrauensankerprüfung `IPS_GetLoggedInUser()` und `IPS_IsAdministrator()`.
Nach Erwerb der Diagnose-/Bündelsperre wird die Prüfung nochmals ausgeführt.
Beide Funktionen müssen vorhanden sein, der Benutzer muss ein nicht leerer
String ohne äußeren Leerraum und ungleich `@unknown` sein, und das native
Administratorergebnis muss strikt `true` sein. Fehlende APIs, unbekannte,
unauthentifizierte oder nicht administrative Kontexte, falsche Rückgabetypen
und Exceptions sperren ohne Import. Es gibt keinen lokalen Vertrauensbonus,
keine Auswertung von selbst gesetztem `$_IPS`, keinen Benutzernamenparameter
und keinen Fallback auf eine bloß gültige Signatur. Auch `@admin` als Text
reicht ohne positives natives Administratorergebnis nicht aus.

**Herstellerbelege, geprüft am 12.11.2026:**
[IPS_IsAdministrator](https://www.symcon.de/de/service/dokumentation/befehlsreferenz/berechtigungsverwaltung/ips-isadministrator/)
prüft den Benutzer des aktuellen Ausführungskontexts. Sowohl der Sonderbenutzer
`@admin` als auch Benutzer mit der Rolle `@admin` gelten als Administratoren;
`@unknown` erhält `false`. Die API benötigt kein RBAC-Lizenzfeature.
[IPS_GetLoggedInUser](https://www.symcon.de/de/service/dokumentation/befehlsreferenz/berechtigungsverwaltung/ips-getloggedinuser/)
liefert den aktuellen Benutzernamen. Skripte übernehmen laut Dokumentation den
Benutzer des startenden Kontexts, auch bei Visualisierungsaktionen.
`@unknown` bedeutet fehlende Anmeldung. Die verwandte Dokumentation zu
[IPS_SetRemoteAccessPassword, ab 9.1](https://www.symcon.de/de/service/dokumentation/befehlsreferenz/programminformationen/ips-setremoteaccesspassword/)
verweist bereits auf diese Kontextsemantik. Dessen Ausnahme für die Einrichtung
ohne Fernzugriffspasswort wird beim B8-Import ausdrücklich nicht übernommen.

**Verfügbarkeit unter IP-Symcon 9.1 bleibt nativ unbestätigt:** Die beiden
API-Seiten nennen keine Mindestversion bzw. konkrete 9.1-Revision. Die aktuelle
Dokumentation und ihr 9.1-Verweis sind kein Nachweis für die lokal installierte
Binärversion. Eine native 9.1-Testinstanz wurde in diesem Auftrag nicht
angesprochen; die realen Funktionen wurden nicht ausgeführt. Deshalb prüft
der Import die Verfügbarkeit bei jedem Aufruf und verweigert andernfalls.
API-Sichtbarkeit im PHP-Modul sowie Kontextvererbung und Authentifizierung über
native Funktionswrapper, Skripte, Timer und Visualisierung müssen vor einer
späteren Freischaltung in einer isolierten, wegwerfbaren 9.1-Installation
nachgewiesen werden. Die Offline-Doubles liefern keine Sicherheitsgarantie
für diese native Grenze.

Die externe Signatur bleibt zusätzlich notwendig und bindet Zweck/Version
`robohub/eureka/b8/runtime/v2`, Konto 45174, Instanz 22708, die **konkrete
Cloud-Gerätekennung** `cloudGeraetId`, E20 Evo Plus, den vorhandenen Frame für
`32 01`, erwartete Laufzeit und eine Frist von höchstens 300 Sekunden.
Schema v1 ohne Gerätekennung wird abgewiesen; es gibt keine automatische
Migration oder Ergänzung aus der aktuellen Zuordnung. Geprüft werden exakte
Feldnamen, Typen und Zielwerte. Gerätekennungen sind nicht leere Strings von
höchstens 256 Bytes ohne äußeren Leerraum oder Steuerzeichen.

Beim Import muss die signierte Gerätekennung bytegleich dem über Instanz,
Konto und lokalen Cache eindeutig ermittelten Ziel entsprechen. Die Kennung
bleibt im separaten flüchtigen Freigabebuffer erhalten und wird bei
Ticketausstellung, Reservierung und jedem Transportprüfpunkt einschließlich
unmittelbar vor `curl_exec()` erneut mit dem aktuellen Ziel verglichen.
Ticket und Sitzungsbindung bleiben zusätzlich wirksam. Ein beobachteter
Vergleichsfehler setzt ein flüchtiges Widerrufsveto; Zurückwechseln oder ein
Attributrollback öffnet diese Laufzeit nicht erneut. Ein alter Buffer ohne
Gerätebindung wird abgewiesen. Änderungen zwischen Prüfzeitpunkten, die vor
der nächsten Prüfung vollständig zurückgenommen werden, sind damit nicht
historisch nachweisbar; native Konfigurations-/Thread-Atomarität bleibt Teil
der Integrationsprüfung. Es wird stets der vor Reservierung bestimmte,
signierte Transportpfad verwendet. Keine vom Import wählbaren URLs oder
Befehle. Modell-, Sitzungs- und Shadow-Prüfungen bleiben zusätzlich aktiv.

Die Kennung wird weder fest im Produktivcode hinterlegt noch in Rückgaben,
Fehlermeldungen oder Debug-/Modullogs ausgegeben. Die signierte Nutzlast ist
Base64-kodiert, **nicht verschlüsselt** und enthält diese Kennung. Dokumente
und Signierausgaben daher außerhalb des Repositorys geschützt halten und
niemals als Debugausgabe, Ablaufverfolgungsparameter oder persistentes Skript
protokollieren. Ob native Symcon-Traces Aufrufargumente mitschreiben, muss
vor dem späteren Import extern geprüft werden; das Modul kann fremdes Tracing
nicht verhindern. Das Repository enthält nur synthetische Fixture-Kennungen.

## B. Exakter späterer administrativer Ablauf

**Nicht in diesem Auftrag ausführen.** Vor einer späteren Bereitstellung sind
folgende Voraussetzungen separat zu prüfen und zu genehmigen:

- Native Benutzer-APIs und Wrapper unter der vorgesehenen 9.1-Revision
  ohne Cloud-Netz in einer Wegwerfinstallation prüfen: angemeldeter
  Administrator (auch über Rolle), Nichtadministrator, `@unknown`, fehlende
  Anmeldung, Skript-, Timer- und Visualisierungskontext. Unklare Semantik
  bleibt ein Freischaltungsblocker; niemals durch einen eigenen Setter umgehen.
- Native Buffer-Löschung bei Neustart und Semaphore-Sichtbarkeit in einer
  wegwerfbaren Offline-Symcon-Instanz mit synthetischen Daten bestätigen.
- Bei unprivilegierter Laufzeit sicherstellen, dass Symcon weder Modulcode noch
  Vertrauensanker ändern kann (einschließlich ACLs, Gruppen und Mounts).
  Bei der vorgesehenen root-Laufzeit ist diese Trennung grundsätzlich nicht
  gegeben: Die privilegierte Manipulierbarkeit wird ausschließlich für den
  kontrollierten Diagnoseversuch bewusst akzeptiert. Die festen Pfad-, Typ-,
  Eigentümer-, Modus- und Symlinkprüfungen bleiben zwingend.
- Ed25519-Schlüsselpaar außerhalb des Symcon-Rechners in der administrativen
  Schlüsselverwaltung erzeugen. Nur den Base64-Public-Key als einzelne Zeile
  extern in `/etc/robohub/eureka-b8.pub` installieren (root, z. B. Modus 0644;
  Verzeichnis root, 0755). Privaten Schlüssel niemals nach Symcon übertragen.
  Schlüsselfingerabdruck und Herkunft unabhängig kontrollieren. Dafür existiert
  bewusst kein automatischer Modulpfad.
- Installierten Code und Zielzuordnung extern prüfen. Diagnoseattribut muss
  leer sein. Vorhandene Zustände werden nicht gelöscht oder repariert; sie sind
  ein Blocker für diesen Bereitstellungsvorgang.

Danach ausschließlich manuell:

1. Lokale Istkennung mit `ROBOHUB_EurekaB8LaufzeitAnzeigen(45174)` lesen.
   Dafür müssen vorhandene Sitzung und lokale Geräte-/Shadow-Daten gültig und
   frisch sein. Der Aufruf erneuert keine Sitzung und lädt keine Daten nach.
2. Laufzeitkennung und konkrete Cloud-Gerätekennung der Zielinstanz unabhängig
   aus dem vertrauenswürdigen lokalen Verwaltungszugang prüfen. Die Cloud-ID
   nicht durch eine neue Cloud-Abfrage ermitteln und nicht im Chat oder Repository
   ablegen. Die Laufzeitkennung mit der vorgesehenen Installation und deren
   Kernelstart abgleichen. Die Nonce kann nicht unabhängig berechnet werden:
   ihre Herkunft muss über den vertrauenswürdigen administrativen Zugriff auf
   genau diese Instanz bestätigt werden. Ein beliebiger kopierter Text ist
   kein Nachweis. Prüfer, Zielsystem und ausdrückliche Genehmigung extern
   dokumentieren. Kein automatisches Abholen-und-Signieren einrichten.
3. Auf dem separaten Administrationsrechner ein JSON-Objekt mit genau diesen
   Feldern in dieser Reihenfolge erstellen (außerhalb des Repositorys):

   | Feld | Inhalt |
   | --- | --- |
   | `genehmigung.zweck` | `robohub/eureka/b8/runtime/v2` |
   | `genehmigung.konto` | Integer 45174 |
   | `genehmigung.instanz` | Integer 22708 |
   | `genehmigung.cloudGeraetId` | extern geprüfte konkrete Cloud-Gerätekennung als String |
   | `genehmigung.modell` | `E20 Evo Plus` |
   | `genehmigung.befehl` | exakter Wert von `RoboHubEurekaDiagnose::BEFEHL` |
   | `genehmigung.erwartet` | extern geprüfte vollständige Laufzeitkennung |
   | `genehmigung.von` | aktuelle Unixzeit als Integer, externe vertrauenswürdige Uhr |
   | `genehmigung.bis` | Unixzeit größer als `von`, maximal 300 Sekunden danach |
   | `externGeprueftUndGenehmigt` | vollständige erwartete Laufzeitkennung ausdrücklich erneut bestätigen |
   | `cloudGeraetExternGeprueftUndGenehmigt` | konkrete Cloud-Gerätekennung ausdrücklich erneut bestätigen |

   Die drei äußeren Felder sind `genehmigung`, `externGeprueftUndGenehmigt`
   und `cloudGeraetExternGeprueftUndGenehmigt`. Keine Tokens aufnehmen.
   Die konkrete Gerätekennung gehört ausschließlich in diese externe Eingabe,
   die Bestätigung und das signierte Dokument, niemals in Repository-Dateien.
   Der Signierer verlangt diese Reihenfolge und beide übereinstimmenden Bestätigungen;
   die tatsächliche externe Prüfung ist organisatorisch nachzuweisen.
4. Auf diesem Rechner ausführen:

   ```sh
   php .tools/eureka-b8-signieren.php < /run/admin/b8-antrag.json 3< /run/admin/b8-private.base64 > /run/admin/b8-signiert.json
   ```

   Die Pfade stehen für dort manuell bereitgestellte, geschützte Dateien. Keine
   Schlüssel oder Genehmigungen ins Repository, in Shellargumente oder Logs
   übernehmen. Der Signierer gibt nur das signierte Dokument auf stdout aus.
5. Das signierte Dokument vor Fristende aus einem nachweislich angemeldeten
   administrativen Kontext direkt
   an `ROBOHUB_EurekaB8Bereitstellen(45174, $dokument)` übergeben. Kein
   persistentes Symcon-Skript mit Freigabewerten anlegen, kein Timer/Retry.
   Nichtadministrative oder unbekannte Kontexte bleiben auch mit gültiger Signatur
   gesperrt. Die Antwort bestätigt lediglich den Import; kein Transport findet statt.
6. Erst ein zusätzlich ausdrücklich genehmigter Testauftrag darf das bestehende
   `ROBOHUB_EurekaB8Freigeben(45174, 60, 'B8-Arbeitszustand einmalig abfragen')`
   aufrufen und dessen Ticket einmal an
   `ROBOHUB_EurekaB8Testen(45174, $ticket)` übergeben. Das Ticket nur flüchtig
   halten. Beide Fristen (externe Genehmigung und Ticket) müssen noch gelten.
7. Vor Transportbeginn mit `ROBOHUB_EurekaB8Widerrufen(45174)` abbrechen.
   Auch ein Widerruf vor dem Import setzt jetzt ein flüchtiges Veto.
   Nach Transportbeginn ist kein Widerruf oder weiterer Versuch möglich.
   Nach Fehler oder Timeout keine neue Bereitstellung und keinen Retry versuchen.

## C. Nachweis der Neustartsperre

Unter dem bestehenden dokumentierten Symcon-Buffervertrag verschwinden sowohl
Freigabe als auch Istnonce bei Neustart. Der Import des alten signierten
Dokuments scheitert am exakten Kennungsvergleich, auch bei identischer
Kernelstartzeit. Das Modul passt `erwartet` niemals an eine neue Istnonce an.
Ein leeres oder zurückgerolltes Diagnoseattribut genügt nicht. Eine spätere
andere Laufzeit benötigt eine neue externe Prüfung und Signatur; das geschieht
niemals automatisch. Offline-Tests simulieren diesen Vertrag, sie beweisen
keine nativen Absturz- oder Thread-Eigenschaften.

## D. Nachweis des einmaligen Verbrauchs

Unter der kurzen Freigabesemaphore akzeptiert der Import nur zwei leere
Ausgangsspeicher. Bereits `genehmigt` sperrt einen zweiten Import, unabhängig
von Dokument oder Schlüssel. Ticketausstellung verbraucht das Ausstellungsrecht;
Reservierung und Transportbeginn bleiben endgültige Übergänge:

```text
leer → genehmigt → ticket → reserviert → geschlossen
  └────────┴────────┴──────────┴──────→ widerrufen
```

Laufzeitwrites werden zurückgelesen. Eine zurückgerollte Diagnosepersistenz
ändert den Buffer nicht. Timeout, abgelaufene Genehmigung und Ticketablauf
öffnen nichts. Die externe Frist wird nach Import zusätzlich monoton begrenzt
und auch bei Reservierung und Transportbeginn geprüft. Die signierte Cloud-ID
bleibt bei allen Übergängen unverändert und wird unabhängig erneut verglichen.
Widerruf vor Import überlebt innerhalb derselben Laufzeit ebenfalls einen Attributrollback.

## E. Auswirkungen auf bestehende Befehlswege

Kein neuer Zweig in ForwardData, Geräte-RequestAction, Visualisierung, Timern,
ApplyChanges, Anmeldung, Vorprüfung oder Statusabfrage. Die vorhandenen
produktiven Befehlssperren, die leere Befehlszuordnung und die gesamte gemeinsame
Transportimplementierung bleiben unverändert. Import und Anzeige führen
keinen GET, Refresh, POST oder sonstigen Netzwerkzugriff aus. Die vorhandene
explizite Vorprüfung mit Listenabruf ist weiterhin ein separater Aufruf.

## F. Geänderte Dateien

- `RoboHubKonto/module.php`: lokale Anzeige, signierter Import, native
  Administratorprüfung unmittelbar am Import-Einstieg und nach Sperrerwerb.
- `libs/RoboHubEurekaDiagnose.php`: Bereitstellung unter Sperre, Fristprüfung,
  Widerrufveto vor Import sowie signierte Gerätebindung an allen Übergängen.
- `libs/RoboHubEurekaGenehmigung.php`: reine Signatur-/Schemaprüfung, fester
  externer Vertrauensanker, striktes v2-Schema mit Cloud-Gerätekennung.
- `.tools/eureka-b8-signieren.php`: externes CLI-Werkzeug mit ausdrücklicher
  separater Bestätigung von Laufzeit und Cloud-Gerätekennung.
- `tests/eureka/diagnostic-test.php`, `tests/eureka/provisioning-cases.php`:
  zusätzliche Offlinefälle im bestehenden Harness, vollständiger Zielwechsel.
- `tests/eureka/import-test.php`: Moduleinstieg mit nativen Benutzer-Doubles
  und echter Signaturprüfung; fehlende APIs in getrennten Prozessen.
- `tests/eureka/diagnostic-http-test.php`, `diagnostic-native-client.php`:
  synthetische Freigabe-Fixtures um die zwingende Gerätebindung ergänzt.
- `tests/eureka/run-offline.sh`: zusätzlicher isolierter Import-Prüfstand.
- `.tools/test-eureka.inc.php`: Geräteaktionen, Formular- und Timerwege geprüft.
- `tests/eureka/README.md`, `docs/eureka-b8-laufzeitsperre.md`,
  `docs/eureka-b8-diagnose.md`: Verweise auf diesen aktuellen Stand.
- `docs/eureka-b8-bereitstellung.md`: Ablauf, Grenzen und Abschlussbericht.

## G. Frühere Testergebnisse der Bereitstellung

`bash tests/eureka/run-offline.sh` lief vollständig mit Exitcode 0 unter
`bwrap --unshare-net`. Externes Netzwerk war für sämtliche Tests und ihre
Unterprozesse gesperrt; nur der native HTTPS-Gegenserver verwendet isoliertes
Loopback. Kein Fallback ohne Netznamespace. Alle Testdaten sind synthetisch.

| Prüfstand | Bestanden | Fehler |
| --- | ---: | ---: |
| Diagnose einschließlich 45 Bereitstellungs-/Gerätebindungsfälle | 175 | 0 |
| Administrativer Moduleinstieg, getrennte PHP-Prozesse | 19 | 0 |
| Gemeinsamer HTTPS-Code, cURL-Doubles | 18 | 0 |
| Native lokale HTTPS-/libcurl-Gegenversuche | 21 | 0 |
| Eureka-Befehle | 233 | 0 |
| Eureka-Zustände | 683 | 0 |
| Token-/GET-Prüfungen | 72 | 0 |
| Vollständiger RoboHub-Prüfstand | 1.199 | 0 |
| **Gesamt** | **2.420** | **0** |

Gegenüber dem vorgefundenen Prüfstand kommen 38 Prüfungen hinzu: 15 Fälle für
Gerätebindung/Signierer, 19 Import-Kontextfälle und vier Integrationsprüfungen
für Geräteaktionen, Formulare und Timer. Alle bisherigen Regressionen bleiben
enthalten. 76 PHP-Dateien sind syntaxfehlerfrei; Python-Syntax und
Diff-Whitespace einschließlich neuer Dateien wurden separat netzisoliert
geprüft. Es wurde nichts committed, gepusht, deployed oder real freigeschaltet.

Neue Fälle prüfen insbesondere vollständigen Zielwechsel vor Import, nach Import,
nach Ticket, nach Reservierung und direkt vor Transport; alte Dokumente/Buffer
ohne Gerätebindung; fehlende APIs, `@unknown` (auch bei widersprüchlich positivem
Admin-Double), leere/defekte Kontexte, Nichtadministrator, Administratorname ohne
Recht, Rückgabetypen und API-Exceptions, Rollenadministrator, gültige Signatur
mit Administrator, ungültige Signatur trotz Administrator, Kontextwechsel nach
Sperrerwerb und fehlenden externen Vertrauensanker. Vorhandene Fälle zu Neustart,
Attributrollback, einmaligem Verbrauch und HTTP-Retry-Gegenversuchen bleiben Teil
des vollständigen Runners. Geräteaktionen, ForwardData, Formular und Timer
bekommen keinen Importpfad; normale Leseabläufe erhalten keine Administratorsperre.

`import-test.php` lädt für seine Positivfälle eine Kopie derselben Modulquelle
im PHP-Speicher; ausschließlich der Aufruf der festen Public-Key-Dateigrenze
wird durch den ephemeren RAM-Schlüssel ersetzt. Die Testkopie verwendet einen
anderen Klassennamen. Die Administratorprüfung und der Importkörper bleiben
unverändert, die Diagnoseklasse prüft echte Ed25519-Signaturen. Es gibt keinen
Produktions-Testschalter oder optionalen Vertrauensankerparameter. Ein weiterer
Fall ruft den unveränderten Moduleinstieg auf und erwartet Ablehnung ohne
Vertrauensanker. Der Host erhält keine `/etc/robohub`- oder Schlüsseldateien.
Diese Tests beweisen keine native Authentifizierung, Dateirechte oder Wrapper.

Die gemeinsame produktive HTTPS-Implementierung und die produktiven Geräte- und
Normalbefehlspfade bleiben unverändert. Bei den nativen HTTPS-Testquellen wurde
nur die neue Gerätebindung in der synthetischen Runtime-Fixture ergänzt;
Gegenserver, Transportinstrumentierung und Retry-Gegenversuche bleiben erhalten.

## H. Verbleibende Sicherheitsgrenzen und Blocker

Keine Software innerhalb derselben privilegierten Symcon-Instanz kann sich
gegen Administratoren schützen, die PHP-Code, beide Buffer oder den festen
Vertrauensanker austauschen können. Die externe Signatur schützt vor autonomer
Genehmigung durch unveränderten Modul-/Skriptcode ohne diesen Schlüssel, nicht
vor einem kompromittierten Host. Liegt der private Schlüssel auf dem
Symcon-Host, bleibt die Freischaltung blockiert. Der private Signaturschlüssel
bleibt ausschließlich auf **codex-dev**. Root kann grundsätzlich Vertrauensanker,
Modulcode und Laufzeitdaten manipulieren; der öffentliche Schlüssel schützt
nicht vor kompromittiertem Root. Diese Einschränkung wird für den kontrollierten
Diagnoseversuch bewusst akzeptiert. Die EUID-0-Kompatibilität erzeugt selbst
keine Genehmigung oder Freischaltung. Kein ungeschützter Setter als Ersatz.

Die native 9.1-Benutzer-/Authentifizierungsprüfung, Buffer-/Semaphore-Prüfung
und die Host-Rechteprüfung sind weiterhin
**offene Voraussetzungen vor jeder echten Freischaltung**. Ein harter Absturz,
RAM-/VM-Rollback beider Speicher, instanzübergreifende native Thread-Sichtbarkeit
und absichtliche privilegierte Manipulation sind offline nicht bewiesen.
Attribut-read-after-write beweist keinen fsync. Offline-Parallelität wird mit
Semaphoren-Doubles und reentrantem Konkurrenzaufruf modelliert.

Die Signatur beweist Schlüsselbesitz, nicht die Sorgfalt eines menschlichen
Prüfers. Externe Genehmigungsaufzeichnungen und die Schlüsselverwaltung bleiben
verantwortlich. Vor dem Import setzt die kurze Signaturfrist eine hinreichend
korrekte Zielsystemuhr voraus; nach Import kommt die monotone Begrenzung hinzu.
Ein abgefangenes gültiges Dokument genügt ohne nativ bestätigten angemeldeten
Administrator nicht zum Import. Ein berechtigter Administrator kann innerhalb
der Frist den ersten Import vorwegnehmen, aber kein zweites Recht erzeugen.
Der Mechanismus ist keine lebenslange Einmalgarantie über neu ausdrücklich genehmigte Laufzeiten hinweg.
Native HTTPS-Tests garantieren keine genau einmalige Verarbeitung in Cloud oder
Gerät. Dieser Arbeitsstand bleibt lokal und wartet auf Freigabe.
