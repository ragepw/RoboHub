<?php
declare(strict_types=1);

// Nur erfundene lokale Daten. Alle HTTP-Grenzen werden ersetzt.
const KR_READY = 10103;
const KL_MESSAGE = 10201;
const KL_WARNING = 10204;
$GLOBALS['locks'] = [];
$GLOBALS['boot'] = time() - 100;
$GLOBALS['ziel'] = ['ConnectionID' => 45174,
    'ModuleInfo' => ['ModuleID' => '{631DA85B-5985-4C25-B222-E47FDD49E302}']];
$GLOBALS['zielId'] = 'offline-e20';
if (!defined('ROBOHUB_DIAGNOSE_NUR_FIXTURE')) {
    function IPS_IsAdministrator() { return $GLOBALS['anzeigeAdmin'] ?? true; }
    function IPS_GetLoggedInUser() { return $GLOBALS['anzeigeBenutzer'] ?? '@admin'; }
}
function IPS_GetKernelRunlevel(): int { return $GLOBALS['runlevel'] ?? KR_READY; }
function IPS_GetKernelStartTime(): int { return $GLOBALS['boot']; }
function IPS_InstanceExists(int $id): bool { return $id === 22708 && !($GLOBALS['fehlt'] ?? false); }
function IPS_GetInstance(int $id): array { return $GLOBALS['ziel']; }
function IPS_GetProperty(int $id, string $name) { return $name === 'Deaktiviert' ? ($GLOBALS['zielDeaktiviert'] ?? false) : $GLOBALS['zielId']; }
function IPS_SemaphoreEnter(string $name, int $ms): bool
{
    if (isset($GLOBALS['locks'][$name]) || ($GLOBALS['lockFehler'] ?? '') === $name) { return false; }
    $GLOBALS['locks'][$name] = true;
    if (isset($GLOBALS['nachSperre'])) {
        $fn = $GLOBALS['nachSperre']; unset($GLOBALS['nachSperre']); $fn();
    }
    return true;
}
function IPS_SemaphoreLeave(string $name): void { unset($GLOBALS['locks'][$name]); }
class IPSModule
{
    public int $InstanceID;
    public array $attrs = [];
    public array $puffer = [];
    public array $props = ['Hersteller' => 'eureka', 'Region' => 'eu', 'Konto' => 'offline@example.invalid'];
    public array $logs = [];
    public $beiSpeichern = null;
    public bool $speichernSperren = false;
    public bool $pufferSchreibenSperren = false;
    public function __construct(int $id) { $this->InstanceID = $id; }
    public function Translate(string $s): string { return $s; }
    public function ReadPropertyString(string $n): string { return $this->props[$n] ?? ''; }
    public function ReadAttributeString(string $n): string { return (string)($this->attrs[$n] ?? ''); }
    public function WriteAttributeString(string $n, string $v): void
    {
        if (in_array($n, ['EurekaB8Diagnose', 'EurekaDockVersuch'], true) && $this->speichernSperren) { return; }
        $this->attrs[$n] = $v;
        if (in_array($n, ['EurekaB8Diagnose', 'EurekaDockVersuch'], true) && is_callable($this->beiSpeichern)) { ($this->beiSpeichern)(); }
    }
    public function ReadAttributeInteger(string $n): int { return (int)($this->attrs[$n] ?? 0); }
    public function WriteAttributeInteger(string $n, int $v): void { $this->attrs[$n] = $v; }
    public function GetBuffer(string $n): string { return $this->puffer[$n] ?? ''; }
    public function SetBuffer(string $n, string $v): void { if (!$this->pufferSchreibenSperren) { $this->puffer[$n] = $v; } }
    public function SendDebug(string $n, string $s, int $f): void { $this->logs[] = $s; }
}
require_once __DIR__ . '/../../RoboHubKonto/module.php';
require_once __DIR__ . '/e20-fixture.php';

function sicher(bool $v): void { if (!$v) { throw new RuntimeException('Offline-Prüfung fehlgeschlagen.'); } }
function gesperrt(callable $fn): void
{
    try { $fn(); } catch (RoboHubFehler $e) {
        foreach (['offline-e20', 'fixture-secret', 'Authorization', 'refreshToken', 'Bearer'] as $s) {
            sicher(!str_contains($e->getMessage(), $s));
        }
        return;
    }
    throw new RuntimeException('Erwartete Sperre fehlt.');
}
function jwt(int $exp): string
{
    return 'e30.' . rtrim(strtr(base64_encode(json_encode(['exp' => $exp])), '+/', '-_'), '=') . '.offline';
}
function frame(int $arbeit = 6, int $akku = 87, int $anzahl = 26, int $typ = 3): string
{
    $body = array_fill(0, $anzahl, 0);
    $body[0] = 0x32; $body[1] = 1;
    if ($anzahl > 2) { $body[2] = $arbeit; }
    if ($anzahl > 12) { $body[12] = $akku; }
    return RoboHubEurekaProtokoll::rahmen($body, $typ);
}
function antwort($data = null, int $status = 200, $code = 0): array
{
    return ['status' => $status, 'response' => json_encode(['errorCode' => $code, 'data' => $data])];
}
class DiagnoseHttp extends RoboHubEureka
{
    public array $calls = [];
    public $antwort = null;
    public $vorTransport = null;
    protected function httpAusfuehren(string $m, string $p, string $t, ?array $b, ?callable $diagnosePruefung = null, bool $dock = false): array
    {
        if (is_callable($this->vorTransport)) { ($this->vorTransport)(); }
        if ($diagnosePruefung !== null) { $diagnosePruefung(true); }
        $this->calls[] = [$m, $p, $t, $b];
        return is_callable($this->antwort) ? ($this->antwort)($m, $p, $t, $b) : ($this->antwort ?? antwort(frame()));
    }
}
function konto(): array
{
    sicher($GLOBALS['locks'] === []);
    $GLOBALS['boot'] = time() - 100;
    $GLOBALS['ziel']['ConnectionID'] = 45174;
    $GLOBALS['ziel']['ModuleInfo']['ModuleID'] = '{631DA85B-5985-4C25-B222-E47FDD49E302}';
    $GLOBALS['zielId'] = 'offline-e20';
    unset($GLOBALS['fehlt'], $GLOBALS['runlevel'], $GLOBALS['lockFehler']);
    $t = new DiagnoseHttp();
    $GLOBALS['robohubTreiberFabrik'] = fn(string $h) => $t;
    $k = new RoboHubKonto(45174);
    $g = e20Fixture()['geraet'];
    $g['verbindungEmpfangen'] = $g['stand']['zeit'] = time();
    // Entspricht den beobachteten Antworten: kein erfundenes Shadow-connected.
    unset($g['stand']['roh']['connected']);
    $g['stand']['online'] = false;
    $k->attrs = ['Buendel' => json_encode(['region' => 'eu', 'access_token' => jwt(time() + 3600),
        'refresh_token' => 'fixture-secret']), 'BuendelRevision' => 'offline-session',
        'Geraete' => json_encode([$g]), 'GeraeteStand' => time(), 'WarteBis' => 0,
        'Schnappschuss' => json_encode([$g['id'] => ['p' => $g['stand']]]), 'EurekaB8Diagnose' => '{}'];
    // Ausschließlich Offline-Fixture: simuliert die separat genehmigte Bereitstellung.
    $k->puffer['EurekaB8Laufzeit'] = str_repeat('a', 64);
    $k->puffer['EurekaB8Laufzeitfreigabe'] = json_encode([
        'erwartet' => $GLOBALS['boot'] . ':' . $k->puffer['EurekaB8Laufzeit'], 'zustand' => 'genehmigt',
        'cloudGeraetId' => $g['id']]);
    return [$k, $t];
}
function frei(RoboHubKonto $k): string
{
    return json_decode($k->EurekaB8Freigeben(60, RoboHubEurekaDiagnose::BESTAETIGUNG), true)['freigabe'];
}
function geraetAendern(RoboHubKonto $k, callable $fn): void
{
    $g = json_decode($k->attrs['Geraete'], true)[0]; $fn($g);
    $k->attrs['Geraete'] = json_encode([$g]);
}
function shadowAendern(RoboHubKonto $k, callable $fn): void
{
    $s = json_decode($k->attrs['Schnappschuss'], true); $fn($s['offline-e20']['p']);
    $k->attrs['Schnappschuss'] = json_encode($s);
}

// Der separate Import-Prüfstand verwendet dieselben lokalen Fixtures, ohne diesen Runner zu starten.
if (defined('ROBOHUB_DIAGNOSE_NUR_FIXTURE')) { return; }

$tests = [];
foreach (['fehlt', 'falsch', 'leer', 'defekt'] as $fall) {
    $tests['Separate Laufzeitfreigabe verweigert: ' . $fall] = function () use ($fall): void {
        [$k, $t] = konto();
        $k->puffer['EurekaB8Laufzeitfreigabe'] = match ($fall) {
            'fehlt' => '', 'falsch' => json_encode(['erwartet' => 'andere-laufzeit', 'zustand' => 'genehmigt']),
            'leer' => '{}', 'defekt' => '{invalid'
        };
        $vorher = $k->puffer['EurekaB8Laufzeitfreigabe'];
        $k->EurekaB8Vorpruefen(false);
        gesperrt(fn() => frei($k));
        sicher($t->calls === [] && $k->attrs['EurekaB8Diagnose'] === '{}');
        sicher($k->puffer['EurekaB8Laufzeitfreigabe'] === $vorher);
    };
}
foreach (['ticket', 'reserviert', 'transport'] as $phase) {
    foreach (['leer', 'bereit'] as $rollback) {
        $tests['Attributrollback in derselben Laufzeit: ' . $phase . '/' . $rollback] = function () use ($phase, $rollback): void {
            [$k, $t] = konto(); $ticket = frei($k); $bereit = $k->attrs['EurekaB8Diagnose'];
            $f = null;
            if ($phase === 'reserviert') {
                $t->vorTransport = fn() => Fiber::suspend('reserviert');
                $f = new Fiber(fn() => gesperrt(fn() => $k->EurekaB8Testen($ticket)));
                sicher($f->start() === 'reserviert');
            } elseif ($phase === 'transport') { $k->EurekaB8Testen($ticket); }
            $k->attrs['EurekaB8Diagnose'] = $rollback === 'leer' ? '{}' : $bereit;
            gesperrt(fn() => frei($k));
            if ($phase !== 'ticket' || $rollback === 'leer') { gesperrt(fn() => $k->EurekaB8Testen($ticket)); }
            if ($f !== null) { $f->resume(); sicher($f->isTerminated()); }
            if ($phase === 'transport') { gesperrt(fn() => $k->EurekaB8Widerrufen()); }
            sicher(count($t->calls) === ($phase === 'transport' ? 1 : 0));
        };
    }
}
$tests['Erhaltene alte Laufzeitfreigabe passt nicht zur neuen Laufzeit'] = function (): void {
    [$k, $t] = konto(); $alt = $k->puffer['EurekaB8Laufzeitfreigabe'];
    $k->puffer = ['EurekaB8Laufzeitfreigabe' => $alt]; // absichtliches Fehlermodell
    gesperrt(fn() => frei($k)); sicher($t->calls === []);
};
foreach (['reservierung', 'vorTransport'] as $phase) {
    $tests['Laufzeitfreigabe erneut prüfen: ' . $phase] = function () use ($phase): void {
        [$k, $t] = konto(); $f = frei($k);
        $aendern = function () use ($k): void { $k->puffer['EurekaB8Laufzeitfreigabe'] = ''; };
        if ($phase === 'reservierung') { $aendern(); }
        else { $t->vorTransport = $aendern; }
        gesperrt(fn() => $k->EurekaB8Testen($f));
        gesperrt(fn() => frei($k)); sicher($t->calls === []);
    };
}
$tests['Fehlgeschriebener Laufzeitverbrauch verhindert Ticket und Transport'] = function (): void {
    [$k, $t] = konto(); $k->pufferSchreibenSperren = true;
    gesperrt(fn() => frei($k)); sicher($t->calls === [] && $k->attrs['EurekaB8Diagnose'] === '{}');
};
$tests['Verbrauch bleibt nach Widerruf und Attributrollback gesperrt'] = function (): void {
    [$k, $t] = konto(); $ticket = frei($k); $bereit = $k->attrs['EurekaB8Diagnose'];
    $t->vorTransport = fn() => Fiber::suspend('reserviert');
    $f = new Fiber(fn() => gesperrt(fn() => $k->EurekaB8Testen($ticket)));
    sicher($f->start() === 'reserviert');
    $k->EurekaB8Widerrufen();
    $k->attrs['EurekaB8Diagnose'] = $bereit;
    $f->resume();
    gesperrt(fn() => frei($k)); gesperrt(fn() => $k->EurekaB8Testen($ticket));
    sicher($f->isTerminated() && $t->calls === []);
};
$tests['Administrativer Kontoablauf sendet einmal exakt den Abfragerahmen'] = function (): void {
    [$k, $t] = konto();
    $vorher = $k->attrs['Schnappschuss'];
    sicher(str_contains($k->EurekaB8Vorpruefen(false), 'bestanden') && $t->calls === []);
    $f = frei($k);
    $t->antwort = function ($m, $p, $token, $b) use ($k): array {
        sicher($m === 'POST' && $p === '/devices/commands/offline-e20');
        sicher(json_encode($b) === '{"data":"AA0CB800000000000003320106"}');
        sicher($token === json_decode($k->attrs['Buendel'], true)['access_token']);
        sicher(json_decode($k->attrs['EurekaB8Diagnose'], true)['zustand'] === 'verbraucht');
        sicher(count($GLOBALS['locks']) === 2);
        return antwort(['data' => frame()]);
    };
    $r = json_decode($k->EurekaB8Testen($f), true);
    sicher($r['httpErfolg'] && $r['cloudErfolg'] && $r['frameGueltig'] && $r['plausibel']);
    sicher(count($t->calls) === 1 && $k->logs === [] && $k->attrs['Schnappschuss'] === $vorher);
    sicher(!str_contains($k->attrs['EurekaB8Diagnose'], $f));
    gesperrt(fn() => $k->EurekaB8Testen($f));
    gesperrt(fn() => frei($k));
    sicher(count($t->calls) === 1);
};
foreach (['modell', 'sn8', 'proto', 'platform', 'station', 'plugin', 'version', 'widerspruch', 'id',
    'cacheAlt', 'cacheZukunft', 'listenzeit', 'cacheOffline', 'cacheUnbekannt', 'cacheFehler',
    'shadowAlt', 'shadowId', 'shadowRohId', 'shadowOffline', 'shadowOnlineFalse', 'shadowFehler',
    'fehlerzustand', 'shadowUnbekannt', 'shadowWiderspruch', 'shadowModell', 'keinFehlernachweis', 'duplikat',
    'cacheFehlt', 'shadowFehlt', 'fremdkonto', 'fremdinstanz', 'fremdmodul', 'geraetFehlt',
    'andereGeraetId', 'hersteller', 'region', 'cloudsperre', 'kernel', 'tokenAbgelaufen', 'tokenFalsch', 'revision'] as $fall) {
    $tests['Freigabe verweigert ungültigen Kontext: ' . $fall] = function () use ($fall): void {
        [$k, $t] = konto();
        switch ($fall) {
            case 'modell': geraetAendern($k, function (&$g) { $g['modell'] = $g['roh']['product']['productModel'] = 'other'; }); break;
            case 'sn8': geraetAendern($k, function (&$g) { $g['roh']['product']['sn8'] = 'other'; }); break;
            case 'proto': geraetAendern($k, function (&$g) { $g['roh']['product']['protoVersion'] = 1; }); break;
            case 'platform': geraetAendern($k, function (&$g) { $g['roh']['product']['platform'] = 'other'; }); break;
            case 'station': geraetAendern($k, function (&$g) { $g['roh']['product']['stationType'] = 'other'; }); break;
            case 'plugin': geraetAendern($k, function (&$g) { $g['roh']['panel']['name'] = 'other'; }); break;
            case 'version': geraetAendern($k, function (&$g) { $g['roh']['panel']['versionName'] = 'other'; }); break;
            case 'widerspruch': geraetAendern($k, function (&$g) { $g['roh']['stationType'] = 'other'; }); break;
            case 'id': geraetAendern($k, function (&$g) { $g['roh']['deviceId'] = 'other'; }); break;
            case 'cacheAlt': geraetAendern($k, function (&$g) { $g['verbindungEmpfangen'] -= 121; }); break;
            case 'cacheZukunft': geraetAendern($k, function (&$g) { $g['verbindungEmpfangen'] += 100; }); break;
            case 'listenzeit': $k->attrs['GeraeteStand'] -= 121; break;
            case 'cacheOffline': geraetAendern($k, function (&$g) { $g['roh']['connected'] = false; }); break;
            case 'cacheUnbekannt': geraetAendern($k, function (&$g) { unset($g['roh']['connected']); }); break;
            case 'cacheFehler': geraetAendern($k, function (&$g) { $g['roh']['deviceFault'] = [1]; }); break;
            case 'shadowAlt': shadowAendern($k, function (&$s) { $s['zeit'] -= 121; }); break;
            case 'shadowId': shadowAendern($k, function (&$s) { $s['deviceId'] = 'other'; }); break;
            case 'shadowRohId': shadowAendern($k, function (&$s) { $s['roh']['deviceId'] = 'other'; }); break;
            case 'shadowOffline': shadowAendern($k, function (&$s) { $s['roh']['connected'] = false; }); break;
            case 'shadowOnlineFalse': shadowAendern($k, function (&$s) { $s['roh']['online'] = false; }); break;
            case 'shadowFehler': shadowAendern($k, function (&$s) { $s['roh']['deviceFault'] = [1]; }); break;
            case 'fehlerzustand': shadowAendern($k, function (&$s) { $s['roh']['state'] = 'error'; }); break;
            case 'shadowUnbekannt': shadowAendern($k, function (&$s) { $s['roh']['state'] = 'other'; }); break;
            case 'shadowWiderspruch': shadowAendern($k, function (&$s) { $s['roh']['parentState'] = 'charging'; }); break;
            case 'shadowModell': shadowAendern($k, function (&$s) { $s['roh']['productModel'] = 'other'; }); break;
            case 'keinFehlernachweis': shadowAendern($k, function (&$s) { unset($s['roh']['deviceFault']); }); break;
            case 'duplikat': $liste = json_decode($k->attrs['Geraete'], true); $k->attrs['Geraete'] = json_encode([...$liste, ...$liste]); break;
            case 'cacheFehlt': $k->attrs['Geraete'] = '[]'; break;
            case 'shadowFehlt': $k->attrs['Schnappschuss'] = '{}'; break;
            case 'fremdkonto': $k->InstanceID = 45175; break;
            case 'fremdinstanz': $GLOBALS['ziel']['ConnectionID'] = 45175; break;
            case 'fremdmodul': $GLOBALS['ziel']['ModuleInfo']['ModuleID'] = 'other'; break;
            case 'geraetFehlt': $GLOBALS['fehlt'] = true; break;
            case 'andereGeraetId': $GLOBALS['zielId'] = 'other'; break;
            case 'hersteller': $k->props['Hersteller'] = 'dreame'; break;
            case 'region': $k->props['Region'] = 'us'; break;
            case 'cloudsperre': $k->attrs['WarteBis'] = time() + 60; break;
            case 'kernel': $GLOBALS['runlevel'] = 0; break;
            case 'tokenAbgelaufen': $k->attrs['Buendel'] = json_encode(['region' => 'eu', 'access_token' => jwt(time())]); break;
            case 'tokenFalsch': $k->attrs['Buendel'] = '{}'; break;
            case 'revision': $k->attrs['BuendelRevision'] = ''; break;
        }
        gesperrt(fn() => frei($k));
        sicher($t->calls === [] && $k->attrs['EurekaB8Diagnose'] === '{}');
    };
}
foreach ([0, -1, 61, 1000] as $dauer) {
    $tests['Freigabedauer ' . $dauer . ' verweigert'] = function () use ($dauer): void {
        [$k] = konto(); gesperrt(fn() => $k->EurekaB8Freigeben($dauer, RoboHubEurekaDiagnose::BESTAETIGUNG));
    };
}
$tests['Fehlende Bestätigung, fehlende Freigabe und falsches Ticket'] = function (): void {
    [$k, $t] = konto();
    gesperrt(fn() => $k->EurekaB8Freigeben(60, 'ja'));
    gesperrt(fn() => $k->EurekaB8Testen(''));
    frei($k); gesperrt(fn() => $k->EurekaB8Testen(str_repeat('a', 64)));
    sicher($t->calls === []);
};
foreach (['abgelaufen', 'uhrZurueck', 'sitzung', 'gleicheTokensNeueSitzung', 'tokenrotation', 'kontoname',
    'neustart', 'pufferVerloren', 'deviceId', 'offline', 'sperre', 'widerruf', 'persistenz', 'zustand'] as $fall) {
    $tests['Nach Freigabe geändert: ' . $fall] = function () use ($fall): void {
        [$k, $t] = konto(); $f = frei($k);
        switch ($fall) {
            case 'abgelaufen': $d = json_decode($k->attrs['EurekaB8Diagnose'], true); $d['bis'] = time(); $d['von'] = time() - 60; $k->attrs['EurekaB8Diagnose'] = json_encode($d); break;
            case 'uhrZurueck': $d = json_decode($k->attrs['EurekaB8Diagnose'], true); $d['von'] += 60; $d['bis'] += 60; $k->attrs['EurekaB8Diagnose'] = json_encode($d); break;
            case 'sitzung': $k->attrs['Buendel'] = ''; break;
            case 'gleicheTokensNeueSitzung': $k->attrs['BuendelRevision'] = 'other'; break;
            case 'tokenrotation': $b = json_decode($k->attrs['Buendel'], true); $b['access_token'] = jwt(time() + 7200); $k->attrs['Buendel'] = json_encode($b); break;
            case 'kontoname': $k->props['Konto'] = 'other@example.invalid'; break;
            case 'neustart': $GLOBALS['boot']--; break;
            case 'pufferVerloren': $k->puffer = []; break;
            case 'deviceId': $GLOBALS['zielId'] = 'other'; break;
            case 'offline': shadowAendern($k, function (&$s) { $s['roh']['connected'] = false; }); break;
            case 'sperre': $k->attrs['WarteBis'] = time() + 60; break;
            case 'widerruf': $k->EurekaB8Widerrufen(); break;
            case 'persistenz': $k->speichernSperren = true; break;
            case 'zustand': $k->attrs['EurekaB8Diagnose'] = '{invalid'; break;
        }
        gesperrt(fn() => $k->EurekaB8Testen($f)); sicher($t->calls === []);
    };
}
$tests['Widerruf trotz ungültiger Sitzung und Cloud-Sperre schließt Laufzeitfreigabe'] = function (): void {
    [$k, $t] = konto(); $f = frei($k); $b = $k->attrs['Buendel'];
    $k->attrs['Buendel'] = ''; $k->attrs['WarteBis'] = time() + 60;
    $k->EurekaB8Widerrufen(); sicher($t->calls === []);
    $k->attrs['Buendel'] = $b; $k->attrs['WarteBis'] = 0;
    gesperrt(fn() => frei($k)); gesperrt(fn() => $k->EurekaB8Testen($f));
    $k->attrs['EurekaB8Diagnose'] = '{}';
    gesperrt(fn() => frei($k)); sicher($t->calls === []);
};
foreach (['tokenrotation', 'widerruf', 'offline', 'verbrauch', 'boot', 'zeit'] as $fall) {
    $tests['Erneute Prüfung direkt vor HTTP: ' . $fall] = function () use ($fall): void {
        [$k, $t] = konto(); $f = frei($k);
        $k->beiSpeichern = function () use ($k, $fall): void {
            $k->beiSpeichern = null;
            switch ($fall) {
                case 'tokenrotation': $k->attrs['BuendelRevision'] = 'rotated'; break;
                case 'widerruf': $k->attrs['EurekaB8Diagnose'] = '{"zustand":"widerrufen"}'; break;
                case 'offline': shadowAendern($k, function (&$s) { $s['roh']['connected'] = false; }); break;
                case 'verbrauch': $d = json_decode($k->attrs['EurekaB8Diagnose'], true); $d['verbrauchtAm']--; $k->attrs['EurekaB8Diagnose'] = json_encode($d); break;
                case 'boot': $GLOBALS['boot']--; break;
                case 'zeit': $k->attrs['GeraeteStand'] -= 121; break;
            }
        };
        gesperrt(fn() => $k->EurekaB8Testen($f)); sicher($t->calls === []);
    };
}
foreach ([401, 429, 500, 302, 'timeout', 'cloudTimeout'] as $fall) {
    $tests['Verbrauch ohne Retry bei ' . $fall] = function () use ($fall): void {
        [$k, $t] = konto(); $f = frei($k); $b = $k->attrs['Buendel'];
        $t->antwort = $fall === 'timeout'
            ? function (): array { throw new RuntimeException('fixture-secret'); }
            : antwort('fixture-secret', is_int($fall) ? $fall : 200, $fall === 'cloudTimeout' ? '0080005' : 0);
        if ($fall === 'timeout') { gesperrt(fn() => $k->EurekaB8Testen($f)); }
        else {
            $r = json_decode($k->EurekaB8Testen($f), true);
            sicher($fall === 'cloudTimeout' ? !$r['cloudErfolg'] : !$r['httpErfolg']);
        }
        sicher($k->attrs['Buendel'] === $b && count($t->calls) === 1);
        if ($fall === 429) { sicher($k->attrs['WarteBis'] >= time() + 59); $k->attrs['WarteBis'] = 0; }
        gesperrt(fn() => $k->EurekaB8Testen($f)); gesperrt(fn() => frei($k));
        $k->puffer = []; $GLOBALS['boot']--; gesperrt(fn() => frei($k));
        sicher(count($t->calls) === 1 && $k->logs === []);
    };
}
$tests['Paralleler Aufruf und Widerruf während HTTP gesperrt, Verbrauch persistent'] = function (): void {
    [$k, $t] = konto(); $f = frei($k);
    $t->antwort = function (): array { Fiber::suspend('http'); return antwort(frame()); };
    $fiber = new Fiber(fn() => $k->EurekaB8Testen($f)); sicher($fiber->start() === 'http');
    gesperrt(fn() => $k->EurekaB8Testen($f)); gesperrt(fn() => $k->EurekaB8Widerrufen()); gesperrt(fn() => frei($k));
    // Zentraler Sitzungsschreiber kann während des Versuchs nicht rotieren.
    $save = new ReflectionMethod(RoboHubKonto::class, 'buendelSpeichern');
    gesperrt(fn() => $save->invoke($k, ''));
    $fiber->resume(); sicher($fiber->isTerminated() && count($t->calls) === 1);
    // Neues Kontoobjekt mit persistierten Attributen nach simuliertem Neustart.
    $neu = new RoboHubKonto(45174); $neu->attrs = $k->attrs;
    gesperrt(fn() => $neu->EurekaB8Testen($f)); gesperrt(fn() => frei($neu));
};
foreach (['bereit', 'leer'] as $rollback) {
    foreach ([false, true] as $neueKernelzeit) {
        $tests['Persistenzmodell: alter Zustand ' . $rollback . ', neuer Buffer, Kernelwechsel ' . (int)$neueKernelzeit] = function () use ($rollback, $neueKernelzeit): void {
            [$k, $t] = konto(); $f = frei($k);
            $vorVerbrauch = $k->attrs;
            $k->EurekaB8Testen($f); sicher(count($t->calls) === 1);
            // Ausschließlich Fehlermodell: verlorener Verbrauch, KEIN nativer Crashnachweis.
            $neu = new RoboHubKonto(45174); $neu->attrs = $vorVerbrauch;
            if ($rollback === 'leer') { $neu->attrs['EurekaB8Diagnose'] = '{}'; }
            if ($neueKernelzeit) { $GLOBALS['boot']--; }
            gesperrt(fn() => $neu->EurekaB8Testen($f));
            sicher(count($t->calls) === 1); // Kein automatischer Neuversuch/keine Freigabe.
            gesperrt(fn() => frei($neu));
            sicher(!isset($neu->puffer['EurekaB8Laufzeitfreigabe']));
            $neu->EurekaB8Widerrufen();
            sicher(count($t->calls) === 1);
        };
    }
}
$tests['Widerruf nach Reservierung verhindert Transport, Versuch bleibt verbraucht'] = function (): void {
    [$k, $t] = konto(); $f = frei($k);
    $t->vorTransport = function (): void { Fiber::suspend('reserviert'); };
    $fiber = new Fiber(fn() => gesperrt(fn() => $k->EurekaB8Testen($f)));
    sicher($fiber->start() === 'reserviert');
    sicher(json_decode($k->attrs['EurekaB8Diagnose'], true)['zustand'] === 'verbraucht');
    $k->EurekaB8Widerrufen();
    $fiber->resume();
    sicher($fiber->isTerminated() && $t->calls === []);
    $d = json_decode($k->attrs['EurekaB8Diagnose'], true);
    sicher($d['zustand'] === 'widerrufen' && isset($d['verbrauchtAm']) && !isset($d['transportBegonnenAm']));
    gesperrt(fn() => frei($k));
};
foreach (['RoboHubEureka_Diagnose_45174', 'RoboHubKonto_Buendel_45174', 'RoboHubEureka_Freigabe_45174'] as $name) {
    $tests['Semaphore-Timeout: ' . $name] = function () use ($name): void {
        [$k, $t] = konto(); $f = frei($k); $GLOBALS['lockFehler'] = $name;
        gesperrt(fn() => $k->EurekaB8Testen($f)); sicher($t->calls === []);
        unset($GLOBALS['lockFehler']);
    };
}
$tests['Vorprüfung benötigt explizites Listen-GET; erfolgreicher GET erteilt keine Freigabe'] = function (): void {
    [$k, $t] = konto(); $k->attrs['GeraeteStand'] -= 121;
    gesperrt(fn() => $k->EurekaB8Vorpruefen(false)); sicher($t->calls === []);
    $g = json_decode($k->attrs['Geraete'], true)[0];
    $t->antwort = function ($m, $p) use ($g): array {
        sicher($m === 'GET' && $p === '/app/devices?version=1&isShared=false'); return antwort([$g['roh']]);
    };
    $k->EurekaB8Vorpruefen(true);
    sicher(count($t->calls) === 1 && $k->attrs['EurekaB8Diagnose'] === '{}');
    gesperrt(fn() => $k->EurekaB8Testen(''));
};
foreach ([401, 429, 500] as $status) {
    $tests['Vorprüfung ohne GET-Refresh/Retry: ' . $status] = function () use ($status): void {
        [$k, $t] = konto(); $t->antwort = antwort([], $status);
        $refreshs = 0;
        $t->beiUnauthorized = function () use (&$refreshs): array { $refreshs++; return []; };
        gesperrt(fn() => $k->EurekaB8Vorpruefen(true));
        sicher(count($t->calls) === 1 && $k->attrs['EurekaB8Diagnose'] === '{}' && $refreshs === 0);
        if ($status === 429) { sicher($k->attrs['WarteBis'] >= time() + 59); }
    };
}
$tests['Shadow-GET ist keine Freigabe'] = function (): void {
    [$k, $t] = konto(); $t->antwort = antwort(['state' => 'cleaning']);
    $t->zustand(json_decode($k->attrs['Buendel'], true), json_decode($k->attrs['Geraete'], true)[0]);
    sicher($k->attrs['EurekaB8Diagnose'] === '{}'); gesperrt(fn() => $k->EurekaB8Testen(''));
};
$tests['Frische Vorprüfung prüft Zielinstanz und Modell vor jedem GET'] = function (): void {
    [$k, $t] = konto();
    geraetAendern($k, function (&$g) { $g['modell'] = 'other'; });
    gesperrt(fn() => $k->EurekaB8Vorpruefen(true)); sicher($t->calls === []);
    [$k, $t] = konto(); $GLOBALS['ziel']['ConnectionID'] = 45175;
    gesperrt(fn() => $k->EurekaB8Vorpruefen(true)); sicher($t->calls === []);
};
$tests['Monotone Frist verhindert Verlängerung durch zurückgestellte Systemuhr'] = function (): void {
    [$k, $t] = konto();
    $m = new ReflectionMethod(RoboHubKonto::class, 'eurekaB8Kontext');
    $ctx = $m->invoke($k); $ctx['monoton'] = 100000;
    $f = [];
    $laufzeit = ['erwartet' => $ctx['boot'], 'zustand' => 'genehmigt', 'cloudGeraetId' => $ctx['geraet']['id']];
    $d = new RoboHubEurekaDiagnose(function () use (&$ctx): array { return $ctx; },
        function () use (&$f): array { return $f; }, function ($v) use (&$f): void { $f = $v; }, fn(callable $fn) => $fn(),
        function () use (&$laufzeit): array { return $laufzeit; },
        function (array $v) use (&$laufzeit): void { $laufzeit = $v; });
    $ticket = $d->freigeben(60, RoboHubEurekaDiagnose::BESTAETIGUNG)['freigabe'];
    // Wanduhr nur 10 s weiter, monotone Uhr bereits exakt 60 s weiter.
    $ctx['jetzt'] += 10; $ctx['monoton'] += 60000;
    gesperrt(fn() => $t->diagnoseB8($d, $ticket)); sicher($t->calls === []);
};
$tests['Normalbefehle und Transport bleiben auch mit Diagnosefreigabe gesperrt'] = function (): void {
    [$k, $t] = konto(); frei($k);
    sicher(!RoboHubEureka::regulaereBedienungFuer('offline-e20'));
    foreach (['start', 'pause', 'station', 'stopp', 'orten', 'raum', 'saugstufe', 'wasserstufe'] as $b) {
        gesperrt(fn() => $t->befehl([], [], $b, []));
    }
    $transport = new ReflectionMethod(RoboHubEureka::class, 'transport');
    foreach (['POST', 'GET', 'PUT'] as $m) {
        gesperrt(fn() => $transport->invoke($t, $m, '/devices/commands/offline-e20', 'fixture-secret', []));
    }
    sicher($t->calls === []);
};
$tests['B8-Parser akzeptiert dokumentierte Mindestlänge und keine unbekannten Felder'] = function (): void {
    $r = RoboHubEurekaB8::lesen(frame()); sicher($r['plausibel']);
    sicher($r['felder'] === ['arbeit' => 6, 'akku' => 87, 'fehlerklasse' => 0, 'fehlercode' => 0]);
};
foreach (['', 'AA', 'not-hex', substr(frame(), 0, -2), 'AB' . substr(frame(), 2),
    substr_replace(frame(), '01', 2, 2), substr_replace(frame(), 'B9', 4, 2),
    substr_replace(frame(), '04', 18, 2), substr_replace(frame(), '00', 6, 2) . '00',
    substr_replace(frame(), '00', -2), frame(6, 87, 2), frame(6, 87, 25), frame(6, 87, 26, 2),
    RoboHubEurekaProtokoll::rahmen([0x32, 6, ...array_fill(0, 24, 0)], 3)] as $i => $hex) {
    $tests['Ungültiger/unvollständiger B8-Frame: ' . $i] = function () use ($hex): void {
        $r = RoboHubEurekaB8::antwort(antwort($hex));
        sicher($r['httpErfolg'] && $r['cloudErfolg'] && !$r['frameGueltig'] && $r['plausibel'] === null);
    };
}
foreach ([[255, 87], [6, 101]] as [$arbeit, $akku]) {
    $tests['Gültiger Frame, unplausibler Inhalt ' . $arbeit . '/' . $akku] = function () use ($arbeit, $akku): void {
        $r = RoboHubEurekaB8::antwort(antwort(frame($arbeit, $akku)));
        sicher($r['httpErfolg'] && $r['cloudErfolg'] && $r['frameGueltig'] && !$r['plausibel']);
    };
}
foreach ([null, '', [], ['unknown' => 'fixture-secret']] as $i => $data) {
    $tests['Cloud-Erfolg ohne gültigen Antwortframe: ' . $i] = function () use ($data): void {
        $r = RoboHubEurekaB8::antwort(antwort($data)); sicher($r['cloudErfolg'] && !$r['frameGueltig']);
    };
}
foreach ([['status' => 200, 'response' => '{invalid'], ['status' => 200, 'response' => '{"data":{}}'],
    antwort(frame(), 200, 'fixture-secret')] as $i => $a) {
    $tests['HTTP-Erfolg ohne Cloud-Erfolg: ' . $i] = function () use ($a): void {
        $r = RoboHubEurekaB8::antwort($a); sicher($r['httpErfolg'] && !$r['cloudErfolg'] && $r['frameGueltig'] === null);
        sicher(!str_contains(json_encode($r), 'fixture-secret'));
    };
}
require __DIR__ . '/b8-response-cases.php';
if (!defined('ROBOHUB_TEST_OHNE_SIGNATUREN')) { require __DIR__ . '/provisioning-cases.php'; }
require __DIR__ . '/runtime-display-cases.php';

$fehler = 0;
foreach ($tests as $name => $fn) {
    try { $fn(); sicher($GLOBALS['locks'] === []); echo 'OK: ' . $name . "\n"; }
    catch (Throwable $e) { $fehler++; echo 'FEHLER: ' . $name . "\n"; $GLOBALS['locks'] = []; }
}
echo count($tests) . ' Tests, ' . $fehler . " Fehler\n";
exit($fehler === 0 ? 0 : 1);
