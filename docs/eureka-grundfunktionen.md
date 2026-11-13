# Eureka E20 Evo Plus: Grundfunktionen

Stand 13.11.2026, Branch `feature/eureka-e20-evo-plus`, bestätigter Funktionsstand
`aff0f0a`. Diese Erweiterung ist ausschließlich lokal entwickelt und offline
geprüft. Keine Änderungen am Pi, keine Cloudabfragen, keine Roboterbefehle,
keine Commits, Pushs oder Deployments. Vorhandene B8-/Diagnoseänderungen bleiben
bestehen. Räume, Raum-IDs, Kartensteuerung und Atrium sind nicht bearbeitet.

## A: Reinigungsparameter und Statusnachweis

Quelle ist das lokale Herstellerarchiv `T0xB8_750Y0015.zip`, Version 2.5.4,
SHA-256 `0a275bbd2ee8c076aeb8e1d314a4bbe98fa5c3c6aeb3750414765adc399f24c6`.
Die Auszüge liegen in [analyse-2.5.4/modules](../../eureka-plugin-analyse/analyse-2.5.4/modules/).
Herstellercode wurde ausschließlich als Text untersucht.

| Herstellerbeleg | Ergebnis und Implementierungsabgleich |
| --- | --- |
| Modul 56489, Zeilen 98–124; Modul 98085, Zeilen 694–750 | E20 bietet soft/normal/high und Wasser off/low/normal/high. Der Konfigurationsname water.low bedeutet key off; water.soft bedeutet key low. |
| Modul 71825, Zeilen 16–44 | `mode auto=8`, fan soft=1, normal=2, high=3; water off=0, low=1, normal=2, high=3. |
| Modul 16153, `cmdStartWork`, Zeilen 2273–2338 | Rahmenbyte 15 mode, 16 fan, 18 water; normaler Aufruf ohne viertes Argument. Entspricht `RoboHubEurekaProtokoll::start()` exakt. |
| Modul 81717, `cmdStartWork(WORK_MODE_NAME.auto, …fan_level, …water_level)` | Neuer Vollauftrag übermittelt alle drei Parameter gemeinsam. Resume verwendet einen eigenen Befehl. |

Startbody: `22 02 00 02 00 08 FF 00 WW 00 00`, FF=1–3, WW=0–3.
Die reale Auswahl **Nur Saugen / Stark / Wasser Aus** entspricht fan=3,
water=0, mode=8, Frame `AA15B800000000000002220200020008030000000000`.
Die zwölf erlaubten Kombinationen bleiben unverändert. `mode=8` bezeichnet
Ganzhaus/auto; die Reinigungsart ergibt sich lokal aus Wasser aus/an.
Kein reines Wischen mit fan=0 und keine Freigabe allgemeiner fan-Enums 4/5.

| Begriff | Aussage |
| --- | --- |
| Sollwert | Die nativen Variablen `EurekaReinigungsart`, `EurekaStartSaugstufe`, `EurekaStartWasserstufe`; können nach dem letzten Start geändert worden sein. |
| Übertragene Sollwerte | Der Startframe trägt fan, water und mode. Der bestehende Cache speichert keinen belastbaren Verlauf der zuletzt gesendeten Parameter. Die aktuelle Auswahl ersetzt diesen Nachweis nicht. |
| Letzte Hersteller-Rückmeldung | `stand.roh.fanLevel`, `waterLevel`, `autoType` aus dem letzten nativen Shadow-GET, mit lokaler Empfangszeit. Keine bestätigte Gerätezeit. |
| Aktueller Istwert | Durch den Cache allein nicht sicher nachweisbar. Eine Übereinstimmung von Auswahl und Rückmeldung beweist weder Übernahme des letzten Befehls noch aktuelle mechanische Ausführung. |

Der vorhandene anonymisierte Befund in `observed-shadow-fixtures.php` meldet
fanLevel=`"3"`, waterLevel=`"2"`, autoType=`room_cleaning`. Dieser Befund belegt
Stark und Wasser Standard zum damaligen Rückmeldestand, nicht Wasser Aus des
späteren erfolgreichen Vollstarts. `autoType` wird nicht in B8-mode umgerechnet.
Die vorhandenen separaten Statusvariablen zeigen diese letzte Rückmeldung und
überschreiben die Auswahl für den nächsten Auftrag nicht.

Vorbereitet ist [.tools/eureka-parameter-lesediagnose.php](../.tools/eureka-parameter-lesediagnose.php).
Das eigenständige native Symcon-Skript liest ausschließlich den lokalen Snapshot
von Konto 45174 und Auswahlvariablen von Gerät 22708. Es prüft Modul-, Konto-,
Region- und Gerätezuordnung und wertet nur den nativen `shadow-get`-Cache aus.
Es gibt freigegebene Parameterwerte, lokale Empfangszeit/Cachealter und fan-/
water-Vergleich aus. Fehlende oder ungültige Werte bleiben null; mode- und
Teppichvergleich bleiben ohne belastbare Cloudzuordnung null. Letzte gesendete
Parameter und bestätigte aktuelle Istwerte bleiben ausdrücklich null.
Kein Refresh, ForwardData, HTTP, B8 oder Schreibzugriff; keine Token-/Kennungs-
oder Snapshotausgabe. Das Skript wurde hier ausschließlich mit Doubles geprüft,
nicht auf dem Pi ausgeführt.

## B: Teppichverstärkung

| Herstellerbeleg | Bedeutung |
| --- | --- |
| Modul 6590-25030, Zeilen 245–274 | App-Schalter `CARPET_MODE`, `CARPET_MODE_TIP`, `rugHanceMode`, cid `rugHance`. |
| Modul 7582-54356, Zeilen 589–610 | `changeStatus`: bisher aus → on, bisher ein → off; rugHance ruft `cmdCarpetSwitch` auf. |
| Modul 192-16153, Framebauer C Zeilen 1094–1112 | Kontrolltyp 02 und Kennung 22 mit Subkommando 0C erzeugen genau drei Bodybytes; kein zusätzlicher Einstellblock. |
| Modul 192-16153, Zeilen 2620–2647 | `cmdCarpetSwitch`: off=0, on=1, Kontrolltyp 02, Byte 10=22, Byte 11=0C, Byte 12=00/01. App liest danach Arbeitsstatus erneut. |
| Modul 192-71825, Zeilen 254–261 | Statusbyte 28 `carpet_switch`: 0=no, 1=yes. Das ist B8, keine belegte HTTPS-Shadow-Zuordnung. |
| Modul 6590-39832, Zeilen 727–728; 7582-77582, Zeilen 1270–1282 | App wertet ausschließlich `carpet_switch == yes` als eingeschaltet aus. |

Implementiert sind nur **Aus / Ein** als native deutsche Integerauswahl
`EurekaTeppichmodus` („Teppichverstärkung (Sollwert)“). Initial -1: keine Vorgabe.
Die Auswahl ist rein lokal und persistent. Die separate native Aktion
`EurekaTeppichAnwenden` („Anwenden“) sendet ausschließlich bei ausdrücklicher
Bedienung den ausgewählten Wert. Keine zusätzliche Oberfläche, kein Senden
beim Start/ApplyChanges und keine Kopplung an den Reinigungsstart.

Aus: `AA0DB800000000000002220C000B`.
Ein: `AA0DB800000000000002220C010A`.

Der bestehende geschützte Befehlsweg übernimmt individuelle Geräte-/Profilbindung,
Sitzung, Frische, Online-/Fehlerprüfung, Semaphore, Verbrauch vor Transport und
Retryverbot. Die neue Aktion ist konservativ auf konsistente Zustände stop,
charging, charging_finish, pause und cleaning sowie den exakt bestätigten
regulären Schlaf ERP_model/sleep ohne Auftrag beschränkt. Andere Schlafzustände,
Stationsarbeit, Fehler und unbekannte Zustände bleiben dafür gesperrt. Beleg und
Grenzen: [Teppich-Schlafnachweis](eureka-teppich-schlaf-nachweis.md). Eine geänderte Auswahl
vor dem Transport sperrt den Versand. Vorhandene Start-/Pause-/Resume-/Dock-
Prüfungen und deren erlaubte Zustände bleiben unverändert.

Die App-Abfolge mit zusätzlichem requestWorkState wird nicht übernommen; es
gibt keinen neuen automatischen B8-Befehl. Eine Cloudannahme ist keine bestätigte
Geräteübernahme. Ohne belegte HTTPS-Zuordnung gibt es keinen erfundenen Teppich-
Istwert. Die separate schreibgeschützte native Anzeige `EurekaTeppichIstwert`
bleibt deshalb vorerst „Unbekannt“; sie übernimmt weder Sollwert noch Cloud-ACK.
Moppanhebung, Teppichvermeidung, Mehrfachmodi oder Wasseränderung werden
nicht aus diesem binären Verstärkungsschalter abgeleitet oder freigeschaltet.

## C: Zustandsanzeige

| Zustand | Beleg, Anzeige und Grenze |
| --- | --- |
| collecting_dust | Lokaler realer Shadowbefund: parent collecting_dust, Mission no_cleaning_mission. „Entleert Staub“, Stationsarbeit; unverändert. |
| sleep_pause | Lokal real beobachtet mit parent sleep, have_cleaning_mission und leerem deviceFault: „Schläft (Auftrag pausiert)“; vorhandene Resumeprüfung bleibt erhalten. |
| ERP_model | Lokal real beobachtet mit parent sleep und no_cleaning_mission: expliziter deutscher Herstellerstatus „Schläft“ ergänzt. Vorhandene differenzierte Startprüfung bleibt erhalten. |
| to_collect_dust | In den lokalen Herstellerartefakten und Beobachtungsfixtures kein eindeutiger Cloudbeleg gefunden. Keine neue Normalisierung oder Befehlsfreigabe; Rohwert bleibt sichtbar und Zustand unbekannt. |

Die Cloudstrings sind keine B8-Enums. Belege zu Schlafuntertypen und Grenzen:
[eureka-start-aus-schlaf.md](eureka-start-aus-schlaf.md),
[eureka-sleep-pause.md](eureka-sleep-pause.md),
[eureka-zustaende.md](eureka-zustaende.md).

## D: Offline-Verifikation und offene Punkte

Prüfbefehl: `bash tests/eureka/run-control-offline.sh`.
Die Suite verweigert Ausführung ohne Netzwerkisolation (`bwrap --unshare-net`);
HTTP-/TLS-Gegenstellen sind ausschließlich lokales isoliertes Loopback.
Zusätzlich zu bestehenden Start-/Pause-/Resume-/Dock-, B8- und Diagnosetests:

- Alle zwölf Startkombinationen und Bytevektoren, lokale Auswahl/Persistenz und getrennte Shadowwerte.
- Teppichframes Aus/Ein, ungültige Typen/Werte/Parameter, Zustands-, Fehler-, Offline- und Frischegrenzen.
- Native Teppichauswahl ohne GET/POST, ausdrückliches Anwenden, fehlende Auswahl und Änderung vor Transport.
- Cachevergleich mit widersprüchlichen, fehlenden, typfalschen und alten Rückmeldungen; ausschließlich erlaubte lokale Leseaufrufe.
- Zustandsnormalisierung einschließlich unbekanntem to_collect_dust, PHP-Lint über alle PHP-Dateien und `git diff --check`.

Ergebnis am 13.11.2026: vollständiger isolierter Lauf erfolgreich (Exitcode 0),
einschließlich bestehender Offline-Suite, neuer Tests, PHP-Lint über alle
PHP-Dateien und `git diff --check`.

Offen bleiben reale Einzelbestätigung von fan/water/mode nach Vollstart,
reale Übernahme des Teppichschalters, dessen HTTPS-Statuszuordnung und ein
belastbarer Cloudbeleg für to_collect_dust. Hierfür wurde keine Cloudabfrage
und kein Roboterbefehl ausgelöst. Ein produktiver Einbau ist nicht erfolgt.
