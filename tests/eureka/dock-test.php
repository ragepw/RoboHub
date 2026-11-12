<?php
declare(strict_types=1);

// Keine Signaturerzeugung, kein Import, keine native cURL-Ausführung.
define('ROBOHUB_DIAGNOSE_NUR_FIXTURE', true);
function IPS_IsAdministrator() { return $GLOBALS['anzeigeAdmin'] ?? true; }
function IPS_GetLoggedInUser() { return $GLOBALS['anzeigeBenutzer'] ?? '@admin'; }
require __DIR__ . '/diagnostic-test.php';

function dockKonto(): array
{
    [$k, $t] = konto();
    $k->attrs['EurekaDockVersuch'] = '{}';
    shadowAendern($k, function (&$s): void { $s['roh']['connected'] = true; unset($s['online']); });
    $k->EurekaDockVorpruefen();
    return [$k, $t];
}
function dockGenehmigt(RoboHubKonto $k): void
{
    // Ausschließlich simulierte Laufzeitfreigabe, kein signiertes Dokument/Import.
    $k->puffer['EurekaDockLaufzeitfreigabe'] = json_encode([
        'erwartet' => $k->EurekaDockLaufzeitAnzeigen(), 'zustand' => 'genehmigt',
        'cloudGeraetId' => 'offline-e20', 'genehmigtBis' => time() + 60,
        'genehmigtMonotonBis' => intdiv(hrtime(true), 1000000) + 60000]);
}
function dockTicket(RoboHubKonto $k): string
{
    return json_decode($k->EurekaDockFreigeben(60, RoboHubEurekaDock::BESTAETIGUNG), true)['freigabe'];
}
$tests = [];
$tests['Native Vorprüfung/Anzeige geben nichts frei, B8 ist wirkungslos'] = function (): void {
    [$k,$t] = dockKonto(); $b8 = $k->puffer['EurekaB8Laufzeitfreigabe'];
    sicher($k->GetBuffer('EurekaDockLaufzeit') !== $k->GetBuffer('EurekaB8Laufzeit'));
    gesperrt(fn() => dockTicket($k)); gesperrt(fn() => $k->EurekaDockTesten(str_repeat('a',64)));
    sicher($t->calls === [] && $k->puffer['EurekaB8Laufzeitfreigabe'] === $b8);
};
foreach (['cleaning','pause','stop'] as $state) {
    $tests['Native Dock-Ausführung einmalig aus ' . $state] = function () use ($state): void {
        [$k,$t] = dockKonto();
        shadowAendern($k, function (&$s) use ($state): void { $s['roh']['state'] = $state; unset($s['roh']['parentState']); });
        dockGenehmigt($k); $ticket = dockTicket($k);
        $b8 = $k->puffer['EurekaB8Laufzeitfreigabe']; $shadow = $k->attrs['Schnappschuss'];
        $t->antwort = antwort();
        $r = json_decode($k->EurekaDockTesten($ticket), true);
        sicher($r['angenommen'] && !$r['ausgefuehrt'] && count($t->calls) === 1);
        sicher($t->calls[0][0] === 'POST' && $t->calls[0][1] === '/devices/commands/offline-e20'
            && $t->calls[0][3] === ['data'=>RoboHubEurekaDock::BEFEHL]);
        gesperrt(fn() => $k->EurekaDockTesten($ticket)); gesperrt(fn() => dockTicket($k));
        sicher(count($t->calls) === 1 && $shadow === $k->attrs['Schnappschuss'] && $b8 === $k->puffer['EurekaB8Laufzeitfreigabe']);
    };
}
$mutationen = [
    'Liste alt' => fn($k) => $k->attrs['GeraeteStand'] = time()-121,
    'Shadow alt' => fn($k) => shadowAendern($k, function (&$s): void { $s['zeit'] = time()-121; }),
    'Verbindung alt' => fn($k) => geraetAendern($k, function (&$g): void { $g['verbindungEmpfangen'] = time()-121; }),
    'Shadow getrennt' => fn($k) => shadowAendern($k, function (&$s): void { $s['roh']['connected'] = false; }),
    'Shadow Verbindung fehlt' => fn($k) => shadowAendern($k, function (&$s): void { unset($s['roh']['connected']); }),
    'Liste getrennt' => fn($k) => geraetAendern($k, function (&$g): void { $g['roh']['connected'] = false; }),
    'Fehler' => fn($k) => shadowAendern($k, function (&$s): void { $s['roh']['deviceFault'] = [1]; }),
    'Docked' => fn($k) => shadowAendern($k, function (&$s): void { $s['roh']['state'] = 'charging'; }),
    'Unbekannt' => fn($k) => shadowAendern($k, function (&$s): void { $s['roh']['state'] = 'unknown'; }),
    'Modell' => fn($k) => geraetAendern($k, function (&$g): void { $g['roh']['product']['sn8'] = 'unknown'; }),
    'Ziel' => fn($k) => $GLOBALS['zielId'] = 'different',
    'Parent' => fn($k) => $GLOBALS['ziel']['ConnectionID'] = 1,
    'Neustart' => fn($k) => $GLOBALS['boot'] = time()-50,
    'Sitzung' => fn($k) => $k->attrs['BuendelRevision'] = 'changed',
    'Cloudsperre' => fn($k) => $k->attrs['WarteBis'] = time()+60,
    'Token abgelaufen' => fn($k) => $k->attrs['Buendel'] = json_encode(['region'=>'eu','access_token'=>jwt(time())]),
    'Admin fehlt' => fn($k) => $GLOBALS['anzeigeAdmin'] = false,
    'Benutzer fehlt' => fn($k) => $GLOBALS['anzeigeBenutzer'] = '@unknown',
    'Deaktiviert' => fn($k) => $GLOBALS['zielDeaktiviert'] = true,
];
foreach ($mutationen as $name => $mutation) {
    foreach (['vor Versuch','vor I/O'] as $phase) {
        $tests[$name . ' ' . $phase] = function () use ($mutation,$phase): void {
            [$k,$t] = dockKonto(); dockGenehmigt($k); $ticket = dockTicket($k);
            if ($phase === 'vor Versuch') { $mutation($k); }
            else { $t->vorTransport = fn() => $mutation($k); }
            gesperrt(fn() => $k->EurekaDockTesten($ticket)); sicher($t->calls === []);
        };
    }
}
foreach ([401,429,500,302,'timeout','invalid'] as $fall) {
    $tests['Kein Retry/Refresh bei ' . $fall] = function () use ($fall): void {
        [$k,$t] = dockKonto(); dockGenehmigt($k); $ticket = dockTicket($k);
        $t->antwort = match($fall) {
            'timeout' => fn() => throw new RuntimeException('fixture-secret'),
            'invalid' => ['status'=>200,'response'=>'invalid'],
            default => antwort(null,$fall),
        };
        gesperrt(fn() => $k->EurekaDockTesten($ticket));
        gesperrt(fn() => $k->EurekaDockTesten($ticket));
        gesperrt(fn() => dockTicket($k)); sicher(count($t->calls) === 1);
        if ($fall === 429) { sicher($k->attrs['WarteBis'] > time()); }
    };
}
$tests['Widerruf und Attributrollback bleiben geschlossen'] = function (): void {
    [$k,$t] = dockKonto(); dockGenehmigt($k); $ticket = dockTicket($k); $alt = $k->attrs['EurekaDockVersuch'];
    $k->EurekaDockWiderrufen(); $k->attrs['EurekaDockVersuch'] = $alt;
    gesperrt(fn() => $k->EurekaDockTesten($ticket)); gesperrt(fn() => dockTicket($k)); sicher($t->calls === []);
};
$tests['Attributrollback nach Verbrauch erlaubt keinen zweiten Versuch'] = function (): void {
    [$k,$t] = dockKonto(); dockGenehmigt($k); $ticket = dockTicket($k); $alt = $k->attrs['EurekaDockVersuch'];
    $t->antwort = antwort(); $k->EurekaDockTesten($ticket); $k->attrs['EurekaDockVersuch'] = $alt;
    gesperrt(fn() => $k->EurekaDockTesten($ticket)); gesperrt(fn() => dockTicket($k)); sicher(count($t->calls) === 1);
};
$tests['Gerätesperre und B8-Operationssperre verhindern Versuch'] = function (): void {
    foreach (['RoboHubEureka_Diagnose_45174','RoboHubEureka_Geraet_'.hash('sha256','eu:robozone:offline-e20')] as $lock) {
        [$k,$t] = dockKonto(); dockGenehmigt($k); $ticket = dockTicket($k);
        $GLOBALS['lockFehler'] = $lock;
        gesperrt(fn() => $k->EurekaDockTesten($ticket)); sicher($t->calls === []);
    }
};
foreach (['fehlt','abgelaufen','monoton','B8-Puffer'] as $fall) {
    $tests['Laufzeitgenehmigung ' . $fall] = function () use ($fall): void {
        [$k,$t] = dockKonto(); dockGenehmigt($k);
        $l = json_decode($k->puffer['EurekaDockLaufzeitfreigabe'],true);
        if ($fall === 'fehlt') { unset($l['genehmigtBis']); }
        if ($fall === 'abgelaufen') { $l['genehmigtBis'] = time(); }
        if ($fall === 'monoton') { $l['genehmigtMonotonBis'] = 0; }
        if ($fall === 'B8-Puffer') { $l = json_decode($k->puffer['EurekaB8Laufzeitfreigabe'],true); }
        $k->puffer['EurekaDockLaufzeitfreigabe'] = json_encode($l);
        gesperrt(fn() => dockTicket($k)); sicher($t->calls === []);
    };
}
foreach ([0,61] as $dauer) {
    $tests['Ticketdauer ' . $dauer] = function () use ($dauer): void {
        [$k,$t] = dockKonto(); dockGenehmigt($k);
        gesperrt(fn() => $k->EurekaDockFreigeben($dauer,RoboHubEurekaDock::BESTAETIGUNG)); sicher($t->calls === []);
    };
}
$tests['B8-Bestätigung und falsches Ticket gesperrt'] = function (): void {
    [$k,$t] = dockKonto(); dockGenehmigt($k);
    gesperrt(fn() => $k->EurekaDockFreigeben(60,RoboHubEurekaDiagnose::BESTAETIGUNG));
    $ticket = dockTicket($k); gesperrt(fn() => $k->EurekaDockTesten(str_repeat('b',64))); sicher($t->calls === []);
};
foreach (['Attribut','Buffer'] as $speicher) {
    foreach (['Ticketausgabe','Verbrauch'] as $phase) {
        $tests['Speicherfehler ' . $speicher . ' bei ' . $phase] = function () use ($speicher,$phase): void {
            [$k,$t] = dockKonto(); dockGenehmigt($k);
            $ticket = $phase === 'Verbrauch' ? dockTicket($k) : '';
            if ($speicher === 'Attribut') { $k->speichernSperren = true; }
            else { $k->pufferSchreibenSperren = true; }
            gesperrt(fn() => $phase === 'Verbrauch' ? $k->EurekaDockTesten($ticket) : dockTicket($k));
            sicher($t->calls === []);
        };
    }
}
$tests['Reentranz und Widerruf unmittelbar vor Transport'] = function (): void {
    foreach (['reentrant','widerruf'] as $fall) {
        [$k,$t] = dockKonto(); dockGenehmigt($k); $ticket = dockTicket($k);
        $t->antwort = antwort();
        $t->vorTransport = function () use ($k,$ticket,$fall): void {
            if ($fall === 'widerruf') { $k->EurekaDockWiderrufen(); }
            else { gesperrt(fn() => $k->EurekaDockTesten($ticket)); }
        };
        if ($fall === 'widerruf') { gesperrt(fn() => $k->EurekaDockTesten($ticket)); }
        else { $k->EurekaDockTesten($ticket); }
        sicher(count($t->calls) === ($fall === 'widerruf' ? 0 : 1));
    }
};
$tests['Kein Dock-Import über ForwardData und keine normalen Befehle freigegeben'] = function (): void {
    [$k,$t] = dockKonto();
    foreach (['EurekaDockBereitstellen','EurekaDockTesten'] as $action) {
        $r = json_decode($k->ForwardData(json_encode(['Aktion'=>$action,'Geraet'=>'offline-e20'])),true);
        sicher($r['ok'] === false);
    }
    foreach (['start','pause','stopp','station','orten'] as $cmd) {
        gesperrt(fn() => (new RoboHubEureka())->befehl([],[],$cmd,[]));
    }
    sicher($t->calls === [] && $k->GetBuffer('EurekaDockLaufzeitfreigabe') === '');
};
// Ed25519-Systemgrenze als Double, keine Schlüssel/Signaturen erzeugen oder importieren.
$source = file_get_contents(__DIR__.'/../../libs/RoboHubEurekaGenehmigung.php');
$source = str_replace('declare(strict_types=1);', 'declare(strict_types=1); namespace DockVerifierDouble; use \\RoboHubFehler; use \\RoboHubEurekaDock; use \\RoboHubEurekaDiagnose; use \\Throwable; use \\RuntimeException;', $source);
eval(substr($source,5));
eval('namespace DockVerifierDouble; function sodium_crypto_sign_verify_detached($sig,$raw,$pk) { return $GLOBALS["dockSignaturGueltig"] ?? true; }');
$tests['Signaturzweck, Befehl, Ziel, Zeit und Laufzeit strikt getrennt'] = function (): void {
    $now = time(); $boot = ($now-100).':'.str_repeat('a',64);
    $g = ['zweck'=>'robohub/eureka/dock/runtime/v1','konto'=>45174,'instanz'=>22708,
        'cloudGeraetId'=>'offline-e20','modell'=>'E20 Evo Plus','befehl'=>RoboHubEurekaDock::BEFEHL,
        'erwartet'=>$boot,'von'=>$now,'bis'=>$now+60];
    $doc = fn($v) => json_encode(['nutzlast'=>base64_encode(json_encode($v)),'signatur'=>base64_encode(str_repeat('x',64))]);
    $pk = base64_encode(str_repeat('x',32)); $v = DockVerifierDouble\RoboHubEurekaGenehmigung::class;
    sicher($v::dockPruefen($doc($g),$pk,$boot,$now) === $g);
    gesperrt(fn() => $v::pruefen($doc($g),$pk,$boot,$now));
    foreach (['zweck'=>'robohub/eureka/b8/runtime/v2','befehl'=>RoboHubEurekaDiagnose::BEFEHL,
        'konto'=>1,'instanz'=>1,'modell'=>'other','cloudGeraetId'=>'','erwartet'=>'other',
        'von'=>$now+1,'bis'=>$now+61] as $field=>$value) {
        $bad = array_replace($g,[$field=>$value]); gesperrt(fn() => $v::dockPruefen($doc($bad),$pk,$boot,$now));
    }
    gesperrt(fn() => $v::dockPruefen($doc($g),$pk,$boot,$now+60));
    $GLOBALS['dockSignaturGueltig'] = false;
    gesperrt(fn() => $v::dockPruefen($doc($g),$pk,$boot,$now)); unset($GLOBALS['dockSignaturGueltig']);
    // Echte Ed25519-Grenze: fiktive Bytes werden abgewiesen.
    gesperrt(fn() => RoboHubEurekaGenehmigung::dockPruefen($doc($g),$pk,$boot,$now));
};
$errors = 0;
foreach ($tests as $name=>$test) {
    try { $test(); sicher($GLOBALS['locks'] === []); echo "OK: $name\n"; }
    catch (Throwable $e) { $errors++; echo "FEHLER: $name: ".$e->getMessage()."\n"; }
    unset($GLOBALS['anzeigeAdmin'],$GLOBALS['anzeigeBenutzer'],$GLOBALS['zielDeaktiviert']);
    $GLOBALS['locks'] = [];
}
echo count($tests)." Dock-Tests, $errors Fehler\n"; exit($errors ? 1 : 0);
