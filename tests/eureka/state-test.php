<?php
declare(strict_types=1);

// Ausschließlich synthetische Daten. Jeder Transport ist ersetzt.
require_once __DIR__ . '/command-fixtures.php';
$tests = [];

// Erwartungen unabhängig als Tabelle aus E20-Analysebericht §6/7 und HA const.py.
$cloud = ['cleaning' => 1, 'pause' => 2, 'charging_pause' => 2, 'wiping_pause' => 2,
    'charging_back' => 3, 'wiping_back' => 3, 'charging' => 4, 'charging_finish' => 5,
    'stop' => 0, 'sleep' => 8, 'upgrading' => 10, 'error' => 7];
$parents = ['cleaning' => ['cleaning', 'pause', 'charging_pause', 'wiping_pause', 'charging_back', 'wiping_back', 'error'],
    'sleep' => ['sleep', 'charging', 'charging_finish', 'stop', 'error']];
foreach ($cloud as $state => $expected) {
    foreach (array_unique(['', $state, ...array_keys($cloud), 'unknown']) as $parent) {
        $valid = $parent === '' || $parent === $state || in_array($state, $parents[$parent] ?? [], true);
        $tests['Shadow ' . $state . ' / ' . $parent] = static function () use ($state, $parent, $expected, $valid): void {
            $raw = ['state' => $state, 'parentState' => $parent];
            $r = (new RoboHubEureka())->normalisiere('E20 Evo Plus', $raw);
            commandAssert($r['werte']['Zustand'] === ($valid || $state === 'error' ? $expected : -1));
            commandAssert($r['zustandsbewertung']['konsistent'] === $valid);
            commandAssert($r['werte']['ZustandRoh'] === $state . ' / ' . $parent);
            commandAssert($r['schreibbar'] === [] && $r['optionen'] === [] && $r['online'] === false);
            // Dieselbe Matrix auch am vorbereiteten Weg prüfen: Anzeige darf
            // keine zusätzliche Start-/Resume-/Stationsfreigabe erzeugen.
            $device = commandDevice($state); $device['stand']['roh']['parentState'] = $parent;
            foreach (['start', 'pause', 'station'] as $command) {
                $allowed = $valid && match ($command) {
                    'start' => in_array($state, ['pause', 'stop', 'charging', 'charging_finish'], true),
                    'pause' => $state === 'cleaning',
                    'station' => in_array($state, ['cleaning', 'pause', 'stop'], true),
                };
                $params = $command === 'start' && $state !== 'pause' ? ['fan' => 2, 'water' => 0, 'mode' => 8] : [];
                if ($allowed) {
                    commandAssert(commandPlan($device, $command, $params)['aktion'] === ($state === 'pause' && $command === 'start' ? 'resume' : $command));
                } else {
                    commandError(fn() => commandPlan($device, $command, $params));
                }
            }
        };
    }
}
foreach ([null, '', 'unknown', 'on_station', 'positioning', 'discovering', 'manual_control',
    'work', 'charge', 'charging_on_dock', 'fault', 1, 2, 8, 10, 12, '2', '8', [], true, false, 2.0] as $i => $state) {
    foreach (['', 'cleaning', 'sleep'] as $parent) {
        $tests['Unbekannter/fremder Shadowwert ' . $i . ' / ' . $parent] = static function () use ($state, $parent): void {
            commandAssert((new RoboHubEureka())->normalisiere('', ['state' => $state, 'parentState' => $parent])['werte']['Zustand'] === -1);
        };
    }
}
foreach (['cleaning', 'sleep', 'error', null, [], true, 2] as $i => $parent) {
    $tests['Parent allein reicht nicht ' . $i] = static function () use ($parent): void {
        commandAssert((new RoboHubEureka())->normalisiere('', ['parentState' => $parent])['werte']['Zustand'] === -1);
    };
}
$tests['Ungültige Typen warnen nicht und löschen keinen Rohbefund'] = static function (): void {
    $r = (new RoboHubEureka())->normalisiere('', ['state' => [], 'parentState' => false, 'fanLevel' => [], 'batteryLevel' => [], 'deviceFault' => null]);
    commandAssert($r['werte']['Zustand'] === -1 && $r['werte']['Akku'] === null && !isset($r['werte']['Saugstufe']));
    commandAssert(json_decode($r['werte']['ZustandRoh'], true) === ['state' => [], 'parentState' => false]);
    commandAssert($r['werte']['Fehler'] === 'null');
    foreach ([-1, 101, '87', true, 87.5] as $battery) {
        commandAssert((new RoboHubEureka())->normalisiere('', ['batteryLevel' => $battery])['werte']['Akku'] === null);
    }
};
$tests['Fehler und Warnungen bleiben Rohdaten, keine pauschale Fehleraktivität'] = static function (): void {
    foreach ([['class' => 3, 'code' => 1], ['class' => 1, 'code' => 2], ['class' => 2, 'code' => 3]] as $fault) {
        $r = (new RoboHubEureka())->normalisiere('', ['state' => 'cleaning', 'deviceFault' => $fault]);
        commandAssert($r['werte']['Zustand'] === 1 && json_decode($r['werte']['Fehler'], true) === $fault);
    }
    commandAssert((new RoboHubEureka())->normalisiere('', ['deviceFault' => []])['werte']['Fehler'] === 'Keine Fehler');
    commandAssert((new RoboHubEureka())->normalisiere('', [])['werte']['Fehler'] === 'Unbekannt');
};

$b8 = [1 => 3, 2 => 1, 3 => 0, 4 => 4, 5 => 0, 6 => 5, 7 => 4, 8 => 2, 9 => 10, 10 => 2,
    11 => 7, 12 => 8, 13 => 2, 14 => -1, 15 => -1, 16 => 6, 17 => 3, 18 => 8, 22 => 3, 23 => 9, 24 => 2];
for ($work = 0; $work <= 255; $work++) {
    $tests['B8-Arbeitsbyte ' . $work] = static function () use ($b8, $work): void {
        $r = RoboHubEurekaZustand::b8($work);
        commandAssert($r['zustand'] === ($b8[$work] ?? -1) && $r['arbeitRoh'] === $work && $r['schlafRoh'] === null && $r['schlaf'] === null);
    };
}
foreach ([-1, 256] as $work) {
    $tests['B8 außerhalb Bytebereich ' . $work] = static fn() => commandAssert(RoboHubEurekaZustand::b8($work)['zustand'] === -1);
}
foreach ([2, 8, 10, 12, 18] as $work) {
    foreach ([null, 0, 1, 2, 3, 4, 5, 255] as $sleep) {
        $tests['B8 Schlafuntertyp ' . $work . ' / ' . ($sleep ?? 'fehlt')] = static function () use ($work, $sleep, $b8): void {
            $r = RoboHubEurekaZustand::b8($work, $sleep);
            $names = [1 => 'charge_sleep', 2 => 'pause_sleep', 3 => 'pause_charge_sleep', 4 => 'stop_sleep'];
            commandAssert($r['zustand'] === $b8[$work] && $r['schlafRoh'] === $sleep);
            commandAssert($r['schlaf'] === (in_array($work, [12, 18], true) ? ($names[$sleep] ?? null) : null));
        };
    }
}

class StateEureka extends RoboHubEureka
{
    public array $response = [];
    public array $calls = [];
    protected function jetzt(): int { return COMMAND_NOW; }
    protected function transport(string $method, string $path, string $token = '', ?array $body = null): array
    {
        $this->calls[] = [$method, $path, $body];
        return commandResponse($this->response);
    }
}
foreach ([true, false, null, 0, 1, 'true', 'false', '1', [], ''] as $i => $connected) {
    $tests['Erreichbarkeit nur boolesch ' . $i] = static function () use ($connected): void {
        $t = new StateEureka(); $d = commandDevice();
        $t->response = [['state' => 'cleaning', 'connected' => $connected]];
        $r = $t->zustand(commandBundle(), $d);
        commandAssert($r['online'] === ($connected === true));
        commandAssert($r['erreichbarkeit']['shadow'] === (is_bool($connected) ? $connected : null));
        commandAssert($r['erreichbarkeit']['empfangen'] === COMMAND_NOW && $r['statusquelle']['geraetezeit'] === null);
        $t->response = [['deviceId' => $d['id'], 'connected' => $connected]];
        commandAssert($t->geraete(commandBundle())[0]['online'] === ($connected === true));
    };
}
$tests['Erfolgreicher GET und positiver Gerätecache beweisen kein online'] = static function (): void {
    $t = new StateEureka(); $d = commandDevice(); $d['roh']['connected'] = true;
    $t->response = [['state' => 'cleaning']];
    $r = $t->zustand(commandBundle(), $d);
    commandAssert(!$r['online'] && $r['erreichbarkeit']['shadow'] === null);
    $d['roh']['connected'] = false; $t->response[0]['connected'] = true;
    commandAssert(!$t->zustand(commandBundle(), $d)['online']);
    $d['roh']['connected'] = true; $t->response[0]['connected'] = false;
    commandAssert(!$t->zustand(commandBundle(), $d)['online']);
};
$tests['GET wählt das Ziel statt des ersten Listeneintrags'] = static function (): void {
    $t = new StateEureka(); $d = commandDevice();
    $target = ['deviceId' => $d['id'], 'state' => 'pause', 'parentState' => 'cleaning', 'connected' => true];
    $t->response = [['deviceId' => 'other', 'state' => 'cleaning'], $target];
    $r = $t->zustand(commandBundle(), $d);
    commandAssert($r['werte']['Zustand'] === 2 && $r['roh'] === $target && $r['online']);
    commandAssert($t->calls === [['GET', '/shadow/device/state?devices=offline-command-device', null]]);
    $t->response = $target;
    commandAssert($t->zustand(commandBundle(), $d)['roh'] === $target);
};
foreach ([[], [null], [['deviceId' => 'other']], [['deviceId' => null]],
    [['id' => 'other', 'deviceId' => 'offline-command-device']],
    [['deviceId' => 'offline-command-device'], ['deviceId' => 'offline-command-device']],
    [['state' => 'pause'], ['deviceId' => 'offline-command-device']],
    [['state' => 'pause'], ['state' => 'cleaning']], [42], [['deviceId' => []]]] as $i => $response) {
    $tests['Keine eindeutige Zielzuordnung ' . $i] = static function () use ($response): void {
        $t = new StateEureka(); $t->response = $response;
        commandError(fn() => $t->zustand(commandBundle(), commandDevice()));
    };
}
$tests['Unbekannter neuer Status übernimmt keine alten Werte'] = static function (): void {
    $t = new StateEureka(); $d = commandDevice('cleaning');
    $d['stand']['zeit'] -= 3600;
    $t->response = [['state' => 'unknown', 'parentState' => 'cleaning']];
    $r = $t->zustand(commandBundle(), $d);
    commandAssert($r['werte']['Zustand'] === -1 && $r['werte']['Akku'] === null && !$r['online']);
};
foreach (['missing-online', 'invalid-online', 'effective-offline', 'missing-fault', 'null-fault', 'old', 'future', 'unknown-time'] as $case) {
    $tests['Befehlsweg konservativ ' . $case] = static function () use ($case): void {
        $d = commandDevice('pause');
        switch ($case) {
            case 'missing-online': unset($d['stand']['roh']['connected']); break;
            case 'invalid-online': $d['stand']['roh']['connected'] = 'true'; break;
            case 'effective-offline': $d['stand']['online'] = false; break;
            case 'missing-fault': unset($d['stand']['roh']['deviceFault']); break;
            case 'null-fault': $d['stand']['roh']['deviceFault'] = null; break;
            case 'old': $d['stand']['zeit'] = COMMAND_NOW - 121; break;
            case 'future': $d['stand']['zeit'] = COMMAND_NOW + 1; break;
            case 'unknown-time': $d['stand']['zeit'] = null; break;
        }
        commandError(fn() => commandPlan($d, 'start', []));
    };
}
$tests['Befehlsweg: 120-Sekunden-Grenze bleibt deterministisch'] = static function (): void {
    $d = commandDevice('pause'); $d['stand']['zeit'] -= 120;
    commandAssert(commandPlan($d, 'start', [])['aktion'] === 'resume');
};
foreach ([
    [1, 'AA0CB800000000000003320106'], [6, 'AA0CB800000000000003320601'],
] as [$sub, $golden]) {
    $tests['Leserahmen ausschließlich offline 32 ' . $sub] = static function () use ($sub, $golden): void {
        commandAssert(RoboHubEurekaProtokoll::rahmen([0x32, $sub], 3) === $golden);
        RoboHubEurekaProtokoll::pruefeRahmen($golden);
        $b = array_values(unpack('C*', hex2bin($golden)));
        commandAssert(count($b) === 13 && $b[1] === 12 && $b[9] === 3 && (array_sum(array_slice($b, 1)) & 255) === 0);
    };
}

$errors = 0;
set_error_handler(static function (int $severity, string $message): never { throw new RuntimeException($message); });
foreach ($tests as $name => $test) {
    try { $test(); }
    catch (Throwable $e) { $errors++; echo 'FEHLER: ' . $name . ' (' . $e->getMessage() . ")\n"; }
}
restore_error_handler();
echo count($tests) . ' Zustandsprüfungen, ' . $errors . " Fehler\n";
exit($errors === 0 ? 0 : 1);
