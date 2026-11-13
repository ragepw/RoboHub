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
    private bool $manuell;
    private $versuch;
    private $vorTransport;
    private $kontextVorbereiten;
    private $beiErfolg;

    public function __construct(callable $transport, array $zuordnungen, ?callable $beiUnauthorized = null, ?callable $kontextLesen = null,
        bool $manuell = false, ?callable $versuch = null, ?callable $vorTransport = null, ?callable $kontextVorbereiten = null,
        ?callable $beiErfolg = null)
    {
        $this->beiErfolg = $beiErfolg;
        $this->kontextVorbereiten = $kontextVorbereiten;
        $this->vorTransport = $vorTransport;
        $this->manuell = $manuell;
        $this->versuch = $versuch;
        $this->kontextLesen = $kontextLesen;
        $this->transport = $transport;
        $this->zuordnungen = $zuordnungen;
        $this->beiUnauthorized = $beiUnauthorized;
    }

    public static function vorbereiten(array $geraet, string $befehl, array $parameter, array $zuordnungen, int $jetzt, bool $manuell = false): array
    {
        if (!in_array($befehl, ['start', 'pause', 'station', 'teppichmodus'], true)) {
            throw new RoboHubFehler('Eureka: Dieser Befehl bleibt gesperrt.');
        }
        RoboHubEurekaProtokoll::pruefeZuordnung($geraet, $zuordnungen);
        $stand = $geraet['stand'] ?? [];
        if (!is_array($stand) ||
            ($stand['deviceId'] ?? null) !== $geraet['id'] ||
            !is_int($stand['zeit'] ?? null) || $stand['zeit'] > $jetzt || $jetzt - $stand['zeit'] > 120 ||
            !is_array($stand['roh'] ?? null) ||
            !self::verbindungErlaubt($geraet, $stand, $jetzt, $manuell)) {
            throw new RoboHubFehler('Eureka: Kein aktueller, zugeordneter Online-Zustand.');
        }
        $roh = $stand['roh'];
        if (array_key_exists('online', $roh) && $roh['online'] !== true) {
            throw new RoboHubFehler('Eureka: Shadow meldet keinen verbundenen Zustand.');
        }
        foreach (['id', 'deviceId'] as $feld) {
            if (array_key_exists($feld, $roh) &&
                ((!is_string($roh[$feld]) && !is_int($roh[$feld])) || (string)$roh[$feld] !== $geraet['id'])) {
                throw new RoboHubFehler('Eureka: Status gehört nicht eindeutig zum Zielgerät.');
            }
        }
        if (array_key_exists('connected', $geraet['roh']) && $geraet['roh']['connected'] !== true) {
            throw new RoboHubFehler('Eureka: Geräteidentifikation meldet kein verbundenes Gerät.');
        }
        if (array_key_exists('deviceFault', $geraet['roh']) && $geraet['roh']['deviceFault'] !== []) {
            throw new RoboHubFehler('Eureka: Gerätecache meldet keinen fehlerfreien Zustand.');
        }
        $state = $roh['state'] ?? null;
        $bewertung = RoboHubEurekaZustand::shadow($roh);
        $schlaf = $befehl === 'start' ? RoboHubEurekaZustand::schlafAktion($stand, $jetzt) : null;
        // charging_pause/wiping_pause können eine andere Wiederaufnahme erfordern.
        $gruppe = match ($state) {
            'cleaning' => 'cleaning',
            'pause' => 'paused',
            'stop' => 'idle',
            'charging', 'charging_finish' => 'docked',
            'ERP_model', 'sleep_pause' => $schlaf === null ? null : 'sleep-' . $schlaf,
            default => null,
        };
        // Nur feste lokale Gründe ausgeben, niemals Herstellerwerte oder Kennungen.
        // Ein erfolgreicher GET ist kein Nachweis eines vollständigen Shadows.
        $gruende = [];
        if ($gruppe === null) { $gruende[] = 'zustandsgruppe-nicht-belegt'; }
        if (!$bewertung['konsistent']) { $gruende[] = 'state-parent-unklar'; }
        if (!array_key_exists('deviceFault', $roh)) {
            $gruende[] = 'deviceFault-fehlt';
        } elseif ($roh['deviceFault'] !== []) {
            $gruende[] = 'deviceFault-nicht-leer-oder-ungueltig';
        }
        if ($gruende !== []) {
            throw new RoboHubFehler('Eureka: Zustand unbekannt, widersprüchlich oder fehlerhaft. Gründe: ' . implode(', ', $gruende) . '.');
        }
        $aktion = $befehl;
        if ($befehl === 'start' && in_array($gruppe, ['paused', 'sleep-resume'], true)) {
            $aktion = 'resume';
            $hex = RoboHubEurekaProtokoll::fortsetzen();
        } elseif ($befehl === 'start' && in_array($gruppe, ['idle', 'docked', 'sleep-start'], true)) {
            // Hersteller-Hauptbedienung: neuer Auftrag nur oberhalb 15 Prozent.
            // Fehlender/ungültiger Befund ist keine Erlaubnis. Pause/Resume/Dock
            // erhalten diese Schranke nicht; sie liegen im Plugin davor.
            if (!is_int($roh['batteryLevel'] ?? null) || $roh['batteryLevel'] <= 15 || $roh['batteryLevel'] > 100) {
                throw new RoboHubFehler('Eureka: Neuer Auftrag benötigt einen bestätigten Akkustand über 15 Prozent.');
            }
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
        } elseif ($befehl === 'teppichmodus' && in_array($gruppe, ['idle', 'docked', 'paused', 'cleaning'], true)) {
            if (array_keys($parameter) !== ['teppichmodus']) {
                throw new RoboHubFehler('Eureka: Expliziter Teppichmodus erforderlich.');
            }
            $hex = RoboHubEurekaProtokoll::teppichmodus($parameter['teppichmodus']);
        } else {
            throw new RoboHubFehler('Eureka: Befehl für diesen Zustand gesperrt.');
        }
        if (!in_array($aktion, ['start', 'teppichmodus'], true) && $parameter !== []) {
            throw new RoboHubFehler('Eureka: Unerwartete Befehlsparameter.');
        }
        return ['pfad' => '/devices/commands/' . rawurlencode($geraet['id']), 'body' => ['data' => $hex], 'aktion' => $aktion];
    }

    /** Der bisherige strenge Vertrag bleibt Standard, insbesondere für Dock-Einmal. */
    private static function verbindungErlaubt(array $g, array $s, int $jetzt, bool $manuell): bool
    {
        if (($g['online'] ?? null) !== true) { return false; }
        if (array_key_exists('connected', $s['roh'])) {
            return $s['roh']['connected'] === true &&
                (!array_key_exists('online', $s) || $s['online'] === true);
        }
        // Nur die ausdrückliche Bedienaktion darf sich auf die frische Liste stützen.
        // stand.online=false bedeutet hier fehlenden Shadow-Nachweis, niemals online=true.
        $zeit = $g['verbindungEmpfangen'] ?? null;
        return $manuell && ($g['roh']['connected'] ?? null) === true &&
            (!array_key_exists('online', $g['roh']) || $g['roh']['online'] === true) &&
            is_int($zeit) && $zeit > 0 && $zeit <= $jetzt && $jetzt - $zeit <= 120 &&
            ($s['statusquelle']['quelle'] ?? null) === 'shadow-get' &&
            ($s['statusquelle']['geraetezeit'] ?? null) === null &&
            (!array_key_exists('online', $s) || $s['online'] === false);
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
        if (!in_array($befehl, ['start', 'pause', 'station', 'teppichmodus'], true)) {
            throw new RoboHubFehler('Eureka: Dieser Befehl bleibt gesperrt.');
        }
        $id = $geraet['id'] ?? null;
        if (!is_string($id) || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $id)) {
            throw new RoboHubFehler('Eureka: Ungültige Geräte-ID.');
        }
        $sem = 'RoboHubEureka_Geraet_' . hash('sha256', 'eu:robozone:' . $id);
        if (!function_exists('IPS_SemaphoreEnter') || !function_exists('IPS_SemaphoreLeave') ||
            !IPS_SemaphoreEnter($sem, 1)) {
            throw new RoboHubFehler('Eureka: Gerät belegt oder Synchronisierung nicht verfügbar; kein Versand.');
        }
        try {
            // Nur einmal pro manueller Anforderung und vor Plan/Verbrauch.
            // Alle späteren Kontextprüfungen bleiben ausschließlich lokal.
            if ($this->manuell && is_callable($this->kontextVorbereiten)) {
                ($this->kontextVorbereiten)($id, $buendel, $befehl);
            }
            $startAusEigenschaften = false;
            if (is_callable($this->kontextLesen)) {
                $kontext = ($this->kontextLesen)($id, $buendel);
                $geraet = $kontext['geraet'];
                $buendel = $kontext['buendel'];
                $jetzt = $kontext['jetzt'];
                if ($befehl === 'start' && $parameter === [] && ($geraet['stand']['roh']['state'] ?? null) !== 'pause') {
                    $parameter = $kontext['startparameter'] ?? [];
                    $startAusEigenschaften = true;
                }
            }
            if (($geraet['id'] ?? null) !== $id) {
                throw new RoboHubFehler('Eureka: Gerätebindung geändert; kein Versand.');
            }
            // Sämtliche Berechtigungs- und Zustandsprüfungen INNERHALB der Sperre.
            return $this->ausfuehrenGesperrt($buendel, $geraet, $befehl, $parameter, $jetzt, $startAusEigenschaften);
        } finally {
            IPS_SemaphoreLeave($sem);
        }
    }

    private function ausfuehrenGesperrt(array $buendel, array $geraet, string $befehl, array $parameter, int $jetzt, bool $startAusEigenschaften): array
    {
        if ($this->verbraucht) {
            throw new RoboHubFehler('Eureka: Befehlsversuch bereits verbraucht; kein doppelter Versand.');
        }
        $plan = self::vorbereiten($geraet, $befehl, $parameter, $this->zuordnungen, $jetzt, $this->manuell);
        if (($buendel['region'] ?? null) !== 'eu' || !is_string($buendel['access_token'] ?? null) ||
            trim($buendel['access_token']) === '') {
            throw new RoboHubFehler('Eureka: Gültiges EU-Token-Bündel erforderlich.');
        }
        // Vor dem Transport setzen: gilt auch bei Timeout, Ausnahme und Reentranz.
        $this->verbraucht = true;
        if (is_callable($this->versuch)) {
            ($this->versuch)($geraet, $buendel, true);
        }
        // Derselbe lokale Kontext wird nach der HTTP-Vorbereitung nochmals geprüft.
        $pruefen = function (bool $transportBeginnt = false) use ($geraet, $buendel, $befehl, $parameter, $jetzt, $plan, $startAusEigenschaften): void {
            $g = $geraet; $b = $buendel; $zeit = $jetzt;
            if (is_callable($this->kontextLesen)) {
                $k = ($this->kontextLesen)($geraet['id'], $buendel);
                $g = $k['geraet']; $b = $k['buendel']; $zeit = $k['jetzt'];
                if ($befehl === 'teppichmodus' && ($k['teppichparameter'] ?? []) !== $parameter) {
                    throw new RoboHubFehler('Eureka: Teppichauswahl geändert; kein Versand.');
                }
                if ($startAusEigenschaften && ($k['startparameter'] ?? []) !== $parameter) {
                    throw new RoboHubFehler('Eureka: Startauswahl geändert; kein Versand.');
                }
            }
            if ($g !== $geraet || $b !== $buendel ||
                self::vorbereiten($g, $befehl, $parameter, $this->zuordnungen, $zeit, $this->manuell) !== $plan) {
                throw new RoboHubFehler('Eureka: Befehlsgrundlage geändert; kein Versand.');
            }
            if (is_callable($this->versuch)) { ($this->versuch)($g, $b, false); }
            if (is_callable($this->vorTransport)) { ($this->vorTransport)($transportBeginnt); }
        };
        try {
            $pruefen();
            $antwort = ($this->transport)('POST', $plan['pfad'], $buendel['access_token'], $plan['body'], $pruefen);
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
        $ergebnis = self::antwortLesen($antwort);
        if (is_callable($this->beiErfolg)) { ($this->beiErfolg)($geraet, $buendel, $befehl); }
        return $ergebnis;
    }

    public function befehl(array $buendel, array $geraet, string $befehl, array $parameter, int $jetzt): string
    {
        $this->ausfuehren($buendel, $geraet, $befehl, $parameter, $jetzt);
        return 'Eureka: Befehl von der Cloud angenommen; Ausführung am Gerät nicht bestätigt.';
    }
}
