<?php
declare(strict_types=1);

// Simulierte Referenzidentität und Antworten. Kein cURL, keine Cloud.
require_once __DIR__ . '/e20-fixture.php';
require_once __DIR__ . '/../../libs/RoboHubEureka.php';

const COMMAND_NOW = 1800000000;
function commandAssert(bool $ok): void
{
    if (!$ok) { throw new RuntimeException('Offline-Prüfung fehlgeschlagen.'); }
}
function commandError(callable $aktion): RoboHubFehler
{
    try { $aktion(); } catch (RoboHubFehler $e) {
        commandAssert(!$e->anmeldungNoetig && !str_contains($e->getMessage(), 'fixture-secret'));
        return $e;
    }
    throw new RuntimeException('Erwartete Sperre fehlt.');
}
function commandDevice(string $state = 'stop'): array
{
    return [
        'id' => 'offline-command-device', 'modell' => 'offline-model', 'online' => true,
        'roh' => [
            'deviceId' => 'offline-command-device',
            'product' => ['productModel' => 'offline-model', 'sn8' => '610VR510', 'protoVersion' => 0, 'platform' => 'offline-platform'],
            'panel' => ['name' => 'T0xB8_610VR510', 'versionName' => '2.2.46'],
        ],
        'stand' => ['deviceId' => 'offline-command-device', 'zeit' => COMMAND_NOW,
            'roh' => ['state' => $state, 'deviceFault' => [], 'connected' => true, 'batteryLevel' => 87]],
    ];
}
function commandMappings(): array
{
    // Testnachweis, KEINE Freigabe oder behauptete Identität des echten E20.
    return [[
        'identifikation' => ['productModel' => 'offline-model', 'sn8' => '610VR510', 'protoVersion' => 0,
            'platform' => 'offline-platform', 'plugin' => ['name' => 'T0xB8_610VR510', 'versionName' => '2.2.46']],
        'familie' => 'midea-b8-v0', 'beleg' => 'Ausschließlich simulierte Offline-Zuordnung',
    ]];
}
function commandBundle(): array { return ['region' => 'eu', 'access_token' => 'fixture-secret-access', 'refresh_token' => 'fixture-secret-refresh']; }
function commandResponse($data = null, int $status = 200): array
{
    return ['status' => $status, 'response' => json_encode(['errorCode' => 0, 'data' => $data])];
}
function commandPlan(array $device, string $command = 'start', array $params = ['fan' => 2, 'water' => 0, 'mode' => 8]): array
{
    return RoboHubEurekaBefehlsweg::vorbereiten($device, $command, $params, commandMappings(), COMMAND_NOW);
}

/** Nur Transport ersetzt; finaler befehl()-Einstieg muss trotzdem sperren. */
class GuardEureka extends RoboHubEureka
{
    public int $calls = 0;
    protected function transport(string $method, string $path, string $token = '', ?array $body = null): array
    {
        $this->calls++;
        throw new RuntimeException('Kein Transport bei gesperrtem Befehl erlaubt.');
    }
}
