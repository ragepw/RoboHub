# Externe Dock-Signierung v2

Lokaler Stand vom 12.11.2026. Implementiert: separater CLI-Signierer
[`.tools/eureka-dock-signieren.php`](../.tools/eureka-dock-signieren.php).
Keine Produktivänderung, kein reales Genehmigungsdokument, keine Schlüsselinstallation.
Sicherheits-, Import- und Transportsperren im Modul bleiben unverändert.

## Schnittstelle und Schlüsseltrennung

Auf einem getrennten Administrationsrechner mit PHP CLI und Sodium ausführen.
Keine Argumente. STDIN enthält höchstens 4096 Bytes JSON mit exakt diesen Feldern
in dieser Reihenfolge:

```text
{
  "genehmigung": {
    "zweck": "robohub/eureka/dock/runtime/v2",
    "konto": 45174,
    "instanz": 22708,
    "cloudGeraetId": <extern geprüfte individuelle Gerätekennung als String>,
    "modell": "E20 Evo Plus",
    "befehl": "AA0DB80000000000000222010016",
    "erwartet": <vollständige aktuelle Dock-Laufzeitanzeige als String>,
    "von": <aktuelle ganze Unixsekunde>,
    "bis": <ganze Unixsekunde, größer von, höchstens von + 60>,
    "ausnahmeVerbindungUnbekannt": true
  },
  "externGeprueftUndGenehmigt": <unabhängig bestätigter gleicher Laufzeitstring>,
  "cloudGeraetExternGeprueftUndGenehmigt": <unabhängig bestätigte gleiche Gerätekennung>
}
```

Das ist ein Schema mit Platzhaltern, kein verwendbares Genehmigungsdokument.
Feldreihenfolge und Typen sind Teil des vorhandenen Importformats.
Die externe Stelle prüft Konto, Instanz, Gerät, Laufzeit, konkreten Befehl und
Ausnahme bewusst vor der Signierung. Bestätigungsfelder nicht aus einer
ungeprüften Anforderung automatisch kopieren. Dieser Signierer verlangt v2 und
`ausnahmeVerbindungUnbekannt=true`; B8 und Dock-v1 werden zurückgewiesen.
Die bestehende v2-Importprüfung erlaubt weiterhin `false` nur mit positivem
Verbindungsnachweis. Das Werkzeug erweitert diese Importregeln nicht.

FD 3 erhält aus der **separaten Dock-Schlüsselverwaltung** den Base64-kodierten
64-Byte-Ed25519-Secret-Key. FD 4 erhält unabhängig daraus den geprüften
Base64-kodierten 32-Byte-Dock-Public-Key. Der Signierer verlangt ein passendes
Paar, prüft das resultierende Dokument mit der unveränderten Dock-Prüfung und
gibt ausschließlich bei Erfolg die Hülle auf STDOUT aus: exakt `nutzlast`,
`signatur`, beide Base64, Ed25519 über die ausgegebenen Nutzlastbytes.
Fehler: Exit 1, leeres STDOUT, generische Meldung ohne Eingabe oder Schlüssel.
Schlüsselbytes werden nach Verwendung soweit möglich aus dem PHP-Speicher entfernt.

Keine Schlüsselpfade, Umgebungswerte, automatische Schlüsselwahl oder
B8-Fallbacks. Ein B8-Schlüssel darf durch den Betreiber auch nicht manuell als
Dock-Paar zugeführt werden: Kryptografie allein erkennt den Verwaltungszweck
eines ausdrücklich übergebenen Schlüsselpaars nicht. Für Dock ist eine eigene
Schlüsselidentität mit eigenem Freigabeprozess vorzusehen; der private Schlüssel
bleibt außerhalb von Symcon und Repository. Dieses Werkzeug erzeugt keine Schlüssel.

## Vertrauensankerprüfung

Unveränderter fester Importpfad: `/etc/robohub/eureka-dock.pub`.
Der Quellcode prüft `/etc`, `/etc/robohub` und die Datei mittels `lstat`:
Root-Eigentum, keine Gruppen-/Fremdschreibrechte, keine Symlinks,
Verzeichnisse bzw. reguläre Datei, begrenzte Dateigröße. Bei nachgewiesener
Nicht-Root-EUID zusätzlich kein Schreibzugriff des Prozesses. Die kryptografische
Prüfung verlangt nach Base64-Dekodierung 32 Bytes.

Lokaler rein lesender Befund: `/etc` ist Root-eigen mit Modus 0755;
`/etc/robohub` und damit der Dock-Anker fehlen. Es wurde nichts angelegt,
überschrieben oder aus dem B8-Anker übernommen. Dies ist kein Befund zum
Produktivsystem. Später muss die Administration den Produktivpfad und alle
Komponenten prüfen, den Schlüssel über einen unabhängigen authentifizierten Weg
mit FD 4 abgleichen und die separate Dock-Schlüsselidentität bestätigen.
Bei vorhandenem Anker niemals automatisch ersetzen; bei Abweichung abbrechen
und die Schlüsselzuordnung separat klären. Keine privaten Schlüssel auf Symcon.

## Kurze spätere Schrittfolge (hier nicht ausgeführt)

Voraussetzung: gesondert autorisierte Bereitstellung, geprüfter Dock-Anker,
native Administratorerkennung und frische zulässige lokale Listen-/Shadowdaten.
Uhren der beiden Rechner müssen übereinstimmen. Beobachter und Übertragungsweg
vor Beginn vorbereiten; die gesamte Genehmigung lebt maximal 60 Sekunden.

1. **Dock-Laufzeit lesen:** als nativ angemeldeter Administrator
   `ROBOHUB_EurekaDockVorpruefen(45174)` und danach
   `ROBOHUB_EurekaDockLaufzeitAnzeigen(45174)` verwenden. Die vollständige
   Anzeige geschützt an die externe Stelle geben. Konto 45174, Instanz 22708
   und individuelle Gerätekennung unabhängig abgleichen. B8-Anzeige ist ungültig.
2. **Extern erstellen:** Schema oben bewusst ausfüllen, `von` auf jetzt setzen,
   `bis` maximal 60 Sekunden später. Die Einwilligung gilt ausschließlich für den
   beaufsichtigten einmaligen Dockversuch bei unbekanntem Verbindungsstatus.
3. **Extern signieren:** die Dock-Schlüsselverwaltung stellt FD 3 und FD 4 über
   anonyme Pipes bereit und führt `php .tools/eureka-dock-signieren.php` mit dem
   geprüften JSON auf STDIN aus. STDOUT in einen geschützten kurzlebigen Speicher
   übernehmen. Keine Schlüssel/JSON als Kommandozeilenargumente, keine Debugausgabe,
   kein `set -x`, kein Shell-Verlauf mit Inhalten, keine Terminalaufzeichnung.
4. **Sicher übertragen:** ausschließlich die signierte Hülle über einen bereits
   authentifizierten verschlüsselten Administrationskanal nach Symcon übertragen
   (z. B. SSH/SFTP mit geprüftem Hostschlüssel, Ziel außerhalb Webroot/Repository,
   nur für den vorgesehenen Administrator lesbar). Keine E-Mail, Tickets,
   Chatablagen, öffentliche Zwischenablagen oder dauerhaften Skriptquelltexte.
   Falls Dateien nötig sind: restriktive Rechte und kurzlebiges Verzeichnis vorab
   einrichten; danach löschen, auch bei Abbruch. Keine privaten Schlüssel übertragen.
5. **Einmal importieren:** Dokument im Speicher der authentifizierten administrativen
   Ausführung an `ROBOHUB_EurekaDockBereitstellen(45174, $dokument)` übergeben.
   Keine Hülle in Logs/Debug/gespeicherten Symcon-Skripten ablegen.
   Import sendet keinen Befehl. Bei Fehler oder Zeitablauf abbrechen; keine
   Zustandsbuffer/Verbrauchsmarken löschen und keinen Wiederholungsautomatismus nutzen.
6. **Vor Ort bestätigen:** unmittelbar vor Ticketausgabe Betriebsbereitschaft,
   freien Rückweg und zulässigen Ausgangszustand prüfen; Beaufsichtigung beginnen.
   Bei negativem/widersprüchlichem Verbindungsbefund abbrechen. Unbekannt bleibt
   unbekannt; Vor-Ort-Beobachtung ersetzt keine Softwareprüfung.
7. **Ticket erzeugen:** einmalig
   `ROBOHUB_EurekaDockFreigeben(45174, 60, $bestaetigung, $vorOrt)` aufrufen.
   `$bestaetigung` exakt: `E20 Evo Plus einmalig zur Station fahren`.
   `$vorOrt` exakt: `Verbindung unbekannt: Ich habe den E20 Evo Plus jetzt vor Ort als betriebsbereit und den Rückweg als frei geprüft und beaufsichtige den einmaligen Docktest`.
   Ticket aus JSON-Feld `freigabe` ausschließlich im Speicher halten; tatsächliches
   Ende ist `gueltigBis`, begrenzt durch die Signaturfrist. Kein zweites Ticket.
8. **Einmal ausführen:** innerhalb der Restfrist ausschließlich einmal
   `ROBOHUB_EurekaDockTesten(45174, $ticket)` administrativ aufrufen.
   Beaufsichtigen; Cloud-Annahme, Bewegung und Stationsankunft getrennt bewerten.
   Auch Timeout/unklare Antwort verbraucht den Versuch. Nicht erneut senden.
   Dokument/Ticket aus dem Arbeitsspeicher und kurzlebige Dateien entfernen.

## Offline-Nachweise

`bash tests/eureka/run-dock-offline.sh` erzwingt `bwrap --unshare-net` ohne
Fallback. Der zusätzliche Prüfstand `tests/eureka/dock-signer-test.php` nutzt
native Ed25519-Erzeugung und -Verifikation ausschließlich mit flüchtigen
synthetischen Testschlüsseln, anonymen Pipes und dem Speicher-Harness. cURL ist
deaktiviert. Nur der externe Anker-Dateizugriff wird für den Moduleinstieg ersetzt;
Importberechtigung, Parser, Signatur, Zustandsmaschine und Ticket bleiben Originalcode.
Keine Produktions-Testschalter, keine Schlüsseldateien, keine Transportaufrufe.

Positive Prüfung: CLI → native Verifikation → echter administrativer Dock-Import
→ Replay-Ablehnung → einmaliges Ticket. Negative Prüfungen: B8-Zweck, B8-Befehl,
vollständiges B8-Dokument, B8-Laufzeit, v1, fremder Schlüssel, geänderte Nutzlast
und Signatur, defekte Hülle, Konto/Instanz/Modell/Ziel/Laufzeit, Zeitfenster und
Typen, Ausnahmeeinwilligung, Administrator/Benutzer und CLI-Eingabe/Schlüssel/Anker.
Alle Fälle prüfen generische Ausgaben bzw. leere Logs und ausbleibenden Transport.
Die übrigen bisherigen Offline-Prüfstände behalten deaktivierte Signaturgeneratoren.

Lokales Ergebnis am 12.11.2026: vollständiger Offline-Runner erfolgreich
(Exit 0), darunter 244 Dock-, 41 HTTPS-Double-, 399 Diagnose-, 284 Zustands-
und 72 Token-Tests ohne Fehler sowie Struktur-, Befehls-, Gesamtintegration,
alle PHP-Lints und `git diff --check`. Der zunächst mit 38 Fällen durchgelaufene
Kryptografie-Prüfstand wurde um fehlende Pflichtfelder und leere/umgekehrte
Fristen ergänzt und danach erneut isoliert ausgeführt: **49 Fälle, 0 Fehler**.

Produktive Ankerinstallation, native Symcon-Umgebung, sicherer Realtransfer und
beaufsichtigte Bewegung bleiben einem gesondert autorisierten Arbeitsschritt vorbehalten.
