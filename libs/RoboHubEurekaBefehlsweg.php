<?php
declare(strict_types=1);

require_once __DIR__ . '/RoboHubEurekaProtokoll.php';
require_once __DIR__ . '/RoboHubEurekaZustand.php';

/**
 * Ein einzelner Befehlsversuch. Besitzt selbst keinen Netzwerktransport.
 * Produktionsadapter liefert die gesperrte Transportgrenze, Tests liefern Fixtures.
 */
final class RoboHubEurekaBefehlsweg
{
    private $transport;
    private $beiUnauthorized;
    private array $zuordnungen;
    private $kontextLesen;
    private bool $verbraucht = false;

    public function __construct(callable $transport, array $zuordnungen, ?callable $beiUnauthorized = null, ?callable $kontextLesen = null)
    {
        $this->kontextLesen = $kontextLesen;
        $this->transport = $transport;
        $this->zuordnungen = $zuordnungen;
        $this->beiUnauthorized = $beiUnauthorized;
    }

    public static function vorbereiten(array $geraet, string $befehl, array $parameter, array $zuordnungen, int $jetzt): array
    {
        if (!in_array($befehl, ['start', 'pause', 'station'], true)) {
            throw new RoboHubFehler('Eureka: Dieser Befehl bleibt gesperrt.');
        }
        RoboHubEurekaProtokoll::pruefeZuordnung($geraet, $zuordnungen);
        $stand = $geraet['stand'] ?? [];
        if (($geraet['online'] ?? null) !== true || !is_array($stand) ||
            ($stand['deviceId'] ?? null) !== $geraet['id'] ||
            !is_int($stand['zeit'] ?? null) || $stand['zeit'] > $jetzt || $jetzt - $stand['zeit'] > 120 ||
            !is_array($stand['roh'] ?? null) ||
            ($stand['roh']['connected'] ?? null) !== true ||
            (array_key_exists('online', $stand) && $stand['online'] !== true)) {
            throw new RoboHubFehler('Eureka: Kein aktueller, zugeordneter Online-Zustand.');
        }
        $roh = $stand['roh'];
        foreach (['id', 'deviceId'] as $feld) {
            if (array_key_exists($feld, $roh) &&
                ((!is_string($roh[$feld]) && !is_int($roh[$feld])) || (string)$roh[$feld] !== $geraet['id'])) {
                throw new RoboHubFehler('Eureka: Status gehört nicht eindeutig zum Zielgerät.');
            }
        }
        if (array_key_exists('connected', $geraet['roh']) && $geraet['roh']['connected'] !== true) {
            throw new RoboHubFehler('Eureka: Geräteidentifikation meldet kein verbundenes Gerät.');
        }
        $state = $roh['state'] ?? null;
        $bewertung = RoboHubEurekaZustand::shadow($roh);
        // charging_pause/wiping_pause können eine andere Wiederaufnahme erfordern.
        $gruppe = match ($state) {
            'cleaning' => 'cleaning',
            'pause' => 'paused',
            'stop' => 'idle',
            'charging', 'charging_finish' => 'docked',
            default => null,
        };
        if ($gruppe === null || !$bewertung['konsistent'] ||
            !array_key_exists('deviceFault', $roh) || $roh['deviceFault'] !== []) {
            throw new RoboHubFehler('Eureka: Zustand unbekannt, widersprüchlich oder fehlerhaft.');
        }
        $aktion = $befehl;
        if ($befehl === 'start' && $gruppe === 'paused') {
            $aktion = 'resume';
            $hex = RoboHubEurekaProtokoll::fortsetzen();
        } elseif ($befehl === 'start' && in_array($gruppe, ['idle', 'docked'], true)) {
            $keys = array_keys($parameter);
            sort($keys);
            if ($keys !== ['fan', 'mode', 'water'] || !is_int($parameter['fan']) ||
                !is_int($parameter['water']) || !is_int($parameter['mode'])) {
                throw new RoboHubFehler('Eureka: Neuer Auftrag benötigt explizite fan-, water- und mode-Werte.');
            }
            $hex = RoboHubEurekaProtokoll::start($parameter['fan'], $parameter['water'], $parameter['mode']);
        } elseif ($befehl === 'pause' && $gruppe === 'cleaning') {
            $hex = RoboHubEurekaProtokoll::pause();
        } elseif ($befehl === 'station' && in_array($gruppe, ['idle', 'paused', 'cleaning'], true)) {
            $hex = RoboHubEurekaProtokoll::station();
        } else {
            throw new RoboHubFehler('Eureka: Befehl für diesen Zustand gesperrt.');
        }
        if ($aktion !== 'start' && $parameter !== []) {
            throw new RoboHubFehler('Eureka: Unerwartete Befehlsparameter.');
        }
        return ['pfad' => '/devices/commands/' . rawurlencode($geraet['id']), 'body' => ['data' => $hex], 'aktion' => $aktion];
    }

    /** Nur Cloud-Annahme; selbst ein gültiger Antwortframe beweist keine Bewegung. */
    public static function antwortLesen(array $antwort): array
    {
        $status = $antwort['status'] ?? 0;
        if ($status === 401) {
            throw new RoboHubFehler('Eureka: Befehlsautorisierung abgelehnt; keine Wiederholung.', 401);
        }
        if ($status === 429) {
            $e = new RoboHubFehler('Eureka: Cloud begrenzt Befehle; keine Wiederholung.', 429);
            $e->warteSekunden = 60;
            throw $e;
        }
        if (!is_int($status) || $status < 200 || $status > 299) {
            throw new RoboHubFehler('Eureka: HTTP-Befehlsfehler; Ergebnis unklar, keine Wiederholung.');
        }
        $data = json_decode(is_string($antwort['response'] ?? null) ? $antwort['response'] : '', true);
        if (!is_array($data) || !array_key_exists('errorCode', $data) ||
            !in_array($data['errorCode'], [0, '0'], true)) {
            if (is_array($data) && ($data['errorCode'] ?? null) === '0080005') {
                throw new RoboHubFehler('Eureka: Cloud meldet Geräte-Timeout; Ausführung unklar, keine Wiederholung.');
            }
            throw new RoboHubFehler('Eureka: Cloud-Befehlsfehler oder ungültige Antwort; keine Wiederholung.');
        }
        $reply = $data['data'] ?? null;
        // Referenz: Daten optional, Antwortframe ggf. in data.data.
        if (is_array($reply)) {
            $reply = $reply['data'] ?? null;
        }
        if ($reply !== null && $reply !== '') {
            if (!is_string($reply)) {
                throw new RoboHubFehler('Eureka: Ungültige Befehlsantwort; keine Wiederholung.');
            }
            RoboHubEurekaProtokoll::pruefeRahmen($reply);
        }
        return ['angenommen' => true, 'ausgefuehrt' => false, 'antwortVorhanden' => is_string($reply) && $reply !== ''];
    }

    public function ausfuehren(array $buendel, array $geraet, string $befehl, array $parameter, int $jetzt): array
    {
        if ($this->verbraucht) {
            throw new RoboHubFehler('Eureka: Befehlsversuch bereits verbraucht; kein doppelter Versand.');
        }
        // Global pro Cloud-Gerät, auch über Konto-/Treiber-/Befehlsobjekte hinweg.
        // Kein Token oder individuelle Gerätekennung im Sperrnamen oder Log.
        RoboHubEurekaProtokoll::identifikation($geraet);
        $id = $geraet['id'];
        $sem = 'RoboHubEureka_Geraet_' . hash('sha256', 'eu:robozone:' . $id);
        if (!function_exists('IPS_SemaphoreEnter') || !function_exists('IPS_SemaphoreLeave') ||
            !IPS_SemaphoreEnter($sem, 1)) {
            throw new RoboHubFehler('Eureka: Gerät belegt oder Synchronisierung nicht verfügbar; kein Versand.');
        }
        try {
            if (is_callable($this->kontextLesen)) {
                $kontext = ($this->kontextLesen)($id, $buendel);
                $geraet = $kontext['geraet'];
                $buendel = $kontext['buendel'];
                $jetzt = $kontext['jetzt'];
            }
            if (($geraet['id'] ?? null) !== $id) {
                throw new RoboHubFehler('Eureka: Gerätebindung geändert; kein Versand.');
            }
            // Sämtliche Berechtigungs- und Zustandsprüfungen INNERHALB der Sperre.
            return $this->ausfuehrenGesperrt($buendel, $geraet, $befehl, $parameter, $jetzt);
        } finally {
            IPS_SemaphoreLeave($sem);
        }
    }

    private function ausfuehrenGesperrt(array $buendel, array $geraet, string $befehl, array $parameter, int $jetzt): array
    {
        if ($this->verbraucht) {
            throw new RoboHubFehler('Eureka: Befehlsversuch bereits verbraucht; kein doppelter Versand.');
        }
        $plan = self::vorbereiten($geraet, $befehl, $parameter, $this->zuordnungen, $jetzt);
        if (($buendel['region'] ?? null) !== 'eu' || !is_string($buendel['access_token'] ?? null) ||
            trim($buendel['access_token']) === '') {
            throw new RoboHubFehler('Eureka: Gültiges EU-Token-Bündel erforderlich.');
        }
        // Vor dem Transport setzen: gilt auch bei Timeout, Ausnahme und Reentranz.
        $this->verbraucht = true;
        try {
            $antwort = ($this->transport)('POST', $plan['pfad'], $buendel['access_token'], $plan['body']);
        } catch (Throwable $e) {
            // Transport-Ausnahmen dürfen keine Tokens, URLs oder Cloud-Freitexte ausgeben.
            throw new RoboHubFehler('Eureka: Befehlsverbindung fehlgeschlagen; Ausführung unklar, keine Wiederholung.');
        }
        if (($antwort['status'] ?? null) === 401 && is_callable($this->beiUnauthorized)) {
            try {
                // Konto serialisiert und persistiert die Rotation. Diesen POST NIE wiederholen.
                ($this->beiUnauthorized)($buendel['access_token']);
            } catch (Throwable $e) {
                throw new RoboHubFehler('Eureka: Token-Erneuerung fehlgeschlagen; Befehl nicht wiederholt.');
            }
        }
        return self::antwortLesen($antwort);
    }

    public function befehl(array $buendel, array $geraet, string $befehl, array $parameter, int $jetzt): string
    {
        $this->ausfuehren($buendel, $geraet, $befehl, $parameter, $jetzt);
        return 'Eureka: Befehl von der Cloud angenommen; Ausführung am Gerät nicht bestätigt.';
    }
}
