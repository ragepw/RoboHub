<?php
declare(strict_types=1);

// Eureka/MZ-Robo: nur Anmeldung und Geräteliste.
// Keine Befehle, keine Speicherung von Zugangsdaten.

function requestCloud(string $url, string $method, ?array $body = null, string $token = ''): array
{
    $headers = [
        'Accept-Language: en',
        'nonce: ' . bin2hex(random_bytes(16)),
        'x-platform: Android29',
        'x-app-version: 1.2.1.26101001',
        'x-app-env: prod',
        'x-timezone-id: UTC',
        'Content-Type: application/json',
        'User-Agent: okhttp/4.10.0',
    ];

    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    }

    try {
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false) {
            throw new RuntimeException('Cloud-Verbindung fehlgeschlagen.');
        }

        $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        if ($status !== 200 || !is_array($data)) {
            throw new RuntimeException("HTTP-Status: $status");
        }

        if (isset($data['errorCode']) && (string)$data['errorCode'] !== '0') {
            throw new RuntimeException('Cloud-Anfrage fehlgeschlagen.');
        }

        return $data['data'] ?? $data;
    } finally {
        curl_close($ch);
    }
}

$email = getenv('EUREKA_EMAIL') ?: '';
$password = getenv('EUREKA_PASSWORD') ?: '';

if ($email === '' || $password === '') {
    exit("EUREKA_EMAIL und EUREKA_PASSWORD fehlen.\n");
}

try {
    $base = 'https://euprod.mzrobo.com';

    echo "1. Anmeldung an der Eureka-EU-Cloud ...\n";
    $login = requestCloud(
        $base . '/auth/login/email-password?clientId=1301',
        'POST',
        ['email' => $email, 'password' => $password]
    );

    $token = $login['accessToken'] ?? '';
    if ($token === '') {
        throw new RuntimeException('Kein Access-Token erhalten');
    }

    echo "   Anmeldung erfolgreich.\n";
    echo "2. Geräteliste abfragen ...\n";

    $result = requestCloud(
        $base . '/app/devices?version=1&isShared=false',
        'GET',
        null,
        $token
    );

    $devices = $result;
    if (!array_is_list($devices)) {
        $devices = [];
        foreach (['list', 'devices', 'items', 'data'] as $key) {
            if (isset($result[$key]) && is_array($result[$key])) {
                $devices = $result[$key];
                break;
            }
        }
    }

    echo '   Anzahl Geräte: ' . count($devices) . "\n";

    foreach ($devices as $index => $device) {
        if (!is_array($device)) continue;

        echo "\nGerät " . ($index + 1) . ":\n";
        foreach (['nickname', 'name', 'model', 'sn8', 'online'] as $key) {
            if (isset($device[$key]) && is_scalar($device[$key])) {
                echo "   $key: " . json_encode($device[$key]) . "\n";
            }
        }
        echo '   Verfügbare Felder: ' . implode(', ', array_keys($device)) . "\n";
    }


    echo "\n3. Modellinformationen und Shadow-Status ...\n";

    foreach ($devices as $device) {
        if (!is_array($device)) continue;

        // Nur unkritische Modellinformationen anzeigen.
        foreach (['product', 'panel'] as $section) {
            if (!isset($device[$section]) || !is_array($device[$section])) continue;

            echo "\n$section:\n";
            foreach ($device[$section] as $key => $value) {
                if (preg_match('/token|secret|key|password|url|id|owner|serial|sn/i', (string)$key)) {
                    continue;
                }
                if (is_scalar($value) || $value === null) {
                    echo "  $key: " . json_encode($value) . "\n";
                }
            }
        }

        $deviceId = (string)($device['deviceId'] ?? '');
        if ($deviceId === '') continue;

        $state = requestCloud(
            $base . '/shadow/device/state?devices=' . rawurlencode($deviceId),
            'GET',
            null,
            $token
        );

        echo "\nShadow-Status – Struktur:\n";

        $showStructure = function (
            array $data,
            int $depth = 0
        ) use (&$showStructure): void {
            if ($depth > 5) return;

            foreach (array_slice($data, 0, 40, true) as $key => $value) {
                $indent = str_repeat('  ', $depth);
                $type = get_debug_type($value);

                if (is_array($value)) {
                    echo "{$indent}{$key}: array\n";
                    $showStructure($value, $depth + 1);
                } else {
                    echo "{$indent}{$key}: {$type}\n";
                }
            }
        };

        $showStructure($state);

        echo "\nAusgewählte Betriebswerte:\n";

        $entry = $state[0] ?? $state;
        if (is_array($entry)) {
            foreach ([
                'state', 'parentState', 'cleaningType',
                'batteryLevel', 'battery', 'deviceFault',
                'fanLevel', 'waterLevel',
                'cleanMissionState', 'cleanProgress', 'autoType'
            ] as $key) {
                if (array_key_exists($key, $entry)) {
                    echo $key . ': ' .
                        json_encode($entry[$key], JSON_UNESCAPED_UNICODE) .
                        "\n";
                }
            }
        }


        // Vorläufig keine Rohwerte ausgeben: möglicherweise Gerätekennungen.
    }

    echo "\nLesetest abgeschlossen.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Eureka-Lesetest fehlgeschlagen.\n");
    exit(1);
}
