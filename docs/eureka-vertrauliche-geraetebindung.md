# Vertrauliche lokale Gerätebindung

Das Bindeskript verlangt vor der Freigabe den Flag `false` und ändert ausschließlich die feste
Cloud-ID im lokalen Treiber; es sendet keine Anfrage und verändert keine
Symcon-Instanz oder Einmalfreigabe.

## Eingabe direkt auf codex-dev

In einer eigenen interaktiven Terminalsitzung auf codex-dev ausführen:

```bash
cd ~/projekte/robohub-eureka
python3 -B .tools/eureka-geraet-binden.py --dock-nachweis /pfad/zum/bisherigen-dock-nachweis.json --geraeteinformationen /pfad/zur/vorhandenen-geraeteliste.json
```

Die Pfade vorher durch vorhandene lokale Dateien ersetzen. Keine Dateien mit
neu erfundenen Vergleichswerten erstellen. Die Cloud-ID ausschließlich in die
beiden verdeckten Eingabeaufforderungen eingeben, niemals als Shellargument,
Umgebungsvariable, Chatnachricht oder hier dokumentierten Wert. Ohne ein
geeignetes Terminal bricht die Eingabe ab; es gibt keinen sichtbaren Fallback.
Bei einer Terminalaufzeichnung muss diese für die Eingabe ausgeschaltet sein.

Der Dock-Nachweis ist die bereits vorhandene Genehmigungsnutzlast, deren lokale
`genehmigung`-Hülle oder das damalige Dokument mit Base64-`nutzlast`. Geprüft
werden Konto 45174, Instanz 22708, Modell, Dock-Zweck, der unveränderte Dockframe
und die individuelle Kennung. Die historische Hülle wird nur dekodiert; eine
Signaturprüfung oder erneute Gültigkeit wird dadurch nicht behauptet. Der
Betreiber muss die Herkunft als tatsächlich beim bestätigten Dock-Test
verwendeten lokalen Befund sicherstellen.

Die Geräteinformationen sind ein bereits lokal gespeicherter
`ROBOHUB_GeraeteListe`-JSON-Befund mit `hersteller=eureka` und `geraete`.
Genau ein Eintrag muss zur eingegebenen Kennung gehören. Modell, rohe Geräte-ID,
Produkt-SN8, Protokollversion, Plattform, Staubstation und Pluginversion werden
mit dem exakten E20-Profil verglichen; widersprüchliche Metadaten sperren.
Es wird kein neuer Export von Produktiv-Symcon angefordert.

Bei Abweichungen, fehlenden Dateien, einer fremden bereits eingetragenen ID
oder einem anderen Freigabeflag wird nichts geändert. Ausgegeben werden nur
allgemeine Erfolg-/Abbruchmeldungen, keine Kennungen und keine Tracebacks.
Der erfolgreiche Dateitausch ist atomar. Die Kennung steht anschließend wie
beauftragt im PHP-Quelltext: Dessen Inhalt und `git diff` daher nicht in Chat,
Terminalaufzeichnungen oder Testlogs ausgeben.

## Prüfung und Freigabeschritte

Nach erfolgreicher Eingabe den Agenten nur über den Abschluss informieren,
ohne die Kennung zu übermitteln. Anschließend den vollständigen Offline-Runner
`bash tests/eureka/run-control-offline.sh` erneut ausführen. Die Python-Tests
prüfen nur synthetische Kennungen, Fehlzuordnungen und unveränderte Dateien
bei verweigertem Abgleich; sie ersetzen nicht den tatsächlichen Vergleich.

Erst nach anschließendem Review darf in einem gesonderten Auftrag
`BEFEHLE_FREIGEGEBEN` auf `true` gesetzt werden. Danach folgen eine separat
beauftragte Aktualisierung und der dokumentierte Funktionstest
[Start → Pause → Fortsetzen → Dock](eureka-regulaere-steuerung.md).
Stopp, Orten und andere Modelle erhalten keine Freigabe.

## Lokaler Prüfstand dieses Folgeauftrags

Der vollständige Runner wurde erneut netzisoliert ausgeführt: Exitcode 0,
3.547 bestehende Prüfungen, vier zusätzliche Python-Testmethoden mit positiven
und negativen Abgleichfällen sowie 92 PHP-Lints bestanden. Nach der letzten
Ergänzung der Pluginmetadatenprüfung wurden die vier Python-Testmethoden erneut
erfolgreich ausgeführt. `git diff --check` ist fehlerfrei.
Der Betreiber hat die reale Eingabe und den erfolgreichen Abgleich gegen die
beiden vorhandenen Originalbefunde inzwischen bestätigt. Die feste Kennung ist
eingetragen. Der anschließende Freigabeauftrag setzt den Flag auf `true`;
der aktuelle Stand und die erneute Gesamtprüfung stehen im
[Steuerungsbericht](eureka-regulaere-steuerung.md).
Der lokale Commit enthält die individuelle Bindung und darf nicht gepusht werden.
Vor einer Remote-Veröffentlichung muss die Kennung aus allen zu übertragenden
Commits entfernt werden, mit gesonderter vertraulicher Bereitstellung der Bindung.
