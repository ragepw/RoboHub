<?php
declare(strict_types=1);

require_once __DIR__ . '/RoboHubEurekaB8.php';
require_once __DIR__ . '/RoboHubEurekaZustand.php';
require_once __DIR__ . '/RoboHubEurekaGenehmigung.php';

/**
 * Administrativer Einmalpfad. Callbacks lesen ausschließlich lokale Kontodaten.
 * Konto hält Diagnose-/Bündelsemaphore für Freigabe und Versuch; Widerruf
 * benötigt nur die kurze Freigabesemaphore, die auch den Transportbeginn schützt.
 */
final class RoboHubEurekaDiagnose
{
    public const BEFEHL = 'AA0CB800000000000003320106';
    public const BESTAETIGUNG = 'B8-Arbeitszustand einmalig abfragen';
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
                throw new RoboHubFehler('Eureka: Bereitstellung bereits erfolgt oder Diagnosezustand nicht leer.');
            }
            $k = ($this->kontext)();
            self::pruefen($k);
            $g = RoboHubEurekaGenehmigung::pruefen($dokument, $publicKey, $k['boot'], $k['jetzt']);
            if (!hash_equals($g['cloudGeraetId'], $k['geraet']['id'])) {
                throw new RoboHubFehler('Eureka: Signiertes Diagnoseziel stimmt nicht mit der lokalen Zuordnung überein.');
            }
            $this->laufzeitSpeichern(['erwartet' => $g['erwartet'], 'zustand' => 'genehmigt',
                'cloudGeraetId' => $g['cloudGeraetId'],
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
            throw new RoboHubFehler('Eureka: Signierte Gerätebindung fehlt oder wurde geändert; Diagnose gesperrt.');
        }
        if (!is_string($l['erwartet'] ?? null) || $l['erwartet'] === '' ||
            !hash_equals($l['erwartet'], $k['boot']) || ($l['zustand'] ?? null) !== $zustand ||
            (isset($l['genehmigtBis']) && ($k['jetzt'] >= $l['genehmigtBis'] ||
                $k['monoton'] >= ($l['genehmigtMonotonBis'] ?? 0))) ||
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

    public static function pruefen(array $k): void
    {
        if (($k['konto'] ?? null) !== 45174 || ($k['instanz'] ?? null) !== 22708 ||
            ($k['hersteller'] ?? null) !== 'eureka' || ($k['region'] ?? null) !== 'eu' ||
            !is_int($k['jetzt'] ?? null) || !is_int($k['monoton'] ?? null) || $k['monoton'] < 0 ||
            !is_string($k['boot'] ?? null) || $k['boot'] === '' ||
            !is_string($k['revision'] ?? null) || $k['revision'] === '' ||
            ($k['warteBis'] ?? PHP_INT_MAX) > $k['jetzt']) {
            throw new RoboHubFehler('Eureka: Diagnosekontext oder Cloud-Sperre verweigert die Abfrage.');
        }
        $g = $k['geraet'] ?? [];
        if (!is_string($g['id'] ?? null) || $g['id'] === '') {
            throw new RoboHubFehler('Eureka: Eindeutige Cloud-Gerätebindung fehlt.');
        }
        $ident = self::modellPruefen($g);
        $s = $g['stand'] ?? [];
        foreach ([$k['cacheZeit'] ?? null, $g['verbindungEmpfangen'] ?? null, $s['zeit'] ?? null] as $zeit) {
            if (!is_int($zeit) || $zeit <= 0 || $zeit > $k['jetzt'] || $k['jetzt'] - $zeit > 120) {
                throw new RoboHubFehler('Eureka: Diagnose benötigt lokal frische Listen- und Shadow-Daten.');
            }
        }
        $roh = $s['roh'] ?? null;
        if (($s['deviceId'] ?? null) !== $g['id'] || !is_array($roh) ||
            ($g['online'] ?? null) !== true || ($g['roh']['connected'] ?? null) !== true ||
            (array_key_exists('connected', $roh) && $roh['connected'] !== true) ||
            (array_key_exists('online', $roh) && $roh['online'] !== true) ||
            (array_key_exists('deviceFault', $g['roh']) && $g['roh']['deviceFault'] !== []) ||
            ($roh['deviceFault'] ?? null) !== [] ||
            ($roh['state'] ?? null) === 'error' || ($roh['parentState'] ?? null) === 'error' ||
            !RoboHubEurekaZustand::shadow($roh)['konsistent']) {
            throw new RoboHubFehler('Eureka: Diagnose wegen Offline-, Fehler- oder unklarem Shadow-Befund gesperrt.');
        }
        foreach (['id', 'deviceId'] as $feld) {
            if (array_key_exists($feld, $roh) &&
                ((!is_string($roh[$feld]) && !is_int($roh[$feld])) || (string)$roh[$feld] !== $g['id'])) {
                throw new RoboHubFehler('Eureka: Shadow-Identität widersprüchlich.');
            }
        }
        // Vorhandene Shadow-Metadaten dürfen der Listenidentifikation nicht widersprechen.
        foreach (['productModel', 'sn8', 'protoVersion', 'platform', 'stationType', 'panel', 'product'] as $feld) {
            if (array_key_exists($feld, $roh)) {
                $vergleich = $g;
                $vergleich['roh'] = array_replace($g['roh'], [$feld => $roh[$feld]]);
                if (RoboHubEurekaProtokoll::identifikation($vergleich) !== $ident ||
                    ($vergleich['roh']['stationType'] ?? 'dust_station') !== 'dust_station' ||
                    ($vergleich['roh']['product']['stationType'] ?? 'dust_station') !== 'dust_station') {
                    throw new RoboHubFehler('Eureka: Shadow-Modell widersprüchlich.');
                }
            }
        }
        self::buendelPruefen($k['buendel'] ?? [], $k['jetzt']);
    }

    public static function modellPruefen(array $g): array
    {
        $ident = RoboHubEurekaProtokoll::identifikation($g);
        if ($ident !== ['productModel' => 'E20 Evo Plus', 'sn8' => '750Y0015',
            'protoVersion' => 0, 'platform' => 'robozone',
            'plugin' => ['name' => 'T0xB8_750Y0015.zip', 'versionName' => '2.5.4']]) {
            throw new RoboHubFehler('Eureka: Diagnosemodell nicht exakt bestätigt.');
        }
        $station = [];
        foreach ([$g['roh']['product'] ?? [], $g['roh']] as $q) {
            if (array_key_exists('stationType', $q)) { $station[] = $q['stationType']; }
        }
        if ($station === [] || count(array_filter($station, fn($v) => $v !== 'dust_station')) > 0) {
            throw new RoboHubFehler('Eureka: Diagnosestation nicht exakt bestätigt.');
        }
        return $ident;
    }

    public static function buendelPruefen(array $b, int $jetzt): void
    {
        $token = $b['access_token'] ?? null;
        $teile = is_string($token) ? explode('.', $token) : [];
        $payload = count($teile) === 3 ? base64_decode(strtr($teile[1], '-_', '+/'), true) : false;
        $jwt = $payload !== false ? json_decode($payload, true) : null;
        if (($b['region'] ?? null) !== 'eu' || !is_array($jwt) || !is_int($jwt['exp'] ?? null) ||
            $jwt['exp'] <= $jetzt + 15) {
            throw new RoboHubFehler('Eureka: Keine lokal gültige Sitzung; Diagnose erneuert keine Tokens.');
        }
    }

    private static function bindung(array $k): string
    {
        return hash('sha256', serialize([$k['konto'], $k['instanz'], $k['geraet']['id'],
            self::BEFEHL, $k['boot'], $k['revision'], $k['buendel'], $k['kontoname']]));
    }

    public function freigeben(int $sekunden, string $bestaetigung): array
    {
        return ($this->mitFreigabeSperre)(fn(): array => $this->freigebenGesperrt($sekunden, $bestaetigung));
    }

    private function freigebenGesperrt(int $sekunden, string $bestaetigung): array
    {
        if ($sekunden < 1 || $sekunden > 60 || $bestaetigung !== self::BESTAETIGUNG) {
            throw new RoboHubFehler('Eureka: Explizite administrative Freigabe für 1 bis 60 Sekunden erforderlich.');
        }
        $alt = ($this->lesen)();
        // Ein erhaltener Verbrauch sperrt auch nach Neustart. Kein fsync-Nachweis.
        if (($alt['zustand'] ?? null) === 'verbraucht' || array_key_exists('verbrauchtAm', $alt)) {
            throw new RoboHubFehler('Eureka: Einmaldiagnose bereits verbraucht.');
        }
        if ($alt !== [] && !in_array($alt['zustand'] ?? null, ['bereit', 'widerrufen'], true)) {
            throw new RoboHubFehler('Eureka: Unbekannter persistenter Diagnosezustand; keine Freigabe.');
        }
        $k = ($this->kontext)();
        self::pruefen($k);
        $l = $this->laufzeitPruefen($k, 'genehmigt');
        $ticket = bin2hex(random_bytes(32));
        $f = ['zustand' => 'bereit', 'ticketHash' => hash('sha256', $ticket),
            'bindung' => self::bindung($k), 'von' => $k['jetzt'], 'bis' => $k['jetzt'] + $sekunden,
            'monotonVon' => $k['monoton'], 'monotonBis' => $k['monoton'] + $sekunden * 1000];
        // Bereits die Ticketausstellung verbraucht die einmalige Ausstellungsbefugnis.
        // Vor dem Attribut schreiben: auch ein Schreibfehler erlaubt kein neues Ticket.
        $this->laufzeitSpeichern(array_replace($l, ['zustand' => 'ticket', 'ticketHash' => $f['ticketHash']]));
        $this->persistieren($f);
        return ['freigabe' => $ticket, 'gueltigBis' => $f['bis']];
    }

    private function persistieren(array $f): void
    {
        ($this->speichern)($f);
        if (($this->lesen)() !== $f) {
            throw new RoboHubFehler('Eureka: Diagnosezustand nicht sicher gespeichert; kein Transport.');
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
            throw new RoboHubFehler('Eureka: Unbekannter persistenter Diagnosezustand; kein Zurücksetzen.');
        }
        // Auch vor der Bereitstellung ein flüchtiges Veto setzen: Attributrollback
        // darf einen vorab ausgesprochenen Widerruf nicht entfernen.
        $this->laufzeitSpeichern(array_replace($l, ['zustand' => 'widerrufen']));
        // Ein bereits reservierter Versuch bleibt endgültig verbraucht, sendet aber nicht.
        $this->persistieren(array_replace($f, ['zustand' => 'widerrufen']));
    }

    private function berechtigung(array $k, array $f, string $ticket, string $zustand): void
    {
        self::pruefen($k);
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
    }

    public function ausfuehren(string $ticket, callable $transport): array
    {
        [$k, $f] = ($this->mitFreigabeSperre)(function () use ($ticket): array {
            $k = ($this->kontext)();
            $f = ($this->lesen)();
            $l = $this->laufzeitPruefen($k, 'ticket', hash('sha256', $ticket));
            $this->berechtigung($k, $f, $ticket, 'bereit');
            $this->laufzeitSpeichern(array_replace($l, ['zustand' => 'reserviert']));
            $f['zustand'] = 'verbraucht';
            $f['verbrauchtAm'] = $k['jetzt'];
            $this->persistieren($f); // Vor jeglichem Transportversuch, auch bei späterem Fehler.
            return [$k, $f];
        });
        $pruefen = function (bool $transportBeginnt = false) use ($ticket, &$f): void {
            ($this->mitFreigabeSperre)(function () use ($ticket, &$f, $transportBeginnt): void {
                $aktuell = ($this->lesen)();
                if ($aktuell !== $f || array_key_exists('transportBegonnenAm', $aktuell)) {
                    throw new RoboHubFehler('Eureka: Persistenter Diagnoseverbrauch geändert oder Transport schon begonnen.');
                }
                $k = ($this->kontext)();
                $l = $this->laufzeitPruefen($k, 'reserviert', hash('sha256', $ticket));
                $this->berechtigung($k, $aktuell, $ticket, 'verbraucht');
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
            throw new RoboHubFehler('Eureka: Diagnoseversuch verbraucht; Transport unbestätigt, keine Wiederholung.');
        }
        return RoboHubEurekaB8::antwort($antwort);
    }
}
