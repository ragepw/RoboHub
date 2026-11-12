<?php
declare(strict_types=1);

// Aufruf ausschließlich mit deaktivierten nativen cURL-Funktionen, siehe README.
// Ersetzt nur die cURL-Systemgrenze; der echte Eureka-HTTPS-Code wird ausgeführt.
if (!in_array('curl_exec', array_map('trim', explode(',', ini_get('disable_functions'))), true)) {
    fwrite(STDERR, "Abbruch: native cURL-Funktionen müssen deaktiviert sein.\n");
    exit(1);
}
// Bedingte Definitionen bleiben auch beim normalen PHP-Lint ohne disable_functions gültig.
if (!function_exists('curl_init')) {
function curl_init($url) { $GLOBALS['httpCalls'][] = ['url' => $url]; return new stdClass(); }
function curl_setopt_array($ch, $options)
{
    $GLOBALS['httpCalls'][array_key_last($GLOBALS['httpCalls'])]['options'] = $options;
    if (is_callable($GLOBALS['beiOptionen'] ?? null)) { ($GLOBALS['beiOptionen'])(); }
    if ($GLOBALS['optionException'] ?? false) { throw new ValueError('offline-secret-must-not-escape'); }
    return $GLOBALS['optionErgebnis'] ?? true;
}
function curl_exec($ch)
{
    $GLOBALS['execCalls'] = ($GLOBALS['execCalls'] ?? 0) + 1;
    if (is_callable($GLOBALS['vorCurl'] ?? null)) { ($GLOBALS['vorCurl'])(); }
    return $GLOBALS['response'];
}
function curl_getinfo($ch, $key) { return $key === CURLINFO_HTTP_VERSION ? ($GLOBALS['httpVersion'] ?? CURL_HTTP_VERSION_1_1) : $GLOBALS['status']; }
function curl_close($ch) { $GLOBALS['closeCalls'] = ($GLOBALS['closeCalls'] ?? 0) + 1; }
}
require_once __DIR__ . '/../../libs/RoboHubEureka.php';
require_once __DIR__ . '/e20-fixture.php';
function sicher(bool $v): void { if (!$v) { throw new RuntimeException('Offline-Transportprüfung fehlgeschlagen.'); } }
function gesperrt(callable $fn): void
{
    try { $fn(); } catch (RoboHubFehler $e) { return; }
    throw new RuntimeException('Erwartete Transportsperre fehlt.');
}
function vorbereiten(): array
{
    $g = e20Fixture()['geraet']; $g['stand']['zeit'] = $g['verbindungEmpfangen'] = time();
    $token = 'e30.' . base64_encode(json_encode(['exp' => time() + 3600])) . '.offline';
    $k = ['konto' => 45174, 'instanz' => 22708, 'hersteller' => 'eureka', 'region' => 'eu',
        'jetzt' => time(), 'monoton' => 100000, 'boot' => 'offline-start', 'revision' => 'offline-session',
        'warteBis' => 0, 'kontoname' => 'offline@example.invalid', 'cacheZeit' => time(),
        'geraet' => $g, 'buendel' => ['region' => 'eu', 'access_token' => $token]];
    $zustand = [];
    $laufzeit = ['erwartet' => $k['boot'], 'zustand' => 'genehmigt', 'cloudGeraetId' => $k['geraet']['id']];
    $d = new RoboHubEurekaDiagnose(fn() => $k, function () use (&$zustand): array { return $zustand; },
        function ($f) use (&$zustand): void { $zustand = $f; }, fn(callable $fn) => $fn(),
        function () use (&$laufzeit): array { return $laufzeit; },
        function (array $v) use (&$laufzeit): void { $laufzeit = $v; });
    $f = $d->freigeben(60, RoboHubEurekaDiagnose::BESTAETIGUNG)['freigabe'];
    $GLOBALS['vorCurl'] = function () use (&$zustand): void {
        sicher($zustand['zustand'] === 'verbraucht' && isset($zustand['transportBegonnenAm']));
    };
    return [$d, $f];
}
$tests = [];
foreach ([200, 401, 429, 500, 302, 307, 'timeout'] as $fall) {
    $tests['Gemeinsamer cURL-Pfad, feste Parameter, keine Wiederholung: ' . $fall] = function () use ($fall): void {
        $GLOBALS['httpCalls'] = [];
        $GLOBALS['status'] = is_int($fall) ? $fall : 0;
        $GLOBALS['response'] = $fall === 'timeout' ? false : '{"errorCode":0}';
        [$d, $f] = vorbereiten(); $t = new RoboHubEureka();
        if ($fall === 'timeout') { gesperrt(fn() => $t->diagnoseB8($d, $f)); }
        else { $t->diagnoseB8($d, $f); }
        gesperrt(fn() => $t->diagnoseB8($d, $f));
        sicher(count($GLOBALS['httpCalls']) === 1);
        $call = $GLOBALS['httpCalls'][0]; $o = $call['options'];
        sicher($call['url'] === 'https://euprod.mzrobo.com/devices/commands/offline-e20');
        sicher($o[CURLOPT_CUSTOMREQUEST] === 'POST');
        sicher($o[CURLOPT_POSTFIELDS] === '{"data":"AA0CB800000000000003320106"}');
        sicher($o[CURLOPT_CONNECTTIMEOUT] === 8 && $o[CURLOPT_TIMEOUT] === 15);
        sicher($o[CURLOPT_FOLLOWLOCATION] === false && $o[CURLOPT_MAXREDIRS] === 0);
        sicher($o[CURLOPT_HTTP_VERSION] === CURL_HTTP_VERSION_1_1);
        sicher($o[CURLOPT_FRESH_CONNECT] === true && $o[CURLOPT_FORBID_REUSE] === true);
        sicher($o[CURLOPT_HTTPAUTH] === CURLAUTH_NONE && $o[CURLOPT_PROXYAUTH] === CURLAUTH_NONE);
        sicher($o[CURLOPT_PROXY] === '' && in_array('Expect:', $o[CURLOPT_HTTPHEADER], true));
        sicher(!array_key_exists(CURLOPT_SSL_VERIFYPEER, $o) && !array_key_exists(CURLOPT_SSL_VERIFYHOST, $o));
        sicher(count(array_filter($o[CURLOPT_HTTPHEADER], fn($v) => str_starts_with($v, 'Authorization: Bearer '))) === 1);
    };
}
$tests['Unterste gemeinsame Transportgrenze sperrt alle normalen Befehle vor cURL'] = function (): void {
    $GLOBALS['httpCalls'] = []; $t = new RoboHubEureka();
    $http = new ReflectionMethod(RoboHubEureka::class, 'httpAusfuehren');
    foreach (['POST', 'GET', 'PUT', 'DELETE'] as $m) {
        gesperrt(fn() => $http->invoke($t, $m, '/devices/commands/offline-e20', 'offline', ['data' => RoboHubEurekaDiagnose::BEFEHL]));
    }
    sicher($GLOBALS['httpCalls'] === []);
};
$tests['Unterste Grenze prüft Berechtigung unmittelbar vor cURL erneut'] = function (): void {
    $GLOBALS['httpCalls'] = []; $t = new RoboHubEureka();
    $http = new ReflectionMethod(RoboHubEureka::class, 'httpAusfuehren');
    $guard = function (): void { throw new RoboHubFehler('Abgewiesen.'); };
    gesperrt(fn() => $http->invoke($t, 'POST', '/devices/commands/offline-e20', 'offline',
        ['data' => RoboHubEurekaDiagnose::BEFEHL], $guard));
    sicher($GLOBALS['httpCalls'] === []);
};
$tests['Letzte Prüfung nach Optionen verhindert I/O bei veränderter Berechtigung'] = function (): void {
    $GLOBALS['httpCalls'] = []; $GLOBALS['execCalls'] = 0;
    $bereit = true;
    $GLOBALS['beiOptionen'] = function () use (&$bereit): void { $bereit = false; };
    $guard = function () use (&$bereit): void { if (!$bereit) { throw new RoboHubFehler('Abgewiesen.'); } };
    $http = new ReflectionMethod(RoboHubEureka::class, 'httpAusfuehren');
    try {
        gesperrt(fn() => $http->invoke(new RoboHubEureka(), 'POST', '/devices/commands/offline-e20', 'offline',
            ['data' => RoboHubEurekaDiagnose::BEFEHL], $guard));
        sicher($GLOBALS['execCalls'] === 0);
    } finally { unset($GLOBALS['beiOptionen']); }
};
$tests['Widerruf während echter cURL-Vorbereitung verhindert curl_exec'] = function (): void {
    $GLOBALS['httpCalls'] = []; $GLOBALS['execCalls'] = 0;
    [$d, $f] = vorbereiten();
    $GLOBALS['beiOptionen'] = fn() => $d->widerrufen();
    try {
        gesperrt(fn() => (new RoboHubEureka())->diagnoseB8($d, $f));
        sicher($GLOBALS['execCalls'] === 0);
        gesperrt(fn() => $d->freigeben(60, RoboHubEurekaDiagnose::BESTAETIGUNG));
    } finally { unset($GLOBALS['beiOptionen']); }
};
$tests['Unterste Grenze akzeptiert keine alternativen Pfade, Methoden oder Bodies'] = function (): void {
    $GLOBALS['httpCalls'] = []; $t = new RoboHubEureka();
    $http = new ReflectionMethod(RoboHubEureka::class, 'httpAusfuehren');
    foreach ([['GET', '/devices/commands/offline-e20', ['data' => RoboHubEurekaDiagnose::BEFEHL]],
        ['POST', '/devices/commands/offline-e20?x=1', ['data' => RoboHubEurekaDiagnose::BEFEHL]],
        ['POST', '/devices/commands/offline-e20', ['data' => 'AA0DB800000000000002221B00FC']],
        ['POST', '/devices/commands/offline-e20', ['data' => RoboHubEurekaDiagnose::BEFEHL, 'extra' => 1]]] as [$m, $p, $b]) {
        gesperrt(fn() => $http->invoke($t, $m, $p, 'offline', $b, function (): void {}));
    }
    sicher($GLOBALS['httpCalls'] === []);
};
$tests['Normaler GET verwendet denselben HTTPS-Code mit bisherigen Timeouts'] = function (): void {
    $GLOBALS['httpCalls'] = []; $GLOBALS['status'] = 200;
    $GLOBALS['response'] = '{"errorCode":0,"data":[]}'; unset($GLOBALS['vorCurl']);
    $t = new RoboHubEureka(); sicher($t->geraete(['access_token' => 'offline']) === []);
    sicher(count($GLOBALS['httpCalls']) === 1);
    $c = $GLOBALS['httpCalls'][0];
    sicher($c['url'] === 'https://euprod.mzrobo.com/app/devices?version=1&isShared=false');
    sicher($c['options'][CURLOPT_CONNECTTIMEOUT] === 8 && $c['options'][CURLOPT_TIMEOUT] === 15);
    foreach ([CURLOPT_HTTP_VERSION, CURLOPT_FRESH_CONNECT, CURLOPT_FORBID_REUSE, CURLOPT_HTTPAUTH,
        CURLOPT_PROXYAUTH, CURLOPT_PROXY] as $option) { sicher(!array_key_exists($option, $c['options'])); }
    sicher(!in_array('Expect:', $c['options'][CURLOPT_HTTPHEADER], true));
};
foreach (['false', 'exception'] as $fehlerart) {
    foreach (['diagnose', 'get'] as $weg) {
        $tests['Konfigurationsfehler vor I/O, Handle geschlossen: ' . $fehlerart . '/' . $weg] = function () use ($fehlerart, $weg): void {
            $GLOBALS['execCalls'] = $GLOBALS['closeCalls'] = 0;
            $GLOBALS['optionErgebnis'] = false;
            $GLOBALS['optionException'] = $fehlerart === 'exception';
            [$d, $f] = vorbereiten(); $t = new RoboHubEureka();
            try {
                gesperrt(fn() => $weg === 'diagnose' ? $t->diagnoseB8($d, $f) : $t->geraete(['access_token' => 'offline']));
                sicher($GLOBALS['execCalls'] === 0 && $GLOBALS['closeCalls'] === 1);
                if ($weg === 'diagnose') {
                    gesperrt(fn() => $t->diagnoseB8($d, $f));
                    gesperrt(fn() => $d->freigeben(60, RoboHubEurekaDiagnose::BESTAETIGUNG));
                    sicher($GLOBALS['closeCalls'] === 1);
                }
            } finally { unset($GLOBALS['optionErgebnis'], $GLOBALS['optionException']); }
        };
    }
}
$tests['Unerwartete HTTP-Version wird ohne weiteren Transport verworfen'] = function (): void {
    $GLOBALS['execCalls'] = $GLOBALS['closeCalls'] = 0;
    $GLOBALS['status'] = 200; $GLOBALS['response'] = '{"errorCode":0}';
    $GLOBALS['httpVersion'] = CURL_HTTP_VERSION_2_0;
    [$d, $f] = vorbereiten(); $t = new RoboHubEureka();
    try {
        gesperrt(fn() => $t->diagnoseB8($d, $f));
        gesperrt(fn() => $t->diagnoseB8($d, $f));
        sicher($GLOBALS['execCalls'] === 1 && $GLOBALS['closeCalls'] === 1);
    } finally { unset($GLOBALS['httpVersion']); }
};
function dockHttpFixture(): array
{
    $o = new stdClass();
    $g = e20Fixture()['geraet']; $g['stand']['zeit'] = $g['verbindungEmpfangen'] = time();
    $o->k = ['konto'=>45174,'instanz'=>22708,'hersteller'=>'eureka','region'=>'eu',
        'jetzt'=>time(),'monoton'=>100000,'boot'=>(time()-100).':'.str_repeat('d',64),
        'revision'=>'offline','warteBis'=>0,'kontoname'=>'offline@example.invalid','cacheZeit'=>time(),
        'geraet'=>$g,'buendel'=>['region'=>'eu','access_token'=>'e30.'.base64_encode(json_encode(['exp'=>time()+3600])).'.offline']];
    $o->f = []; $o->l = ['erwartet'=>$o->k['boot'],'zustand'=>'genehmigt','cloudGeraetId'=>$g['id'],
        'genehmigtBis'=>time()+60,'genehmigtMonotonBis'=>160000];
    $d = new RoboHubEurekaDock(fn()=>$o->k,fn()=>$o->f,function ($v) use ($o) { $o->f=$v; },
        fn($fn)=>$fn(),fn()=>$o->l,function ($v) use ($o) { $o->l=$v; });
    $f = $d->freigeben(60,RoboHubEurekaDock::BESTAETIGUNG)['freigabe'];
    return [$d,$f,$o];
}
foreach ([200,401,429,500,302,307,'timeout'] as $fall) {
    $tests['Dock im echten gemeinsamen HTTPS-Code ohne Retry: ' . $fall] = function () use ($fall): void {
        unset($GLOBALS['vorCurl']); $GLOBALS['execCalls']=$GLOBALS['closeCalls']=0; $GLOBALS['httpCalls']=[];
        [$d,$f,$o] = dockHttpFixture();
        $GLOBALS['status'] = is_int($fall) ? $fall : 0;
        $GLOBALS['response'] = $fall === 'timeout' ? false : '{"errorCode":0}';
        $GLOBALS['vorCurl'] = function () use ($o): void {
            sicher($o->f['zustand'] === 'verbraucht' && isset($o->f['transportBegonnenAm']) && $o->l['zustand'] === 'geschlossen');
        };
        if ($fall === 200) { sicher((new RoboHubEureka())->dockEinmal($d,$f)['angenommen']); }
        else { gesperrt(fn()=>(new RoboHubEureka())->dockEinmal($d,$f)); }
        gesperrt(fn()=>(new RoboHubEureka())->dockEinmal($d,$f));
        sicher($GLOBALS['execCalls'] === 1 && $GLOBALS['closeCalls'] === 1);
        $c=$GLOBALS['httpCalls'][0]; $v=$c['options'];
        sicher($c['url'] === 'https://euprod.mzrobo.com/devices/commands/offline-e20');
        sicher($v[CURLOPT_POSTFIELDS] === '{"data":"AA0DB80000000000000222010016"}' && $v[CURLOPT_CUSTOMREQUEST] === 'POST');
        sicher($v[CURLOPT_HTTP_VERSION] === CURL_HTTP_VERSION_1_1 && $v[CURLOPT_FRESH_CONNECT] && $v[CURLOPT_FORBID_REUSE]);
        sicher($v[CURLOPT_HTTPAUTH] === CURLAUTH_NONE && $v[CURLOPT_PROXYAUTH] === CURLAUTH_NONE && $v[CURLOPT_PROXY] === '');
        sicher(in_array('Expect:',$v[CURLOPT_HTTPHEADER],true) && $v[CURLOPT_FOLLOWLOCATION] === false && $v[CURLOPT_MAXREDIRS] === 0);
        sicher(!isset($v[CURLOPT_SSL_VERIFYPEER]) && !isset($v[CURLOPT_SSL_VERIFYHOST]));
        unset($GLOBALS['vorCurl']);
    };
}
foreach (['widerruf','zustand','ablauf','konfiguration'] as $fall) {
    $tests['Dock letzte Prüfung verhindert I/O: ' . $fall] = function () use ($fall): void {
        [$d,$f,$o] = dockHttpFixture();
        $GLOBALS['execCalls']=0; $GLOBALS['httpCalls']=[];
        $GLOBALS['beiOptionen'] = function () use ($d,$o,$fall): void {
            if ($fall === 'widerruf') { $d->widerrufen(); }
            if ($fall === 'zustand') { $o->k['geraet']['stand']['roh']['connected']=false; }
            if ($fall === 'ablauf') { $o->k['monoton']+=60000; }
        };
        if ($fall === 'konfiguration') { $GLOBALS['optionErgebnis']=false; }
        try {
            gesperrt(fn()=>(new RoboHubEureka())->dockEinmal($d,$f));
            gesperrt(fn()=>(new RoboHubEureka())->dockEinmal($d,$f));
            sicher($GLOBALS['execCalls'] === 0);
        } finally { unset($GLOBALS['beiOptionen'],$GLOBALS['optionErgebnis']); }
    };
}
$tests['Dock-Flag erlaubt weder Start/Pause noch B8 oder Versand ohne Berechtigung'] = function (): void {
    $h=new ReflectionMethod(RoboHubEureka::class,'httpAusfuehren'); $GLOBALS['httpCalls']=[];
    foreach ([RoboHubEurekaDiagnose::BEFEHL,RoboHubEurekaProtokoll::start(2,0,8),RoboHubEurekaProtokoll::pause(),RoboHubEurekaProtokoll::fortsetzen()] as $cmd) {
        gesperrt(fn()=>$h->invoke(new RoboHubEureka(),'POST','/devices/commands/offline-e20','offline',['data'=>$cmd],fn()=>null,true));
    }
    gesperrt(fn()=>$h->invoke(new RoboHubEureka(),'POST','/devices/commands/offline-e20','offline',['data'=>RoboHubEurekaDock::BEFEHL],null,true));
    gesperrt(fn()=>$h->invoke(new RoboHubEureka(),'POST','/devices/commands/offline-e20','offline',['data'=>RoboHubEurekaDock::BEFEHL],fn()=>null));
    $t=new ReflectionMethod(RoboHubEureka::class,'transport');
    gesperrt(fn()=>$t->invoke(new RoboHubEureka(),'POST','/devices/commands/offline-e20','offline',['data'=>RoboHubEurekaDock::BEFEHL]));
    sicher($GLOBALS['httpCalls'] === []);
};
$fehler = 0;
foreach ($tests as $name => $fn) {
    try { $fn(); echo 'OK: ' . $name . "\n"; }
    catch (Throwable $e) { $fehler++; echo 'FEHLER: ' . $name . "\n"; }
}
echo count($tests) . ' Tests, ' . $fehler . " Fehler\n";
exit($fehler === 0 ? 0 : 1);
