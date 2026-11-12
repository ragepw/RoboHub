<?php
declare(strict_types=1);

// Ausschließlich Offline-Fixtures. Der Transport wird vollständig ersetzt.
require_once __DIR__ . '/../../libs/RoboHubEureka.php';
require_once __DIR__ . '/command-fixtures.php';

const KL_MESSAGE = 10201;
const KL_WARNING = 10204;
$GLOBALS['sperre'] = true;
$GLOBALS['gesperrt'] = false;
$GLOBALS['locks'] = [];
function IPS_SemaphoreEnter(string $name, int $timeout): bool
{
    if (str_starts_with($name, 'RoboHubEureka_Geraet_') && isset($GLOBALS['locks'][$name])) { return false; }
    if (!$GLOBALS['sperre'] || ($GLOBALS['timeoutName'] ?? '') === $name) { return false; }
    while (isset($GLOBALS['locks'][$name])) {
        pruefe(Fiber::getCurrent() !== null);
        Fiber::suspend('wartet');
    }
    $GLOBALS['locks'][$name] = true;
    $GLOBALS['gesperrt'] = isset($GLOBALS['locks']['RoboHubKonto_123']);
    if ($name === 'RoboHubKonto_123' && isset($GLOBALS['nachSperre'])) {
        $aktion = $GLOBALS['nachSperre'];
        unset($GLOBALS['nachSperre']);
        $aktion();
    }
    return true;
}
function IPS_SemaphoreLeave(string $name): void
{
    pruefe(isset($GLOBALS['locks'][$name]));
    unset($GLOBALS['locks'][$name]);
    $GLOBALS['gesperrt'] = isset($GLOBALS['locks']['RoboHubKonto_123']);
}
function IPS_SetProperty(int $id, string $name, $value): void {}
function IPS_ApplyChanges(int $id): void {}
function IPS_GetKernelStartTime(): int { return 1799990000; }
function IPS_GetInstance(int $id): array { return ['ConnectionID' => 0]; }
class IPSModule
{
    public int $InstanceID;
    public array $attrs = [];
    public array $logs = [];
    public array $buffers = [];
    public function GetBuffer(string $n): string { return $this->buffers[$n] ?? ''; }
    public function SetBuffer(string $n, string $v): void { $this->buffers[$n] = $v; }
    public function __construct(int $id) { $this->InstanceID = $id; }
    public function ReadAttributeString(string $name): string { return (string)($this->attrs[$name] ?? ''); }
    public function WriteAttributeString(string $name, string $value): void { $this->attrs[$name] = $value; }
    public function ReadAttributeInteger(string $name): int { return (int)($this->attrs[$name] ?? 0); }
    public function WriteAttributeInteger(string $name, int $value): void { $this->attrs[$name] = $value; }
    public function ReadPropertyString(string $name): string { return ['Hersteller' => 'eureka', 'Konto' => 'offline@example.invalid', 'Passwort' => 'offline-password', 'Region' => 'eu'][$name] ?? ''; }
    public function ReadPropertyBoolean(string $name): bool { return false; }
    public function Translate(string $text): string { return $text; }
    public function SetTimerInterval(string $name, int $value): void {}
    public function SetStatus(int $status): void {}
    public function UpdateFormField(string $name, string $field, $value): void {}
    public function SendDebug(string $name, string $text, int $format): void { $this->logs[] = $text; }
    public function LogMessage(string $text, int $level): void { $this->logs[] = $text; }
}
require_once __DIR__ . '/../../RoboHubKonto/module.php';
class TestKonto extends RoboHubKonto
{
    public function neuerTreiber(): RoboHubTreiber { return $this->treiber(); }
}
function pruefe(bool $ok): void
{
    if (!$ok) {
        throw new RuntimeException('Offline-Prüfung fehlgeschlagen.');
    }
}
function testJwt(int $ablauf): string
{
    $encode = static fn(array $data): string => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');
    return $encode(['alg' => 'none', 'typ' => 'JWT']) . '.' . $encode(['exp' => $ablauf]) . '.fixture';
}
class OfflineEureka extends RoboHubEureka
{
    public array $antworten = [];
    public int $aufrufe = 0;
    protected function jetzt(): int { return 1800000000; }
    public function simulierterTransport(string $method, string $path, string $token = '', ?array $body = null): array
    {
        return $this->transport($method, $path, $token, $body);
    }
    protected function transport(string $method, string $path, string $token = '', ?array $body = null): array
    {
        $this->aufrufe++;
        pruefe($this->antworten !== []);
        $antwort = array_shift($this->antworten);
        if (is_callable($antwort)) {
            $antwort = $antwort($method, $path, $token, $body);
        }
        if ($antwort instanceof Throwable) {
            throw $antwort;
        }
        return $antwort;
    }
}
function antwort($data, int $status = 200): array
{
    return ['status' => $status, 'response' => json_encode(['errorCode' => 0, 'data' => $data])];
}
function buendel(int $rest = 3600): array
{
    return ['region' => 'eu', 'access_token' => testJwt(1800000000 + $rest), 'refresh_token' => 'fixture-refresh /+?&'];
}
function neu(): array { return ['accessToken' => testJwt(1800007200), 'refreshToken' => 'fixture-rotation']; }
function fehler(callable $aktion, bool $anmeldung = false): RoboHubFehler
{
    try { $aktion(); } catch (RoboHubFehler $e) {
        pruefe($e->anmeldungNoetig === $anmeldung);
        pruefe(!str_contains($e->getMessage(), 'fixture'));
        return $e;
    }
    throw new RuntimeException('Erwarteter Fehler fehlt.');
}
function konto(OfflineEureka $treiber, array $b): TestKonto
{
    $GLOBALS['sperre'] = true;
    $GLOBALS['robohubTreiberFabrik'] = static fn(string $hersteller) => $treiber;
    $konto = new TestKonto(123);
    $konto->attrs['Buendel'] = json_encode($b);
    return $konto;
}
function abruf(TestKonto $konto, string $aktion = 'geraete'): array
{
    return json_decode($konto->ForwardData(json_encode(['Aktion' => $aktion, 'Neu' => true, 'Geraet' => 'offline-device'])), true);
}
$tests = [];
foreach ([3600, 301, 300] as $rest) {
    $tests['Gültigkeit ' . $rest . ' Sekunden ohne Refresh'] = static function () use ($rest): void {
        $t = new OfflineEureka();
        pruefe($t->erneuern(buendel($rest)) === buendel($rest) && $t->aufrufe === 0);
    };
}
foreach ([299, 240, 0, -60] as $rest) {
    $tests['Refresh bei Restgültigkeit ' . $rest] = static function () use ($rest): void {
        $t = new OfflineEureka();
        $t->antworten[] = static function ($method, $path, $token, $body): array {
            pruefe($method === 'GET' && $token === '' && $body === null);
            pruefe($path === '/auth/refresh-token?refreshToken=' . rawurlencode(buendel()['refresh_token']));
            return antwort(neu());
        };
        $b = $t->erneuern(buendel($rest));
        pruefe($b['access_token'] === neu()['accessToken'] && $b['refresh_token'] === neu()['refreshToken']);
        pruefe($b['region'] === 'eu' && $t->aufrufe === 1);
    };
}
$tests['Refresh ohne Rotation behält Refresh-Token'] = static function (): void {
    $t = new OfflineEureka();
    $t->antworten[] = antwort(['accessToken' => neu()['accessToken']]);
    pruefe($t->erneuern(buendel(20))['refresh_token'] === buendel()['refresh_token']);
};
foreach (['geraete', 'zustand'] as $aktion) {
    $tests['401: zentral speichern und einmal wiederholen (' . $aktion . ')'] = static function () use ($aktion): void {
        $t = new OfflineEureka();
        $k = konto($t, buendel());
        $k->attrs['Geraete'] = json_encode([['id' => 'offline-device', 'modell' => 'E20 Evo Plus']]);
        $t->antworten = [antwort([], 401), static function () use ($k): array {
            pruefe($GLOBALS['gesperrt'] && json_decode($k->attrs['Buendel'], true) === buendel());
            return antwort(neu());
        }, static function ($method, $path, $token) use ($k, $aktion): array {
            $gespeichert = json_decode($k->attrs['Buendel'], true);
            pruefe(!$GLOBALS['gesperrt'] && $token === $gespeichert['access_token']);
            pruefe($gespeichert['refresh_token'] === neu()['refreshToken']);
            return antwort($aktion === 'geraete' ? [] : [['state' => 'cleaning']]);
        }];
        pruefe(abruf($k, $aktion)['ok'] && $t->aufrufe === 3);
        // Neuer Treiberaufruf verwendet ausschließlich das persistierte Bündel.
        $t2 = new OfflineEureka();
        $GLOBALS['robohubTreiberFabrik'] = static fn(string $h) => $t2;
        $t2->antworten[] = static function ($method, $path, $token): array {
            pruefe($token === neu()['accessToken']);
            return antwort([]);
        };
        pruefe(abruf($k)['ok'] && $t2->aufrufe === 1);
    };
}
$tests['Zentrale proaktive Erneuerung speichert Rotation'] = static function (): void {
    $t = new OfflineEureka(); $k = konto($t, buendel(240));
    $t->antworten = [antwort(neu()), antwort([])];
    pruefe(abruf($k)['ok']);
    pruefe(json_decode($k->attrs['Buendel'], true)['refresh_token'] === neu()['refreshToken']);
};
foreach ([401, 429, 500, 503, 'netz', 'json', 'daten', 'cloud', 'access', 'refresh', 'jwt', 'abgelaufen'] as $fall) {
    $tests['Refresh-Fehler behält Bündel außer bestätigtem 401: ' . $fall] = static function () use ($fall): void {
        $t = new OfflineEureka(); $k = konto($t, buendel()); $alt = $k->attrs['Buendel'];
        $fixture = match ($fall) {
            'netz' => new RoboHubFehler('Eureka: Cloud-Verbindung fehlgeschlagen.'),
            'json' => ['status' => 200, 'response' => 'fixture-invalid-json'],
            'daten' => antwort('fixture-invalid-data'),
            'cloud' => ['status' => 200, 'response' => json_encode(['errorCode' => 'fixture-secret', 'message' => 'fixture-secret'])],
            'access' => antwort(['accessToken' => []]),
            'refresh' => antwort(['accessToken' => neu()['accessToken'], 'refreshToken' => '']),
            'jwt' => antwort(['accessToken' => 'fixture-invalid-jwt']),
            'abgelaufen' => antwort(['accessToken' => testJwt(1799999999)]),
            default => antwort([], $fall),
        };
        $t->antworten = [antwort([], 401), $fixture];
        $result = abruf($k);
        pruefe(!$result['ok'] && $result['anmeldungNoetig'] === ($fall === 401));
        pruefe($k->attrs['Buendel'] === ($fall === 401 ? '' : $alt) && $t->aufrufe === 2);
        pruefe(!str_contains(json_encode([$result, $k->logs]), 'fixture'));
        if ($fall === 429) { pruefe($k->attrs['WarteBis'] >= time() + 59); }
    };
}
foreach ([401, 503] as $status) {
    $tests['Fehler nach gespeichertem Refresh: ' . $status] = static function () use ($status): void {
        $t = new OfflineEureka(); $k = konto($t, buendel());
        $t->antworten = [antwort([], 401), antwort(neu()), antwort([], $status)];
        $r = abruf($k);
        pruefe(!$r['ok'] && !$r['anmeldungNoetig'] && $t->aufrufe === 3);
        pruefe(json_decode($k->attrs['Buendel'], true)['refresh_token'] === neu()['refreshToken']);
    };
}
$tests['Paralleler Aufruf übernimmt bereits gespeicherte Rotation'] = static function (): void {
    $t = new OfflineEureka(); $k = konto($t, buendel());
    $t->antworten = [antwort([], 401), static function ($method, $path, $token): array {
        pruefe($token === neu()['accessToken'] && !str_starts_with($path, '/auth/'));
        return antwort([]);
    }];
    $GLOBALS['nachSperre'] = static function () use ($k): void {
        $b = buendel(); $b['access_token'] = neu()['accessToken']; $b['refresh_token'] = neu()['refreshToken'];
        $k->attrs['Buendel'] = json_encode($b);
    };
    $k->neuerTreiber()->geraete(buendel());
    pruefe($t->aufrufe === 2);
};
$tests['Keine Erneuerung ohne Kontosperre'] = static function (): void {
    $t = new OfflineEureka(); $k = konto($t, buendel(240)); $alt = $k->attrs['Buendel'];
    $GLOBALS['sperre'] = false;
    $r = abruf($k);
    pruefe(!$r['ok'] && !$r['anmeldungNoetig'] && $t->aufrufe === 0 && $k->attrs['Buendel'] === $alt);
    $GLOBALS['sperre'] = true;
};
$tests['401 ohne Kontorückruf verwirft keine Tokens'] = static function (): void {
    $t = new OfflineEureka(); $t->antworten = [antwort([], 401)];
    fehler(static fn() => $t->geraete(buendel()));
    pruefe($t->aufrufe === 1);
};
$tests['Fehlender Access-Token lässt sich mit Refresh-Token wiederherstellen'] = static function (): void {
    $t = new OfflineEureka(); $b = buendel(); unset($b['access_token']);
    $t->antworten = [antwort(neu())];
    pruefe($t->erneuern($b)['access_token'] === neu()['accessToken']);
};
$tests['Abmeldung während des Wartens wird nicht rückgängig gemacht'] = static function (): void {
    $t = new OfflineEureka(); $k = konto($t, buendel(240));
    $GLOBALS['nachSperre'] = static function () use ($k): void { $k->attrs['Buendel'] = ''; };
    pruefe(!abruf($k)['ok'] && $k->attrs['Buendel'] === '' && $t->aufrufe === 0);
};
foreach ([429, 503, 'json'] as $fall) {
    $tests['Datenabruf ohne Auth-Fehler löst keinen Refresh aus: ' . $fall] = static function () use ($fall): void {
        $t = new OfflineEureka(); $k = konto($t, buendel()); $alt = $k->attrs['Buendel'];
        $t->antworten = [$fall === 'json' ? ['status' => 200, 'response' => 'fixture-invalid'] : antwort([], $fall)];
        $r = abruf($k);
        pruefe(!$r['ok'] && !$r['anmeldungNoetig'] && $t->aufrufe === 1 && $k->attrs['Buendel'] === $alt);
    };
}
foreach ([401, 429, 503, 'json'] as $fall) {
    $tests['Proaktiver Refresh-Fehler im Konto: ' . $fall] = static function () use ($fall): void {
        $t = new OfflineEureka(); $k = konto($t, buendel(240)); $alt = $k->attrs['Buendel'];
        $t->antworten = [$fall === 'json' ? ['status' => 200, 'response' => 'fixture-invalid'] : antwort([], $fall)];
        $r = abruf($k);
        pruefe(!$r['ok'] && $r['anmeldungNoetig'] === ($fall === 401) && $t->aufrufe === 1);
        pruefe($k->attrs['Buendel'] === ($fall === 401 ? '' : $alt));
    };
}
$tests['Fehlende und unlesbare Tokens kontrolliert behandeln'] = static function (): void {
    $t = new OfflineEureka();
    fehler(static fn() => $t->erneuern(['access_token' => 'fixture-invalid', 'refresh_token' => 'fixture']));
    fehler(static fn() => $t->erneuern(['access_token' => testJwt(1800000240)]));
    fehler(static fn() => $t->erneuern([]), true);
    pruefe($t->aufrufe === 0);
};
// Deterministische Interleavings: Transport hält den Refresh an, echte Kontomethoden schreiben.
foreach (['abmelden', 'anmelden', 'identisch'] as $wechsel) {
    foreach ([200, 401, 503] as $status) {
        $tests['Laufender Refresh / ' . $wechsel . ' / Antwort ' . $status] = static function () use ($wechsel, $status): void {
            $t = new OfflineEureka(); $k = konto($t, buendel(240));
            $t->antworten = [static function () use ($status): array {
                Fiber::suspend('cloud');
                return antwort(neu(), $status);
            }];
            $f = new Fiber(static fn() => abruf($k));
            pruefe($f->start() === 'cloud');
            if ($wechsel === 'abmelden') {
                $k->Abmelden();
                pruefe($k->attrs['Buendel'] === '');
            } else {
                $login = $wechsel === 'identisch'
                    ? ['accessToken' => buendel(240)['access_token'], 'refreshToken' => buendel()['refresh_token']]
                    : ['accessToken' => testJwt(1800010000), 'refreshToken' => 'offline-new-session'];
                $t->antworten = [antwort($login), antwort([])];
                pruefe(str_contains($k->Anmelden(), '✅'));
            }
            $stand = $k->attrs['Buendel']; $revision = $k->attrs['BuendelRevision'];
            $f->resume();
            pruefe(!$f->getReturn()['ok']);
            pruefe($k->attrs['Buendel'] === $stand && $k->attrs['BuendelRevision'] === $revision);
            pruefe($GLOBALS['locks'] === []);
        };
    }
}
foreach ([false, true] as $mit401) {
    $tests['Zwei parallele Refreshs mit Rotation / 401=' . (int)$mit401] = static function () use ($mit401): void {
        $t = new OfflineEureka(); $k = konto($t, buendel($mit401 ? 3600 : 240));
        $t->antworten = $mit401 ? [antwort([], 401)] : [];
        $t->antworten[] = static function (): array { Fiber::suspend('cloud'); return antwort(neu()); };
        if ($mit401) { $t->antworten[] = antwort([], 401); }
        $t->antworten[] = static function ($method, $path, $token): array {
            pruefe($token === neu()['accessToken']); return antwort([]);
        };
        $t->antworten[] = static function ($method, $path, $token): array {
            pruefe($token === neu()['accessToken']); return antwort([]);
        };
        $f = new Fiber(static fn() => abruf($k));
        pruefe($f->start() === 'cloud');
        // Für 401 startet der zweite Datenabruf gezielt mit dem alten Bündel.
        $g = new Fiber(static fn() => $mit401 ? $k->neuerTreiber()->geraete(buendel()) : abruf($k));
        pruefe($g->start() === 'wartet');
        $f->resume(); $g->resume();
        pruefe($f->getReturn()['ok']);
        pruefe($mit401 ? $g->getReturn() === [] : $g->getReturn()['ok']);
        pruefe($t->aufrufe === ($mit401 ? 5 : 3));
        pruefe(json_decode($k->attrs['Buendel'], true)['refresh_token'] === neu()['refreshToken']);
        pruefe($GLOBALS['locks'] === []);
    };
}
foreach (['abmelden', 'anmelden', 'refresh', 'commit'] as $pfad) {
    $tests['Attribut-Semaphore-Timeout / ' . $pfad] = static function () use ($pfad): void {
        $t = new OfflineEureka(); $k = konto($t, buendel(240)); $alt = $k->attrs['Buendel'];
        $name = 'RoboHubKonto_Buendel_123';
        if ($pfad === 'commit') {
            $t->antworten[] = static function () use ($name): array {
                $GLOBALS['timeoutName'] = $name; return antwort(neu());
            };
        } else { $GLOBALS['timeoutName'] = $name; }
        try {
            if ($pfad === 'abmelden') { pruefe(!str_contains($k->Abmelden(), '⏏️')); }
            elseif ($pfad === 'anmelden') { pruefe(!str_contains($k->Anmelden(), '✅')); }
            else { pruefe(!abruf($k)['ok']); }
            pruefe($k->attrs['Buendel'] === $alt && $GLOBALS['locks'] === []);
            pruefe($t->aufrufe === ($pfad === 'commit' ? 1 : 0));
        } finally { unset($GLOBALS['timeoutName']); }
    };
}
$tests['Laufende Anmeldung nach Abmeldung verwerfen'] = static function (): void {
    $t = new OfflineEureka(); $k = konto($t, buendel());
    $t->antworten[] = static function (): array { Fiber::suspend('login'); return antwort(neu()); };
    $f = new Fiber(static fn() => $k->Anmelden());
    pruefe($f->start() === 'login');
    $k->Abmelden(); $revision = $k->attrs['BuendelRevision'];
    $f->resume();
    pruefe(!str_contains($f->getReturn(), '✅') && $k->attrs['Buendel'] === '');
    pruefe($k->attrs['BuendelRevision'] === $revision && $GLOBALS['locks'] === []);
};
$tests['Auth-Fehler darf bei Sperr-Timeout keine Tokens löschen'] = static function (): void {
    $t = new OfflineEureka(); $k = konto($t, buendel(240)); $alt = $k->attrs['Buendel'];
    $t->antworten[] = static function (): array {
        $GLOBALS['timeoutName'] = 'RoboHubKonto_Buendel_123'; return antwort([], 401);
    };
    try { pruefe(!abruf($k)['ok'] && $k->attrs['Buendel'] === $alt && $GLOBALS['locks'] === []); }
    finally { unset($GLOBALS['timeoutName']); }
};
$tests['Befehlsweg: zentrale proaktive Rotation vor genau einem POST persistiert'] = static function (): void {
    $t = new OfflineEureka(); $k = konto($t, buendel(240));
    $t->antworten = [antwort(neu()), static function ($method, $path, $token, $body) use ($k): array {
        $gespeichert = json_decode($k->attrs['Buendel'], true);
        pruefe($method === 'POST' && $path === '/devices/commands/offline-command-device');
        pruefe($token === $gespeichert['access_token'] && $gespeichert['refresh_token'] === neu()['refreshToken']);
        pruefe($body === ['data' => 'AA0DB800000000000002221C00FB'] && !$GLOBALS['gesperrt']);
        return antwort(null);
    }];
    $vorbereiten = new ReflectionMethod(RoboHubKonto::class, 'gueltigesBuendel');
    $b = $vorbereiten->invoke($k);
    $w = new RoboHubEurekaBefehlsweg([$t, 'simulierterTransport'], commandMappings(), $k->neuerTreiber()->beiUnauthorized);
    $r = $w->ausfuehren($b, commandDevice('pause'), 'start', [], COMMAND_NOW);
    pruefe($r['angenommen'] && !$r['ausgefuehrt'] && $t->aufrufe === 2 && $GLOBALS['locks'] === []);
};
$tests['Befehlsweg: 401 rotiert zentral, wiederholt POST nicht; nächster expliziter Versuch nutzt Rotation'] = static function (): void {
    $t = new OfflineEureka(); $k = konto($t, buendel());
    $t->antworten = [antwort(null, 401), static function ($method) use ($k): array {
        pruefe($method === 'GET' && $GLOBALS['gesperrt'] && json_decode($k->attrs['Buendel'], true) === buendel());
        return antwort(neu());
    }];
    $w = new RoboHubEurekaBefehlsweg([$t, 'simulierterTransport'], commandMappings(), $k->neuerTreiber()->beiUnauthorized);
    pruefe(fehler(fn() => $w->ausfuehren(buendel(), commandDevice('pause'), 'start', [], COMMAND_NOW))->getCode() === 401);
    $gespeichert = json_decode($k->attrs['Buendel'], true);
    pruefe($gespeichert['refresh_token'] === neu()['refreshToken'] && $t->aufrufe === 2);
    fehler(fn() => $w->ausfuehren($gespeichert, commandDevice('pause'), 'start', [], COMMAND_NOW));
    pruefe($t->aufrufe === 2);
    $t->antworten[] = static function ($method, $path, $token): array {
        pruefe($method === 'POST' && $token === neu()['accessToken'] && !$GLOBALS['gesperrt']);
        return antwort(null);
    };
    $w2 = new RoboHubEurekaBefehlsweg([$t, 'simulierterTransport'], commandMappings(), $k->neuerTreiber()->beiUnauthorized);
    pruefe($w2->ausfuehren($gespeichert, commandDevice('pause'), 'start', [], COMMAND_NOW)['angenommen']);
    pruefe($t->aufrufe === 3);
};
$tests['Befehlsweg: parallele 401 verwenden eine zentral persistierte Rotation, ohne POST-Retry'] = static function (): void {
    $t = new OfflineEureka(); $k = konto($t, buendel());
    $t->antworten = [antwort(null, 401), static function (): array { Fiber::suspend('command-refresh'); return antwort(neu()); }, antwort(null, 401)];
    $callback = $k->neuerTreiber()->beiUnauthorized;
    $w1 = new RoboHubEurekaBefehlsweg([$t, 'simulierterTransport'], commandMappings(), $callback);
    $w2 = new RoboHubEurekaBefehlsweg([$t, 'simulierterTransport'], commandMappings(), $callback);
    $f1 = new Fiber(static fn() => fehler(fn() => $w1->ausfuehren(buendel(), commandDevice('pause'), 'start', [], COMMAND_NOW)));
    $other = commandDevice('pause');
    $other['id'] = $other['roh']['deviceId'] = $other['stand']['deviceId'] = 'offline-other-device';
    $f2 = new Fiber(static fn() => fehler(fn() => $w2->ausfuehren(buendel(), $other, 'start', [], COMMAND_NOW)));
    pruefe($f1->start() === 'command-refresh' && $f2->start() === 'wartet');
    $f1->resume(); $f2->resume();
    pruefe($f1->isTerminated() && $f2->isTerminated() && $t->aufrufe === 3);
    pruefe(json_decode($k->attrs['Buendel'], true)['refresh_token'] === neu()['refreshToken'] && $GLOBALS['locks'] === []);
};
$tests['Befehlsweg: Abmeldung während Refresh verhindert erneutes Speichern und zweiten POST'] = static function (): void {
    $t = new OfflineEureka(); $k = konto($t, buendel());
    $t->antworten = [antwort(null, 401), static function (): array { Fiber::suspend('command-refresh'); return antwort(neu()); }];
    $w = new RoboHubEurekaBefehlsweg([$t, 'simulierterTransport'], commandMappings(), $k->neuerTreiber()->beiUnauthorized);
    $f = new Fiber(static fn() => fehler(fn() => $w->ausfuehren(buendel(), commandDevice('pause'), 'start', [], COMMAND_NOW)));
    pruefe($f->start() === 'command-refresh'); $k->Abmelden(); $f->resume();
    pruefe($f->isTerminated() && $k->attrs['Buendel'] === '' && $t->aufrufe === 2 && $GLOBALS['locks'] === []);
};
$tests['Befehlsweg: bestätigter Auth-Refresh-401 löscht zentral, ohne zweiten POST'] = static function (): void {
    $t = new OfflineEureka(); $k = konto($t, buendel());
    $t->antworten = [antwort(null, 401), antwort(null, 401)];
    $w = new RoboHubEurekaBefehlsweg([$t, 'simulierterTransport'], commandMappings(), $k->neuerTreiber()->beiUnauthorized);
    fehler(fn() => $w->ausfuehren(buendel(), commandDevice('pause'), 'start', [], COMMAND_NOW));
    pruefe($k->attrs['Buendel'] === '' && $t->aufrufe === 2 && $GLOBALS['locks'] === []);
};
foreach (['direkt', 'data', 'list', 'devices', 'items', 'ohne-errorCode'] as $form) {
    $tests['GET-Leseparser behält Geräteformat ' . $form] = static function () use ($form): void {
        $t = new OfflineEureka();
        $g = [e20Fixture()['geraet']['roh']];
        $result = in_array($form, ['list', 'devices', 'items'], true) ? [$form => $g] : $g;
        $payload = match ($form) {
            'direkt' => $g,
            'ohne-errorCode' => ['data' => $g],
            default => ['errorCode' => 0, 'data' => $result],
        };
        $t->antworten[] = ['status' => 200, 'response' => json_encode($payload)];
        $devices = $t->geraete(buendel());
        pruefe(count($devices) === 1 && $devices[0]['modell'] === 'E20 Evo Plus' && $t->aufrufe === 1);
    };
}
foreach (['current', 'state', 'removed', 'session', 'same-token-session', 'lock-timeout'] as $case) {
    $tests['Befehl: echter lokaler Kontokontext nach Gerätesperre / ' . $case] = static function () use ($case): void {
        $t = new OfflineEureka(); $k = konto($t, buendel());
        $d = commandDevice('pause'); $d['stand']['zeit'] = time();
        $k->attrs['Geraete'] = json_encode([$d]);
        $k->attrs['Schnappschuss'] = json_encode([$d['id'] => ['p' => $d['stand']]]);
        $callback = $k->neuerTreiber()->befehlsKontext;
        if ($case === 'state') {
            $d['stand']['roh']['state'] = 'unknown';
            $k->attrs['Schnappschuss'] = json_encode([$d['id'] => ['p' => $d['stand']]]);
        } elseif ($case === 'removed') { $k->attrs['Geraete'] = '[]'; }
        elseif ($case === 'session') { $k->attrs['Buendel'] = ''; }
        elseif ($case === 'same-token-session') { $k->attrs['BuendelRevision'] = 'new-session'; }
        elseif ($case === 'lock-timeout') { $GLOBALS['timeoutName'] = 'RoboHubKonto_Buendel_123'; }
        $calls = 0;
        $w = new RoboHubEurekaBefehlsweg(static function () use (&$calls): array { $calls++; return commandResponse(); }, commandMappings(), null, $callback);
        try {
            $run = fn() => $w->ausfuehren(buendel(), commandDevice('pause'), 'start', [], COMMAND_NOW);
            // Konto 123 ist kein freigegebenes Bedienkonto 45174.
            fehler($run);
            pruefe($calls === 0 && $t->aufrufe === 0 && $GLOBALS['locks'] === []);
        } finally { unset($GLOBALS['timeoutName']); }
    };
}
$fehler = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo 'OK: ' . $name . "\n";
    } catch (Throwable $e) {
        // Keine Exceptions/Stacktraces mit Fixture- oder Transportdaten ausgeben.
        echo 'FEHLER: ' . $name . "\n";
        $fehler++;
    }
}
echo count($tests) . ' Tests, ' . $fehler . " Fehler\n";
exit($fehler === 0 ? 0 : 1);
