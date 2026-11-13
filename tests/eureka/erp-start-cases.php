<?php
declare(strict_types=1);

$erpStart = commandDevice('ERP_model');
$erpStart['stand']['roh'] += ['parentState' => 'sleep', 'cleanMissionState' => 'no_cleaning_mission'];
$tests['ERP: neuer Auftrag ohne B8 mit expliziten Parametern und Einmalverbrauch'] = static function () use ($erpStart): void {
    $calls = 0;
    $weg = new RoboHubEurekaBefehlsweg(static function ($method, $path, $token, $body, $check) use (&$calls): array {
        $check(); $check(true); ++$calls;
        commandAssert($method === 'POST' && $body === ['data' => 'AA15B800000000000002220200020008030000000000']);
        return commandResponse();
    }, commandMappings());
    $weg->ausfuehren(commandBundle(), $erpStart, 'start', ['fan' => 3, 'water' => 0, 'mode' => 8], COMMAND_NOW);
    commandError(fn() => $weg->ausfuehren(commandBundle(), $erpStart, 'start', ['fan' => 3, 'water' => 0, 'mode' => 8], COMMAND_NOW));
    commandAssert($calls === 1);
};
foreach ([1, 2, 3] as $fan) {
    foreach ([0, 1, 2, 3] as $water) {
        $tests['ERP: Startbytes aller belegten Kombinationen ' . $fan . '/' . $water] = static function () use ($erpStart, $fan, $water): void {
            $plan = commandPlan($erpStart, 'start', ['fan' => $fan, 'water' => $water, 'mode' => 8]);
            $bytes = [0x15, 0xB8, 0, 0, 0, 0, 0, 0, 2, 0x22, 2, 0, 2, 0, 8, $fan, 0, $water, 0, 0];
            $hex = strtoupper(bin2hex(pack('C*', 0xAA, ...[...$bytes, (-array_sum($bytes)) & 255])));
            commandAssert($plan['aktion'] === 'start' && $plan['body'] === ['data' => $hex]);
        };
    }
}
foreach ([
    'state' => ['sleep', 'sleep_pause', 'unknown', null, []],
    'parentState' => ['charging', '', null, []],
    'cleanMissionState' => ['have_cleaning_mission', '', null, [], true],
    'deviceFault' => [null, [1], '', false],
    'batteryLevel' => [null, 0, 15, 101, '100', true],
] as $field => $values) {
    foreach ($values as $i => $value) {
        $tests['ERP: fehlender/abweichender Shadow sperrt ' . $field . '/' . $i] = static function () use ($erpStart, $field, $value): void {
            $d = $erpStart;
            $d['stand']['roh'][$field] = $value;
            if ($value === null) { unset($d['stand']['roh'][$field]); }
            commandAssert(RoboHubEurekaZustand::schlafAktion($d['stand'], COMMAND_NOW) === null);
            commandError(fn() => commandPlan($d));
        };
    }
}
foreach ([null, 0, COMMAND_NOW + 1, COMMAND_NOW - 121, (string)COMMAND_NOW] as $i => $zeit) {
    $tests['ERP: ungültige Shadowzeit ' . $i] = static function () use ($erpStart, $zeit): void {
        $d = $erpStart; $d['stand']['zeit'] = $zeit;
        commandAssert(RoboHubEurekaZustand::schlafAktion($d['stand'], COMMAND_NOW) === null);
        commandError(fn() => commandPlan($d));
    };
}
foreach ([null, [], false, 'invalid', ['zeit' => COMMAND_NOW, 'felder' => null]] as $i => $b8) {
    $tests['ERP: vorhandener defekter B8-Zusatz sperrt statt Fallback ' . $i] = static function () use ($erpStart, $b8): void {
        $d = $erpStart; $d['stand']['b8'] = $b8;
        commandAssert(RoboHubEurekaZustand::schlafAktion($d['stand'], COMMAND_NOW) === null);
        commandError(fn() => commandPlan($d));
    };
}
foreach ([[], ['fan' => '3', 'water' => 0, 'mode' => 8], ['fan' => 4, 'water' => 0, 'mode' => 8],
    ['fan' => 3, 'water' => -1, 'mode' => 8], ['fan' => 3, 'water' => 0, 'mode' => 9],
    ['fan' => 3, 'water' => 0, 'mode' => 8, 'extra' => 1]] as $i => $parameter) {
    $tests['ERP: ungültige Startparameter ' . $i] = static fn() => commandError(fn() => commandPlan($erpStart, 'start', $parameter));
}
$tests['ERP: Frischegrenze, Akku, Bindung und Offline bleiben maßgeblich'] = static function () use ($erpStart): void {
    $d = $erpStart; $d['stand']['zeit'] -= 120; $d['stand']['roh']['batteryLevel'] = 16;
    commandAssert(commandPlan($d)['aktion'] === 'start');
    $d = $erpStart; $d['stand']['deviceId'] = 'other'; commandError(fn() => commandPlan($d));
    $d = $erpStart; $d['stand']['roh']['connected'] = false; commandError(fn() => commandPlan($d));
    $d = $erpStart; $d['roh']['connected'] = false; commandError(fn() => commandPlan($d));
};
unset($erpStart);
