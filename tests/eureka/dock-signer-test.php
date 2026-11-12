<?php
declare(strict_types=1);

// Nur synthetische Ziele; flüchtige Testschlüssel im RAM und anonymen Pipes.
// Native Ed25519-Prüfung, echter Moduleinstieg; allein die externe Ankerdatei ist ersetzt.
define('ROBOHUB_DIAGNOSE_NUR_FIXTURE', true);
function IPS_IsAdministrator() { return $GLOBALS['testAdmin'] ?? true; }
function IPS_GetLoggedInUser() { return $GLOBALS['testUser'] ?? 'offline-admin'; }
require __DIR__ . '/diagnostic-test.php';
function dockTestAnker(): string { return $GLOBALS['dockTestPk']; }
$source = file_get_contents(__DIR__ . '/../../RoboHubKonto/module.php');
$source = str_replace('RoboHubEurekaGenehmigung::dockVertrauensanker()', 'dockTestAnker()', $source, $anzahl);
sicher($anzahl === 1);
$source = str_replace(['<?php', '__DIR__', 'class RoboHubKonto extends IPSModule'],
    ['', var_export(realpath(__DIR__ . '/../../RoboHubKonto'), true), 'class DockImportTest extends RoboHubKonto'], $source);
eval($source);

function dockImportFixture(): array
{
    [$basis, $t] = konto();
    $k = new DockImportTest(45174); $k->attrs = $basis->attrs; $k->puffer = $basis->puffer;
    $k->attrs['EurekaDockVersuch'] = '{}';
    shadowAendern($k, function (&$s): void {
        $s['statusquelle'] = ['quelle'=>'shadow-get', 'geraetezeit'=>null];
        unset($s['roh']['connected']); $s['online'] = false;
    });
    $k->EurekaDockVorpruefen();
    $now = time();
    $g = ['zweck'=>'robohub/eureka/dock/runtime/v2', 'konto'=>45174, 'instanz'=>22708,
        'cloudGeraetId'=>'offline-e20', 'modell'=>'E20 Evo Plus', 'befehl'=>RoboHubEurekaDock::BEFEHL,
        'erwartet'=>$k->EurekaDockLaufzeitAnzeigen(), 'von'=>$now, 'bis'=>$now+60,
        'ausnahmeVerbindungUnbekannt'=>true];
    return [$k, $t, ['genehmigung'=>$g, 'externGeprueftUndGenehmigt'=>$g['erwartet'],
        'cloudGeraetExternGeprueftUndGenehmigt'=>$g['cloudGeraetId']]];
}
function dockSigner(string $json, ?string $sk, ?string $pk): array
{
    $fds = [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']];
    if ($sk !== null) { $fds[3] = ['pipe','r']; }
    if ($pk !== null) { $fds[4] = ['pipe','r']; }
    $p = proc_open([PHP_BINARY, __DIR__.'/../../.tools/eureka-dock-signieren.php'], $fds, $pipes);
    sicher(is_resource($p));
    foreach ([0=>$json, 3=>$sk, 4=>$pk] as $fd=>$value) {
        if (isset($pipes[$fd])) { fwrite($pipes[$fd], $value); fclose($pipes[$fd]); }
    }
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return [proc_close($p), $out, $err];
}
function dockTestSign(array $g, string $sk): string
{
    $raw = json_encode($g, JSON_THROW_ON_ERROR);
    return json_encode(['nutzlast'=>base64_encode($raw), 'signatur'=>base64_encode(sodium_crypto_sign_detached($raw,$sk))]);
}
$pair = sodium_crypto_sign_keypair();
$sk = sodium_crypto_sign_secretkey($pair); $pk = base64_encode(sodium_crypto_sign_publickey($pair));
$key = base64_encode($sk); $GLOBALS['dockTestPk'] = $pk;
$tests = [];
$tests['CLI, native Signatur, einmaliger Modulimport und Ticket ohne Transport'] = function () use ($key,$pk): void {
    [$k,$t,$a] = dockImportFixture();
    [$code,$doc,$err] = dockSigner(json_encode($a),$key,$pk);
    sicher($code === 0 && $err === '' && !str_contains($doc,$key));
    sicher(RoboHubEurekaGenehmigung::dockPruefen(trim($doc),$pk,$a['genehmigung']['erwartet'],time()) === $a['genehmigung']);
    $b8 = $k->puffer['EurekaB8Laufzeitfreigabe'];
    sicher(str_contains($k->EurekaDockBereitstellen(trim($doc)), 'bereitgestellt'));
    gesperrt(fn()=>$k->EurekaDockBereitstellen(trim($doc)));
    $ticket = json_decode($k->EurekaDockFreigeben(60,RoboHubEurekaDock::BESTAETIGUNG,RoboHubEurekaDock::VOR_ORT_BESTAETIGUNG),true);
    sicher($ticket['ausnahmeVerbindungUnbekannt'] === true && $ticket['gueltigBis'] <= $a['genehmigung']['bis']);
    gesperrt(fn()=>$k->EurekaDockFreigeben(60,RoboHubEurekaDock::BESTAETIGUNG,RoboHubEurekaDock::VOR_ORT_BESTAETIGUNG));
    sicher($k->puffer['EurekaB8Laufzeitfreigabe'] === $b8 && $t->calls === [] && $k->logs === []);
};
$mutationen = [
    'B8-Zweck'=>fn(&$g)=>$g['zweck']=RoboHubEurekaGenehmigung::ZWECK,
    'B8-Befehl'=>fn(&$g)=>$g['befehl']=RoboHubEurekaDiagnose::BEFEHL,
    'v1'=>fn(&$g)=>$g['zweck']='robohub/eureka/dock/runtime/v1',
    'Konto'=>fn(&$g)=>$g['konto']++, 'Instanz'=>fn(&$g)=>$g['instanz']++,
    'Modell'=>fn(&$g)=>$g['modell']='anderes', 'Ziel leer'=>fn(&$g)=>$g['cloudGeraetId']='',
    'Laufzeitformat'=>fn(&$g)=>$g['erwartet']='ungueltig',
    'Null Sekunden'=>fn(&$g)=>$g['bis']=$g['von'],
    'umgekehrte Frist'=>fn(&$g)=>$g['bis']=$g['von']-1,
    '61 Sekunden'=>fn(&$g)=>$g['bis']=$g['von']+61,
    'abgelaufen'=>function (&$g): void { $g['von']-=100; $g['bis']=time(); },
    'zukünftig'=>function (&$g): void { $g['von']+=30; $g['bis']+=30; },
    'Zeittyp'=>fn(&$g)=>$g['von']=(string)$g['von'],
    'Zusatz'=>fn(&$g)=>$g['extra']=true,
    'Einwilligungstyp'=>fn(&$g)=>$g['ausnahmeVerbindungUnbekannt']='true',
    'Einwilligung fehlt'=>function (&$g): void { unset($g['ausnahmeVerbindungUnbekannt']); },
];
foreach ($mutationen as $name=>$mutieren) {
    $tests['signierter Import und CLI verweigern/'.$name] = function () use ($mutieren,$sk,$key,$pk): void {
        [$k,$t,$a] = dockImportFixture(); $mutieren($a['genehmigung']);
        gesperrt(fn()=>$k->EurekaDockBereitstellen(dockTestSign($a['genehmigung'],$sk)));
        [$code,$out,$err] = dockSigner(json_encode($a),$key,$pk);
        sicher($code === 1 && $out === '' && $err === "Dock-Signierung verweigert: Eingabe, ausdrückliche Genehmigung oder externer Dock-Schlüssel ungültig.\n");
        sicher($k->GetBuffer('EurekaDockLaufzeitfreigabe') === '' && $t->calls === [] && $k->logs === []);
    };
}
foreach (['zweck','konto','instanz','cloudGeraetId','modell','befehl','erwartet','von','bis'] as $feld) {
    $tests['fehlendes signiertes Pflichtfeld/'.$feld] = function () use ($feld,$sk,$key,$pk): void {
        [$k,$t,$a] = dockImportFixture(); unset($a['genehmigung'][$feld]);
        gesperrt(fn()=>$k->EurekaDockBereitstellen(dockTestSign($a['genehmigung'],$sk)));
        [$code,$out,$err]=dockSigner(json_encode($a),$key,$pk);
        sicher($code===1 && $out==='' && $err==="Dock-Signierung verweigert: Eingabe, ausdrückliche Genehmigung oder externer Dock-Schlüssel ungültig.\n");
        sicher($k->GetBuffer('EurekaDockLaufzeitfreigabe')==='' && $t->calls===[] && $k->logs===[]);
    };
}
foreach (['Fremdschlüssel','Nutzlast','Signatur','Hülle','Zielwechsel','Laufzeitwechsel','B8-Laufzeit','kein Admin','unknown','Einwilligung false','B8-Dokument'] as $fall) {
    $tests['Import verweigert/'.$fall] = function () use ($fall,$sk,$pk): void {
        [$k,$t,$a] = dockImportFixture(); $g = $a['genehmigung'];
        if ($fall === 'Zielwechsel') { $g['cloudGeraetId']='offline-other'; }
        if ($fall === 'Laufzeitwechsel') { $g['erwartet']='1:'.str_repeat('b',64); }
        if ($fall === 'B8-Laufzeit') { $g['erwartet']=$GLOBALS['boot'].':'.$k->puffer['EurekaB8Laufzeit']; }
        if ($fall === 'Einwilligung false') { $g['ausnahmeVerbindungUnbekannt']=false; }
        if ($fall === 'B8-Dokument') { $g['zweck']=RoboHubEurekaGenehmigung::ZWECK; $g['befehl']=RoboHubEurekaDiagnose::BEFEHL; unset($g['ausnahmeVerbindungUnbekannt']); }
        $doc = dockTestSign($g,$sk);
        if (in_array($fall,['Nutzlast','Signatur'],true)) {
            $h=json_decode($doc,true); $f=$fall==='Nutzlast'?'nutzlast':'signatur';
            $raw=base64_decode($h[$f]); $raw[0]=chr(ord($raw[0])^1); $h[$f]=base64_encode($raw); $doc=json_encode($h);
        }
        if ($fall === 'Hülle') { $doc='{}'; }
        if ($fall === 'Fremdschlüssel') { $GLOBALS['dockTestPk']=base64_encode(str_repeat('x',32)); }
        if ($fall === 'kein Admin') { $GLOBALS['testAdmin']=false; }
        if ($fall === 'unknown') { $GLOBALS['testUser']='@unknown'; }
        try { gesperrt(fn()=>$k->EurekaDockBereitstellen($doc)); }
        finally { $GLOBALS['dockTestPk']=$pk; $GLOBALS['testAdmin']=true; $GLOBALS['testUser']='offline-admin'; }
        sicher($k->GetBuffer('EurekaDockLaufzeitfreigabe') === '' && $t->calls === [] && $k->logs === []);
    };
}
foreach (['JSON','Überlänge','Bestätigung fehlt','Laufzeitbestätigung','Zielbestätigung','false','Schlüssel fehlt','Schlüssel defekt','Anker fehlt','Anker falsch','Anker Überlänge'] as $fall) {
    $tests['CLI verweigert/'.$fall] = function () use ($fall,$key,$pk): void {
        [,, $a]=dockImportFixture(); $s=$key; $p=$pk;
        if ($fall==='Bestätigung fehlt') { unset($a['externGeprueftUndGenehmigt']); }
        if ($fall==='Laufzeitbestätigung') { $a['externGeprueftUndGenehmigt']='falsch'; }
        if ($fall==='Zielbestätigung') { $a['cloudGeraetExternGeprueftUndGenehmigt']='falsch'; }
        if ($fall==='false') { $a['genehmigung']['ausnahmeVerbindungUnbekannt']=false; }
        if ($fall==='Schlüssel fehlt') { $s=null; }
        if ($fall==='Schlüssel defekt') { $s='***'; }
        if ($fall==='Anker fehlt') { $p=null; }
        if ($fall==='Anker falsch') { $p=base64_encode(str_repeat('x',32)); }
        if ($fall==='Anker Überlänge') { $p=str_repeat(' ',129).$pk; }
        $json=match($fall) { 'JSON'=>'{', 'Überlänge'=>str_repeat(' ',4097), default=>json_encode($a) };
        [$code,$out,$err]=dockSigner($json,$s,$p);
        sicher($code===1 && $out==='' && $err==="Dock-Signierung verweigert: Eingabe, ausdrückliche Genehmigung oder externer Dock-Schlüssel ungültig.\n");
    };
}
$fehler=0;
foreach ($tests as $name=>$fn) {
    try { $fn(); echo 'OK Dock-Kryptografie/'.$name."\n"; }
    catch (Throwable $e) { $fehler++; echo 'FEHLER Dock-Kryptografie/'.$name."\n"; }
}
sodium_memzero($sk); sodium_memzero($key); sodium_memzero($pair);
echo count($tests)." Dock-Kryptografie-Tests, $fehler Fehler\n";
exit($fehler ? 1 : 0);
