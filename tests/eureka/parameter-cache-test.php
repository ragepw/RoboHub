<?php
declare(strict_types=1);

// Natives Skript mit ausschließlich lokalen IPS-Lesedoubles ausführen.
function IPS_GetInstance(int $id): array
{
    $GLOBALS['reads'][] = 'instance';
    return ['ModuleInfo' => ['ModuleID' => $id === 45174
        ? '{DB269BEC-0CA6-4E6A-83B8-78FF8CFB2DB0}' : '{631DA85B-5985-4C25-B222-E47FDD49E302}'],
        'ConnectionID' => $GLOBALS['connection'] ?? 45174];
}
function IPS_GetProperty(int $id, string $field): string
{
    $GLOBALS['reads'][] = 'property';
    return ['Hersteller' => 'eureka', 'Region' => 'eu', 'GeraetID' => 'secret-device'][$field];
}
function IPS_GetSnapshot(): string
{
    $GLOBALS['reads'][] = 'snapshot';
    if ($GLOBALS['failure'] ?? false) { trigger_error('secret-error'); }
    return $GLOBALS['snapshot'];
}
function diagnosticRun(object $raw, string $age = 'frisch'): array
{
    $GLOBALS['reads'] = [];
    $stand = ['deviceId' => 'secret-device', 'zeit' => time() - ($age === 'alt' ? 121 : 0),
        'statusquelle' => ['quelle' => 'shadow-get'], 'roh' => $raw];
    $GLOBALS['snapshot'] = json_encode(['objects' => ['ID45174' => ['data' => ['attributes' => [
        'Buendel' => 'secret-token', 'Schnappschuss' => json_encode(['secret-device' => ['p' => $stand]], JSON_PRESERVE_ZERO_FRACTION)]]]]]);
    ob_start();
    try { include __DIR__ . '/../../.tools/eureka-parameter-lesediagnose.php'; }
    finally { $output = ob_get_clean(); }
    if (str_contains($output, 'secret-')) { throw new RuntimeException('Vertrauliche Ausgabe'); }
    return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
}
function diagnosticAssert(bool $ok): void
{
    if (!$ok) { throw new RuntimeException('Diagnosegegenprobe fehlgeschlagen'); }
    $GLOBALS['checks'] = ($GLOBALS['checks'] ?? 0) + 1;
}

function IPS_GetObjectIDByIdent(string $ident, int $id): int { return array_search($ident,
    [101 => 'EurekaReinigungsart', 102 => 'EurekaStartSaugstufe', 103 => 'EurekaStartWasserstufe', 104 => 'EurekaTeppichmodus'], true); }
function GetValue(int $id): mixed { return $GLOBALS['selection'][$id] ?? null; }
$GLOBALS['selection'] = [101 => 0, 102 => 3, 103 => 0, 104 => 1];
$r = diagnosticRun((object)['fanLevel' => '3', 'waterLevel' => '2', 'autoType' => 'room_cleaning']);
diagnosticAssert($r['vergleich'] === ['fan' => true, 'water' => false, 'mode' => null, 'teppichmodus' => null]);
diagnosticAssert($r['lokaleAuswahl']['EurekaStartWasserstufe'] === 0 && $r['letzteHerstellerRueckmeldung']['waterLevel'] === 2);
diagnosticAssert($r['zuletztGesendeteParameter'] === null && $r['bestaetigterAktuellerIstwert'] === null);
foreach ([[], ['fanLevel' => true, 'waterLevel' => 'secret-water', 'autoType' => 'secret-mode'],
    ['fanLevel' => '03', 'waterLevel' => 2.0], ['fanLevel' => (object)[]]] as $raw) {
    $r = diagnosticRun((object)$raw, 'alt');
    diagnosticAssert($r['vergleich']['fan'] === null && $r['vergleich']['water'] === null && $r['frische'] === 'lokal-aelter-120s');
}
$GLOBALS['selection'] = [102 => '3', 103 => 99];
$r = diagnosticRun((object)['fanLevel' => 3, 'waterLevel' => 0]);
diagnosticAssert($r['vergleich']['fan'] === null && $r['vergleich']['water'] === null);
$GLOBALS['connection'] = 123;
diagnosticAssert(isset(diagnosticRun((object)[])['fehler']));
$source = file_get_contents(__DIR__ . '/../../.tools/eureka-parameter-lesediagnose.php');
preg_match_all('/\b(IPS_[A-Za-z0-9_]+|GetValue|ROBO[A-Za-z0-9_]+|curl_[a-z_]+)\s*\(/', $source, $calls);
diagnosticAssert(array_diff($calls[1], ['IPS_GetInstance', 'IPS_GetProperty', 'IPS_GetSnapshot', 'IPS_GetObjectIDByIdent', 'GetValue']) === []);
echo ($GLOBALS['checks'] ?? 0) . " Parametercache-Prüfungen, 0 Fehler\n";
