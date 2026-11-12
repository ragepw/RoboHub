# Beaufsichtigter Pause-Einmaltest im regulären IP-Symcon-Befehlsweg

Stand 12.11.2026, lokale Entwicklung auf `feature/eureka-e20-evo-plus`, Basis
`de811a9`. Die Implementierung ist vorbereitet, aber nirgends bereitgestellt
oder freigeschaltet. Es wurden keine Cloud-Anfragen, Roboterbefehle,
Produktivänderungen, Genehmigungsdokumente, Importe, Commits oder Pushs ausgeführt.
Alle unten beschriebenen Freigaben und Verbräuche wurden ausschließlich mit
erfundenen Daten in lokalen Transportdoubles geprüft.

## Umfang und unveränderte Sperren

`RoboHubEurekaPauseTest` ist eine lokale Berechtigungsprüfung ohne eigenen
Transport. Die neue native Kontofunktion
`ROBOHUB_EurekaPauseTestFreigeben(45174, $cloudGeraetId, $sekunden, $bestaetigung)`
ist ein ausdrücklich administrativer, beaufsichtigter Bedienaufruf. Sie sendet
nichts und erzeugt/importiert kein externes Genehmigungsdokument. Es gibt keine
zusätzlichen Linux-Dienste, Signierer, Schlüssel, Timer, Formularknöpfe oder
Steuerungsvariablen. Der spätere Test verwendet die vorhandene Geräteinstanz
22708 und deren Befehlsvariable 22284.

Die drei globalen Produktivsperren in `RoboHubEureka` bleiben unverändert:
`BEFEHLE_FREIGEGEBEN=false`, `BEFEHLS_ZUORDNUNGEN=[]`,
`BEFEHLS_GERAET_ID=''`. Ausschließlich ein gültiger lokaler Pause-Testkontext
darf sie im exakt vorbereiteten regulären Pauseplan überbrücken. Start,
Fortsetzen, Station/Dock, Stopp und Orten bleiben gesperrt. Auch
`ROBO_Anhalten` und andere Skript-/Automatikeinstiege ohne den bestehenden
manuellen Bedienmarker bleiben gesperrt.

Die Dateien `RoboHubEurekaB8.php`, `RoboHubEurekaDiagnose.php`,
`RoboHubEurekaDock.php` und `RoboHubEurekaGenehmigung.php` sowie die bisherigen
B8-/Dock-Einstiegsmethoden sind unverändert. Deren Zustände, Genehmigungen und
Verbräuche können keine Pause erlauben. Die Pause-Berechtigung verändert diese
Zustände ebenfalls nicht.

## Feste Bindung, kurze Dauer und Beaufsichtigung

Die Freigabe verlangt:

- Konto 45174, Hersteller Eureka, EU und betriebsbereiten Symcon-Kernel;
- Geräteinstanz 22708, richtige Modul-GUID, Parent 45174, nicht deaktiviert und
  Befehlsident `Befehl` mit Objekt-ID 22284;
- eine vom Bediener **individuell und unabhängig bestätigte** Cloud-Gerätekennung
  als expliziten Parameter, exakt gleich der aktuellen Gerätezuordnung;
- das vollständig geprüfte E20-Evo-Plus-Profil einschließlich Plugin 2.5.4 und
  `dust_station`;
- einen nativ angemeldeten Administrator mit bekannter Benutzerkennung;
- den exakten Text aus `RoboHubEurekaPauseTest::BESTAETIGUNG`, der aktuelle
  Vor-Ort-Beaufsichtigung und Reinigung bestätigt;
- eine explizite Dauer von 1 bis höchstens 60 Sekunden.

Die Cloud-ID wird weder aus einer Produktkennung wie SN8 abgeleitet noch
vorausgefüllt. Die tatsächliche individuelle ID wurde für diese Entwicklung
nicht bestätigt und wird hier nicht angegeben. Die Testfixture `offline-e20`
ist ausschließlich erfunden.

Der gespeicherte Bindungshash umfasst Konto, Instanz, Variable, individuelle
Cloud-ID, Kernel-/Modullaufzeit, Sitzungsrevision, vollständiges Tokenbündel,
Kontonamen und beaufsichtigenden Benutzer. Bei jeder Prüfung muss derselbe
bekannte Administrator angemeldet sein. `@unknown`, Benutzerwechsel,
Tokenrotation, Ab-/Neuanmeldung, Gerätewechsel und Neustart sperren. Ein späterer
Rückwechsel erlaubt keine zweite Ausstellung, sobald eine Freigabe gespeichert
wurde. Beide Zeitgrenzen gelten parallel: Systemzeit und monotone Uhr. Ablauf,
Widerruf und Verbrauch erlauben keine Verlängerung oder neue Ausstellung.

## Vorbedingungen vor dem Versand

Die Prüfung verwendet ausschließlich bereits lokal vorliegende Daten aus dem
regulären Listen-/Shadow-Lesepfad; die Freigabe führt keine GETs oder
Token-Erneuerung aus. Vor dem späteren Test müssen diese Daten über die
bestehenden nativen Leseaktionen aktualisiert worden sein.

Listenabrufzeit, Empfangszeit des Listenverbindungsbefunds und Shadowzeit müssen
jeweils positiv, höchstens 120 Sekunden alt und nicht zukünftig sein. Die Liste
muss das Ziel genau einmal enthalten, `connected=true` und normalisiert
`online=true` melden. Ein vorhandenes anderes Listen-`online`, ein widersprüchliches
rohes `id`/`deviceId` oder ein negativer/unklarer Listenfehlerbefund sperrt.

Der Shadow muss über `shadow-get` eindeutig diesem Ziel, der aktuellen
Sitzungsrevision, der Modullaufzeit und dem Kernelstart zugeordnet sein. Es gelten
die vorhandenen Modell- und Identitätsgegenprüfungen des regulären Kontokontexts.
Nur `state=cleaning`, konsistente Zustandsfelder und explizites
`deviceFault=[]` sind zulässig. Pause, Stopp, Laden, Stationsarbeit, Rückfahrt,
Fehler, unbekannte und widersprüchliche Zustände sperren.

**Fehlendes Shadow-`connected` bleibt unbekannter Geräte-Onlinestatus.**
Der unveränderte manuelle Planer darf sich in diesem Fall auf die frische
Cloudliste und einen gültigen Shadow stützen. `stand.online=false` bleibt dabei
unverändert; es wird kein `connected=true` ergänzt. Jeder ausdrückliche negative
oder unklare Verbindungswert in vorhandenen Rohfeldern sperrt. Für den Fall ohne
Shadow-`connected` bleiben insbesondere `statusquelle.geraetezeit=null` und der
bestehende abgeleitete unbekannte Befund Voraussetzung. Eine lokale Abrufzeit
beweist keine sofortige Aktualität des Cloudzustands am Roboter; die bewusste
Vor-Ort-Beaufsichtigung ist deshalb Bestandteil der Freigabe.

Die EU-Sitzung muss lokal gültig sein, einschließlich JWT-Ablauf mehr als
15 Sekunden in der Zukunft. Aktive Cloud-Wartezeit sperrt. Der Pause-Test löst
weder vor dem Befehl noch nach HTTP-401 einen Refresh oder eine Wiederholung aus.
Andere reguläre Leseaktionen behalten ihre bestehende Sitzungsverwaltung.

## Einmalverbrauch und gemeinsamer Transport

Der Ablauf bleibt:

`RequestAction(22284, Pause)` → Geräteinstanz 22708 → reguläres `ForwardData`
mit manuellem Bedienmarker → `RoboHubEureka::befehl` →
`RoboHubEurekaBefehlsweg` → bestehender `transport` → `httpAusfuehren`.

Es gibt keine neue Test-Sendemethode. Die normale Geräte-Semaphore bleibt
prozess-/kontoübergreifend an dieselbe Cloud-ID gebunden. Der geprüfte Plan
verwendet ausschließlich `POST /devices/commands/<bestätigte Cloud-ID>` und
`{"data":"AA0DB800000000000002221B00FC"}`. Dies ist der bereits belegte
Pauseframe; der Dock-Nachweis wird nicht als realer Pause-Nachweis ausgegeben.

Der neue Kontoattribut-/Buffername ist jeweils `EurekaPauseTest`. Die separate
kurze Semaphore `RoboHubEureka_PauseTest_45174` serialisiert Erteilung,
Verbrauch, Widerruf und Transportbeginn. Das persistente Attribut und der
flüchtige Buffer müssen exakt übereinstimmen. Jede Speicherung schreibt zuerst
den flüchtigen Zustand und dann das Attribut; auch ein wirkungsloser erster
Schreibaufruf versucht ein persistentes Veto. Unterschiedliche oder unlesbare
Speicherstände erlauben keinen Versand. Es gibt keine automatische Reparatur
und keinen Reset-Einstieg.

Zustände: leer → `bereit` → `verbraucht` → `geschlossen`; vor dem Transportbeginn
ist `widerrufen` möglich. Bereits die Erteilung sperrt eine zweite Erteilung.
Unter der vorhandenen Gerätesperre wird der Test vor dem Transportadapter
verbraucht; zusätzlich greift unverändert der reguläre Verbrauch des
Shadowstandes in `EurekaBefehlsversuch`. Neue Treiberobjekte, frische Shadows oder
weitere Betätigungen eröffnen keinen neuen Testversuch.

Der optionale zusätzliche lokale Prüfrückruf im regulären Befehlsweg prüft den
verbrauchten Testkontext bei jeder Transportprüfung. Unmittelbar vor
`curl_exec`, nach der gesamten cURL-Konfiguration, setzt er unter derselben
Freigabesemaphore den Zustand `geschlossen` und bestätigt den Transportbeginn
in beiden Speichern. Ein davor erfolgreich abgeschlossener Widerruf verhindert
I/O. Ein Widerruf nach diesem Punkt kann den begonnenen Transport nicht
zurückholen und wird entsprechend abgewiesen.

HTTPS bleibt die vorhandene gemeinsame Implementierung mit HTTP/1.1, frischer
Verbindung, ohne Verbindungswiederverwendung, Weiterleitungen, Proxy,
Auth-Aushandlung oder Expect-Neuversand sowie mit 8/15 Sekunden Zeitlimits.
Konfigurationsfehler, Timeout, Protokollfehler, HTTP-/Cloudfehler und unklare
Antworten bleiben endgültig verbraucht, selbst wenn kein I/O nachweisbar war.
Der gemeinsame Antwortparser meldet nur Cloudannahme; tatsächliche
Pausenausführung wird daraus nicht abgeleitet.

Ein erhaltenes Attribut sperrt auch nach Neustart, ein Bufferverlust erlaubt
keine Nutzung eines alten `bereit`. Ein Attributrollback bei erhaltener Laufzeit
scheitert am Buffervergleich. IP-Symcon-Speicherung ist kein nachgewiesenes
`fsync`-Journal; administratives Löschen beider Zustände oder vollständiges
Zurückspielen alter Backups liegt außerhalb dieses Laufzeitschutzes und darf
nicht zum Wiederholen des Tests verwendet werden.

## Offlineprüfung

Ausgeführt: `bash tests/eureka/run-pause-offline.sh`, Exitcode 0,
PHP 8.2.35. Der Runner erzwingt einen eigenen Netzwerk-Namespace ohne externen
Netzzugriff. Eureka-Verbindungen sind Transportdoubles; die bestehenden nativen
libcurl-Gegenversuche und Hersteller-Nachbauten nutzen ausschließlich isoliertes
Loopback. Signatur-/Schlüsselgeneratoren sind deaktiviert. Signierer-,
Bereitstellungs- und Importprüfstände, die Dokumente erzeugen oder importieren,
werden wegen der Auftragsgrenzen nicht ausgeführt. Die älteren allgemeinen
Runner werden für diesen Auftrag nicht verwendet.

| Prüfstand | Bestanden |
| --- | ---: |
| Pause-Sicherheits- und native Kontogegenproben | 114 |
| Gemeinsamer HTTPS-Code mit cURL-Doubles | 97 (davon 20 neue Pause-Gegenproben) |
| Bestehender Dock-Einmalpfad | 244 |
| Bestehender B8-Diagnosepfad ohne Signaturerzeugung/Import | 399 |
| Native libcurl-Gegenversuche auf isoliertem Loopback | 21 |
| Reguläre Befehle | 344 |
| Zustandsbewertung | 683 |
| Token-/GET-Regressionen | 72 |
| RoboHub-Gesamtprüfstand | 1.281 |
| Modulstruktur-Gegenproben | 22 |
| PHP-Lint | 92 Dateien |

Der vollständige Runner enthielt zunächst 108 Pause-Gegenproben. Sechs danach
hinzugefügte Gegenproben zu konkurrierenden Anforderungen, Gerätesperrfehlern,
Ein-/60-Sekunden-Grenzen, endgültiger Schließung und B8-/Dock-Isolation wurden
anschließend mit dem vollständigen Pause-Prüfstand separat erneut erfolgreich
ausgeführt. Der Produktivcode wurde danach nicht verändert. Abschließendes
PHP-Lint und `git diff --check` sind fehlerfrei.

Abgedeckt sind auch falsche ID/Bestätigung, fehlende Freigabe, Administrator- und
Benutzerwechsel, Geräte-/Variablen-/Parentbindung, Doppelliste, Listen-/Shadowalter,
explizit offline gegenüber unbekannt, Zustands-/Fehlerbefunde, ungültige Sitzung,
Uhrsprünge, Widerruf in der cURL-Vorbereitung, Kontextänderungen vor I/O,
Schreibfehler beider Speicher, Neustart, Attributrollback und neue Shadowstände.
Die native Integration verwendet die unveränderte echte Treiberklasse mit allen
drei geschlossenen Globalsperren und bedient die vorhandene Variable über das
echte Gerätemodul. Alle anderen Aktionen und Automatikeinstiege bleiben zu.

## Verbleibende Voraussetzungen und nächster Schritt

Vor einem realen Test fehlen weiterhin eine gesonderte ausdrückliche
Bereitstellungs-/Testfreigabe, die unabhängige Bestätigung der individuellen
Cloud-Gerätekennung und die native Prüfung, dass der beaufsichtigende
Administrator sowohl beim Freigabeaufruf als auch bei der Variablenbedienung
mit derselben bekannten Benutzerkennung erkennbar ist. `@unknown` wird nicht
ersatzweise akzeptiert. Reale Pause-Reaktion und Cloud-Latenz sind nicht geprüft.

Nächster Schritt ist die lokale Prüfung dieses Diffs und des beschriebenen
Ablaufs. Erst nach gesonderter Autorisierung darf der geprüfte Stand in
IP-Symcon bereitgestellt werden. Danach im selben Kernel-/Modullauf und in
gültiger Sitzung die Liste und den Shadow nativ neu lesen, aktive Reinigung
und Fehlerfreiheit vor Ort prüfen und die explizite Kurzfreigabe erteilen.
Anschließend genau einmal **Pause** über Variable 22284 bedienen und das reale
Anhalten vor Ort beobachten. Die Antwort und die Beobachtung getrennt bewerten;
bei unklarem Ausgang oder fehlendem Anhalten nicht erneut bedienen. Auch eine
unbenutzte, abgelaufene oder widerrufene Freigabe wird nicht erneut erteilt.
Nach Auswertung bleibt die allgemeine Produktivsteuerung dreifach gesperrt.
