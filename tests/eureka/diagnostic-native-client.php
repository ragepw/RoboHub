<?php
declare(strict_types=1);

// Nur vom netzisolierten Python-Prüfstand mit ephemerer CA und Loopback-Port starten.
namespace EurekaOffline;
if (!getenv('ROBOHUB_TEST_OUTER_NETNS') || readlink('/proc/self/ns/net') === getenv('ROBOHUB_TEST_OUTER_NETNS')) {
    throw new \RuntimeException('Isolierter Netzwerknamespace erforderlich.');
}
require_once __DIR__ . '/../../libs/RoboHubEureka.php';
require_once __DIR__ . '/e20-fixture.php';

function curl_init($url)
{
    if ($url !== 'https://euprod.mzrobo.com/devices/commands/offline-e20') {
        throw new \RuntimeException('Unerwartetes Offline-Ziel.');
    }
    $ch = \curl_init($url);
    // Noch vor den Produktivoptionen zwingend lokal umleiten; Host/SNI/TLS prüfen.
    if (!\curl_setopt_array($ch, [CURLOPT_CONNECT_TO => ['euprod.mzrobo.com:443:127.0.0.1:' . $GLOBALS['port']],
        CURLOPT_CAINFO => $GLOBALS['ca'], CURLOPT_PROXY => ''])) {
        throw new \RuntimeException('Offline-Umleitung fehlgeschlagen.');
    }
    if (str_contains($GLOBALS['mode'], 'reused')) {
        \curl_setopt_array($ch, [CURLOPT_URL => 'https://euprod.mzrobo.com/prime', CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1]);
        if (\curl_exec($ch) !== 'prime') { throw new \RuntimeException('Vorverbindung fehlgeschlagen.'); }
        \curl_setopt($ch, CURLOPT_URL, $url);
    }
    return $ch;
}
function curl_setopt_array($ch, $options)
{
    $mode = $GLOBALS['mode'];
    if (str_starts_with($mode, 'control-')) {
        foreach ([CURLOPT_HTTP_VERSION, CURLOPT_FRESH_CONNECT, CURLOPT_FORBID_REUSE, CURLOPT_HTTPAUTH,
            CURLOPT_PROXYAUTH, CURLOPT_PROXY] as $key) { unset($options[$key]); }
        $options[CURLOPT_HTTPHEADER] = array_values(array_filter($options[CURLOPT_HTTPHEADER], fn($h) => $h !== 'Expect:'));
        if ($mode !== 'control-h2') { $options[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_1_1; }
        if (str_starts_with($mode, 'control-expect')) { $options[CURLOPT_HTTPHEADER][] = 'Expect: 100-continue'; }
        if ($mode === 'control-expect') { $options[CURLOPT_MAXREDIRS] = 50; }
    }
    if ($mode === 'setopt-false') {
        // Native Teilkonfiguration: erste Option gültig, zweite wird abgewiesen.
        // Kein reines false-Double; der Rückgabewert stammt aus echter libcurl.
        return \curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_HTTP_VERSION => 999]);
    }
    return \curl_setopt_array($ch, $options);
}
function curl_exec($ch)
{
    $GLOBALS['execs']++;
    if (($GLOBALS['state']['zustand'] ?? '') !== 'verbraucht' || !isset($GLOBALS['state']['transportBegonnenAm'])) {
        throw new \RuntimeException('Verbrauch vor I/O fehlt.');
    }
    $response = \curl_exec($ch);
    $GLOBALS['httpVersion'] = \curl_getinfo($ch, CURLINFO_HTTP_VERSION);
    $GLOBALS['errno'] = \curl_errno($ch);
    $GLOBALS['httpStatus'] = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return $response;
}
function curl_getinfo($ch, $key) { return \curl_getinfo($ch, $key); }
function curl_close($ch) { $GLOBALS['closes']++; \curl_close($ch); }

// Dieselbe Quelldatei, nur Funktionsauflösung in diesen Namespace verschieben.
// Keine alternative Transportimplementierung; nur die cURL-Systemgrenze instrumentieren.
$source = file_get_contents(__DIR__ . '/../../libs/RoboHubEureka.php');
$source = str_replace(['<?php', 'declare(strict_types=1);', '__DIR__'],
    ['', '', var_export(realpath(__DIR__ . '/../../libs'), true)], $source);
preg_match_all('/\bRoboHub[A-Za-z]+\b/', $source, $matches);
$imports = '';
foreach (array_unique($matches[0]) as $class) {
    if ($class !== 'RoboHubEureka') { $imports .= 'use \\' . $class . ';'; }
}
eval('namespace EurekaOffline;' . $imports . $source);

[$GLOBALS['port'], $GLOBALS['ca'], $GLOBALS['mode']] = array_slice($argv, 1);
$GLOBALS['execs'] = $GLOBALS['closes'] = $GLOBALS['consumedWrites'] = $GLOBALS['httpVersion'] = $GLOBALS['errno'] = 0;
$GLOBALS['state'] = [];
$g = \e20Fixture()['geraet']; $g['stand']['zeit'] = $g['verbindungEmpfangen'] = time();
$k = ['konto' => 45174, 'instanz' => 22708, 'hersteller' => 'eureka', 'region' => 'eu',
    'jetzt' => time(), 'monoton' => 100000, 'boot' => 'isolated-native-start', 'revision' => 'offline-session',
    'warteBis' => 0, 'kontoname' => 'offline@example.invalid', 'cacheZeit' => time(), 'geraet' => $g,
    'buendel' => ['region' => 'eu', 'access_token' => 'e30.' . base64_encode(json_encode(['exp' => time() + 3600])) . '.offline']];
$GLOBALS['runtime'] = ['erwartet' => $k['boot'], 'zustand' => 'genehmigt'];
$d = new \RoboHubEurekaDiagnose(fn() => $k, fn() => $GLOBALS['state'], function ($f): void {
    if (($f['zustand'] ?? '') === 'verbraucht' && !isset($f['transportBegonnenAm'])) { $GLOBALS['consumedWrites']++; }
    $GLOBALS['state'] = $f;
}, fn(callable $fn) => $fn(), fn() => $GLOBALS['runtime'],
    function (array $v): void { $GLOBALS['runtime'] = $v; });
$f = $d->freigeben(60, \RoboHubEurekaDiagnose::BESTAETIGUNG)['freigabe'];
$t = new RoboHubEureka();
$outcome = 'response';
try { $t->diagnoseB8($d, $f); } catch (\RoboHubFehler $e) { $outcome = 'unconfirmed'; }
$secondBlocked = $newBlocked = false;
try { $t->diagnoseB8($d, $f); } catch (\RoboHubFehler $e) { $secondBlocked = true; }
try { $d->freigeben(60, \RoboHubEurekaDiagnose::BESTAETIGUNG); } catch (\RoboHubFehler $e) { $newBlocked = true; }
echo json_encode(['execs' => $GLOBALS['execs'], 'closes' => $GLOBALS['closes'], 'consumedWrites' => $GLOBALS['consumedWrites'],
    'httpVersion' => $GLOBALS['httpVersion'], 'errno' => $GLOBALS['errno'], 'status' => $GLOBALS['httpStatus'] ?? 0,
    'transportMarked' => isset($GLOBALS['state']['transportBegonnenAm']),
    'outcome' => $outcome, 'secondBlocked' => $secondBlocked, 'newBlocked' => $newBlocked,
    'libcurl' => \curl_version()['version'], 'tls' => \curl_version()['ssl_version']]) . "\n";
