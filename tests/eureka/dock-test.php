<?php
declare(strict_types=1);

// Keine Signaturerzeugung, kein Import, keine native cURL-Ausführung.
define('ROBOHUB_DIAGNOSE_NUR_FIXTURE', true);
function IPS_IsAdministrator() { return $GLOBALS['anzeigeAdmin'] ?? true; }
function IPS_GetLoggedInUser() { return $GLOBALS['anzeigeBenutzer'] ?? '@admin'; }
require __DIR__ . '/diagnostic-test.php';

function dockKonto(bool $unbekannt = false): array
{
    [$k, $t] = konto();
    $k->attrs['EurekaDockVersuch'] = '{}';
    shadowAendern($k, function (&$s) use ($unbekannt): void {
        $s['statusquelle'] = ['quelle' => 'shadow-get', 'geraetezeit' => null];
        if ($unbekannt) { unset($s['roh']['connected']); $s['online'] = false; }
        else { $s['roh']['connected'] = true; unset($s['online']); }
    });
    $k->EurekaDockVorpruefen();
    return [$k, $t];
}
function dockGenehmigt(RoboHubKonto $k, bool $ausnahme = false): void
{
    // Ausschließlich simulierte Laufzeitfreigabe, kein signiertes Dokument/Import.
    $k->puffer['EurekaDockLaufzeitfreigabe'] = json_encode([
        'erwartet' => $k->EurekaDockLaufzeitAnzeigen(), 'zustand' => 'genehmigt',
        'cloudGeraetId' => 'offline-e20', 'genehmigtBis' => time() + 60,
        'ausnahmeVerbindungUnbekannt' => $ausnahme,
        'genehmigtMonotonBis' => intdiv(hrtime(true), 1000000) + 60000]);
}
function dockTicket(RoboHubKonto $k, bool $ausnahme = false): string
{
    return json_decode($k->EurekaDockFreigeben(60, RoboHubEurekaDock::BESTAETIGUNG,
        $ausnahme ? RoboHubEurekaDock::VOR_ORT_BESTAETIGUNG : ''), true)['freigabe'];
}
$tests = [];
require_once __DIR__ . '/observed-shadow-fixtures.php';
// Native Wrapper-Nachbildung: Attribute werden ausschließlich durch das Modul gelesen.
function ROBOHUB_EurekaDockShadowLesediagnose(int $id): string
{
    sicher($id === 45174);
    return $GLOBALS['lesediagnoseKonto']->EurekaDockShadowLesediagnose();
}
// Ohne Vorprüfung/Initialisierung: jeder Schreib-, Buffer- oder Logzugriff scheitert.
class ShadowLesekonto extends RoboHubKonto
{
    public function ReadAttributeString(string $n): string
    {
        sicher($n === 'Schnappschuss');
        return parent::ReadAttributeString($n);
    }
    public function ReadAttributeInteger(string $n): int
    {
        sicher($n === 'GeraeteStand');
        return parent::ReadAttributeInteger($n);
    }
    public function WriteAttributeString(string $n, string $v): void { throw new RuntimeException('Schreibzugriff'); }
    public function WriteAttributeInteger(string $n, int $v): void { throw new RuntimeException('Schreibzugriff'); }
    public function GetBuffer(string $n): string { throw new RuntimeException('Bufferzugriff'); }
    public function SetBuffer(string $n, string $v): void { throw new RuntimeException('Bufferzugriff'); }
    public function SendDebug(string $n, string $s, int $f): void { throw new RuntimeException('Logzugriff'); }
}
foreach (['', '{', 'null', '{"offline-e20":{"p":"fixture-secret"}}',
    json_encode(['offline-e20' => ['p' => ['deviceId' => 'offline-e20', 'zeit' => time() - 121,
        'roh' => ['state' => 'fixture-secret', 'connected' => false, 'online' => false,
            'token' => 'fixture-secret'], 'statusquelle' => ['quelle' => 'fixture-secret']]]])] as $index => $snap) {
    $tests['Öffentliche Shadow-Lesediagnose ohne Initialisierung ' . $index] = function () use ($snap, $index): void {
        $k = new ShadowLesekonto(45174);
        $k->attrs = ['Schnappschuss' => $snap, 'GeraeteStand' => time() - 121];
        $vorher = [$k->attrs, $k->puffer, $k->logs];
        $ausgabe = $k->EurekaDockShadowLesediagnose();
        $d = json_decode($ausgabe, true, 512, JSON_THROW_ON_ERROR);
        sicher(array_keys($d) === ['shadowVorhanden', 'lokaleZielbindung', 'listeLokalFrisch',
            'shadowLokalFrisch', 'connectedFehlt', 'connectedExplizitFalse', 'rohOnlineExplizitFalse',
            'getHerkunftErfuellt', 'abgeleitetesOnlineErfuellt', 'arbeitszustandErfuellt',
            'state', 'meldung2Teilbedingung']);
        foreach ($d as $feld => $wert) { sicher($feld === 'state' ? $wert === 'sonstig/fehlend' : is_bool($wert)); }
        sicher(!$d['listeLokalFrisch'] && !$d['shadowLokalFrisch'] && $d['meldung2Teilbedingung'] === ($index !== 4));
        sicher($d['connectedExplizitFalse'] === ($index === 4) && $d['rohOnlineExplizitFalse'] === ($index === 4));
        sicher(!str_contains($ausgabe, 'offline-e20') && !str_contains($ausgabe, 'fixture-secret'));
        sicher($vorher === [$k->attrs, $k->puffer, $k->logs]);
    };
}
foreach (['getHerkunftErfuellt', 'abgeleitetesOnlineErfuellt', 'arbeitszustandErfuellt'] as $feld) {
    $tests['Lesediagnose isoliert ' . $feld] = function () use ($feld): void {
        [$k, $t] = dockKonto(true);
        shadowAendern($k, function (&$s) use ($feld): void {
            if ($feld === 'getHerkunftErfuellt') { unset($s['statusquelle']); }
            if ($feld === 'abgeleitetesOnlineErfuellt') { $s['online'] = true; }
            if ($feld === 'arbeitszustandErfuellt') { $s['roh']['state'] = 'fixture-secret'; }
        });
        $GLOBALS['lesediagnoseKonto'] = $k;
        $vorher = [$k->attrs, $k->puffer, $k->logs];
        ob_start();
        include __DIR__ . '/../../.tools/eureka-dock-shadow-lesediagnose.php';
        $ausgabe = ob_get_clean();
        $d = json_decode($ausgabe, true, 512, JSON_THROW_ON_ERROR);
        sicher($d[$feld] === false && $d['meldung2Teilbedingung'] === true);
        sicher(!str_contains($ausgabe, 'fixture-secret'));
        sicher($vorher === [$k->attrs, $k->puffer, $k->logs] && $t->calls === []);
        unset($GLOBALS['lesediagnoseKonto']);
    };
}
foreach (observedE20Shadows() as $beobachtung) {
    $state = $beobachtung['shadow']['state'];
    $tests['Native Lesediagnose und Vorprüfung: beobachtetes ' . $state] = function () use ($beobachtung, $state): void {
        [$k, $t] = dockKonto(true);
        shadowAendern($k, function (&$s) use ($beobachtung): void { $s['roh'] = $beobachtung['shadow']; });
        $GLOBALS['lesediagnoseKonto'] = $k;
        $vorher = [$k->attrs, $k->puffer, $k->logs];
        ob_start();
        include __DIR__ . '/../../.tools/eureka-dock-shadow-lesediagnose.php';
        $ausgabe = ob_get_clean();
        $d = json_decode($ausgabe, true, 512, JSON_THROW_ON_ERROR);
        sicher($d['getHerkunftErfuellt'] && $d['abgeleitetesOnlineErfuellt'] && $d['connectedFehlt']);
        sicher($d['meldung2Teilbedingung'] === !in_array($state, ['pause', 'cleaning'], true));
        sicher($vorher === [$k->attrs, $k->puffer, $k->logs] && $t->calls === []);
        sicher(!str_contains($ausgabe, 'offline-e20') && !str_contains($ausgabe, 'fixture-secret'));
        if ($d['meldung2Teilbedingung']) {
            try { $k->EurekaDockVorpruefen(); throw new RuntimeException('Sperre fehlt'); }
            catch (RoboHubFehler $e) { sicher(str_contains($e->getMessage(), 'erfolgreich empfangenen')); }
        } else { sicher(str_contains($k->EurekaDockVorpruefen(), 'Verbindung unbekannt')); }
        unset($GLOBALS['lesediagnoseKonto']);
    };
}
$tests['Beobachteter E20: Unbekannt bleibt unbekannt, Vorprüfung erteilt keine Ausnahme'] = function (): void {
    [$k, $t] = dockKonto(true);
    $vorher = $k->attrs['Schnappschuss'];
    sicher(str_contains($k->EurekaDockVorpruefen(), 'Verbindung unbekannt'));
    gesperrt(fn() => dockTicket($k, true));
    sicher($t->calls === [] && $k->attrs['Schnappschuss'] === $vorher);
    sicher($k->GetBuffer('EurekaDockLaufzeitfreigabe') === '');
};
// Synthetische Gegenproben, keine Aussage über reale E20-Shadowfelder.
foreach ([true, false, null] as $liste) {
    foreach ([true, false, null] as $shadow) {
        foreach ([5, 125] as $listenalter) {
            foreach ([5, 125] as $shadowalter) {
                $tests['Online-Nachweis ' . json_encode([$liste, $shadow, $listenalter, $shadowalter])] =
                    function () use ($liste, $shadow, $listenalter, $shadowalter): void {
                        [$k, $t] = konto();
                        $k->attrs['EurekaDockVersuch'] = '{}';
                        $jetzt = time();
                        $k->attrs['GeraeteStand'] = $jetzt - $listenalter;
                        geraetAendern($k, function (&$g) use ($liste, $jetzt, $listenalter): void {
                            unset($g['roh']['connected']);
                            if ($liste !== null) { $g['roh']['connected'] = $liste; }
                            $g['online'] = $liste === true;
                            $g['verbindungEmpfangen'] = $jetzt - $listenalter;
                        });
                        shadowAendern($k, function (&$s) use ($shadow, $jetzt, $shadowalter): void {
                            unset($s['roh']['connected']);
                            if ($shadow !== null) { $s['roh']['connected'] = $shadow; }
                            $s['online'] = $shadow === true;
                            $s['statusquelle'] = ['quelle' => 'shadow-get', 'geraetezeit' => null];
                            $s['zeit'] = $jetzt - $shadowalter;
                        });
                        $g = json_decode($k->attrs['Geraete'], true)[0];
                        $s = json_decode($k->attrs['Schnappschuss'], true)[$g['id']]['p'];
                        $anzeige = RoboHubEurekaZustand::erreichbarkeit($s['roh'], $g, $jetzt);
                        // Fehlendes connected ist unbekannt, niemals bestätigtes Offline.
                        sicher($anzeige['shadow'] === $shadow);
                        sicher($anzeige['verbindung'] === ($shadow ?? ($listenalter <= 120 ? $liste : null)));
                        if ($liste === true && $shadow !== false && $listenalter <= 120 && $shadowalter <= 120) {
                            sicher(str_contains($k->EurekaDockVorpruefen(), 'bestanden'));
                        } else {
                            gesperrt(fn() => $k->EurekaDockVorpruefen());
                        }
                        sicher($t->calls === [] && $k->GetBuffer('EurekaDockLaufzeitfreigabe') === '');
                        // Erst signiertes Opt-in UND unmittelbare Beobachtung erlauben ein Ticket.
                        if ($liste === true && $shadow !== false && $listenalter <= 120 && $shadowalter <= 120) {
                            dockGenehmigt($k, true);
                            $ticket = dockTicket($k, true);
                            $t->antwort = antwort();
                            $r = json_decode($k->EurekaDockTesten($ticket), true);
                            sicher($r['verbindung'] === ($shadow === true ? 'online' : 'unbekannt'));
                            sicher(count($t->calls) === 1);
                        } else {
                            dockGenehmigt($k, true);
                            gesperrt(fn() => dockTicket($k, true));
                            sicher($t->calls === []);
                        }
                    };
            }
        }
    }
}
$tests['Native Vorprüfung/Anzeige geben nichts frei, B8 ist wirkungslos'] = function (): void {
    [$k,$t] = dockKonto(); $b8 = $k->puffer['EurekaB8Laufzeitfreigabe'];
    sicher($k->GetBuffer('EurekaDockLaufzeit') !== $k->GetBuffer('EurekaB8Laufzeit'));
    gesperrt(fn() => dockTicket($k)); gesperrt(fn() => $k->EurekaDockTesten(str_repeat('a',64)));
    sicher($t->calls === [] && $k->puffer['EurekaB8Laufzeitfreigabe'] === $b8);
};
foreach ([false,true] as $ausnahme) {
foreach (['cleaning','pause','stop'] as $state) {
    $tests['Native Dock-Ausführung ' . ($ausnahme ? 'mit Ausnahme ' : '') . 'einmalig aus ' . $state] = function () use ($state,$ausnahme): void {
        [$k,$t] = dockKonto($ausnahme);
        shadowAendern($k, function (&$s) use ($state): void { $s['roh']['state'] = $state; unset($s['roh']['parentState']); });
        dockGenehmigt($k,$ausnahme); $ticket = dockTicket($k,$ausnahme);
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
}
$mutationen = [
    'Liste alt' => fn($k) => $k->attrs['GeraeteStand'] = time()-121,
    'Shadow alt' => fn($k) => shadowAendern($k, function (&$s): void { $s['zeit'] = time()-121; }),
    'Verbindung alt' => fn($k) => geraetAendern($k, function (&$g): void { $g['verbindungEmpfangen'] = time()-121; }),
    'Shadow getrennt' => fn($k) => shadowAendern($k, function (&$s): void { $s['roh']['connected'] = false; }),
    'Shadow Verbindung fehlt' => fn($k) => shadowAendern($k, function (&$s): void { unset($s['roh']['connected']); }),
    'Liste getrennt' => fn($k) => geraetAendern($k, function (&$g): void { $g['roh']['connected'] = false; }),
    'Shadow falsches Ziel' => fn($k) => shadowAendern($k, function (&$s): void { $s['deviceId'] = 'different'; }),
    'Shadow rohe Fremdkennung' => fn($k) => shadowAendern($k, function (&$s): void { $s['roh']['deviceId'] = 'different'; }),
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
// Dieselben Sperren mit ausdrücklich aktivierter Ausnahme, auch unmittelbar vor I/O.
$ausnahmeMutationen = $mutationen;
unset($ausnahmeMutationen['Shadow Verbindung fehlt']);
$ausnahmeMutationen += [
    'Shadow connected null' => fn($k) => shadowAendern($k, function (&$s): void { $s['roh']['connected'] = null; }),
    'Shadow connected falscher Typ' => fn($k) => shadowAendern($k, function (&$s): void { $s['roh']['connected'] = 'true'; }),
    'Shadow rohe Online-Meldung offline' => fn($k) => shadowAendern($k, function (&$s): void { $s['roh']['online'] = false; }),
    'Shadow Quelle fehlt' => fn($k) => shadowAendern($k, function (&$s): void { unset($s['statusquelle']); }),
    'Shadow Quelle kein GET' => fn($k) => shadowAendern($k, function (&$s): void { $s['statusquelle']['quelle'] = 'mqtt'; }),
    'Abgeleiteter Shadow online widerspricht fehlendem Nachweis' => fn($k) => shadowAendern($k, function (&$s): void { $s['online'] = true; }),
    'Liste connected fehlt' => fn($k) => geraetAendern($k, function (&$g): void { unset($g['roh']['connected']); }),
    'Liste Online-Meldung offline' => fn($k) => geraetAendern($k, function (&$g): void { $g['roh']['online'] = false; }),
    'Liste normalisiert offline' => fn($k) => geraetAendern($k, function (&$g): void { $g['online'] = false; }),
    'Liste rohe Fremdkennung' => fn($k) => geraetAendern($k, function (&$g): void { $g['roh']['deviceId'] = 'different'; }),
    'Liste Kennung fehlt' => fn($k) => geraetAendern($k, function (&$g): void { unset($g['roh']['deviceId']); }),
    'Liste doppelt' => fn($k) => $k->attrs['Geraete'] = json_encode(array_merge(json_decode($k->attrs['Geraete'],true), json_decode($k->attrs['Geraete'],true))),
    'Liste zukünftig' => fn($k) => $k->attrs['GeraeteStand'] = time()+60,
    'Shadow zukünftig' => fn($k) => shadowAendern($k, function (&$s): void { $s['zeit'] = time()+60; }),
    'Shadow Fehlerentwarnung fehlt' => fn($k) => shadowAendern($k, function (&$s): void { unset($s['roh']['deviceFault']); }),
    'Shadow Zustand widersprüchlich' => fn($k) => shadowAendern($k, function (&$s): void { $s['roh']['parentState'] = 'sleep'; }),
    'Anderer Administrator' => fn($k) => $GLOBALS['anzeigeBenutzer'] = '@other-admin',
    'Falsches Konto' => fn($k) => $k->InstanceID = 1,
    'Widerruf' => fn($k) => $k->EurekaDockWiderrufen(),
    'Laufzeitpuffer gelöscht' => fn($k) => $k->puffer['EurekaDockLaufzeitfreigabe'] = '',
    'Ausnahmeeinwilligung entfernt' => function ($k): void {
        $l = json_decode($k->puffer['EurekaDockLaufzeitfreigabe'],true);
        unset($l['ausnahmeVerbindungUnbekannt']);
        $k->puffer['EurekaDockLaufzeitfreigabe'] = json_encode($l);
    },
    'Vor-Ort-Beobachtung geändert' => function ($k): void {
        $f = json_decode($k->attrs['EurekaDockVersuch'],true);
        $f['beaufsichtigung']['vorOrt']['am']--;
        $k->attrs['EurekaDockVersuch'] = json_encode($f);
    },
];
foreach ($ausnahmeMutationen as $name => $mutation) {
    foreach (['vor Versuch','vor I/O'] as $phase) {
        $tests['Ausnahme: ' . $name . ' ' . $phase] = function () use ($mutation,$phase): void {
            [$k,$t] = dockKonto(true); dockGenehmigt($k,true); $ticket = dockTicket($k,true);
            if ($phase === 'vor Versuch') { $mutation($k); }
            else { $t->vorTransport = fn() => $mutation($k); }
            gesperrt(fn() => $k->EurekaDockTesten($ticket)); sicher($t->calls === []);
        };
    }
}
foreach ([true,false,null,'true',1] as $einwilligung) {
    foreach (['', 'ja', RoboHubEurekaDock::VOR_ORT_BESTAETIGUNG] as $vorOrt) {
        $tests['Ausnahme verlangt zwei Einwilligungen ' . json_encode([$einwilligung,$vorOrt])] = function () use ($einwilligung,$vorOrt): void {
            [$k,$t] = dockKonto(true); dockGenehmigt($k);
            $l = json_decode($k->puffer['EurekaDockLaufzeitfreigabe'],true);
            if ($einwilligung === null) { unset($l['ausnahmeVerbindungUnbekannt']); }
            else { $l['ausnahmeVerbindungUnbekannt'] = $einwilligung; }
            $k->puffer['EurekaDockLaufzeitfreigabe'] = json_encode($l);
            if ($einwilligung === true && $vorOrt === RoboHubEurekaDock::VOR_ORT_BESTAETIGUNG) {
                $f = json_decode($k->EurekaDockFreigeben(60,RoboHubEurekaDock::BESTAETIGUNG,$vorOrt),true);
                sicher($f['verbindung'] === 'unbekannt' && $f['ausnahmeVerbindungUnbekannt'] === true);
                $snapshot = $k->attrs['Schnappschuss'];
                $t->antwort = antwort();
                $r = json_decode($k->EurekaDockTesten($f['freigabe']),true);
                sicher($r['verbindung'] === 'unbekannt' && !$r['ausgefuehrt'] && count($t->calls) === 1);
                sicher($snapshot === $k->attrs['Schnappschuss']);
                gesperrt(fn() => dockTicket($k,true));
                gesperrt(fn() => $k->EurekaDockTesten($f['freigabe']));
            } else {
                // Eine persistierte Checkbox ist niemals Eingabe für die Beobachtung.
                $k->props['VorOrtBestaetigt'] = true;
                gesperrt(fn() => $k->EurekaDockFreigeben(60,RoboHubEurekaDock::BESTAETIGUNG,$vorOrt));
                sicher($t->calls === [] && $k->attrs['EurekaDockVersuch'] === '{}');
            }
        };
    }
}
// Verstellbare lokale Uhren statt Warten oder manipulierter Ablaufzeiten.
function dockZeitFixture(): array
{
    [$k] = dockKonto(true);
    $o = new stdClass();
    $o->k = (new ReflectionMethod(RoboHubKonto::class,'eurekaDockKontext'))->invoke($k);
    $o->k['monoton'] = 100000;
    $o->f = [];
    $o->l = ['erwartet'=>$o->k['boot'],'zustand'=>'genehmigt','cloudGeraetId'=>'offline-e20',
        'genehmigtBis'=>$o->k['jetzt']+60,'genehmigtMonotonBis'=>160000,'ausnahmeVerbindungUnbekannt'=>true];
    $d = new RoboHubEurekaDock(fn()=>$o->k,fn()=>$o->f,function ($v) use ($o): void { $o->f=$v; },
        fn($fn)=>$fn(),fn()=>$o->l,function ($v) use ($o): void { $o->l=$v; });
    $ticket = $d->freigeben(30,RoboHubEurekaDock::BESTAETIGUNG,RoboHubEurekaDock::VOR_ORT_BESTAETIGUNG)['freigabe'];
    return [$d,$o,$ticket];
}
foreach (['wanduhr','monoton','rückwärts','neustart','genehmigtBis','genehmigtMonotonBis'] as $fall) {
    foreach (['vor Versuch','vor I/O'] as $phase) {
        $tests['Ausnahme: Ablauf ' . $fall . ' ' . $phase] = function () use ($fall,$phase): void {
            [$d,$o,$ticket] = dockZeitFixture(); $calls = 0;
            $aendern = function () use ($o,$fall): void {
                if ($fall === 'wanduhr') { $o->k['jetzt'] += 30; }
                if ($fall === 'monoton') { $o->k['monoton'] += 30000; }
                if ($fall === 'rückwärts') { $o->k['monoton']--; }
                if ($fall === 'neustart') { $o->k['boot'] = 'andere-laufzeit'; }
                if ($fall === 'genehmigtBis') { $o->l['genehmigtBis'] = $o->k['jetzt']; }
                if ($fall === 'genehmigtMonotonBis') { $o->l['genehmigtMonotonBis'] = $o->k['monoton']; }
            };
            if ($phase === 'vor Versuch') { $aendern(); }
            $transport = function ($p,$t,$b,$pruefen) use ($phase,$aendern,&$calls): array {
                if ($phase === 'vor I/O') { $aendern(); }
                $pruefen(true); $calls++; return antwort();
            };
            gesperrt(fn() => $d->ausfuehren($ticket,$transport));
            sicher($calls === 0);
        };
    }
}
foreach ([120,121] as $alter) {
    $tests['Ausnahme: Frischegrenze exakt ' . $alter] = function () use ($alter): void {
        [$d,$o,$ticket] = dockZeitFixture();
        $o->k['cacheZeit'] = $o->k['geraet']['verbindungEmpfangen'] = $o->k['geraet']['stand']['zeit'] = $o->k['jetzt']-$alter;
        $transport = function ($p,$t,$b,$pruefen): array { $pruefen(true); return antwort(); };
        if ($alter === 120) { sicher($d->ausfuehren($ticket,$transport)['verbindung'] === 'unbekannt'); }
        else { gesperrt(fn() => $d->ausfuehren($ticket,$transport)); }
    };
}
foreach ([false,true] as $ausnahme) {
foreach ([401,429,500,302,'timeout','invalid','cloud-timeout'] as $fall) {
    $tests['Kein Retry/Refresh ' . ($ausnahme ? 'mit Ausnahme ' : '') . 'bei ' . $fall] = function () use ($fall,$ausnahme): void {
        [$k,$t] = dockKonto($ausnahme); dockGenehmigt($k,$ausnahme); $ticket = dockTicket($k,$ausnahme);
        $t->antwort = match($fall) {
            'timeout' => fn() => throw new RuntimeException('fixture-secret'),
            'invalid' => ['status'=>200,'response'=>'invalid'],
            'cloud-timeout' => antwort(null,200,'0080005'),
            default => antwort(null,$fall),
        };
        gesperrt(fn() => $k->EurekaDockTesten($ticket));
        gesperrt(fn() => $k->EurekaDockTesten($ticket));
        gesperrt(fn() => dockTicket($k)); sicher(count($t->calls) === 1);
        if ($fall === 429) { sicher($k->attrs['WarteBis'] > time()); }
    };
}
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
        gesperrt(fn() => $k->EurekaDockFreigeben($dauer,RoboHubEurekaDock::BESTAETIGUNG,'')); sicher($t->calls === []);
    };
}
$tests['B8-Bestätigung und falsches Ticket gesperrt'] = function (): void {
    [$k,$t] = dockKonto(); dockGenehmigt($k);
    gesperrt(fn() => $k->EurekaDockFreigeben(60,RoboHubEurekaDiagnose::BESTAETIGUNG,''));
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
    [$k,$t] = dockKonto(true); dockGenehmigt($k,true); $ticket = dockTicket($k,true);
    foreach (['EurekaDockBereitstellen','EurekaDockFreigeben','EurekaDockTesten'] as $action) {
        $r = json_decode($k->ForwardData(json_encode(['Aktion'=>$action,'Geraet'=>'offline-e20'])),true);
        sicher($r['ok'] === false);
    }
    foreach (['start','pause','resume','stopp','station','orten'] as $cmd) {
        gesperrt(fn() => (new RoboHubEureka())->befehl([],[],$cmd,[]));
    }
    // Auch der vorbereitete Planer erhält keine Ausnahme aus einem Dockticket.
    $g = json_decode($k->attrs['Geraete'],true)[0];
    $g['stand'] = json_decode($k->attrs['Schnappschuss'],true)[$g['id']]['p'];
    $zuordnung = [['identifikation'=>RoboHubEurekaProtokoll::identifikation($g),
        'familie'=>RoboHubEurekaProtokoll::FAMILIE,'beleg'=>'Offline-Test']];
    foreach (['start','pause','station'] as $cmd) {
        gesperrt(fn() => RoboHubEurekaBefehlsweg::vorbereiten($g,$cmd,[],$zuordnung,time()));
    }
    sicher($t->calls === []);
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
    foreach ([true,false] as $einwilligung) {
        $v2 = array_replace($g,['zweck'=>'robohub/eureka/dock/runtime/v2']);
        $v2['ausnahmeVerbindungUnbekannt'] = $einwilligung;
        sicher($v::dockPruefen($doc($v2),$pk,$boot,$now) === $v2);
        gesperrt(fn() => $v::pruefen($doc($v2),$pk,$boot,$now));
        gesperrt(fn() => RoboHubEurekaGenehmigung::dockPruefen($doc($v2),$pk,$boot,$now));
        foreach ([null,1,'true',[], 'fehlt'] as $wert) {
            $bad = array_replace($v2,['ausnahmeVerbindungUnbekannt'=>$wert]);
            if ($wert === 'fehlt') { unset($bad['ausnahmeVerbindungUnbekannt']); }
            gesperrt(fn() => $v::dockPruefen($doc($bad),$pk,$boot,$now));
        }
        foreach (['zweck'=>'robohub/eureka/b8/runtime/v2','konto'=>1,'instanz'=>1,
            'erwartet'=>'other','befehl'=>RoboHubEurekaDiagnose::BEFEHL,'bis'=>$now+61] as $feld=>$wert) {
            $bad = array_replace($v2,[$feld=>$wert]);
            gesperrt(fn() => $v::dockPruefen($doc($bad),$pk,$boot,$now));
        }
        $GLOBALS['dockSignaturGueltig'] = false;
        gesperrt(fn() => $v::dockPruefen($doc($v2),$pk,$boot,$now));
        unset($GLOBALS['dockSignaturGueltig']);
    }
    // v1 kann niemals durch ein angehängtes Feld zur Ausnahmeeinwilligung werden.
    gesperrt(fn() => $v::dockPruefen($doc(array_merge($g,['ausnahmeVerbindungUnbekannt'=>true])),$pk,$boot,$now));
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
