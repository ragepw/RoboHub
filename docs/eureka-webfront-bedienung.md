# Klassisches WebFront: Bedienrückmeldung (13.11.2026)

Untersuchter Basisstand: c98e398. Ausschließlich lokale Quelltextprüfung und
netzisolierte Offline-Doubles; kein Zugriff auf Pi oder Cloud.

## Nachgewiesener Fehler

`ApplyChanges` erhält die Integer-Variable `Befehl`, deren fünf Werte und
`EnableAction`. `RequestAction('Befehl', 2)` übersetzt zu `pause` und übergibt
`Bedienung=manuell` über `frageKonto` an `ForwardData`. Konto und Treiber prüfen
weiterhin Gerätebindung, Sitzung, Zustandsalter und Einmalverbrauch.

`ForwardData` fängt Ausnahmen und liefert `ok=false` mit Fehlertext zurück.
Das Gerät schreibt diesen Fehler in das Meldungsprotokoll und die eigene
Kachel; `fuehreAus` liefert false. Der bisherige RequestAction-Zweig ignoriert
dieses Ergebnis. Das klassische WebFront erhält keine Rückmeldung. Außerdem
fehlte auf diesem gesamten regulären Bedienweg jede Geräte-Debugausgabe.
Ein leeres Debug beweist deshalb nicht, dass der Klick das Modul nicht erreicht.

Die [Symcon-Dokumentation zur Aufzählung](https://www.symcon.de/de/service/dokumentation/komponenten/objekt-darstellung/aufzaehlung/)
führt diese Darstellung ausdrücklich auch für das WebFront auf. Es gibt keinen
belegten Anlass, sie durch ein Legacy-Profil zu ersetzen.
Laut [RequestAction-Dokumentation](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/requestaction/)
werden Ausgaben über echo an die Visualisierung weitergeleitet.

## Lokale Korrektur und Grenzen

Nur der Zweig `Befehl` meldet Eingang und Ergebnis mit festem Befehlsnamen
im Geräte-Debug. Bei Fehlschlag gibt er einen übersetzten, festen Hinweis auf
das bestehende Meldungsprotokoll und die notwendige Statusaktualisierung aus.
Die neue Ausgabe enthält keine Rohantworten, Gerätekennungen oder Zugangsdaten.
Erfolg bedeutet Versand/Cloudannahme, keine bestätigte Roboterbewegung.
Darstellung, Aktionsbindung, Freigaben und sämtliche Kontoprüfungen bleiben bestehen.

Welche konkrete Ablehnung beim gemeldeten Live-Klick auftrat, ist ohne damaliges
Meldungsprotokoll nicht nachgewiesen. Ebenso kann die Offlineprüfung keinen
Browserklick oder eine eventuell konfigurierte eigene Variablenaktion überprüfen.
Es wird keine zusätzliche Transport- oder Darstellungsursache behauptet.

## Offlineprüfung

`.tools/test-eureka-control.inc.php` ergänzt den vorhandenen Prüfstand um ein
Variablen-ID-/Standardaktionsdouble für 22284. Es prüft aktive Integer-Aufzählung,
Pause mit genau einem Transport, unveränderten Zustand/Tasterwert sowie
Rückmeldung und Debug bei verbrauchtem Stand, falschem Zustand, fehlender
Kontobindung, deaktiviertem Gerät, altem Stand und Cloudfehler.
Ungültige lokale Voraussetzungen erzeugen keinen Transport. Die bestehenden
Bindungs-, Sitzungs-, Zustands- und Wiederholungsprüfungen bleiben erhalten.

Vollständiger Runner einschließlich PHP-Lint: `bash tests/eureka/run-control-offline.sh`.

Ergebnis: Vor der Korrektur scheiterten genau die sieben neuen Prüfungen für
Debug/Rückmeldung. Nach der Korrektur bestand der vollständige Runner mit
Exitcode 0 einschließlich aller neun neuen Prüfungen, bestehender Offline-Tests,
PHP-Lint für 93 Dateien und `git diff --check`.
