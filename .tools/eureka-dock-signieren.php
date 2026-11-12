<?php
declare(strict_types=1);

// Nur externer Administrationsrechner. Keine Dateipfade, Umgebungsschlüssel oder B8-Fallbacks.
require_once __DIR__ . '/../libs/RoboHubModell.php';
require_once __DIR__ . '/../libs/RoboHubEurekaDock.php';

$sk = null;
$code = 0;
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
        !hash_equals($a['genehmigung']['cloudGeraetId'], $a['cloudGeraetExternGeprueftUndGenehmigt']) ||
        ($a['genehmigung']['zweck'] ?? null) !== 'robohub/eureka/dock/runtime/v2' ||
        ($a['genehmigung']['ausnahmeVerbindungUnbekannt'] ?? null) !== true) {
        throw new RuntimeException();
    }
    // FD 3: separater Dock-Secret-Key; FD 4: unabhängig geprüfter Dock-Vertrauensanker.
    // Beide liefert ausschließlich die externe Dock-Schlüsselverwaltung.
    $lesen = static function (int $nummer): string {
        $fd = @fopen('php://fd/' . $nummer, 'r');
        if ($fd === false) { throw new RuntimeException(); }
        $text = stream_get_contents($fd, 129); fclose($fd);
        if ($text === false || strlen($text) > 128) { throw new RuntimeException(); }
        $bytes = base64_decode(trim($text), true);
        if ($bytes === false) { throw new RuntimeException(); }
        return $bytes;
    };
    $sk = $lesen(3);
    $pk = $lesen(4);
    if (strlen($sk) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES || strlen($pk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ||
        !hash_equals($pk, sodium_crypto_sign_publickey_from_secretkey($sk))) {
        throw new RuntimeException();
    }
    $roh = json_encode($a['genehmigung'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $d = json_encode(['nutzlast' => base64_encode($roh),
        'signatur' => base64_encode(sodium_crypto_sign_detached($roh, $sk))], JSON_THROW_ON_ERROR);
    RoboHubEurekaGenehmigung::dockPruefen($d, base64_encode($pk), $a['externGeprueftUndGenehmigt'], time());
    fwrite(STDOUT, $d . "\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Dock-Signierung verweigert: Eingabe, ausdrückliche Genehmigung oder externer Dock-Schlüssel ungültig.\n");
    $code = 1;
} finally {
    if (is_string($sk)) { sodium_memzero($sk); }
}

exit($code);
