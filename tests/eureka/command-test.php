<?php
declare(strict_types=1);

require_once __DIR__ . '/command-fixtures.php';
require_once __DIR__ . '/command-lock-fixture.php';

$tests = [];
// Golden Frames unabhängig aus der Python-Referenz ce933e6 ermittelt.
foreach ([
    'Start 2/0/8' => [fn() => RoboHubEurekaProtokoll::start(2, 0, 8), 'AA15B800000000000002220200020008020000000001'],
    'Start 3/2/8' => [fn() => RoboHubEurekaProtokoll::start(3, 2, 8), 'AA15B8000000000000022202000200080300020000FE'],
    'Pause' => [fn() => RoboHubEurekaProtokoll::pause(), 'AA0DB800000000000002221B00FC'],
    'Resume' => [fn() => RoboHubEurekaProtokoll::fortsetzen(), 'AA0DB800000000000002221C00FB'],
    'Station' => [fn() => RoboHubEurekaProtokoll::station(), 'AA0DB80000000000000222010016'],
] as $name => [$bauen, $golden]) {
    $tests['Exakter Rahmen: ' . $name] = static function () use ($bauen, $golden): void {
        commandAssert($bauen() === $golden);
        RoboHubEurekaProtokoll::pruefeRahmen($golden);
        $bytes = array_values(unpack('C*', hex2bin($golden)));
        commandAssert(count($bytes) === $bytes[1] + 1 && (array_sum(array_slice($bytes, 1)) % 256) === 0);
    };
}
foreach (['AB0DB800000000000002221B00FC', 'AA0CB800000000000002221B00FC', 'AA0DB900000000000002221B00FC',
    'AA0DB800010000000002221B00FC', 'AA0DB800000000000004221B00FC', 'AA0DB800000000000002221B00FB',
    'AA0DB800000000000002221B00', '', 'AA', 'not hex', 'AA0DB800000000000002221B00FC00'] as $i => $hex) {
    $tests['Ungültiger Rahmen ' . $i] = static fn() => commandError(fn() => RoboHubEurekaProtokoll::pruefeRahmen($hex));
}
foreach ([[[], 2], [[256], 2], [[-1], 2], [['2'], 2], [[true], 2], [[1.0], 2], [[1 => 2], 2], [[1], 4], [array_fill(0, 246, 0), 2]] as $i => [$body, $type]) {
    $tests['Ungültige Rahmenparameter ' . $i] = static fn() => commandError(fn() => RoboHubEurekaProtokoll::rahmen($body, $type));
}
$tests['Maximale Länge und Query-Nachrichtentyp'] = static function (): void {
    $hex = RoboHubEurekaProtokoll::rahmen(array_fill(0, 245, 255), 3);
    commandAssert(strlen($hex) === 512 && substr($hex, 2, 2) === 'FF' && substr($hex, 18, 2) === '03');
    RoboHubEurekaProtokoll::pruefeRahmen($hex);
};
foreach ([[-1, 0, 8], [6, 0, 8], [2, -1, 8], [2, 4, 8], [2, 0, 10], [2, 0, 0]] as $i => $params) {
    $tests['Ungültige Reinigungsparameter ' . $i] = static fn() => commandError(fn() => RoboHubEurekaProtokoll::start(...$params));
}
foreach (['productModel', 'sn8', 'protoVersion', 'platform'] as $field) {
    $tests['Fehlende Identifikation ' . $field] = static function () use ($field): void {
        $d = commandDevice(); unset($d['roh']['product'][$field]);
        commandError(fn() => commandPlan($d));
    };
    foreach ([null, '', [], true, 1.0] as $i => $value) {
        $tests['Falscher Identifikationstyp ' . $field . ' ' . $i] = static function () use ($field, $value): void {
            $d = commandDevice(); $d['roh']['product'][$field] = $value;
            commandError(fn() => commandPlan($d));
        };
    }
    $tests['Widersprüchliche Identifikation ' . $field] = static function () use ($field): void {
        $d = commandDevice(); $d['roh'][$field] = $field === 'protoVersion' ? 1 : 'different';
        commandError(fn() => commandPlan($d));
    };
}
foreach ([null, '', '../device', 'device?x=1', "device\n", 123, [], 'other-device'] as $i => $id) {
    $tests['Falsche Geräte-ID ' . $i] = static function () use ($id): void {
        $d = commandDevice(); $d['id'] = $id; commandError(fn() => commandPlan($d));
    };
}
$tests['Fehlendes Steuerplugin'] = static function (): void {
    $d = commandDevice(); unset($d['roh']['panel']); commandError(fn() => commandPlan($d));
};
$tests['Fehlende ursprüngliche Geräte-ID'] = static function (): void {
    $d = commandDevice(); unset($d['roh']['deviceId']); commandError(fn() => commandPlan($d));
};
foreach (['deviceId', 'product'] as $field) {
    $tests['Ungültiger Rohidentifikationstyp ' . $field] = static function () use ($field): void {
        $d = commandDevice(); $d['roh'][$field] = ['invalid']; commandError(fn() => commandPlan($d));
    };
}
foreach ([null, [], 'plugin', ['name' => 'T0xB8_610VR510'], ['name' => '', 'versionName' => '2.2.46'],
    ['name' => 'T0xB8_610VR510', 'versionName' => []], ['name' => 'T0xB8_610VR510', 'versionName' => '9.0']] as $i => $plugin) {
    $tests['Ungültiges oder nicht belegtes Steuerplugin ' . $i] = static function () use ($plugin): void {
        $d = commandDevice(); $d['roh']['panel'] = $plugin; commandError(fn() => commandPlan($d));
    };
}
$tests['Widersprüchliche Steuerplugins'] = static function (): void {
    $d = commandDevice(); $d['roh']['product']['plugin'] = ['name' => 'other', 'versionName' => '2.2.46'];
    commandError(fn() => commandPlan($d));
};
$tests['Identifikation im Top-Level und Plugin im Product'] = static function (): void {
    $d = commandDevice(); $product = $d['roh']['product'];
    $product['protoVersion'] = '0'; $product['plugin'] = $d['roh']['panel'];
    unset($d['roh']['panel']); $d['roh']['product'] = ['plugin' => $product['plugin']];
    unset($product['plugin']); $d['roh'] += $product;
    commandAssert(commandPlan($d)['aktion'] === 'start');
};
foreach (['610VR510', '620VR520', '9A0VR870'] as $sn8) {
    $tests['Nur Referenzkandidat ' . $sn8] = static function () use ($sn8): void {
        commandAssert(RoboHubEurekaProtokoll::referenzFamilie(['sn8' => $sn8, 'protoVersion' => 0]) === 'midea-b8-v0');
        $d = commandDevice(); $d['roh']['product']['sn8'] = $sn8;
        commandError(fn() => RoboHubEurekaProtokoll::pruefeZuordnung($d, []));
    };
}
foreach ([['sn8' => 'unknown', 'protoVersion' => 0], ['sn8' => '610VR510', 'protoVersion' => 1],
    ['sn8' => '610VR510'], [], ['protoVersion' => 0]] as $i => $ident) {
    $tests['Unbekannte Protokollfamilie ' . $i] = static fn() => commandAssert(RoboHubEurekaProtokoll::referenzFamilie($ident) === null);
}
$tests['E20-Modell allein bestätigt keine Protokollfamilie'] = static function (): void {
    $d = ['id' => 'offline-e20', 'modell' => 'E20 Evo Plus', 'roh' => ['deviceId' => 'offline-e20', 'product' => ['productModel' => 'E20 Evo Plus']]];
    $ident = RoboHubEurekaProtokoll::identifikation($d);
    commandAssert($ident['sn8'] === null && $ident['protoVersion'] === null && $ident['platform'] === null && $ident['plugin'] === null);
    commandError(fn() => RoboHubEurekaProtokoll::pruefeZuordnung($d, commandMappings()));
};
$tests['E20: live bestätigte SN8 und B8-Familie und ohne Gerätefreigabe'] = static function (): void {
    $fixture = e20Fixture(); $d = $fixture['geraet'];
    $ident = RoboHubEurekaProtokoll::identifikation($d);
    commandAssert($ident['productModel'] === 'E20 Evo Plus' && $ident['sn8'] === '750Y0015'
        && $ident['platform'] === 'robozone' && $ident['plugin']['versionName'] === '2.5.4'
        && $d['roh']['product']['sn8'] === '750Y0015'
        && $d['roh']['product']['stationType'] === 'dust_station'
        && RoboHubEurekaProtokoll::referenzFamilie($ident) === RoboHubEurekaProtokoll::FAMILIE);
    foreach ([[], commandMappings()] as $m) {
        $calls = 0;
        $w = new RoboHubEurekaBefehlsweg(static function () use (&$calls): array { $calls++; return commandResponse(); }, $m);
        commandError(fn() => $w->ausfuehren(commandBundle(), $d, 'pause', [], COMMAND_NOW));
        commandAssert($calls === 0);
    }
    $t = new GuardEureka();
    foreach (['start', 'pause', 'resume', 'station'] as $cmd) {
        commandError(fn() => $t->befehl(commandBundle(), $d, $cmd, []));
    }
    commandAssert($t->calls === 0);
    $rc = new ReflectionClass(RoboHubEureka::class);
    commandAssert($rc->getConstant('BEFEHLE_FREIGEGEBEN') === false && $rc->getConstant('BEFEHLS_ZUORDNUNGEN') === []);
};
foreach ([null, 1, '0'] as $version) {
    $tests['E20: fehlende oder unbekannte Familienversion ' . var_export($version, true)] = static function () use ($version): void {
        $ident = RoboHubEurekaProtokoll::identifikation(e20Fixture()['geraet']);
        $ident['protoVersion'] = $version;
        commandAssert(RoboHubEurekaProtokoll::referenzFamilie($ident) === null);
    };
}
$tests['Bekannte SN8 überstimmt kein widersprüchliches Plugin'] = static function (): void {
    foreach (['T0xB9_610VR510.zip', 'unknown', 'T0xB8_', 'T0xB8_750Y0015.zip/other'] as $name) {
        $d = commandDevice(); $d['roh']['panel']['name'] = $name;
        $ident = RoboHubEurekaProtokoll::identifikation($d);
        commandAssert(RoboHubEurekaProtokoll::referenzFamilie($ident) === null);
        commandError(fn() => commandPlan($d));
    }
};
$tests['E20: widersprüchliche Metadaten werden abgewiesen'] = static function (): void {
    foreach (['productModel', 'sn8', 'protoVersion', 'platform'] as $field) {
        $d = e20Fixture()['geraet']; $d['roh'][$field] = $field === 'protoVersion' ? 1 : 'different';
        commandError(fn() => RoboHubEurekaProtokoll::identifikation($d));
    }
};
$tests['Explizites null in Rohdaten oder Product ist keine fehlende Metainformation'] = static function (): void {
    $d = commandDevice(); $d['roh'] = null;
    commandError(fn() => RoboHubEurekaProtokoll::identifikation($d));
    $d = commandDevice(); $d['roh']['product'] = null;
    commandError(fn() => RoboHubEurekaProtokoll::identifikation($d));
};
$tests['Unbekannte Version bleibt auch mit exakter Testzuordnung gesperrt'] = static function (): void {
    $d = commandDevice(); $d['roh']['product']['protoVersion'] = 1;
    $m = commandMappings(); $m[0]['identifikation']['protoVersion'] = 1;
    commandError(fn() => RoboHubEurekaProtokoll::pruefeZuordnung($d, $m));
};
foreach (['familie', 'beleg', 'identifikation'] as $field) {
    $tests['Keine Zuordnung ohne ' . $field] = static function () use ($field): void {
        $m = commandMappings(); unset($m[0][$field]);
        commandError(fn() => RoboHubEurekaProtokoll::pruefeZuordnung(commandDevice(), $m));
    };
}
$tests['Neuer Auftrag verwendet explizite Parameter und POST-Envelope'] = static function (): void {
    commandAssert(commandPlan(commandDevice()) === ['pfad' => '/devices/commands/offline-command-device',
        'body' => ['data' => 'AA15B800000000000002220200020008020000000001'], 'aktion' => 'start']);
};
$tests['Pause wird fortgesetzt, ohne neue Saug- oder Wasserwerte'] = static function (): void {
    $d = commandDevice('pause'); $d['stand']['roh']['parentState'] = 'cleaning';
    $p = commandPlan($d, 'start', []);
    commandAssert($p['aktion'] === 'resume' && $p['body']['data'] === 'AA0DB800000000000002221C00FB');
    commandError(fn() => commandPlan($d));
};
foreach (['stop', 'charging', 'charging_finish'] as $state) {
    $tests['Neustart aus ' . $state] = static fn() => commandAssert(commandPlan(commandDevice($state))['aktion'] === 'start');
}
$tests['Pause nur während Reinigung'] = static function (): void {
    commandAssert(commandPlan(commandDevice('cleaning'), 'pause', [])['body']['data'] === 'AA0DB800000000000002221B00FC');
    commandError(fn() => commandPlan(commandDevice('pause'), 'pause', []));
};
foreach (['cleaning', 'pause', 'stop'] as $state) {
    $tests['Station aus ' . $state] = static fn() => commandAssert(commandPlan(commandDevice($state), 'station', [])['body']['data'] === 'AA0DB80000000000000222010016');
}
foreach (['unknown', 'sleep', 'upgrading', 'charging_pause', 'wiping_pause', 'error', 'charging_back', 'positioning', 'on_station', ''] as $state) {
    foreach (['start', 'pause', 'resume', 'station'] as $cmd) {
        $tests['Keine riskante Entscheidung ' . $state . '/' . $cmd] = static fn() => commandError(fn() => commandPlan(commandDevice($state), $cmd, []));
    }
}
foreach (['stopp', 'orten', 'saugstufe', 'wasserstufe', 'raum', 'resume', 'zeitplan', 'reset'] as $cmd) {
    $tests['Gesperrter Befehl ' . $cmd] = static fn() => commandError(fn() => commandPlan(commandDevice(), $cmd, []));
}
foreach ([[], ['fan' => 2], ['fan' => 2, 'water' => 0], ['fan' => '2', 'water' => 0, 'mode' => 8],
    ['fan' => 2, 'water' => 0, 'mode' => 8, 'rooms' => [1]]] as $i => $params) {
    $tests['Keine Start-Defaults oder versteckte Zusatzparameter ' . $i] = static fn() => commandError(fn() => commandPlan(commandDevice(), 'start', $params));
}
foreach (['offline', 'disconnected', 'stale', 'future', 'missing-time', 'wrong-device', 'missing-state', 'parent', 'fault'] as $fall) {
    $tests['Ungeeigneter Zustandsstand ' . $fall] = static function () use ($fall): void {
        $d = commandDevice();
        switch ($fall) {
            case 'offline': $d['online'] = false; break;
            case 'disconnected': $d['stand']['roh']['connected'] = false; break;
            case 'stale': $d['stand']['zeit'] -= 121; break;
            case 'future': $d['stand']['zeit']++; break;
            case 'missing-time': unset($d['stand']['zeit']); break;
            case 'wrong-device': $d['stand']['deviceId'] = 'other-device'; break;
            case 'missing-state': unset($d['stand']['roh']['state']); break;
            case 'parent': $d['stand']['roh']['parentState'] = 'cleaning'; break;
            case 'fault': $d['stand']['roh']['deviceFault'] = ['fixture-secret']; break;
        }
        commandError(fn() => commandPlan($d));
    };
}
$tests['Keine redundanten Starts oder Stationsbefehle'] = static function (): void {
    commandError(fn() => commandPlan(commandDevice('cleaning')));
    commandError(fn() => commandPlan(commandDevice('charging'), 'station', []));
};
foreach (['id', 'deviceId'] as $field) {
    foreach (['other-device', [], null] as $i => $value) {
        $tests['Status mit falscher Gerätebindung ' . $field . ' ' . $i] = static function () use ($field, $value): void {
            $d = commandDevice(); $d['stand']['roh'][$field] = $value; commandError(fn() => commandPlan($d));
        };
    }
}
$tests['Widersprüchliche Erreichbarkeit in Identifikation'] = static function (): void {
    $d = commandDevice(); $d['roh']['connected'] = false; commandError(fn() => commandPlan($d));
};
foreach ([null, [], ['data' => null], ['data' => 'AA0DB800000000000002221B00FC'], 'AA0DB800000000000002221B00FC'] as $i => $data) {
    $tests['Cloud-Annahme ist keine Ausführungsbestätigung ' . $i] = static function () use ($data): void {
        $r = RoboHubEurekaBefehlsweg::antwortLesen(commandResponse($data));
        commandAssert($r['angenommen'] && !$r['ausgefuehrt']);
    };
}
foreach ([401, 429, 500, 502, 503, 504, 400, 404, 302, 0] as $status) {
    $tests['HTTP-Fehler ohne POST-Wiederholung ' . $status] = static function () use ($status): void {
        $calls = 0; $refresh = 0;
        $w = new RoboHubEurekaBefehlsweg(static function () use (&$calls, $status): array { $calls++; return commandResponse(null, $status); },
            commandMappings(), static function ($token) use (&$refresh): array { commandAssert($token === commandBundle()['access_token']); $refresh++; return []; });
        $e = commandError(fn() => $w->ausfuehren(commandBundle(), commandDevice(), 'start', ['fan' => 2, 'water' => 0, 'mode' => 8], COMMAND_NOW));
        commandAssert($calls === 1 && $refresh === ($status === 401 ? 1 : 0) && $e->warteSekunden === ($status === 429 ? 60 : 0));
        commandError(fn() => $w->ausfuehren(commandBundle(), commandDevice(), 'start', [], COMMAND_NOW));
        commandAssert($calls === 1);
    };
}
foreach ([
    ['status' => 200, 'response' => 'fixture-secret-invalid-json'],
    ['status' => 200, 'response' => json_encode(['errorCode' => 'fixture-secret', 'msg' => 'fixture-secret'])],
    ['status' => 200, 'response' => json_encode(['errorCode' => '0080005', 'msg' => 'fixture-secret'])],
    ['status' => 200, 'response' => json_encode(['errorCode' => false])],
    ['status' => 200, 'response' => json_encode(['data' => []])],
    ['status' => 204, 'response' => ''],
    commandResponse(false), commandResponse(42), commandResponse(['data' => []]),
    commandResponse('AA0DB800000000000002221B00FB'), commandResponse('fixture-secret'),
] as $i => $response) {
    $tests['Cloud- oder Antwortfehler ohne Doppelversand ' . $i] = static function () use ($response): void {
        $calls = 0;
        $w = new RoboHubEurekaBefehlsweg(static function () use (&$calls, $response): array { $calls++; return $response; }, commandMappings());
        commandError(fn() => $w->ausfuehren(commandBundle(), commandDevice('pause'), 'start', [], COMMAND_NOW));
        commandError(fn() => $w->ausfuehren(commandBundle(), commandDevice('pause'), 'start', [], COMMAND_NOW));
        commandAssert($calls === 1);
    };
}
$tests['Timeout oder Transportfehler bleibt unklar und verbraucht Versuch'] = static function (): void {
    $calls = 0;
    $w = new RoboHubEurekaBefehlsweg(static function () use (&$calls): array { $calls++; throw new RuntimeException('fixture-secret'); }, commandMappings());
    $e = commandError(fn() => $w->ausfuehren(commandBundle(), commandDevice('pause'), 'start', [], COMMAND_NOW));
    commandAssert(str_contains($e->getMessage(), 'unklar'));
    commandError(fn() => $w->ausfuehren(commandBundle(), commandDevice('pause'), 'start', [], COMMAND_NOW));
    commandAssert($calls === 1);
};
$tests['Authentifizierter POST genau einmal und angenommener Befehl ohne Ausführungsbehauptung'] = static function (): void {
    $calls = 0;
    $w = new RoboHubEurekaBefehlsweg(static function ($method, $path, $token, $body) use (&$calls): array {
        $calls++;
        commandAssert($method === 'POST' && $path === '/devices/commands/offline-command-device' &&
            $token === commandBundle()['access_token'] && $body === ['data' => 'AA0DB800000000000002221C00FB']);
        return commandResponse();
    }, commandMappings());
    commandAssert(str_contains($w->befehl(commandBundle(), commandDevice('pause'), 'start', [], COMMAND_NOW), 'nicht bestätigt'));
    commandError(fn() => $w->befehl(commandBundle(), commandDevice('pause'), 'start', [], COMMAND_NOW));
    commandAssert($calls === 1);
};
$tests['Reentranter Aufruf erzeugt keinen zweiten POST'] = static function (): void {
    $calls = 0; $w = null;
    $w = new RoboHubEurekaBefehlsweg(static function () use (&$w, &$calls): array {
        $calls++;
        commandError(fn() => $w->ausfuehren(commandBundle(), commandDevice('pause'), 'start', [], COMMAND_NOW));
        return commandResponse();
    }, commandMappings());
    $w->ausfuehren(commandBundle(), commandDevice('pause'), 'start', [], COMMAND_NOW);
    commandAssert($calls === 1);
};
foreach ([[], ['region' => 'na', 'access_token' => 'fixture-secret'], ['region' => 'eu', 'access_token' => []], ['region' => 'eu', 'access_token' => '']] as $i => $bundle) {
    $tests['Tokenvalidierung vor Transport ' . $i] = static function () use ($bundle): void {
        $w = new RoboHubEurekaBefehlsweg(static function (): array { throw new RuntimeException('Transport darf nicht erreicht werden.'); }, commandMappings());
        commandError(fn() => $w->ausfuehren($bundle, commandDevice('pause'), 'start', [], COMMAND_NOW));
    };
}
$tests['Unbestätigte Zuordnung verhindert bereits den Testtransport'] = static function (): void {
    $w = new RoboHubEurekaBefehlsweg(static function (): array { throw new RuntimeException('Transport darf nicht erreicht werden.'); }, []);
    commandError(fn() => $w->ausfuehren(commandBundle(), commandDevice('pause'), 'start', [], COMMAND_NOW));
};
$tests['Fehlgeschlagener Refresh wiederholt keinen POST und gibt keine Tokens aus'] = static function (): void {
    $calls = 0;
    $w = new RoboHubEurekaBefehlsweg(static function () use (&$calls): array { $calls++; return commandResponse(null, 401); }, commandMappings(),
        static function (): array { throw new RuntimeException('fixture-secret'); });
    commandError(fn() => $w->ausfuehren(commandBundle(), commandDevice('pause'), 'start', [], COMMAND_NOW));
    commandAssert($calls === 1);
};
$tests['Produktiver Adapter blockiert alle Befehle vor jedem Transport'] = static function (): void {
    $t = new GuardEureka();
    foreach (['start', 'pause', 'station', 'stopp', 'orten', 'saugstufe', 'wasserstufe', 'raum'] as $cmd) {
        commandError(fn() => $t->befehl(commandBundle(), commandDevice('pause'), $cmd, ['freigabe' => true]));
    }
    commandAssert($t->calls === 0 && (new ReflectionMethod(RoboHubEureka::class, 'befehl'))->isFinal());
};
$tests['Zweite Sperre am echten Transport vor cURL, unabhängig vom Befehlseinstieg'] = static function (): void {
    $t = new RoboHubEureka();
    $transport = new ReflectionMethod(RoboHubEureka::class, 'transport');
    foreach (['/devices/commands/offline-device', '/devices/commands', '/devices/commands/offline-device?x=1'] as $path) {
        commandError(fn() => $transport->invoke($t, 'POST', $path, 'fixture-secret', ['data' => 'fixture-secret']));
    }
};

$tests['Zwei unabhängige Anforderungen: gleiches Gerät blockiert, anderes Gerät unabhängig'] = static function (): void {
    $calls = 0;
    $transport = static function () use (&$calls): array {
        $calls++;
        Fiber::suspend('POST');
        return commandResponse();
    };
    $w1 = new RoboHubEurekaBefehlsweg($transport, commandMappings());
    $w2 = new RoboHubEurekaBefehlsweg($transport, commandMappings());
    $w3 = new RoboHubEurekaBefehlsweg($transport, commandMappings());
    $d = commandDevice('pause'); $other = $d;
    $other['id'] = $other['roh']['deviceId'] = $other['stand']['deviceId'] = 'offline-other-device';
    $f1 = new Fiber(fn() => $w1->ausfuehren(commandBundle(), $d, 'start', [], COMMAND_NOW));
    $f2 = new Fiber(fn() => commandError(fn() => $w2->ausfuehren(commandBundle(), $d, 'start', [], COMMAND_NOW)));
    $f3 = new Fiber(fn() => $w3->ausfuehren(commandBundle(), $other, 'start', [], COMMAND_NOW));
    commandAssert($f1->start() === 'POST');
    $f2->start();
    commandAssert($f2->isTerminated() && $calls === 1);
    commandAssert($f3->start() === 'POST' && $calls === 2 && count($GLOBALS['commandLocks']) === 2);
    foreach (array_keys($GLOBALS['commandLocks']) as $key) {
        commandAssert(preg_match('/^RoboHubEureka_Geraet_[a-f0-9]{64}$/D', $key) === 1);
    }
    $f1->resume(); $f3->resume();
    commandAssert($GLOBALS['commandLocks'] === []);
};
$tests['Sperrtimeout sendet nichts und gibt keine fremde Sperre frei'] = static function (): void {
    $GLOBALS['commandLockTimeout'] = true;
    try {
        $w = new RoboHubEurekaBefehlsweg(static function (): array { throw new RuntimeException('Unzulässiger Transport'); }, commandMappings());
        commandError(fn() => $w->ausfuehren(commandBundle(), commandDevice(), 'start', [], COMMAND_NOW));
        commandAssert($GLOBALS['commandLocks'] === []);
    } finally { $GLOBALS['commandLockTimeout'] = false; }
};
foreach (['timeout', 'exception', 'http', 'auth', 'parser', 'context'] as $case) {
    $tests['Sperrfreigabe ohne POST-Retry: ' . $case] = static function () use ($case): void {
        $calls = 0;
        $w = new RoboHubEurekaBefehlsweg(static function () use (&$calls, $case): array {
            $calls++;
            return match ($case) {
                'timeout' => ['status' => 200, 'response' => '{"errorCode":"0080005"}'],
                'exception' => throw new RuntimeException('fixture-secret'),
                'http' => commandResponse(null, 500),
                'auth' => commandResponse(null, 401),
                default => ['status' => 200, 'response' => 'invalid'],
            };
        }, commandMappings(), static function (): void { throw new RuntimeException('fixture-secret'); },
        $case === 'context' ? static function (): array { throw new RoboHubFehler('Kontext geändert'); } : null);
        commandError(fn() => $w->ausfuehren(commandBundle(), commandDevice('pause'), 'start', [], COMMAND_NOW));
        commandAssert($calls === ($case === 'context' ? 0 : 1) && $GLOBALS['commandLocks'] === []);
        if ($case !== 'context') {
            commandError(fn() => $w->ausfuehren(commandBundle(), commandDevice('pause'), 'start', [], COMMAND_NOW));
            commandAssert($calls === 1);
        }
        // Unabhängiger, expliziter späterer Versuch kann die freigegebene Sperre erwerben.
        $next = new RoboHubEurekaBefehlsweg(static fn() => commandResponse(), commandMappings());
        commandAssert($next->ausfuehren(commandBundle(), commandDevice('pause'), 'start', [], COMMAND_NOW)['angenommen']);
    };
}
foreach (['binding', 'state', 'metadata', 'age', 'token'] as $case) {
    $tests['Nach Sperrerwerb erneut prüfen: ' . $case] = static function () use ($case): void {
        $d = commandDevice('pause'); $bundle = commandBundle(); $calls = 0;
        $GLOBALS['commandAfterLock'] = static function () use (&$d, &$bundle, $case): void {
            match ($case) {
                'binding' => $d['id'] = 'offline-other-device',
                'state' => $d['stand']['roh']['state'] = 'unknown',
                'metadata' => $d['roh']['product']['sn8'] = 'unknown',
                'age' => $d['stand']['zeit'] -= 121,
                'token' => $bundle['region'] = 'na',
            };
        };
        $w = new RoboHubEurekaBefehlsweg(static function () use (&$calls): array { $calls++; return commandResponse(); }, commandMappings(), null,
            static function () use (&$d, &$bundle): array {
                commandAssert(count($GLOBALS['commandLocks']) === 1);
                return ['geraet' => $d, 'buendel' => $bundle, 'jetzt' => COMMAND_NOW];
            });
        commandError(fn() => $w->ausfuehren(commandBundle(), commandDevice('pause'), 'start', [], COMMAND_NOW));
        commandAssert($calls === 0 && $GLOBALS['commandLocks'] === []);
    };
}
$tests['E20: fehlende SN8 bleibt auch bei künstlicher Zuordnung gesperrt'] = static function (): void {
    $d = e20Fixture()['geraet']; unset($d['roh']['product']['sn8']);
    $ident = RoboHubEurekaProtokoll::identifikation($d);
    commandError(fn() => RoboHubEurekaProtokoll::pruefeZuordnung($d, [
        ['identifikation' => $ident, 'familie' => RoboHubEurekaProtokoll::FAMILIE, 'beleg' => 'Offline-Test'],
    ]));
};

$tests['E20: Plugin-SN8-Widerspruch bleibt auch bei exakter Testzuordnung gesperrt'] = static function (): void {
    $d = e20Fixture()['geraet']; $d['roh']['product']['sn8'] = '610VR510';
    $ident = RoboHubEurekaProtokoll::identifikation($d);
    commandAssert(RoboHubEurekaProtokoll::referenzFamilie($ident) === null);
    commandError(fn() => RoboHubEurekaProtokoll::pruefeZuordnung($d, [
        ['identifikation' => $ident, 'familie' => RoboHubEurekaProtokoll::FAMILIE, 'beleg' => 'Offline-Test'],
    ]));
};
$tests['E20: verschachtelte Plugin- und Panelwerte müssen übereinstimmen'] = static function (): void {
    foreach (['name', 'versionName'] as $field) {
        $d = e20Fixture()['geraet']; $d['roh']['panel'][$field] = 'unknown';
        commandError(fn() => RoboHubEurekaProtokoll::identifikation($d));
    }
    foreach (['productModel', 'sn8', 'protoVersion', 'platform'] as $field) {
        $d = e20Fixture()['geraet']; unset($d['roh']['product'][$field]);
        commandError(fn() => RoboHubEurekaProtokoll::pruefeZuordnung($d, []));
    }
};

$errors = 0;
// Warnungen (etwa implizite Typumwandlungen) sind ebenfalls Testfehler.
set_error_handler(static function (int $severity, string $message): never { throw new RuntimeException('PHP-Warnung im Offline-Test.'); });
foreach ($tests as $name => $test) {
    try { $test(); echo 'OK: ' . $name . "\n"; }
    catch (Throwable $e) { $errors++; echo 'FEHLER: ' . $name . "\n"; }
}
restore_error_handler();
echo count($tests) . ' Tests, ' . $errors . " Fehler\n";
exit($errors === 0 ? 0 : 1);
