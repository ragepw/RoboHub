<?php
declare(strict_types=1);

// Native Dateisystemprüfungen, ausschließlich in wegwerfbaren Namespaces.
if ($argc === 1) {
    $faelle = ['gueltig', 'falscherEigentuemer', 'gruppenSchreibbar', 'weltSchreibbar',
        'symlink', 'verzeichnisSymlink', 'verzeichnisSchreibbar', 'etcSchreibbar',
        'dateityp', 'fehlt', 'ungueltigeSignatur', 'keinAdmin', 'falschesCloudGeraet',
        'neustart', 'rollback', 'widerruf', 'falschesKonto', 'falscheLaufzeit', 'defekterSchluessel'];
    $fehler = 0;
    $pruefungen = [];
    foreach (['posixRoot', 'ohnePosix'] as $modus) {
        foreach ($faelle as $fall) { $pruefungen[] = [$modus, $fall]; }
    }
    $pruefungen[] = ['posixNichtRoot', 'schreibzugriff'];
    foreach ($pruefungen as [$modus, $fall]) {
        $cmd = ['bwrap', '--unshare-net', '--unshare-user', '--uid', '0', '--gid', '0',
            '--dev-bind', '/', '/', '--tmpfs', '/etc', '--ro-bind', '/etc/php', '/etc/php',
            '--dir', '/etc/robohub'];
        // Ein echter fremder Eigentümer (nicht in den Usernamespace abgebildete Host-UID).
        if ($fall === 'falscherEigentuemer') {
            array_push($cmd, '--ro-bind', '/etc/passwd', '/etc/robohub/eureka-b8.pub');
        }
        array_push($cmd, '--', PHP_BINARY);
        if ($modus === 'ohnePosix') { array_push($cmd, '-n', '-d', 'extension=ctype'); }
        if ($modus === 'posixNichtRoot') { array_push($cmd, '-d', 'disable_functions=posix_geteuid'); }
        array_push($cmd, __FILE__, $fall, $modus);
        $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) { throw new RuntimeException('Root-Testnamespace fehlt.'); }
        fclose($pipes[0]); $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $ok = proc_close($p) === 0 && $out === "OK\n" && $err === '';
        echo ($ok ? 'OK ' : 'FEHLER ') . 'B8-Import/' . $modus . '/' . $fall . "\n";
        if (!$ok) { $fehler++; echo $out, $err; }
    }
    echo count($pruefungen) . ' B8-Dateisystem-Import-Tests, ' . $fehler . " Fehler\n";
    exit($fehler === 0 ? 0 : 1);
}

// Vor jedem Schreibzugriff: kein Host-root, isoliertes Netz und separates tmpfs-/etc.
$uidMap = trim(file_get_contents('/proc/self/uid_map'));
$outer = getenv('ROBOHUB_TEST_OUTER_NETNS');
$modus = $argv[2];
if ($modus === 'posixRoot' && (!extension_loaded('posix') || !function_exists('posix_geteuid'))) {
    throw new RuntimeException('Native POSIX-EUID-Prüfung erforderlich.');
}
if ((function_exists('posix_geteuid') && posix_geteuid() !== 0) || !preg_match('/\A0\s+[1-9][0-9]*\s+1\z/', $uidMap) ||
    !$outer || readlink('/proc/self/ns/net') === $outer ||
    !preg_match('~ /etc [^\n]* - tmpfs ~', file_get_contents('/proc/self/mountinfo'))) {
    throw new RuntimeException('Isolierter Root-Prüfstand erforderlich.');
}
if ($modus === 'ohnePosix' && (extension_loaded('posix') || function_exists('posix_geteuid'))) {
    throw new RuntimeException('Prüfstand muss tatsächlich ohne POSIX laufen.');
}
if ($modus === 'posixNichtRoot') {
    // Nur die EUID-Grenze simulieren: native is_writable-/lstat-Prüfungen bleiben
    // erhalten. Das schreibbare root-eigene Fixture muss am zusätzlichen Veto scheitern.
    if (function_exists('posix_geteuid')) { throw new RuntimeException('Native EUID-Funktion muss deaktiviert sein.'); }
    function posix_geteuid(): int { return 1000; }
}
function IPS_IsAdministrator(): bool { return $GLOBALS['anzeigeAdmin'] ?? true; }
function IPS_GetLoggedInUser(): string { return '@admin'; }
define('ROBOHUB_DIAGNOSE_NUR_FIXTURE', true);
require __DIR__ . '/diagnostic-test.php';
$tests = [];
require __DIR__ . '/provisioning-cases.php';
[$k, $t, $d, $pk, $g, $sign] = provisionFixture();
$fall = $argv[1];
$pfad = '/etc/robohub/eureka-b8.pub';
chmod('/etc', 0755); chmod('/etc/robohub', 0755);
if ($fall === 'falscherEigentuemer') {
    sicher(lstat($pfad)['uid'] !== 0);
} else {
    file_put_contents($pfad, $pk . "\n"); chmod($pfad, 0644);
    sicher(lstat($pfad)['uid'] === 0);
}
if ($fall === 'gruppenSchreibbar') { chmod($pfad, 0664); }
if ($fall === 'weltSchreibbar') { chmod($pfad, 0646); }
if ($fall === 'verzeichnisSchreibbar') { chmod('/etc/robohub', 0775); }
if ($fall === 'etcSchreibbar') { chmod('/etc', 0777); }
if ($fall === 'symlink') { rename($pfad, $pfad . '.real'); symlink($pfad . '.real', $pfad); }
if ($fall === 'verzeichnisSymlink') { rename('/etc/robohub', '/etc/robohub.real'); symlink('/etc/robohub.real', '/etc/robohub'); }
if (in_array($fall, ['dateityp', 'fehlt'], true)) { unlink($pfad); }
if ($fall === 'dateityp') { mkdir($pfad); }
if ($fall === 'defekterSchluessel') { file_put_contents($pfad, 'kein-public-key'); }
if ($fall === 'falschesKonto') { $g['konto']++; }
if ($fall === 'falscheLaufzeit') { $g['erwartet'] = $GLOBALS['boot'] . ':' . str_repeat('b', 64); }
if ($fall === 'keinAdmin') { $GLOBALS['anzeigeAdmin'] = false; }
if ($fall === 'falschesCloudGeraet') { $g['cloudGeraetId'] .= '-anderes'; }
$doc = $sign($g);
if ($fall === 'ungueltigeSignatur') {
    $h = json_decode($doc, true); $h['signatur'] = base64_encode(random_bytes(64)); $doc = json_encode($h);
}
if (in_array($fall, ['gueltig', 'neustart', 'rollback', 'widerruf'], true)) {
    sicher(RoboHubEurekaGenehmigung::vertrauensanker() === $pk . "\n");
    sicher(str_contains($k->EurekaB8Bereitstellen($doc), 'bereitgestellt'));
    foreach (['start', 'pause', 'station'] as $befehl) {
        $r = json_decode($k->ForwardData(json_encode(['Aktion' => 'befehl',
            'Geraet' => 'offline-e20', 'Befehl' => $befehl])), true);
        sicher($r['ok'] === false);
    }
    sicher($t->calls === []);
    // Schon vor Ticketausstellung muss der zweite Import scheitern.
    gesperrt(fn() => $k->EurekaB8Bereitstellen($doc));
    if ($fall === 'neustart') {
        $k->puffer = []; $k->attrs['EurekaB8Diagnose'] = '{}';
        gesperrt(fn() => $k->EurekaB8Bereitstellen($doc)); gesperrt(fn() => frei($k));
    } elseif ($fall === 'widerruf') {
        $k->EurekaB8Widerrufen(); $k->attrs['EurekaB8Diagnose'] = '{}';
        gesperrt(fn() => $k->EurekaB8Bereitstellen($doc)); gesperrt(fn() => frei($k));
    } else {
        $ticket = frei($k); $k->EurekaB8Testen($ticket);
        if ($fall === 'rollback') { $k->attrs['EurekaB8Diagnose'] = '{}'; }
        gesperrt(fn() => $k->EurekaB8Testen($ticket));
        gesperrt(fn() => $k->EurekaB8Bereitstellen($doc)); gesperrt(fn() => frei($k));
        sicher(count($t->calls) === 1);
    }
} else {
    if (in_array($fall, ['keinAdmin', 'ungueltigeSignatur', 'falschesCloudGeraet', 'falschesKonto', 'falscheLaufzeit'], true)) {
        sicher(RoboHubEurekaGenehmigung::vertrauensanker() === $pk . "\n");
    }
    gesperrt(fn() => $k->EurekaB8Bereitstellen($doc));
    sicher(!isset($k->puffer['EurekaB8Laufzeitfreigabe']) && $t->calls === []);
    if (!in_array($fall, ['keinAdmin', 'ungueltigeSignatur', 'falschesCloudGeraet', 'falschesKonto', 'falscheLaufzeit', 'defekterSchluessel'], true)) {
        gesperrt(fn() => RoboHubEurekaGenehmigung::vertrauensanker());
    }
}
sicher($k->logs === [] && $GLOBALS['locks'] === []);
echo "OK\n";
