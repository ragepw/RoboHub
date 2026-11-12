<?php
declare(strict_types=1);

// Ausschließlich auf separatem Administrationsrechner verwenden. Keine Schlüsseldatei
// öffnen, kein Netzwerk, keine automatische Ermittlung einer Symcon-Laufzeit.
require_once __DIR__ . '/../libs/RoboHubModell.php';
require_once __DIR__ . '/../libs/RoboHubEurekaDiagnose.php';

try {
    if (PHP_SAPI !== 'cli' || $argc !== 1 || !function_exists('sodium_crypto_sign_detached')) {
        throw new RuntimeException();
    }
    $eingabe = stream_get_contents(STDIN, 4097);
    if (strlen($eingabe) > 4096) { throw new RuntimeException(); }
    $a = json_decode($eingabe, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($a) || array_keys($a) !== ['genehmigung', 'externGeprueftUndGenehmigt', 'cloudGeraetExternGeprueftUndGenehmigt'] ||
        !is_string($a['externGeprueftUndGenehmigt']) ||
        !is_string($a['genehmigung']['erwartet'] ?? null) ||
        !hash_equals($a['genehmigung']['erwartet'], $a['externGeprueftUndGenehmigt']) ||
        !is_string($a['cloudGeraetExternGeprueftUndGenehmigt']) ||
        !is_string($a['genehmigung']['cloudGeraetId'] ?? null) ||
        !hash_equals($a['genehmigung']['cloudGeraetId'], $a['cloudGeraetExternGeprueftUndGenehmigt'])) {
        throw new RuntimeException();
    }
    // FD 3 muss von der externen Schlüsselverwaltung bereitgestellt werden.
    $fd = @fopen('php://fd/3', 'r');
    if ($fd === false) { throw new RuntimeException(); }
    $sk = base64_decode(trim(stream_get_contents($fd, 129)), true);
    fclose($fd);
    if ($sk === false || strlen($sk) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) { throw new RuntimeException(); }
    $pk = sodium_crypto_sign_publickey_from_secretkey($sk);
    $roh = json_encode($a['genehmigung'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $d = json_encode(['nutzlast' => base64_encode($roh),
        'signatur' => base64_encode(sodium_crypto_sign_detached($roh, $sk))], JSON_THROW_ON_ERROR);
    sodium_memzero($sk);
    RoboHubEurekaGenehmigung::pruefen($d, base64_encode($pk), $a['externGeprueftUndGenehmigt'], time());
    fwrite(STDOUT, $d . "\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Signierung verweigert: Eingabe, ausdrückliche Genehmigung oder externer Schlüssel ungültig.\n");
    exit(1);
}
