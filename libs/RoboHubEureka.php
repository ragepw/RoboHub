<?php
declare(strict_types=1);

require_once __DIR__ . '/RoboHubModell.php';
require_once __DIR__ . '/RoboHubEurekaBefehlsweg.php';
require_once __DIR__ . '/RoboHubEurekaZustand.php';
require_once __DIR__ . '/RoboHubEurekaDiagnose.php';
require_once __DIR__ . '/RoboHubEurekaDock.php';
require_once __DIR__ . '/RoboHubEurekaPauseTest.php';

/**
 * Eureka / MZ-Robo Cloud
 * Anmeldung, Geräte, Status und individuell gebundene E20-Bedienung.
 */
class RoboHubEureka implements RoboHubTreiber
{
    public $debug = null;

    /**
     * Konto-Rückruf: erneuert und persistiert vor dem erneuten Datenabruf.
     * @var callable|null Empfängt den abgelehnten Access-Token, liefert das gespeicherte Bündel.
     */
    public $beiUnauthorized = null;

    /** Lokaler Konto-/Gerätekontext, nach Erwerb der Gerätesperre erneut lesen. */
    public $befehlsKontext = null;
    public $befehlsVorbereitung = null;
    public $befehlsErfolg = null;
    public $befehlsVersuch = null;
    public bool $manuelleBedienung = false;
    private ?array $steuerTransport = null;
    public ?RoboHubEurekaPauseTest $pauseTest = null;

    protected function jetzt(): int
    {
        return time();
    }

    private const BASE = 'https://euprod.mzrobo.com';
    private const CLIENT_ID = '1301';
    private const APP_VERSION = '1.2.1.26101001';
    // Nur nach gesonderter Freigabe und Review im Quellcode änderbar.
    private const BEFEHLE_FREIGEGEBEN = true;
    // Liveprofil laut Gerätecache; Herstellerbytes: Plugin 2.5.4.
    // Nur dieses vollständige Profil, niemals eine Modell-Heuristik.
    private const BEFEHLS_ZUORDNUNGEN = [[
        'identifikation' => [
            'productModel' => 'E20 Evo Plus', 'sn8' => '750Y0015',
            'protoVersion' => 0, 'platform' => 'robozone',
            'plugin' => ['name' => 'T0xB8_750Y0015.zip', 'versionName' => '2.5.4'],
        ],
        'familie' => RoboHubEurekaProtokoll::FAMILIE,
        'beleg' => 'docs/eureka-regulaere-steuerung.md: Liveprofil und Herstellerbelege 2.5.4',
    ]];
    private const BEFEHLS_GERAET_ID = '2115890368241139713';

    /** Nur feste individuelle Bindung; Modell und Kontext werden unter Sperre geprüft. */
    public static function regulaereBedienungFuer(string $id): bool
    {
        return self::BEFEHLE_FREIGEGEBEN && self::BEFEHLS_GERAET_ID !== '' &&
            hash_equals(self::BEFEHLS_GERAET_ID, $id);
    }

    protected function transport(
        string $method,
        string $path,
        string $token = '',
        ?array $body = null
    ): array {
        // Zweite, vom befehl()-Einstieg unabhängige Sperre VOR Headern und cURL.
        if (str_starts_with($path, '/devices/commands')) {
            if (!$this->steuerTransportErlaubt() || $this->steuerTransport === null ||
                [$method, $path, $token, $body] !== $this->steuerTransport['auftrag']) {
                throw new RoboHubFehler('Eureka: Produktiver Befehls-Transport gesperrt.');
            }
            return $this->httpAusfuehren($method, $path, $token, $body, $this->steuerTransport['pruefen']);
        }
        return $this->httpAusfuehren($method, $path, $token, $body);
    }

    /** Nur der exakt vorbereitete Pauseplan darf die unveränderten Globalsperren umgehen. */
    private function steuerTransportErlaubt(): bool
    {
        if (self::BEFEHLE_FREIGEGEBEN) { return true; }
        if ($this->pauseTest === null || ($this->steuerTransport['pauseTest'] ?? false) !== true ||
            !$this->manuelleBedienung) { return false; }
        $a = $this->steuerTransport['auftrag'];
        if ($a[0] !== 'POST' || $a[3] !== ['data' => RoboHubEurekaProtokoll::pause()]) { return false; }
        $this->pauseTest->vorTransport();
        return true;
    }

    /** Gemeinsame HTTPS-Implementierung für GETs, Befehle und B8-/Dock-Einmalpfade. */
    protected function httpAusfuehren(string $method, string $path, string $token, ?array $body, ?callable $diagnosePruefung = null, bool $dock = false): array
    {
        // Auch die gemeinsame unterste Transportgrenze bleibt für Normalbefehle zu.
        if (str_starts_with($path, '/devices/commands')) {
            $normal = $this->steuerTransportErlaubt() && !$dock && $this->steuerTransport !== null &&
                $diagnosePruefung === $this->steuerTransport['pruefen'] &&
                [$method, $path, $token, $body] === $this->steuerTransport['auftrag'];
            if ($normal) {
                // Exakter geprüfter Plan, dieselbe HTTPS-Implementierung wie Dock.
                $diagnosePruefung();
            } else {
                if ($method !== 'POST' || !preg_match('~\A/devices/commands/[A-Za-z0-9_-]{1,128}\z~D', $path) ||
                    $body !== ['data' => ($dock ? RoboHubEurekaDock::BEFEHL : RoboHubEurekaDiagnose::BEFEHL)] || $token === '' || $diagnosePruefung === null) {
                    throw new RoboHubFehler('Eureka: Befehls-Transport ohne Diagnoseberechtigung gesperrt.');
                }
                $diagnosePruefung();
            }
        }
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

        try {
            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
            ];

            if ($body !== null) {
                $options[CURLOPT_POSTFIELDS] =
                    json_encode($body, JSON_THROW_ON_ERROR);
            }

            if ($diagnosePruefung !== null) {
                // HTTP/2 REFUSED_STREAM kann selbst innerhalb eines curl_exec() erneut
                // senden. HTTP/1.1 allein genügt ebenfalls nicht: keine alte Verbindung,
                // kein Expect-417-Neuversuch, keine HTTP-/Proxy-Auth-Aushandlung.
                $options[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_1_1;
                $options[CURLOPT_FRESH_CONNECT] = true;
                $options[CURLOPT_FORBID_REUSE] = true;
                $options[CURLOPT_HTTPAUTH] = CURLAUTH_NONE;
                $options[CURLOPT_PROXYAUTH] = CURLAUTH_NONE;
                $options[CURLOPT_PROXY] = '';
                $options[CURLOPT_HTTPHEADER][] = 'Expect:';
            }

            // Bei false ist nur ein Teil der Optionen gesetzt. Niemals damit senden.
            try {
                $konfiguriert = curl_setopt_array($ch, $options);
            } catch (Throwable $e) {
                throw new RoboHubFehler('Eureka: cURL-Konfiguration fehlgeschlagen; kein Transport.');
            }
            if ($konfiguriert !== true) {
                throw new RoboHubFehler('Eureka: cURL-Konfiguration fehlgeschlagen; kein Transport.');
            }

            if ($diagnosePruefung !== null) {
                // Letzte lokale Prüfung nach der cURL-Vorbereitung und vor dem ersten I/O.
                $diagnosePruefung(true);
            }
            $response = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($response === false) {
                throw new RoboHubFehler(
                    'Eureka: Cloud-Verbindung fehlgeschlagen.'
                );
            }
            if ($diagnosePruefung !== null && curl_getinfo($ch, CURLINFO_HTTP_VERSION) !== CURL_HTTP_VERSION_1_1) {
                // Nachträglicher Laufzeitnachweis, keine Zustellgarantie und kein Retry.
                throw new RoboHubFehler('Eureka: Diagnose-HTTP-Version unbestätigt; keine Wiederholung.');
            }
        } finally {
            curl_close($ch);
        }

        return ['status' => $status, 'response' => $response];
    }

    /** Kein 401-Refresh, kein Retry, kein anderer Befehl/Endpunkt. Nur Konto-Adapter. */
    final public function diagnoseB8(RoboHubEurekaDiagnose $diagnose, string $freigabe): array
    {
        return $diagnose->ausfuehren($freigabe,
            function (string $path, string $token, array $body, callable $pruefen): array {
                if (!preg_match('~\A/devices/commands/[A-Za-z0-9_-]{1,128}\z~D', $path) ||
                    $body !== ['data' => RoboHubEurekaDiagnose::BEFEHL] || $token === '') {
                    throw new RoboHubFehler('Eureka: Diagnose-Transportparameter gesperrt.');
                }
                // Direkt vor Headern/cURL erneut lokal prüfen: Kontext, Sitzung,
                // gültige Freigabe UND bereits persistierten Verbrauch.
                $pruefen();
                return $this->httpAusfuehren('POST', $path, $token, $body, $pruefen);
            });
    }

    /** Separater Dock-Pfad, keine normale Befehlsfreigabe und kein Refresh/Retry. */
    final public function dockEinmal(RoboHubEurekaDock $dock, string $freigabe): array
    {
        return $dock->ausfuehren($freigabe,
            function (string $path, string $token, array $body, callable $pruefen): array {
                if (!preg_match('~\A/devices/commands/[A-Za-z0-9_-]{1,128}\z~D', $path) ||
                    $body !== ['data' => RoboHubEurekaDock::BEFEHL] || $token === '') {
                    throw new RoboHubFehler('Eureka: Dock-Transportparameter gesperrt.');
                }
                $pruefen();
                return $this->httpAusfuehren('POST', $path, $token, $body, $pruefen, true);
            });
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
                'online' => RoboHubEurekaZustand::verbunden($g) === true,
                'roh' => $g,
                'verbindungEmpfangen' => $this->jetzt(),
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

        $p = RoboHubEurekaZustand::ziel($result, (string)$geraet['id']);

        $daten = $this->normalisiere(
            (string)($geraet['modell'] ?? ''),
            $p
        );

        // Die boolesche Altvariable bleibt streng auf Shadow-Nachweis begrenzt.
        // Die zusätzliche Anzeige darf einen frischen Listenbefund verwenden.
        $daten['online'] = RoboHubEurekaZustand::verbunden($p) === true;
        $daten['erreichbarkeit'] = RoboHubEurekaZustand::erreichbarkeit($p, $geraet, $this->jetzt());
        $daten['eurekaStatus'] = true;
        // Empfangszeit ist ausdrücklich keine bestätigte Gerätezeit.
        $daten['statusquelle'] = ['quelle' => 'shadow-get', 'geraetezeit' => null];
        $daten['roh'] = $p;

        return $daten;
    }

    public function normalisiere(string $modell, array $p): array
    {
        $bewertung = RoboHubEurekaZustand::shadow($p);
        $state = $p['state'] ?? '';
        $parent = $p['parentState'] ?? '';
        // Das bisherige Textformat für gültige Strings erhalten. Ungültige
        // Typen als JSON bewahren, statt sie zu "Array" oder "1" umzuwandeln.
        $roh = is_string($state) && is_string($parent) ? $state . ' / ' . $parent :
            json_encode(array_intersect_key($p, array_flip(['state', 'parentState'])),
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        $daten = [
            'Zustand' => $bewertung['zustand'],
            'ZustandRoh' => isset($bewertung['herstellerstatus'])
                ? $bewertung['herstellerstatus'] . ' (' . $roh . ')' : $roh,
            'Akku' => is_int($p['batteryLevel'] ?? null) && $p['batteryLevel'] >= 0 && $p['batteryLevel'] <= 100
                ? $p['batteryLevel'] : null,
        ];

        // Istwerte separat von der lokalen Auswahl für den nächsten Auftrag.
        foreach (['fanLevel' => 'Saugstufe', 'waterLevel' => 'Wassermenge', 'autoType' => 'Reinigungsmodus'] as $quelle => $ziel) {
            $daten[$ziel] = is_int($p[$quelle] ?? null) || is_string($p[$quelle] ?? null)
                ? (string)$p[$quelle] : 'Unbekannt';
        }

        if (array_key_exists('deviceFault', $p)) {
            $daten['Fehler'] = $p['deviceFault'] === [] ? 'Keine Fehler' :
                json_encode($p['deviceFault'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        } else {
            // Keine alte Entwarnung oder Fehlermeldung als aktuellen Befund behalten.
            $daten['Fehler'] = 'Unbekannt';
        }

        // Fortschritt erst aktivieren, wenn dessen
        // Semantik am E20 Evo Plus nachgewiesen ist.
        return ['werte' => $daten, 'optionen' => [], 'schreibbar' => [],
            'online' => RoboHubEurekaZustand::verbunden($p) === true,
            'zustandsbewertung' => $bewertung];
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

    final public function befehl(
        array $buendel,
        array $geraet,
        string $befehl,
        array $parameter
    ): string {
        $pause = $this->pauseTest;
        // Alte Einmalverbräuche bleiben gespeichert. Die separat freigegebene
        // reguläre Bedienung besitzt ihren eigenen, weiterhin gültigen Verbrauch.
        $regulaer = self::regulaereBedienungFuer((string)($geraet['id'] ?? ''));
        $test = !$regulaer && $pause !== null && $this->manuelleBedienung && $befehl === 'pause' && $parameter === [];
        $zuordnungen = self::BEFEHLS_ZUORDNUNGEN;
        if ($test) {
            $k = $pause->pruefen();
            if (($geraet['id'] ?? null) !== $k['geraet']['id'] || $buendel !== $k['buendel']) {
                throw new RoboHubFehler('Eureka: Pause-Testziel oder Sitzung geändert.');
            }
            $zuordnungen = RoboHubEurekaPauseTest::zuordnungen($k);
        } elseif (!self::BEFEHLE_FREIGEGEBEN) {
            throw new RoboHubFehler('Eureka: Steuerbefehle noch nicht freigegeben.');
        }
        if (!$test && !$this->manuelleBedienung) {
            throw new RoboHubFehler('Eureka: Ausschließlich manuelle Bedienung freigegeben.');
        }
        if (!$test && (self::BEFEHLS_GERAET_ID === '' || ($geraet['id'] ?? null) !== self::BEFEHLS_GERAET_ID)) {
            throw new RoboHubFehler('Eureka: Separat bestätigte Befehlsgerätebindung fehlt.');
        }
        if (!is_callable($this->befehlsKontext) || !is_callable($this->befehlsVersuch)) {
            throw new RoboHubFehler('Eureka: Lokale Kontextprüfung fehlt; kein Versand.');
        }
        // Das Konto liefert bereits ein erneuertes und persistiertes Bündel.
        // Eine neue Instanz entspricht genau einem Versuch, ohne POST-Wiederholung.
        $weg = new RoboHubEurekaBefehlsweg(
            function (string $method, string $path, string $token, ?array $body, callable $pruefen) use ($test): array {
                if ($this->steuerTransport !== null) {
                    throw new RoboHubFehler('Eureka: Befehls-Transport bereits belegt.');
                }
                $this->steuerTransport = ['auftrag' => [$method, $path, $token, $body], 'pruefen' => $pruefen, 'pauseTest' => $test];
                try {
                    return $this->transport($method, $path, $token, $body);
                } finally {
                    $this->steuerTransport = null;
                }
            },
            $zuordnungen,
            $test ? null : $this->beiUnauthorized,
            $this->befehlsKontext,
            $this->manuelleBedienung,
            function (array $g, array $b, bool $verbrauchen) use ($test, $pause): void {
                if ($test && $verbrauchen) { $pause->verbrauchen(); }
                ($this->befehlsVersuch)($g, $b, $verbrauchen);
            },
            $test ? fn(bool $beginn) => $pause->vorTransport($beginn) : null,
            $test ? null : $this->befehlsVorbereitung,
            $test ? null : $this->befehlsErfolg
        );
        return $weg->befehl($buendel, $geraet, $befehl, $parameter, $this->jetzt());
    }

    public function echtzeitMoeglich(): bool
    {
        return false;
    }
}
