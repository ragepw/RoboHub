# Zusätzliche B8-Laufzeitsperre – lokaler Abschlussbericht

12.11.2026, Branch `feature/eureka-e20-evo-plus`, Basis `2f034c6`.
Vorhandene uncommittete Diagnose- und Transportänderungen wurden erhalten.
Keine konkrete Laufzeit wurde freigeschaltet. Keine Cloud-/Geräteaufrufe,
Produktivänderungen, Firmware-/App-Änderungen, Dienste, Commits, Pushs oder
Deployments. Die HTTPS-Tests verwenden nur temporäre lokale Gegenserver im
isolierten Netzwerk-Namespace. Herstellerdokumentation wurde lesend abgerufen.

## A. Architektur

Zusätzlich zum persistenten Attribut `EurekaB8Diagnose` prüft die Diagnose einen
unabhängigen **flüchtigen Modulbuffer** `EurekaB8Laufzeitfreigabe` des Kontos.
Er ist standardmäßig leer. Es gibt keinen öffentlichen Bereitstellungs-, Reset-
oder Erneuerungseinstieg. Ein fehlender, defekter oder unpassender Buffer sperrt.

Der Buffer enthält eine separat genehmigte Erwartung `erwartet` und einen
Zustand. Ausschließlich eine künftige separate administrative Bereitstellung
dürfte `genehmigt` setzen. Dieses Modul enthält keinen solchen Schreibpfad.
Die Übergänge lauten:

```text
genehmigt → ticket → reserviert → geschlossen
     └────────┴──────────┴──────→ widerrufen
```

`geschlossen` ist terminal und erlaubt keinen Widerruf. Auch `widerrufen`
ermöglicht keine erneute Ausstellung. Bereits die Ticketausstellung ersetzt
`genehmigt` durch `ticket` und bindet den Buffer an den SHA-256-Tickethash.
Es gibt höchstens ein Ticket pro bereitgestellter Laufzeitfreigabe, auch wenn
dieses abläuft oder nie benutzt wird. Reservierung und Transportbeginn ändern
zuerst den Bufferzustand, danach das Diagnoseattribut. Beide Writes werden
zurückgelesen; Abweichungen verhindern den Transport. Teilfehler werden nicht
automatisch repariert und können die Freigabe unbrauchbar machen.

Alle Übergänge und die erneute Laufzeitprüfung benutzen dieselbe kurze
Freigabesemaphore. Prüfung erfolgt vor Ticketausgabe, bei Reservierung und an
der bestehenden unteren HTTPS-Grenze unmittelbar vor `curl_exec()`.
Die lange Diagnose- und Bündelsperre sowie sämtliche Transportkorrekturen bleiben
erhalten. Ein zweiter Transportversuch ist auch nach Attributrollback gesperrt.

## B. Speicherort und Herkunft der Kennung

Die bestehende **aktuelle** Istkennung besteht aus `IPS_GetKernelStartTime()`
und einem zufälligen 256-Bit-Wert im flüchtigen Buffer `EurekaB8Laufzeit`.
Die vorhandene Erzeugung dieses Istwertes bleibt erhalten; sie erteilt niemals
eine Genehmigung. Die **erwartete** Kennung im separaten Freigabebuffer muss
extern und ausdrücklich genehmigt bereitgestellt werden. Der Diagnosecode
vergleicht sie mit `hash_equals()` und übernimmt sie unverändert in strengere
Zustände. Er setzt niemals `erwartet` auf die aktuelle Kennung.

In diesem Auftrag existiert keine administrative Bereitstellung. Nur die
Offline-Fixtures simulieren sie mit erfundenen Werten. Es werden keine
permanenten Gerätekennungen oder Tokens zusätzlich in Konfigurationen oder
Logs gespeichert. Im neuen Buffer stehen nur Laufzeitkennung, Zustand und nach
Ausstellung der Tickethash. Bestehende Sitzungsspeicherung bleibt unverändert.

## C. Neustart und Rollback; tatsächlicher Sicherheitsgewinn

Die offizielle [SetBuffer-Dokumentation](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/setbuffer/)
beschreibt den Buffer als auf die laufende Symcon-Ausführung begrenzt und nach
Neustart gelöscht. Dagegen wird die `settings.json` laut
[Datensicherungsdokumentation](https://www.symcon.de/de/service/dokumentation/datensicherung/)
standardmäßig zyklisch gespeichert. Ein weiteres Attribut in derselben
zurückrollbaren Speicherung wäre deshalb keine unabhängige Sperre.

Die gewählte Trennung bietet unter diesem dokumentierten Buffervertrag einen
konkreten Gewinn: Während der Laufzeit kann das Diagnoseattribut zurückgerollt
werden, ohne den separaten Ausstellungs-/Verbrauchszustand zurückzusetzen.
Nach Neustart fehlt die Genehmigung vollständig, auch wenn das Diagnoseattribut
wieder `{}` oder `bereit` enthält. Der Diagnosecode stellt sie nicht wieder her.
Eine gleiche Kernelstartzeit genügt nicht zum Öffnen. Selbst ein absichtlich
erhaltener alter Freigabebuffer passt bei neuer Istnonce nicht mehr.

Damit muss kein absturzfester Attribut-Flush behauptet werden, um nach Neustart
standardmäßig zu schließen. Die Offline-Tests simulieren diese Eigenschaften;
sie sind kein nativer Symcon-Absturzbeweis. Der Kernelstartzeitstempel allein
ist kein nachgewiesener eindeutiger Laufzeitbezeichner.

## D. Keine automatische Freigabe

Quellcodeprüfung: Die Produktionsschreibpfade für den Freigabebuffer bestehen
nur aus Übergängen zu `ticket`, `reserviert`, `geschlossen` und `widerrufen`.
`genehmigt` wird ausschließlich geprüft; es gibt keine automatische Erzeugung
oder Zuweisung von `erwartet`. `Create`, Modulübernahme/`ApplyChanges`,
Anmeldung, Status, Geräteaktualisierung und Kernelstart initialisieren diesen
Buffer nicht. Zusätzliche RoboHub-Integrationstests prüfen diese Lebenszyklen.
Lokale Vorprüfung und der bisherige explizite Listen-GET erteilen weiterhin
keine Laufzeit- oder Ticketfreigabe.

Timeout, HTTP-Fehler, unklare Antwort, Widerruf und Ausnahmen öffnen keinen
Übergang zurück. Nach Transportbeginn bleibt die Laufzeitfreigabe geschlossen;
nach Neustart bleibt sie fehlend. Es gibt keine Timeout-Reparatur.

## E. In diesem Auftrag geänderte Dateien

- `RoboHubKonto/module.php`: getrennte Buffer-Reader/-Writer anschließen.
- `libs/RoboHubEurekaDiagnose.php`: Laufzeitprüfung und terminale Übergänge.
- `tests/eureka/diagnostic-test.php`: Rollback-, Neustart-, Sperr- und Fehlerfälle.
- `tests/eureka/diagnostic-http-test.php`: separate Offline-Laufzeitfixture.
- `tests/eureka/diagnostic-native-client.php`: separate native Offline-Fixture.
- `.tools/test-eureka.inc.php`: keine Freigabe durch normale Lebenszyklen.
- `docs/eureka-b8-diagnose.md`: neue Vorbedingung und Widerrufverhalten.
- `docs/eureka-b8-transportsicherheit.md`: historischen Stand kennzeichnen.
- `tests/eureka/README.md`: aktueller Prüfstand.
- `docs/eureka-b8-laufzeitsperre.md`: dieser Bericht.

Die zuvor vorhandenen Änderungen einschließlich `libs/RoboHubEureka.php`,
B8-Parser, Steuerungs-/Zustandsdokumentation und Offline-Runner bleiben erhalten.
Die Transportquelldatei wurde gegenüber dem vorgefundenen Stand bytegleich
belassen.

## F. Testergebnisse

Vollständiger finaler Lauf mit `bwrap --unshare-net`, keine externen
Transportverbindungen, Exitcode 0:

| Prüfstand | Bestanden | Fehler |
| --- | ---: | ---: |
| Konto-/Diagnose | 130 | 0 |
| Gemeinsamer HTTPS-Code mit cURL-Doubles | 18 | 0 |
| Sämtliche native HTTPS-/libcurl-Gegenversuche | 21 | 0 |
| Eureka-Befehle | 233 | 0 |
| Eureka-Zustände | 683 | 0 |
| Eureka-Token-/GET-Prüfungen | 72 | 0 |
| Vollständiger RoboHub-Prüfstand, andere Hersteller eingeschlossen | 1.194 | 0 |
| **Gesamt** | **2.351** | **0** |

**72 PHP-Dateien** ohne Syntaxfehler. Python-Syntax und `git diff --check`
fehlerfrei. Auch die neuen, noch unversionierten Dateien wurden auf
Diff-Whitespace geprüft. Die Transportquelldatei `libs/RoboHubEureka.php`
ist bytegleich zum vorgefundenen uncommitteten Stand.

Abgedeckt sind fehlende/falsche/defekte Laufzeitfreigabe, alte Freigabe bei neuer
Istnonce, Neustart und Attributrollback auf `{}`/`bereit` auch bei gleicher
Kernelzeit, Rollback innerhalb derselben Laufzeit vor/nach Reservierung und
Transport, Widerruf plus Rollback, fehlgeschriebener Buffer, erneute Prüfung
bei Reservierung und Transport, Fiber-Parallelität, Timeout und HTTP-Fehler.
Die fünf neuen Integrationstests prüfen die normalen Modul-Lebenszyklen ohne
Freigabe. Die 21 nativen Fälle einschließlich positiver Retry-Gegenkontrollen
bleiben unverändert erfolgreich; ihre detaillierte Matrix steht im
[Transportbericht](eureka-b8-transportsicherheit.md).

Reproduzierbarer Aufruf: `bash tests/eureka/run-offline.sh`.

## G. Risiken und Voraussetzungen

- Native Symcon-Semaphoren, Buffer-Lebenszyklus bei hartem Absturz und Sichtbarkeit
  zwischen Threads wurden nicht auf einem echten Symcon-Kernel geprüft.
- Voraussetzung ist der dokumentierte volatile Buffervertrag. Falls ein
  Zielsystem Buffer über Neustarts rekonstruiert, muss die Bereitstellung bis
  zur Klärung blockiert bleiben. Eine native Freigabe benötigt einen separat
  genehmigten isolierten Test mit synthetischen Daten und lokalem Transport.
- Vollständige VM-/RAM-Snapshots, Manipulation beider Speicher durch privilegierte
  Administratoren und erneute externe Genehmigung liegen außerhalb des Schutzes.
  Der Mechanismus ist keine lebenslange, administrativ unumgehbare Einmalgarantie.
- Eine künftige Bereitstellung muss dieselbe Semaphore respektieren, darf keine
  verbrauchte Genehmigung erneuern und keine automatische Wiederherstellung
  einführen. Ein belastbarer administrativer Ablauf ist noch zu entwerfen.
- Read-after-write beweist weiterhin keinen `fsync` für Attribute. Der permanente
  Verbrauch bleibt zusätzliche Abwehr, seine Datenträgerhaltbarkeit unbewiesen.
- Die native lokale libcurl-Testmatrix beweist keine genau einmalige Verarbeitung
  in Cloud oder Roboter und keine Sicherheit beliebiger libcurl-Versionen.

## H. Empfehlung für Commit und spätere Bereitstellung

Den vollständigen vorgefundenen Diagnose-/Transportstand zusammen mit dieser
Sperre und den Tests prüfen. Möglicher späterer Commit-Titel:
`Eureka B8: separate standardmäßig geschlossene Laufzeitfreigabe`.
Noch kein Commit erstellt. Bereitstellung und konkrete Laufzeitfreischaltung
bleiben getrennte, ausdrücklich zu genehmigende Folgeaufträge. Zuerst native
Buffer-/Semaphore-Prüfung in einer wegwerfbaren Offline-Symcon-Testinstanz.
Dieser Arbeitsstand bleibt lokal und wartet auf Freigabe.
