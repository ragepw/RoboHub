<?php
declare(strict_types=1);

// Eingebunden in den nativen Kontrollprüfstand; ausschließlich erfundene GET-/POST-Doubles.
abschnitt('Eureka: kontrollierter Refresh bei manueller Bedienung');
$listenPfad = '/app/devices?version=1&isShared=false';
$shadowPfad = '/shadow/device/state?devices=offline-e20';
$getAntwort = static fn(array $data): array => ['status' => 200, 'response' => json_encode(['errorCode' => 0, 'data' => $data])];
$lesedaten = function (string $state = 'cleaning') use ($ct, $listenPfad, $getAntwort): void {
    $ct->leseAntwort = static function (string $pfad) use ($state, $listenPfad, $getAntwort): array {
        if ($pfad === $listenPfad) { return $getAntwort([e20Fixture()['geraet']['roh']]); }
        $roh = e20Fixture()['geraet']['stand']['roh']; unset($roh['connected']);
        $roh['state'] = $roh['parentState'] = $state;
        return $getAntwort($roh);
    };
};
$anfrage = static fn(string $cmd = 'pause', string $id = 'offline-e20', string $bedienung = 'manuell'): string =>
    json_encode(['Aktion' => 'befehl', 'Geraet' => $id, 'Befehl' => $cmd, 'Parameter' => [], 'Bedienung' => $bedienung]);

foreach ([['stop', 1], ['pause', 1], ['cleaning', 2], ['cleaning', 4]] as [$state, $action]) {
    foreach (['frisch', 'liste', 'listenempfang', 'shadow', 'shadow-runtime', 'shadow-session', 'beide', 'leer'] as $fall) {
        $reset($state); $lesedaten($state);
        $cg->props['EurekaStartSaugstufe'] = 2; $cg->props['EurekaStartWasserstufe'] = 0;
        if (in_array($fall, ['liste', 'beide'], true)) { $ck->WriteAttributeInteger('GeraeteStand', time() - 121); }
        if ($fall === 'listenempfang') { $listeAendern(function (&$g) { $g['verbindungEmpfangen'] -= 121; }); }
        if (in_array($fall, ['shadow', 'beide'], true)) { $shadowAendern(function (&$s) { $s['zeit'] -= 121; }); }
        if ($fall === 'shadow-runtime') { $ck->SetBuffer('EurekaSteuerLaufzeit', ''); }
        if ($fall === 'shadow-session') { $shadowAendern(function (&$s) { $s['sitzung'] = 'alte-sitzung'; }); }
        if ($fall === 'leer') { $ck->WriteAttributeString('Geraete', '[]'); $ck->WriteAttributeString('Schnappschuss', '{}'); }
        $cg->RequestAction('Befehl', $action);
        $erwartet = match ($fall) {
            'frisch' => [], 'liste', 'listenempfang' => [$listenPfad],
            'shadow', 'shadow-runtime', 'shadow-session' => [$shadowPfad], default => [$listenPfad, $shadowPfad],
        };
        check('Kontrollierter Refresh ' . $state . '/' . $action . '/' . $fall,
            $ct->reads === $erwartet && count($ct->posts) === 1 && $GLOBALS['EUREKA_CONTROL_LOCKS'] === []);
        $cg->RequestAction('Befehl', $action);
        check('Doppelbedienung nach Refresh ohne weiteren GET/POST ' . $state . '/' . $fall,
            $ct->reads === $erwartet && count($ct->posts) === 1);
    }
}
foreach (['liste', 'shadow'] as $phase) {
    foreach ([401, 429, 500, 'parser', 'transport'] as $fehler) {
        $reset();
        if ($phase === 'liste') { $ck->WriteAttributeInteger('GeraeteStand', time() - 121); }
        else { $shadowAendern(function (&$s) { $s['zeit'] -= 121; }); }
        $ct->leseAntwort = static function () use ($fehler): array {
            if ($fehler === 'transport') { throw new RuntimeException('offline-secret'); }
            return ['status' => is_int($fehler) ? $fehler : 200, 'response' => '{'];
        };
        // Die Bedienvorbereitung wiederholt auch GET-401 nicht.
        $logStart = count($GLOBALS['LOG']);
        $cg->RequestAction('Befehl', 2);
        $meldungen = implode('\n', array_slice($GLOBALS['LOG'], $logStart));
        check('Fehlgeschlagener Refresh sperrt Steuerung ' . $phase . '/' . $fehler,
            count($ct->reads) === 1 && $ct->posts === [] && str_contains($meldungen, 'Aktualisierung/Prüfung fehlgeschlagen') &&
            !str_contains($meldungen, 'offline-secret') && $GLOBALS['EUREKA_CONTROL_LOCKS'] === []);
        $cg->RequestAction('Befehl', 2);
        check('Refresh-Abkühlzeit verhindert weitere Abrufe ' . $phase . '/' . $fehler,
            count($ct->reads) === 1 && $ct->posts === [] && ($fehler !== 429 || $ck->ReadAttributeInteger('WarteBis') > time()));
    }
}
foreach (['anderes-geraet', 'offline', 'unbekannt', 'modell', 'shadow-id', 'shadow-offline', 'shadow-konflikt', 'zustand', 'sitzung', 'bindung', 'variable'] as $fall) {
    $reset(); $ck->WriteAttributeInteger('GeraeteStand', time() - 121);
    $ct->leseAntwort = function (string $p) use ($fall, $ck, $cg, $getAntwort): array {
        $g = e20Fixture()['geraet']['roh'];
        switch ($fall) {
            case 'anderes-geraet': $g['deviceId'] = 'other'; break;
            case 'offline': $g['connected'] = false; break;
            case 'unbekannt': unset($g['connected']); break;
            case 'modell': $g['product']['sn8'] = 'other'; break;
            case 'sitzung': $ck->WriteAttributeString('BuendelRevision', 'other'); break;
            case 'bindung': $cg->props['GeraetID'] = 'other'; break;
            case 'variable': $GLOBALS['OBJ'][22284]['ident'] = 'other'; break;
        }
        return $getAntwort([$g]);
    };
    if (str_starts_with($fall, 'shadow-') || $fall === 'zustand') {
        $shadowAendern(function (&$s) use ($fall) {
            if ($fall === 'shadow-id') { $s['roh']['deviceId'] = 'other'; }
            if ($fall === 'shadow-offline') { $s['roh']['connected'] = false; }
            if ($fall === 'shadow-konflikt') { $s['roh']['parentState'] = 'pause'; }
            if ($fall === 'zustand') { $s['roh']['state'] = $s['roh']['parentState'] = 'upgrading'; }
        });
    }
    $cg->RequestAction('Befehl', 2);
    check('Vollständige Neuprüfung nach Listenrefresh ' . $fall, count($ct->reads) === ($fall === 'shadow-id' ? 0 : 1) && $ct->posts === []);
    $GLOBALS['OBJ'][22284]['ident'] = 'Befehl';
}
foreach (['other', 'stopp', 'auto', 'cloud-limit'] as $fall) {
    $reset(); $lesedaten(); $ck->WriteAttributeInteger('GeraeteStand', time() - 121);
    if ($fall === 'cloud-limit') { $ck->WriteAttributeInteger('WarteBis', time() + 60); }
    $r = json_decode($ck->ForwardData($anfrage($fall === 'stopp' ? 'stopp' : 'pause',
        $fall === 'other' ? 'other' : 'offline-e20', $fall === 'auto' ? '' : 'manuell')), true);
    check('Kein Refresh oder POST vor sicherer Bedienfreigabe ' . $fall, !$r['ok'] && $ct->reads === [] && $ct->posts === []);
}

foreach (['id', 'offline', 'invalid-online', 'konflikt', 'modell', 'sitzung', 'bindung', 'zustand'] as $fall) {
    $reset(); $shadowAendern(function (&$s) { $s['zeit'] -= 121; });
    $ct->leseAntwort = function () use ($fall, $ck, $cg, $getAntwort): array {
        $roh = e20Fixture()['geraet']['stand']['roh'];
        switch ($fall) {
            case 'id': $roh['deviceId'] = 'other'; break;
            case 'offline': $roh['connected'] = false; break;
            case 'invalid-online': $roh['connected'] = 'true'; break;
            case 'konflikt': $roh['parentState'] = 'pause'; break;
            case 'modell': $roh['productModel'] = 'other'; break;
            case 'sitzung': $ck->WriteAttributeString('BuendelRevision', 'other'); break;
            case 'bindung': $cg->props['GeraetID'] = 'other'; break;
            case 'zustand': $roh['state'] = $roh['parentState'] = 'upgrading'; break;
        }
        return $getAntwort($roh);
    };
    $cg->RequestAction('Befehl', 2);
    check('Neu gelesener Shadow wird vollständig geprüft ' . $fall, $ct->reads === [$shadowPfad] && $ct->posts === []);
}
foreach (['cache-id', 'raw-id'] as $fall) {
    $reset(); $lesedaten();
    $shadowAendern(function (&$s) use ($fall) {
        $s['zeit'] -= 121;
        if ($fall === 'cache-id') { $s['deviceId'] = 'other'; } else { $s['roh']['deviceId'] = 'other'; }
    });
    $cg->RequestAction('Befehl', 2);
    check('Falsch zugeordneter alter Shadow wird nicht durch Nachlesen repariert ' . $fall, $ct->reads === [] && $ct->posts === []);
}

// Suche und Polling behalten ihre normale GET-401-Tokenrotation. Ein fremder
// Sitzungswechsel während des GET darf dagegen keinen neuen Cache erzeugen.
foreach (['liste', 'shadow'] as $phase) {
    $reset();
    $cacheVorher = $ck->ReadAttributeString($phase === 'liste' ? 'Geraete' : 'Schnappschuss');
    $ct->leseAntwort = function () use ($ck, $phase, $getAntwort): array {
        $ck->WriteAttributeString('BuendelRevision', 'fremde-anmeldung');
        return $getAntwort($phase === 'liste' ? [e20Fixture()['geraet']['roh']] : e20Fixture()['geraet']['stand']['roh']);
    };
    if ($phase === 'liste') { $ck->GeraeteSuchen(); }
    else { $ck->ForwardData(json_encode(['Aktion' => 'zustand', 'Geraet' => 'offline-e20'])); }
    check('Fremde Sitzung während normalem GET überschreibt keinen Cache ' . $phase,
        $ck->ReadAttributeString($phase === 'liste' ? 'Geraete' : 'Schnappschuss') === $cacheVorher && $ct->posts === []);
}

$reset(); $cg->RequestAction('Befehl', 2);
$marker = json_decode($ck->GetBuffer('EurekaLetzteBedienung'), true);
check('Erfolgsmarker ist an genau den angenommenen Pauseverbrauch gebunden',
    $marker === ['verbrauch' => $ck->ReadAttributeString('EurekaBefehlsversuch'), 'befehl' => 'pause']);
$lesedaten('pause');
$cg->RequestAction('Befehl', 1);
check('Pause -> Fortsetzen liest selbstständig einmal Shadow ohne Suchen/Aktualisieren',
    $ct->reads === [$shadowPfad] && count($ct->posts) === 2 && $ct->posts[1][2] === ['data' => RoboHubEurekaProtokoll::fortsetzen()]);
$cg->RequestAction('Befehl', 1);
check('Fortsetzen wird nach selbstständigem Nachlesen nicht doppelt gesendet', count($ct->posts) === 2 && $ct->reads === [$shadowPfad]);

$reset(); $cg->RequestAction('Befehl', 2); $lesedaten('cleaning');
$cg->RequestAction('Befehl', 1);
check('Cloudseitig noch alter Shadow nach Pause löst weder Start noch eine Nachleseschleife aus',
    $ct->reads === [$shadowPfad] && count($ct->posts) === 1);

foreach ([500, 'transport'] as $fehler) {
    $reset(); $lesedaten('pause');
    $ct->antwort = ['status' => 500, 'response' => '{}'];
    if ($fehler === 'transport') { $ct->vorTransport = static function (): void { throw new RuntimeException('offline-secret'); }; }
    $cg->RequestAction('Befehl', 2);
    $ct->vorTransport = null;
    $cg->RequestAction('Befehl', 1);
    check('Fehlgeschlagener POST löst keinen Folge-Refresh aus ' . $fehler, $ct->reads === [] && count($ct->posts) === ($fehler === 500 ? 1 : 0));
}

foreach (['liste', 'shadow'] as $phase) {
    $reset(); $lesedaten(); $normal = $ct->leseAntwort;
    if ($phase === 'liste') { $ck->WriteAttributeInteger('GeraeteStand', time() - 121); }
    else { $shadowAendern(function (&$s) { $s['zeit'] -= 121; }); }
    $ct->leseAntwort = static function (string $p) use ($normal): array { Fiber::suspend('GET'); return $normal($p); };
    $f = new Fiber(fn() => $ck->ForwardData($anfrage()));
    check('Parallele Gegenprobe hält GET an ' . $phase, $f->start() === 'GET');
    $zweiter = json_decode($ck->ForwardData($anfrage()), true);
    $ck->GeraeteSuchen();
    $poll = json_decode($ck->ForwardData(json_encode(['Aktion' => 'zustand', 'Geraet' => 'offline-e20'])), true);
    check('Parallele Bedienung/Suche/Polling erzeugen keinen GET-Sturm ' . $phase,
        !$zweiter['ok'] && !$poll['ok'] && count($ct->reads) === 1 && $ct->posts === []);
    $f->resume();
    check('Ein Besitzer beendet Refresh und sendet genau einmal ' . $phase,
        json_decode($f->getReturn(), true)['ok'] && count($ct->reads) === 1 && count($ct->posts) === 1 && $GLOBALS['EUREKA_CONTROL_LOCKS'] === []);
}

$reset(); $lesedaten();
$ct->vorTransport = function () use ($shadowAendern): void { $shadowAendern(function (&$s) { $s['zeit'] -= 121; }); };
$cg->RequestAction('Befehl', 2);
check('Frischeverlust vor I/O wird lokal abgewiesen und startet keinen späten Refresh', $ct->reads === [] && $ct->posts === []);
