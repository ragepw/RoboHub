<?php
declare(strict_types=1);

// Ausschließlich synthetische Fixtures und anonyme Pipes; keine Schlüsseldateien.
define('ROBOHUB_DIAGNOSE_NUR_FIXTURE', true);
require __DIR__ . '/diagnostic-test.php';
$tests = [];
require __DIR__ . '/provisioning-cases.php';
$tests = [];

function signerAufruf(string $json, ?string $key): array
{
    $fds = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    if ($key !== null) { $fds[3] = ['pipe', 'r']; }
    $p = proc_open([PHP_BINARY, __DIR__ . '/../../.tools/eureka-b8-signieren.php'], $fds, $pipes);
    sicher(is_resource($p));
    fwrite($pipes[0], $json); fclose($pipes[0]);
    if ($key !== null) { fwrite($pipes[3], $key); fclose($pipes[3]); }
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return [proc_close($p), $out, $err];
}

[$k, $t, $d, , $g] = provisionFixture();
$pair = sodium_crypto_sign_keypair();
$sk = sodium_crypto_sign_secretkey($pair);
$pk = base64_encode(sodium_crypto_sign_publickey($pair));
$a = ['genehmigung' => $g, 'externGeprueftUndGenehmigt' => $g['erwartet'],
    'cloudGeraetExternGeprueftUndGenehmigt' => $g['cloudGeraetId']];
$json = json_encode($a, JSON_THROW_ON_ERROR);
$key = base64_encode($sk);
$tests['gültiges JSON, Ed25519 und unveränderte Nutzlast'] = function () use ($json, $key, $pk, $g, $d, $k, $t): void {
    [$code, $out, $err] = signerAufruf($json, $key);
    sicher($code === 0 && $err === '' && !str_contains($out, $key));
    $h = json_decode($out, true, 8, JSON_THROW_ON_ERROR);
    sicher(array_keys($h) === ['nutzlast', 'signatur']);
    sicher(sodium_crypto_sign_verify_detached(base64_decode($h['signatur']), base64_decode($h['nutzlast']), base64_decode($pk)));
    sicher(RoboHubEurekaGenehmigung::pruefen(trim($out), $pk, $g['erwartet'], time()) === $g);
    foreach (['', 'invalid', base64_encode(random_bytes(31)), base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()))] as $bad) {
        gesperrt(fn() => RoboHubEurekaGenehmigung::pruefen(trim($out), $bad, $g['erwartet'], time()));
    }
    gesperrt(fn() => RoboHubEurekaGenehmigung::pruefen(trim($out), $pk, '1:' . str_repeat('b', 64), time()));
    gesperrt(fn() => RoboHubEurekaGenehmigung::pruefen(trim($out), $pk, $g['erwartet'], $g['bis']));
    foreach (['nutzlast', 'signatur'] as $feld) {
        $bad = $h; $raw = base64_decode($bad[$feld]); $raw[0] = chr(ord($raw[0]) ^ 1);
        $bad[$feld] = base64_encode($raw);
        gesperrt(fn() => RoboHubEurekaGenehmigung::pruefen(json_encode($bad), $pk, $g['erwartet'], time()));
    }
    $d->bereitstellen(trim($out), $pk);
    sicher(json_decode($k->puffer['EurekaB8Laufzeitfreigabe'], true)['cloudGeraetId'] === $g['cloudGeraetId']);
    sicher($t->calls === [] && $k->logs === []);
};
$badJson = ['{', 'null', '[]', '"text"', str_repeat(' ', 4097), json_encode(array_reverse($a, true)), json_encode($a + ['extra' => true])];
foreach ($badJson as $i => $bad) {
    $tests['JSON-Ablehnung/' . $i] = fn() => signerVerweigert($bad, $key);
}
foreach ([null, '', '***', base64_encode(random_bytes(32)), base64_encode(random_bytes(63))] as $i => $bad) {
    $tests['Schlüssel-Ablehnung/' . $i] = fn() => signerVerweigert($json, $bad);
}
foreach (['abgelaufen', 'laufzeit', 'gerät'] as $fall) {
    $bad = $a;
    if ($fall === 'abgelaufen') { $bad['genehmigung']['von'] = time() - 100; $bad['genehmigung']['bis'] = time() - 1; }
    if ($fall === 'laufzeit') { $bad['externGeprueftUndGenehmigt'] = '1:' . str_repeat('b', 64); }
    if ($fall === 'gerät') { $bad['cloudGeraetExternGeprueftUndGenehmigt'] = 'offline-other'; }
    $tests['Inhalt-Ablehnung/' . $fall] = fn() => signerVerweigert(json_encode($bad), $key);
}
function signerVerweigert(string $json, ?string $key): void
{
    [$code, $out, $err] = signerAufruf($json, $key);
    sicher($code === 1 && $out === '');
    // Exakte generische Meldung: weder Schlüssel noch Genehmigungsdaten im Fehlerkanal.
    sicher($err === "Signierung verweigert: Eingabe, ausdrückliche Genehmigung oder externer Schlüssel ungültig.\n");
}
$fehler = 0;
foreach ($tests as $name => $fn) {
    try { $fn(); echo 'OK Signierer/' . $name . "\n"; }
    catch (Throwable $e) { $fehler++; echo 'FEHLER Signierer/' . $name . "\n"; }
}
sodium_memzero($sk); sodium_memzero($key); sodium_memzero($pair);
echo count($tests) . ' Signierer-Tests, ' . $fehler . " Fehler\n";
exit($fehler === 0 ? 0 : 1);
