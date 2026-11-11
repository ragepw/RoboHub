<?php
declare(strict_types=1);

require_once __DIR__ . '/../../libs/RoboHubEureka.php';

$email = getenv('EUREKA_EMAIL') ?: '';
$password = getenv('EUREKA_PASSWORD') ?: '';

if ($email === '' || $password === '') {
    exit("Eureka-Zugangsdaten fehlen.\n");
}

try {
    $treiber = new RoboHubEureka();

    echo "1. Eureka-Anmeldung ...\n";
    $buendel = $treiber->anmelden($email, $password, 'eu');
    echo "   Erfolgreich.\n";

    $token = (string)($buendel['access_token'] ?? '');
    $parts = explode('.', $token);

    echo "   Refresh-Token vorhanden: " .
        (!empty($buendel['refresh_token']) ? "Ja" : "Nein") . "\n";

    if (count($parts) === 3) {
        $payload = json_decode(
            base64_decode(strtr($parts[1], '-_', '+/'), true) ?: '',
            true
        );

        if (is_array($payload) && isset($payload['exp'])) {
            echo "   Token-Ablauf (UTC): " .
                gmdate('Y-m-d H:i:s', (int)$payload['exp']) . "\n";
            echo "   Restlaufzeit (Minuten): " .
                (int)floor(((int)$payload['exp'] - time()) / 60) . "\n";
        } else {
            echo "   Keine lesbare JWT-Ablaufzeit.\n";
        }
    } else {
        echo "   Token ist kein dreiteiliges JWT.\n";
    }


    echo "2. Geräte erkennen ...\n";
    $geraete = $treiber->geraete($buendel);
    echo "   Gefundene Geräte: " . count($geraete) . "\n";

    foreach ($geraete as $geraet) {
        echo "\nModell: " . $geraet['modell'] . "\n";
        echo "Name: " . $geraet['name'] . "\n";
        echo "Online: " . ($geraet['online'] ? 'Ja' : 'Nein') . "\n";

        echo "3. Status auslesen ...\n";
        $status = $treiber->zustand($buendel, $geraet);

        // Rohdaten und Gerätekennungen nicht ausgeben.
        unset($status['roh']);

        print_r($status);
    }

    echo "\nTreiber-Test abgeschlossen.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Eureka-Treibertest fehlgeschlagen.\n");
    exit(1);
}
