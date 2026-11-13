<?php
declare(strict_types=1);

// Nutzerbeobachtung, keine Live-Abfrage. Gemeinsame Gegenproben für Anzeige und Plan.
$sleepPause = commandDevice('sleep_pause');
$sleepPause['stand']['roh'] += ['parentState' => 'sleep', 'cleanMissionState' => 'have_cleaning_mission'];
$tests['Sleep-Pause: Anzeige Schlaf, lokaler Resume-Plan ohne B8, genau ein Continue'] = static function () use ($sleepPause): void {
    $r = (new RoboHubEureka())->normalisiere('E20 Evo Plus', $sleepPause['stand']['roh']);
    commandAssert($r['werte']['Zustand'] === RoboHubModell::ZUSTAND_SCHLAF && $r['zustandsbewertung']['konsistent']);
    commandAssert(str_contains($r['werte']['ZustandRoh'], 'Auftrag pausiert'));
    $plan = commandPlan($sleepPause, 'start', []);
    commandAssert($plan['aktion'] === 'resume' && $plan['body'] === ['data' => 'AA0DB800000000000002221C00FB']);
    $calls = 0;
    $way = new RoboHubEurekaBefehlsweg(
        static function ($method, $path, $token, $body, $check) use (&$calls): array {
            $check(); $check(true); $calls++;
            commandAssert($method === 'POST' && $body === ['data' => 'AA0DB800000000000002221C00FB']);
            return commandResponse();
        }, commandMappings(), null,
        static fn() => ['geraet' => $sleepPause, 'buendel' => commandBundle(), 'jetzt' => COMMAND_NOW]);
    $way->ausfuehren(commandBundle(), $sleepPause, 'start', [], COMMAND_NOW);
    commandError(fn() => $way->ausfuehren(commandBundle(), $sleepPause, 'start', [], COMMAND_NOW));
    commandAssert($calls === 1);
};
foreach ([
    'state' => ['sleep_unknown', 'sleep', 'pause_sleep', 'sleep_charge', '', null, []],
    'parentState' => ['cleaning', 'pause', '', null, [], false],
    'cleanMissionState' => ['no_cleaning_mission', '', null, [], true],
    'deviceFault' => [[1], ['message' => 'fault'], null, '', false, (object)[]],
] as $field => $values) {
    foreach ($values as $i => $value) {
        $tests['Sleep-Pause: Gegenfall ' . $field . '/' . $i] = static function () use ($sleepPause, $field, $value): void {
            $d = $sleepPause; $d['stand']['roh'][$field] = $value;
            if ($value === null) { unset($d['stand']['roh'][$field]); }
            $r = RoboHubEurekaZustand::shadow($d['stand']['roh']);
            // Der bisher bekannte generische sleep-Wert bleibt Anzeige, ohne Resume.
            if ($field !== 'state' || $value !== 'sleep') {
                commandAssert(!$r['konsistent'] && $r['zustand'] === RoboHubModell::ZUSTAND_UNBEKANNT);
            }
            commandAssert(RoboHubEurekaZustand::schlafAktion($d['stand'], COMMAND_NOW) === null);
            foreach (['start', 'pause', 'station'] as $cmd) { commandError(fn() => commandPlan($d, $cmd, [])); }
        };
    }
}
foreach ([null, 0, 15, 101, '87', true, []] as $i => $battery) {
    $tests['Sleep-Pause: Akkuschranke ' . $i] = static function () use ($sleepPause, $battery): void {
        $d = $sleepPause; $d['stand']['roh']['batteryLevel'] = $battery;
        commandAssert(RoboHubEurekaZustand::schlafAktion($d['stand'], COMMAND_NOW) === null);
        commandError(fn() => commandPlan($d, 'start', []));
    };
}
foreach ([COMMAND_NOW - 121, COMMAND_NOW + 1, null, '1800000000', 0] as $i => $time) {
    $tests['Sleep-Pause: Cachealter ' . $i] = static function () use ($sleepPause, $time): void {
        $d = $sleepPause; $d['stand']['zeit'] = $time;
        commandAssert(RoboHubEurekaZustand::schlafAktion($d['stand'], COMMAND_NOW) === null);
        commandError(fn() => commandPlan($d, 'start', []));
    };
}
$tests['Sleep-Pause: frische Grenze, Offline, Kennung, Parameter und andere Befehle'] = static function () use ($sleepPause): void {
    $d = $sleepPause; $d['stand']['zeit'] -= 120; $d['stand']['roh']['batteryLevel'] = 16;
    commandAssert(commandPlan($d, 'start', [])['aktion'] === 'resume');
    foreach (['pause', 'station', 'resume'] as $cmd) { commandError(fn() => commandPlan($sleepPause, $cmd, [])); }
    commandError(fn() => commandPlan($sleepPause)); // Keine Neuauftragsparameter auf Continue.
    $d = $sleepPause; $d['stand']['roh']['connected'] = false;
    commandError(fn() => commandPlan($d, 'start', []));
    $d = $sleepPause; $d['stand']['deviceId'] = 'wrong-device';
    commandError(fn() => commandPlan($d, 'start', []));
    $d = $sleepPause; $d['roh']['deviceFault'] = [1];
    commandError(fn() => commandPlan($d, 'start', []));
};
unset($sleepPause);
