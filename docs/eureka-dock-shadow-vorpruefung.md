# Native Dock-Vorprüfung: Shadow-Schranke

Lokale Analyse zu `04c4c49`, Konto 45174, Geräteinstanz 22708, IP-Symcon 9.1.
Keine Live-Diagnose durchgeführt; die beiden gemeldeten Texte allein erlauben
keine eindeutige Zuordnung des zweiten Fehlers zu einem einzelnen Feld.

## Konkrete Bedingung

`RoboHubEurekaDock::pruefen($k, true)` ruft zuerst die gemeinsame Diagnoseprüfung
auf. Meldung 1 bedeutet: Mindestens eine der Zeiten `GeraeteStand`,
`Geraete[].verbindungEmpfangen`, `Schnappschuss[id].p.zeit` fehlt, ist kein
positiver Integer, liegt in der Zukunft oder ist älter als 120 Sekunden.

Meldung 2 entsteht danach ausschließlich bei fehlendem Schlüssel
`Schnappschuss[id].p.roh.connected` im Ausnahmeweg, wenn mindestens eines gilt:

| Teilprüfung | Erwartung |
| --- | --- |
| `p.statusquelle.quelle` | Exakt `shadow-get` |
| `p.online` | Fehlend oder strikt boolesch `false` |
| `p.roh.state` | Exakt `stop`, `pause` oder `cleaning` |

`online=false` ist hier die dokumentierte Ableitung aus fehlendem `connected`.
Das ist kein bestätigter Offlinebefund. Explizites rohes `connected=false`
oder `roh.online=false` wird bereits von der gemeinsamen Diagnose gesperrt.
Der reguläre Befehlsplaner bleibt unverändert.

Die vorangehenden Prüfungen verlangen außerdem passende Gerätekennung
(`p.deviceId`), eine frische positive Listenmeldung (`online=true` und
`roh.connected=true`), `deviceFault=[]` im Shadow, konsistente Zustandsfelder,
widerspruchsfreie Identitäts-/Modellmetadaten, das exakte E20-Modell samt
Staubstation und eine lokal gültige Sitzung. Konto und Geräteinstanz sind fest
gebunden. Diese Anforderungen sind keine Bestandteile der zweiten Fehlermeldung.

## Abgleich mit beobachteten Antworten

Die anonymisierten `observed-shadow-fixtures.php` enthalten kein `connected`.
`pause` und `cleaning` bestehen die Zustandsliste; `charging_back`, `charging`,
`collecting_dust` und `ERP_model` scheitern trotz erfolgreichem GET an Meldung 2.
Diese Liste entspricht den bisherigen Ausgangszuständen für einen Stationsbefehl
im normalen Befehlsplaner. Ein Erfolg beim Abruf allein bedeutet somit keine
Eignung für den Dockversuch; der Meldungstext unterscheidet diese Gründe nicht.
Die vorliegenden Beobachtungen belegen keine Berechtigung, weitere Zustände
für Dock zuzulassen.

`RoboHubEureka::zustand()` setzt nach erfolgreicher Zielauswahl die Herkunft
`shadow-get` und das abgeleitete `online=false`; `zustandMitBlockSperre()`
übernimmt beides samt `roh`, `deviceId` und lokaler Zeit in den Schnappschuss.
Ein Verlust dieser Herkunft ist in diesem Speicherpfad nicht erkennbar.
Für ältere Schnappschüsse ohne Herkunft greift die Sperre ebenfalls.

## Nächster rein lesender Nachweis

Die öffentliche Kontomethode `EurekaDockShadowLesediagnose(): string` liefert
JSON mit den unveränderten zwölf Feldern der bisherigen Lesediagnose.
Exakter nativer Funktionsaufruf in IP-Symcon 9.1 (Konto 45174):

```php
echo ROBOHUB_EurekaDockShadowLesediagnose(45174) . "\n";
```

Auch als [natives Leseskript](../.tools/eureka-dock-shadow-lesediagnose.php)
hinterlegt. Voraussetzung ist eine Installation mit dieser Kontomethode;
dieser Auftrag installiert oder aktualisiert keine produktive Instanz.
Ohne vorherigen Refresh oder Modulaktionen ausführen. Die Methode liest
`GeraetID` der bisherigen festen Geräteinstanz 22708 mit `IPS_GetProperty()`
und intern ausschließlich die bestehenden Attribute `Schnappschuss` über
`ReadAttributeString()` sowie `GeraeteStand` über `ReadAttributeInteger()`.
`IPS_GetAttribute()` existiert in IP-Symcon 9.1 nicht und wird nicht verwendet.
Die Methode schreibt und protokolliert nichts, greift auf keine Buffer zu und
initialisiert auch keine Dock-Laufzeitkennung. Sie verwendet weder Treiber noch
Sitzung, Freigabepfad oder Cloud.
Die Ausgabe enthält ausschließlich feste Zustandskategorien und Wahrheitswerte,
keine Tokens, Kennungen, Rohantworten, Signaturen oder Freigaben.
`getHerkunftErfuellt`, `abgeleitetesOnlineErfuellt` und
`arbeitszustandErfuellt` isolieren exakt die drei Teilprüfungen.
`meldung2Teilbedingung` bildet nur diese lokale Schranke ab; sie ersetzt nicht
die gesamte Vorprüfung und sagt nichts über eine Freigabe aus.

Eine funktionale Lockerung ist aktuell nicht eindeutig belegt und wurde deshalb
nicht vorgenommen. Nächster Schritt ist die Auswertung dieser begrenzten Ausgabe
aus dem bereits vorhandenen Cache. Die Offlinefälle vergleichen die Diagnose mit
der nativen Vorprüfung für alle sechs beobachteten Shadowkombinationen und prüfen
unveränderte Attribute/Buffer sowie ausbleibenden Transport.

Validierung nach Umstellung auf die öffentliche Kontomethode:
`bash tests/eureka/run-dock-offline.sh` bestand mit Exitcode 0 im isolierten
Netznamespace und deaktivierter Signaturerzeugung: 244 Docktests, 41 HTTPS-,
399 Diagnose-, 284 Befehls-, 683 Zustands- und 72 Tokenprüfungen sowie
Gesamtprüfstand, 22 Struktur-Gegenproben und 84 PHP-Lints.
Die fünf zusätzlichen direkten Methodenfälle prüfen leere, defekte und fehlende
Shadows sowie unbekannte Zustände und explizit negative Verbindungssignale.
Sie verbieten jeden Attributschreib-, Buffer- und Debugzugriff und prüfen das
exakte Ausgabeschema. Die sechs beobachteten Zustände und drei isolierten
Teilbedingungen laufen über die native Wrapper-Nachbildung ohne einen
`IPS_GetAttribute()`-Teststub.
`git diff --check` bestand. Die Dock-Sicherheitsprüfungen bleiben unverändert.
Keine Cloudabfrage, kein Import, kein Deployment, kein Commit oder Push.
