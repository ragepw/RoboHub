<?php
declare(strict_types=1);
require_once __DIR__ . '/command-fixtures.php';
foreach ([0 => 'AA0DB800000000000002220C000B', 1 => 'AA0DB800000000000002220C010A'] as $wert => $hex) {
    commandAssert(RoboHubEurekaProtokoll::teppichmodus($wert) === $hex);
    foreach (['stop', 'charging', 'charging_finish', 'pause', 'cleaning'] as $state) {
        commandAssert(commandPlan(commandDevice($state), 'teppichmodus', ['teppichmodus' => $wert])['body'] === ['data' => $hex]);
    }
}
foreach ([-1, 2, 255, '1', true, null, [], 1.0] as $wert) {
    commandError(fn() => RoboHubEurekaProtokoll::teppichmodus($wert));
    commandError(fn() => commandPlan(commandDevice(), 'teppichmodus', ['teppichmodus' => $wert]));
}
foreach (['sleep', 'ERP_model', 'sleep_pause', 'to_collect_dust', 'collecting_dust', 'unknown', 'error'] as $state) {
    commandError(fn() => commandPlan(commandDevice($state), 'teppichmodus', ['teppichmodus' => 1]));
    if ($state === 'to_collect_dust') {
        commandAssert((new RoboHubEureka())->normalisiere('', ['state' => $state])['werte']['Zustand'] === -1);
    }
}
foreach ([[], ['teppichmodus' => 1, 'extra' => 0]] as $p) {
    commandError(fn() => commandPlan(commandDevice(), 'teppichmodus', $p));
}
foreach (['offline', 'alt', 'fehler', 'parent'] as $fall) {
    $d = commandDevice();
    if ($fall === 'offline') { $d['stand']['roh']['connected'] = false; }
    if ($fall === 'alt') { $d['stand']['zeit'] -= 121; }
    if ($fall === 'fehler') { $d['stand']['roh']['deviceFault'] = [1]; }
    if ($fall === 'parent') { $d['stand']['roh']['parentState'] = 'sleep_pause'; }
    commandError(fn() => commandPlan($d, 'teppichmodus', ['teppichmodus' => 1]));
}
echo "Teppich-Protokoll, Werte, Zustands- und Sicherheitsgegenproben bestanden\n";

// Regulärer Schlaf: ausschließlich der bestätigte Teppichbefehl.
foreach ([0, 1] as $wert) {
    $d = commandDevice('ERP_model');
    $d['stand']['roh']['parentState'] = 'sleep';
    $d['stand']['roh']['cleanMissionState'] = 'no_cleaning_mission';
    commandAssert(commandPlan($d, 'teppichmodus', ['teppichmodus' => $wert])['body'] ===
        ['data' => RoboHubEurekaProtokoll::teppichmodus($wert)]);
    foreach (['parent', 'mission', 'fault', 'missing-fault', 'old', 'offline', 'binding', 'value', 'extra'] as $fall) {
        $g = $d; $p = ['teppichmodus' => $wert];
        switch ($fall) {
            case 'parent': $g['stand']['roh']['parentState'] = 'unknown'; break;
            case 'mission': $g['stand']['roh']['cleanMissionState'] = 'have_cleaning_mission'; break;
            case 'fault': $g['stand']['roh']['deviceFault'] = [1]; break;
            case 'missing-fault': unset($g['stand']['roh']['deviceFault']); break;
            case 'old': $g['stand']['zeit'] -= 121; break;
            case 'offline': $g['stand']['roh']['connected'] = false; break;
            case 'binding': $g['stand']['deviceId'] = 'other'; break;
            case 'value': $p['teppichmodus'] = '1'; break;
            case 'extra': $p['other'] = 1; break;
        }
        commandError(fn() => commandPlan($g, 'teppichmodus', $p));
    }
    foreach (['pause', 'station'] as $b) { commandError(fn() => commandPlan($d, $b, [])); }
}
echo "Teppich-Schlafgrenzen und Sicherheitsgegenproben bestanden\n";
