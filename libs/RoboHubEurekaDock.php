<?php
declare(strict_types=1);

require_once __DIR__ . '/RoboHubEurekaDiagnose.php';
require_once __DIR__ . '/RoboHubEurekaBefehlsweg.php';
require_once __DIR__ . '/RoboHubEurekaZustand.php';
require_once __DIR__ . '/RoboHubEurekaGenehmigung.php';

/**
 * Administrativer Einmalpfad. Callbacks lesen ausschließlich lokale Kontodaten.
 * Konto hält Dock-/Bündelsemaphore für Freigabe und Versuch; Widerruf
 * benötigt nur die kurze Freigabesemaphore, die auch den Transportbeginn schützt.
 */
final class RoboHubEurekaDock
{
    public const BEFEHL = 'AA0DB80000000000000222010016';
    public const BESTAETIGUNG = 'E20 Evo Plus einmalig zur Station fahren';
    public const VOR_ORT_BESTAETIGUNG = 'Verbindung unbekannt: Ich habe den E20 Evo Plus jetzt vor Ort als betriebsbereit und den Rückweg als frei geprüft und beaufsichtige den einmaligen Docktest';
    private $kontext;
    private $lesen;
    private $speichern;
    private $mitFreigabeSperre;
    private $laufzeitLesen;
    private $laufzeitSpeichern;

    public function __construct(callable $kontext, callable $lesen, callable $speichern, callable $mitFreigabeSperre,
        callable $laufzeitLesen, callable $laufzeitSpeichern)
    {
        $this->kontext = $kontext;
        $this->lesen = $lesen;
        $this->speichern = $speichern;
        $this->mitFreigabeSperre = $mitFreigabeSperre;
        $this->laufzeitLesen = $laufzeitLesen;
        $this->laufzeitSpeichern = $laufzeitSpeichern;
    }

    /** Nur signierte externe Erwartung übernehmen; niemals aus Istkennung ableiten. */
    public function bereitstellen(string $dokument, string $publicKey): void
    {
        ($this->mitFreigabeSperre)(function () use ($dokument, $publicKey): void {
            // Auch unbekannte/teilgeschriebene Zustände niemals reparieren.
            if (($this->laufzeitLesen)() !== [] || ($this->lesen)() !== []) {
                throw new RoboHubFehler('Eureka: Bereitstellung bereits erfolgt oder Dockzustand nicht leer.');
            }
            $k = ($this->kontext)();
            $g = RoboHubEurekaGenehmigung::dockPruefen($dokument, $publicKey, $k['boot'], $k['jetzt']);
            self::pruefen($k, ($g['ausnahmeVerbindungUnbekannt'] ?? false) === true);
            if (!hash_equals($g['cloudGeraetId'], $k['geraet']['id'])) {
                throw new RoboHubFehler('Eureka: Signiertes Dockziel stimmt nicht mit der lokalen Zuordnung überein.');
            }
            $this->laufzeitSpeichern(['erwartet' => $g['erwartet'], 'zustand' => 'genehmigt',
                'cloudGeraetId' => $g['cloudGeraetId'],
                'ausnahmeVerbindungUnbekannt' => ($g['ausnahmeVerbindungUnbekannt'] ?? false) === true,
                'genehmigtBis' => $g['bis'],
                'genehmigtMonotonBis' => $k['monoton'] + ($g['bis'] - $k['jetzt']) * 1000]);
        });
    }

    /** Nur unter Freigabesemaphore. Keine Erzeugung/Erneuerung der Erwartung. */
    private function laufzeitPruefen(array $k, string $zustand, ?string $ticketHash = null): array
    {
        $l = ($this->laufzeitLesen)();
        // Ziel aus der externen Signatur beibehalten; niemals aus aktueller Zuordnung erneuern.
        if (!is_string($l['cloudGeraetId'] ?? null) || $l['cloudGeraetId'] === '' ||
            !is_string($k['geraet']['id'] ?? null) || !hash_equals($l['cloudGeraetId'], $k['geraet']['id'])) {
            // Ein erkannter Zielwechsel bleibt auch nach Rückwechsel/Attributrollback gesperrt.
            if (is_string($l['cloudGeraetId'] ?? null) && $l['cloudGeraetId'] !== '') {
                $this->laufzeitSpeichern(array_replace($l, ['zustand' => 'widerrufen']));
            }
            throw new RoboHubFehler('Eureka: Signierte Gerätebindung fehlt oder wurde geändert; Dock gesperrt.');
        }
        if (!is_string($l['erwartet'] ?? null) || $l['erwartet'] === '' ||
            !hash_equals($l['erwartet'], $k['boot']) || ($l['zustand'] ?? null) !== $zustand ||
            (!is_int($l['genehmigtBis'] ?? null) || !is_int($l['genehmigtMonotonBis'] ?? null) ||
                $k['jetzt'] >= $l['genehmigtBis'] || $k['monoton'] >= $l['genehmigtMonotonBis']) ||
            ($ticketHash !== null && (!is_string($l['ticketHash'] ?? null) ||
                !hash_equals($l['ticketHash'], $ticketHash)))) {
            throw new RoboHubFehler('Eureka: Separate Laufzeitfreigabe fehlt, ist ungültig oder bereits verwendet.');
        }
        return $l;
    }

    private function laufzeitSpeichern(array $l): void
    {
        ($this->laufzeitSpeichern)($l);
        if (($this->laufzeitLesen)() !== $l) {
            throw new RoboHubFehler('Eureka: Laufzeitsperre nicht bestätigt; kein Transport.');
        }
    }

    public static function pruefen(array $k, bool $ausnahmeVerbindungUnbekannt = false): string
    {
        // Dieselben strengen Listen-, Modell-, Sitzungs- und Frischeprüfungen.
        RoboHubEurekaDiagnose::pruefen($k);
        $g = $k['geraet'];
        // Die rohe Listenkennung muss die normalisierte Zuordnung bestätigen.
        if (!array_key_exists('deviceId', $g['roh']) ||
            (array_key_exists('online', $g['roh']) && $g['roh']['online'] !== true)) {
            throw new RoboHubFehler('Eureka: Listenidentität oder Verbindungsbefund widersprüchlich.');
        }
        foreach (['id', 'deviceId'] as $feld) {
            if (array_key_exists($feld, $g['roh']) &&
                ((!is_string($g['roh'][$feld]) && !is_int($g['roh'][$feld])) || (string)$g['roh'][$feld] !== $g['id'])) {
                throw new RoboHubFehler('Eureka: Rohe Listenidentität widersprüchlich.');
            }
        }
        $s = $g['stand'];
        if (!array_key_exists('connected', $s['roh']) && $ausnahmeVerbindungUnbekannt) {
            // stand.online=false wird vom Treiber auch für fehlendes connected abgeleitet.
            // Kein Online-Default, keine Änderung am Snapshot oder normalen Befehlsplaner.
            if (($s['statusquelle']['quelle'] ?? null) !== 'shadow-get' ||
                (array_key_exists('online', $s) && $s['online'] !== false) ||
                !in_array($s['roh']['state'] ?? null, ['stop', 'pause', 'cleaning'], true)) {
                throw new RoboHubFehler('Eureka: Dockausnahme benötigt einen erfolgreich empfangenen, zulässigen Shadow.');
            }
            return 'unbekannt';
        }
        $ident = RoboHubEurekaDiagnose::modellPruefen($k['geraet']);
        RoboHubEurekaBefehlsweg::vorbereiten($k['geraet'], 'station', [], [[
            'identifikation' => $ident, 'familie' => RoboHubEurekaProtokoll::FAMILIE,
            'beleg' => 'Separater signierter Dock-Einmalversuch; keine globale Gerätefreigabe',
        ]], $k['jetzt']);
        return 'online';
    }

    private static function bindung(array $k): string
    {
        return hash('sha256', serialize([$k['konto'], $k['instanz'], $k['geraet']['id'],
            self::BEFEHL, $k['boot'], $k['revision'], $k['buendel'], $k['kontoname']]));
    }

    public function freigeben(int $sekunden, string $bestaetigung, string $vorOrtBestaetigung = ''): array
    {
        return ($this->mitFreigabeSperre)(fn(): array => $this->freigebenGesperrt($sekunden, $bestaetigung, $vorOrtBestaetigung));
    }

    private function freigebenGesperrt(int $sekunden, string $bestaetigung, string $vorOrtBestaetigung): array
    {
        if ($sekunden < 1 || $sekunden > 60 || $bestaetigung !== self::BESTAETIGUNG) {
            throw new RoboHubFehler('Eureka: Explizite administrative Freigabe für 1 bis 60 Sekunden erforderlich.');
        }
        $alt = ($this->lesen)();
        // Ein erhaltener Verbrauch sperrt auch nach Neustart. Kein fsync-Nachweis.
        if (($alt['zustand'] ?? null) === 'verbraucht' || array_key_exists('verbrauchtAm', $alt)) {
            throw new RoboHubFehler('Eureka: Einmaldock bereits verbraucht.');
        }
        if ($alt !== [] && !in_array($alt['zustand'] ?? null, ['bereit', 'widerrufen'], true)) {
            throw new RoboHubFehler('Eureka: Unbekannter persistenter Dockzustand; keine Freigabe.');
        }
        $k = ($this->kontext)();
        $l = $this->laufzeitPruefen($k, 'genehmigt');
        $ausnahme = $vorOrtBestaetigung !== '';
        if ($ausnahme && ($vorOrtBestaetigung !== self::VOR_ORT_BESTAETIGUNG ||
            ($l['ausnahmeVerbindungUnbekannt'] ?? null) !== true ||
            !is_string($k['benutzer'] ?? null) || trim($k['benutzer']) === '' || $k['benutzer'] === '@unknown')) {
            throw new RoboHubFehler('Eureka: Signierte Ausnahmeeinwilligung und unmittelbare Vor-Ort-Bestätigung erforderlich.');
        }
        $verbindung = self::pruefen($k, $ausnahme);
        // Nur dieser Aufruf bestätigt die Beobachtung; kein Property/Checkbox-Fallback.
        $beaufsichtigung = ['ausnahmeVerbindungUnbekannt' => $ausnahme,
            'vorOrt' => $ausnahme ? ['bestaetigung' => $vorOrtBestaetigung, 'am' => $k['jetzt'],
                'monoton' => $k['monoton'], 'benutzer' => $k['benutzer']] : null];
        $ticket = bin2hex(random_bytes(32));
        $f = ['zustand' => 'bereit', 'ticketHash' => hash('sha256', $ticket),
            'bindung' => self::bindung($k), 'von' => $k['jetzt'], 'bis' => $k['jetzt'] + $sekunden,
            'monotonVon' => $k['monoton'], 'monotonBis' => $k['monoton'] + $sekunden * 1000,
            'beaufsichtigung' => $beaufsichtigung];
        // Bereits die Ticketausstellung verbraucht die einmalige Ausstellungsbefugnis.
        // Vor dem Attribut schreiben: auch ein Schreibfehler erlaubt kein neues Ticket.
        $this->laufzeitSpeichern(array_replace($l, ['zustand' => 'ticket', 'ticketHash' => $f['ticketHash'],
            'beaufsichtigung' => $beaufsichtigung]));
        $this->persistieren($f);
        return ['freigabe' => $ticket, 'gueltigBis' => min($f['bis'], $l['genehmigtBis']),
            'verbindung' => $verbindung, 'ausnahmeVerbindungUnbekannt' => $ausnahme];
    }

    private function persistieren(array $f): void
    {
        ($this->speichern)($f);
        if (($this->lesen)() !== $f) {
            throw new RoboHubFehler('Eureka: Dockzustand nicht sicher gespeichert; kein Transport.');
        }
    }

    public function widerrufen(): void
    {
        ($this->mitFreigabeSperre)(fn() => $this->widerrufenGesperrt());
    }

    private function widerrufenGesperrt(): void
    {
        $f = ($this->lesen)();
        $l = ($this->laufzeitLesen)();
        if (($l['zustand'] ?? null) === 'geschlossen' || array_key_exists('transportBegonnenAm', $f)) {
            throw new RoboHubFehler('Eureka: Transport bereits begonnen; Widerruf nicht mehr möglich.');
        }
        if ($f !== [] && !in_array($f['zustand'] ?? null, ['bereit', 'verbraucht', 'widerrufen'], true)) {
            throw new RoboHubFehler('Eureka: Unbekannter persistenter Dockzustand; kein Zurücksetzen.');
        }
        // Auch vor der Bereitstellung ein flüchtiges Veto setzen: Attributrollback
        // darf einen vorab ausgesprochenen Widerruf nicht entfernen.
        $this->laufzeitSpeichern(array_replace($l, ['zustand' => 'widerrufen']));
        // Ein bereits reservierter Versuch bleibt endgültig verbraucht, sendet aber nicht.
        $this->persistieren(array_replace($f, ['zustand' => 'widerrufen']));
    }

    private function berechtigung(array $k, array $f, string $ticket, string $zustand, array $l): string
    {
        $b = $f['beaufsichtigung'] ?? null;
        if (!is_array($b) || $b !== ($l['beaufsichtigung'] ?? null) ||
            !is_bool($b['ausnahmeVerbindungUnbekannt'] ?? null)) {
            throw new RoboHubFehler('Eureka: Kurzlebige Dock-Beaufsichtigung fehlt oder wurde geändert.');
        }
        $ausnahme = $b['ausnahmeVerbindungUnbekannt'];
        if ($ausnahme && (($l['ausnahmeVerbindungUnbekannt'] ?? null) !== true ||
            ($b['vorOrt']['bestaetigung'] ?? null) !== self::VOR_ORT_BESTAETIGUNG ||
            ($b['vorOrt']['am'] ?? null) !== ($f['von'] ?? null) ||
            ($b['vorOrt']['monoton'] ?? null) !== ($f['monotonVon'] ?? null) ||
            !is_string($k['benutzer'] ?? null) ||
            ($b['vorOrt']['benutzer'] ?? null) !== $k['benutzer'])) {
            throw new RoboHubFehler('Eureka: Unmittelbare Vor-Ort-Bestätigung oder Ausnahmeeinwilligung ungültig.');
        }
        $verbindung = self::pruefen($k, $ausnahme);
        if (($f['zustand'] ?? null) !== $zustand || !preg_match('/\A[a-f0-9]{64}\z/D', $ticket) ||
            !is_string($f['ticketHash'] ?? null) || !hash_equals($f['ticketHash'], hash('sha256', $ticket)) ||
            !is_string($f['bindung'] ?? null) || !hash_equals($f['bindung'], self::bindung($k)) ||
            !is_int($f['von'] ?? null) || !is_int($f['bis'] ?? null) ||
            $f['bis'] - $f['von'] < 1 || $f['bis'] - $f['von'] > 60 ||
            $k['jetzt'] < $f['von'] || $k['jetzt'] >= $f['bis'] ||
            !is_int($f['monotonVon'] ?? null) || !is_int($f['monotonBis'] ?? null) ||
            $f['monotonBis'] - $f['monotonVon'] !== ($f['bis'] - $f['von']) * 1000 ||
            $k['monoton'] < $f['monotonVon'] || $k['monoton'] >= $f['monotonBis']) {
            throw new RoboHubFehler('Eureka: Einmalfreigabe fehlt, ist verbraucht, geändert oder abgelaufen.');
        }
        return $verbindung;
    }

    public function ausfuehren(string $ticket, callable $transport): array
    {
        [$k, $f, $verbindung] = ($this->mitFreigabeSperre)(function () use ($ticket): array {
            $k = ($this->kontext)();
            $f = ($this->lesen)();
            $l = $this->laufzeitPruefen($k, 'ticket', hash('sha256', $ticket));
            $verbindung = $this->berechtigung($k, $f, $ticket, 'bereit', $l);
            $this->laufzeitSpeichern(array_replace($l, ['zustand' => 'reserviert']));
            $f['zustand'] = 'verbraucht';
            $f['verbrauchtAm'] = $k['jetzt'];
            $this->persistieren($f); // Vor jeglichem Transportversuch, auch bei späterem Fehler.
            return [$k, $f, $verbindung];
        });
        $pruefen = function (bool $transportBeginnt = false) use ($ticket, &$f, &$verbindung): void {
            ($this->mitFreigabeSperre)(function () use ($ticket, &$f, &$verbindung, $transportBeginnt): void {
                $aktuell = ($this->lesen)();
                if ($aktuell !== $f || array_key_exists('transportBegonnenAm', $aktuell)) {
                    throw new RoboHubFehler('Eureka: Persistenter Dockverbrauch geändert oder Transport schon begonnen.');
                }
                $k = ($this->kontext)();
                $l = $this->laufzeitPruefen($k, 'reserviert', hash('sha256', $ticket));
                $verbindung = $this->berechtigung($k, $aktuell, $ticket, 'verbraucht', $l);
                if ($transportBeginnt) {
                    // Linearisiert gegen Widerruf, unmittelbar vor curl_exec().
                    $this->laufzeitSpeichern(array_replace($l, ['zustand' => 'geschlossen']));
                    $f['transportBegonnenAm'] = $k['jetzt'];
                    $this->persistieren($f);
                }
            });
        };
        try {
            $antwort = $transport('/devices/commands/' . rawurlencode($k['geraet']['id']),
                $k['buendel']['access_token'], ['data' => self::BEFEHL], $pruefen);
        } catch (Throwable $e) {
            // Auch unkontrollierte Transport-Exceptions enthalten potentiell Geheimnisse.
            throw new RoboHubFehler('Eureka: Dockversuch verbraucht; Transport unbestätigt, keine Wiederholung.');
        }
        return array_merge(RoboHubEurekaBefehlsweg::antwortLesen($antwort), ['verbindung' => $verbindung,
            'ausnahmeVerbindungUnbekannt' => $f['beaufsichtigung']['ausnahmeVerbindungUnbekannt']]);
    }
}
