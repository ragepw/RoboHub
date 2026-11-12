<?php
declare(strict_types=1);

// Eigene Prozesse sind nötig, um fehlende native Funktionen tatsächlich abzubilden.
if ($argc === 1) {
    $faelle = ['beideFehlen', 'adminFunktionFehlt', 'benutzerFunktionFehlt', 'unknown',
        'unknownMitAdmin', 'leer', 'leerzeichen', 'benutzerTyp', 'benutzerException',
        'keinAdmin', 'adminNameOhneRecht', 'adminTyp', 'adminException', 'admin', 'rollenAdmin',
        'adminUngueltigeSignatur', 'adminFalschesZiel', 'kontextWechsel', 'ohneVertrauensanker'];
    $fehler = 0;
    foreach ($faelle as $importFall) {
        $p = proc_open([PHP_BINARY, __FILE__, $importFall],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) { throw new RuntimeException('Offline-Unterprozess fehlt.'); }
        fclose($pipes[0]); $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $ok = proc_close($p) === 0 && $out === "OK\n" && $err === '';
        echo ($ok ? 'OK ' : 'FEHLER ') . 'Administrativer Moduleinstieg/' . $importFall . "\n";
        if (!$ok) { $fehler++; }
    }
    echo count($faelle) . ' Import-Tests, ' . $fehler . " Fehler\n";
    exit($fehler === 0 ? 0 : 1);
}

$importFall = $argv[1];
$GLOBALS['adminPruefungen'] = $GLOBALS['benutzerPruefungen'] = $GLOBALS['ankerPruefungen'] = 0;
if (!in_array($importFall, ['beideFehlen', 'adminFunktionFehlt'], true)) {
    function IPS_IsAdministrator()
    {
        $GLOBALS['adminPruefungen']++;
        return match ($GLOBALS['importFall']) {
            'keinAdmin', 'adminNameOhneRecht', 'unknown' => false,
            'adminTyp' => 1,
            'adminException' => throw new RuntimeException('Vertraulicher API-Fehler'),
            default => true,
        };
    }
}
if (!in_array($importFall, ['beideFehlen', 'benutzerFunktionFehlt'], true)) {
    function IPS_GetLoggedInUser()
    {
        $GLOBALS['benutzerPruefungen']++;
        return match ($GLOBALS['importFall']) {
            'unknown', 'unknownMitAdmin' => '@unknown',
            'leer' => '', 'leerzeichen' => ' ', 'benutzerTyp' => null,
            'benutzerException' => throw new RuntimeException('Vertraulicher API-Fehler'),
            'kontextWechsel' => $GLOBALS['benutzerPruefungen'] === 1 ? '@admin' : '@unknown',
            'rollenAdmin', 'keinAdmin' => 'offline-benutzer',
            default => '@admin',
        };
    }
}

define('ROBOHUB_DIAGNOSE_NUR_FIXTURE', true);
require __DIR__ . '/diagnostic-test.php';
$tests = [];
require __DIR__ . '/provisioning-cases.php';

// Nur die externe Dateisystemgrenze ersetzen. Keine Produktions-Testschalter,
// keine Schlüsseldateien auf dem Host; Signatur-/Kontextprüfung bleibt Originalcode.
function b8ImportOfflineVertrauensanker(): string
{
    $GLOBALS['ankerPruefungen']++;
    return $GLOBALS['importPublicKey'];
}
if ($importFall !== 'ohneVertrauensanker') {
    $source = file_get_contents(__DIR__ . '/../../RoboHubKonto/module.php');
    $source = str_replace('RoboHubEurekaGenehmigung::vertrauensanker()', 'b8ImportOfflineVertrauensanker()', $source, $anzahl);
    sicher($anzahl === 1);
    $source = str_replace(['<?php', '__DIR__', 'class RoboHubKonto extends IPSModule'],
        ['', var_export(realpath(__DIR__ . '/../../RoboHubKonto'), true), 'class RoboHubKontoImportOffline extends RoboHubKonto'], $source);
    eval($source);
}

[$k, $t, $d, $pk, $g, $sign] = provisionFixture();
$GLOBALS['importPublicKey'] = $pk;
if ($importFall !== 'ohneVertrauensanker') {
    $probe = new RoboHubKontoImportOffline(45174);
    $probe->attrs = $k->attrs; $probe->puffer = $k->puffer; $k = $probe;
}
if ($importFall === 'adminUngueltigeSignatur') {
    $doc = $sign($g); $h = json_decode($doc, true);
    $h['signatur'] = base64_encode(random_bytes(64)); $doc = json_encode($h);
} else {
    if ($importFall === 'adminFalschesZiel') { $g['cloudGeraetId'] .= '-anderes'; }
    $doc = $sign($g);
}
if (in_array($importFall, ['admin', 'rollenAdmin'], true)) {
    $r = $k->EurekaB8Bereitstellen($doc);
    sicher(str_contains($r, 'bereitgestellt'));
    sicher($GLOBALS['ankerPruefungen'] === 1 && $GLOBALS['adminPruefungen'] === 2);
    sicher($t->calls === []);
    $ticket = frei($k); $k->EurekaB8Testen($ticket);
    gesperrt(fn() => $k->EurekaB8Testen($ticket));
    gesperrt(fn() => $k->EurekaB8Bereitstellen($doc));
    sicher(count($t->calls) === 1);
} else {
    try { $k->EurekaB8Bereitstellen($doc); throw new RuntimeException('Importsperre fehlt.'); }
    catch (RoboHubFehler $e) {
        $signaturFehler = in_array($importFall, ['adminUngueltigeSignatur', 'adminFalschesZiel'], true);
        if (!$signaturFehler && $importFall !== 'ohneVertrauensanker') {
            sicher($e->getMessage() === 'Eureka: Import erfordert einen nativ bestätigten angemeldeten Administrator.');
            sicher($GLOBALS['ankerPruefungen'] === 0);
        }
        if ($signaturFehler) { sicher($GLOBALS['ankerPruefungen'] === 1); }
        sicher(!str_contains($e->getMessage(), $g['cloudGeraetId']));
    }
    sicher(!isset($k->puffer['EurekaB8Laufzeitfreigabe']) && $t->calls === []);
    sicher($GLOBALS['locks'] === []);
    // Vorprüfung bleibt erhalten; Laufzeitanzeige verlangt native Administratorrechte.
    $k->EurekaB8Vorpruefen(false);
    if (in_array($importFall, ['adminUngueltigeSignatur', 'adminFalschesZiel', 'ohneVertrauensanker'], true)) {
        $k->EurekaB8LaufzeitAnzeigen();
    } else {
        gesperrt(fn() => $k->EurekaB8LaufzeitAnzeigen());
    }
}
sicher($k->logs === [] && $GLOBALS['locks'] === []);
echo "OK\n";
