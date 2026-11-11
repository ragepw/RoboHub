<?php
declare(strict_types=1);

require_once __DIR__ . '/RoboHubModell.php';

/**
 * Eureka / MZ-Robo Cloud
 * Erste Version: Anmeldung, Geräte und Status.
 * Keine Steuerbefehle und keine Firmwareänderungen.
 */
class RoboHubEureka implements RoboHubTreiber
{
    public $debug = null;

    /**
     * Konto-Rückruf: erneuert und persistiert vor dem erneuten Datenabruf.
     * @var callable|null Empfängt den abgelehnten Access-Token, liefert das gespeicherte Bündel.
     */
    public $beiUnauthorized = null;

    protected function jetzt(): int
    {
        return time();
    }

    private const BASE = 'https://euprod.mzrobo.com';
    private const CLIENT_ID = '1301';
    private const APP_VERSION = '1.2.1.26101001';

    protected function transport(
        string $method,
        string $path,
        string $token = '',
        ?array $body = null
    ): array {
        $headers = [
            'Accept-Language: en',
            'nonce: ' . bin2hex(random_bytes(16)),
            'x-platform: Android29',
            'x-app-version: ' . self::APP_VERSION,
            'x-app-env: prod',
            'x-timezone-id: UTC',
            'Content-Type: application/json',
            'User-Agent: okhttp/4.10.0',
        ];

        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $ch = curl_init(self::BASE . $path);

        if ($ch === false) {
            throw new RoboHubFehler('Eureka: cURL konnte nicht starten.');
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 15,
        ];

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] =
                json_encode($body, JSON_THROW_ON_ERROR);
        }

        curl_setopt_array($ch, $options);

        try {
            $response = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($response === false) {
                throw new RoboHubFehler(
                    'Eureka: Cloud-Verbindung fehlgeschlagen.'
                );
            }
        } finally {
            curl_close($ch);
        }

        return ['status' => $status, 'response' => $response];
    }

    private function request(
        string $method,
        string $path,
        string $token = '',
        ?array $body = null
    ): array {
        $antwort = $this->transport($method, $path, $token, $body);
        $status = $antwort['status'];
        if ($status === 401) {
            // Nur die Ablehnung am Auth-Endpunkt beweist, dass eine
            // erneute Anmeldung nötig ist. Datenabrufe dürfen nichts löschen.
            $e = new RoboHubFehler('Eureka: Autorisierung abgelehnt.', 401);
            $e->anmeldungNoetig = str_starts_with($path, '/auth/');
            throw $e;
        }
        if ($status !== 200) {
            $e = new RoboHubFehler('Eureka: HTTP-Fehler ' . $status);
            if ($status === 429) {
                $e->warteSekunden = 60;
            }
            throw $e;
        }

        $data = json_decode($antwort['response'], true);
        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RoboHubFehler('Eureka: Ungültige Cloud-Antwort.');
        }
        if (array_key_exists('errorCode', $data) &&
            $data['errorCode'] !== 0 && $data['errorCode'] !== '0') {
            // Cloud-Freitext und Fehlercodes können Zugangsdaten enthalten.
            // Unbekannte Codes sind kein Nachweis eines ungültigen Refresh-Tokens.
            throw new RoboHubFehler('Eureka: Cloud-Anfrage fehlgeschlagen.');
        }
        $result = array_key_exists('data', $data) ? $data['data'] : $data;
        if (!is_array($result)) {
            throw new RoboHubFehler('Eureka: Ungültige Cloud-Daten.');
        }
        return $result;
    }

    private function datenAbrufen(string $path, array $buendel): array
    {
        $access = (string)($buendel['access_token'] ?? '');
        try {
            return $this->request('GET', $path, $access);
        } catch (RoboHubFehler $e) {
            if ($e->getCode() !== 401 || !is_callable($this->beiUnauthorized)) {
                throw $e;
            }
        }
        // Kein lokaler Refresh: das Konto muss das neue Bündel zuerst speichern.
        $neu = ($this->beiUnauthorized)($access);
        // Genau ein Wiederholungsversuch; ein weiterer 401 löscht keine Tokens.
        return $this->request('GET', $path, (string)$neu['access_token']);
    }

    private function ablauf(string $access): int
    {
        $teile = explode('.', $access);
        if (count($teile) === 3) {
            $json = base64_decode(strtr($teile[1], '-_', '+/'), true);
            $daten = $json !== false ? json_decode($json, true) : null;
            if (is_array($daten) && isset($daten['exp']) &&
                is_int($daten['exp']) && $daten['exp'] > 0) {
                return $daten['exp'];
            }
        }
        throw new RoboHubFehler('Eureka: JWT-Ablaufzeit nicht lesbar.');
    }

    public function anmelden(
        string $konto,
        string $passwort,
        string $region
    ): array {
        if ($region !== 'eu') {
            throw new RoboHubFehler(
                'Eureka: Derzeit ist nur die EU-Region implementiert.'
            );
        }

        $result = $this->request(
            'POST',
            '/auth/login/email-password?clientId=' . self::CLIENT_ID,
            '',
            ['email' => $konto, 'password' => $passwort]
        );

        if (empty($result['accessToken'])) {
            throw new RoboHubFehler(
                'Eureka: Kein Access-Token erhalten.'
            );
        }

        return [
            'region' => 'eu',
            'access_token' => (string)$result['accessToken'],
            'refresh_token' => (string)($result['refreshToken'] ?? ''),
        ];
    }

    /** $erzwingen nur nach HTTP 401; die Persistierung übernimmt weiterhin das Konto. */
    public function erneuern(array $buendel, bool $erzwingen = false): array
    {
        $access = (string)($buendel['access_token'] ?? '');
        $refresh = (string)($buendel['refresh_token'] ?? '');
        $ablauf = $access === '' ? 0 : $this->ablauf($access);

        // Exakt fünf Minuten Restgültigkeit erfordern noch keinen Refresh.
        if (!$erzwingen && $ablauf >= $this->jetzt() + 300) {
            return $buendel;
        }
        if ($refresh === '') {
            $e = new RoboHubFehler('Eureka: Token-Erneuerung ohne Refresh-Token nicht möglich.');
            $e->anmeldungNoetig = $erzwingen || $ablauf <= $this->jetzt();
            throw $e;
        }
        $result = $this->request(
            'GET',
            '/auth/refresh-token?refreshToken=' . rawurlencode($refresh)
        );
        if (!isset($result['accessToken']) || !is_string($result['accessToken']) ||
            $result['accessToken'] === '' ||
            $this->ablauf($result['accessToken']) <= $this->jetzt()) {
            throw new RoboHubFehler('Eureka: Refresh lieferte keinen gültigen Access-Token.');
        }
        if (array_key_exists('refreshToken', $result) &&
            (!is_string($result['refreshToken']) || $result['refreshToken'] === '')) {
            throw new RoboHubFehler('Eureka: Refresh lieferte einen ungültigen Refresh-Token.');
        }
        $buendel['access_token'] = $result['accessToken'];
        $buendel['refresh_token'] = $result['refreshToken'] ?? $refresh;
        return $buendel;
    }

    public function geraete(array $buendel): array
    {
        $result = $this->datenAbrufen(
            '/app/devices?version=1&isShared=false', $buendel
        );

        if (!array_is_list($result)) {
            foreach (['list', 'devices', 'items', 'data'] as $key) {
                if (isset($result[$key]) && is_array($result[$key])) {
                    $result = $result[$key];
                    break;
                }
            }
        }

        $geraete = [];

        foreach ($result as $g) {
            if (!is_array($g) || empty($g['deviceId'])) {
                continue;
            }

            $geraete[] = [
                'id' => (string)$g['deviceId'],
                'name' => (string)($g['nickname'] ?? 'Eureka'),
                'modell' => (string)(
                    $g['product']['productModel'] ?? 'Eureka'
                ),
                'klasse' => RoboHubModell::KLASSE_SAUGER,
                'online' => (bool)($g['connected'] ?? false),
                'roh' => $g,
            ];
        }

        return $geraete;
    }

    public function zustand(array $buendel, array $geraet): array
    {
        $result = $this->datenAbrufen(
            '/shadow/device/state?devices=' . rawurlencode((string)$geraet['id']),
            $buendel
        );

        $p = $result[0] ?? $result;

        if (!is_array($p)) {
            throw new RoboHubFehler(
                'Eureka: Kein gültiger Gerätestatus.'
            );
        }

        $daten = $this->normalisiere(
            (string)($geraet['modell'] ?? ''),
            $p
        );

        $daten['online'] = true;
        $daten['roh'] = $p;

        return $daten;
    }

    public function normalisiere(string $modell, array $p): array
    {
        $state = (string)($p['state'] ?? '');
        $parent = (string)($p['parentState'] ?? '');

        $zustand = RoboHubModell::ZUSTAND_UNBEKANNT;

        if ($state === 'cleaning' || $parent === 'cleaning') {
            $zustand = RoboHubModell::ZUSTAND_REINIGT;
        } elseif ($parent === 'sleep') {
            $zustand = RoboHubModell::ZUSTAND_SCHLAF;
        }

        $daten = [
            'Zustand' => $zustand,
            'ZustandRoh' => $state . ' / ' . $parent,
            'Akku' => isset($p['batteryLevel'])
                ? (int)$p['batteryLevel'] : null,
        ];

        if (isset($p['fanLevel'])) {
            $daten['Saugstufe'] = (string)$p['fanLevel'];
        }

        if (isset($p['deviceFault']) &&
            is_array($p['deviceFault']) &&
            $p['deviceFault'] === []) {
            $daten['Fehler'] = 'Keine Fehler';
        }

        // Fortschritt erst aktivieren, wenn dessen
        // Semantik am E20 Evo Plus nachgewiesen ist.
        return ['werte' => $daten, 'optionen' => [], 'schreibbar' => []];
    }

    public function karte(array $buendel, array $geraet): array
    {
        return ['raeume' => [], 'bild' => '', 'marken' => [], 'station' => null];
    }

    public function liveKarte(array $buendel, array $geraet): array
    {
        return $this->karte($buendel, $geraet);
    }

    public function mqttZiel(array $geraete, array $buendel): array
    {
        return ['', 0];
    }

    public function mqttTopic(array $geraet, array $buendel): string
    {
        return '';
    }

    public function mqttZugang(array $buendel): array
    {
        return ['', ''];
    }

    public function pushLesen(string $payload): array
    {
        return [];
    }

    public function zuruecksetzen(
        array $buendel,
        array $geraet,
        string $teil
    ): string {
        throw new RoboHubFehler(
            'Eureka: Wartungsreset noch nicht implementiert.'
        );
    }

    public function befehl(
        array $buendel,
        array $geraet,
        string $befehl,
        array $parameter
    ): string {
        throw new RoboHubFehler(
            'Eureka: Steuerbefehle noch nicht freigegeben.'
        );
    }

    public function echtzeitMoeglich(): bool
    {
        return false;
    }
}
