<?php
declare(strict_types=1);
// Nur native Konto-Doubles; Signaturgeneratoren und echte Transporte im Runner deaktiviert.
define('ROBOHUB_DIAGNOSE_NUR_FIXTURE', true);
function IPS_IsAdministrator() { return $GLOBALS['anzeigeAdmin'] ?? true; }
function IPS_GetLoggedInUser() { return $GLOBALS['anzeigeBenutzer'] ?? '@admin'; }
function IPS_GetObjectIDByIdent(string $ident, int $parent): int { return $GLOBALS['pauseVariable'] ?? 22284; }
require __DIR__ . '/diagnostic-test.php';
require __DIR__ . '/pause-fixtures.php';

function pauseKonto(): array
{
    [$k, $t] = konto();
    $k->attrs['EurekaPauseTest'] = $k->attrs['EurekaBefehlsversuch'] = '{}';
    $k->puffer['EurekaSteuerLaufzeit'] = 'offline-control-runtime';
    shadowAendern($k, function (&$s): void {
        $s['statusquelle'] = ['quelle' => 'shadow-get', 'geraetezeit' => null];
        $s['sitzung'] = 'offline-session';
        $s['befehlsLaufzeit'] = 'offline-control-runtime';
        $s['kernelstart'] = IPS_GetKernelStartTime();
    });
    return [$k, $t];
}
function pauseAnfrage(RoboHubKonto $k, string $cmd = 'pause', bool $manuell = true, array $params = []): array
{
    return json_decode($k->ForwardData(json_encode(['Aktion' => 'befehl', 'Geraet' => 'offline-e20',
        'Befehl' => $cmd, 'Parameter' => $params, 'Bedienung' => $manuell ? 'manuell' : 'automatisch'])), true);
}
function pauseKontoFreigeben(RoboHubKonto $k): void
{
    $k->EurekaPauseTestFreigeben('offline-e20', 60, RoboHubEurekaPauseTest::BESTAETIGUNG);
}
$tests = [];
$tests['Ohne Freigabe kein regulärer Pauseversand'] = function (): void {
    [$k, $t] = pauseKonto(); sicher(!pauseAnfrage($k)['ok'] && $t->calls === []);
};
$tests['Native Freigabe sendet nichts; regulärer Pauseweg genau einmal, Shadow unverändert'] = function (): void {
    [$k, $t] = pauseKonto(); $vorher = $k->attrs['Schnappschuss'];
    pauseKontoFreigeben($k); sicher($t->calls === []);
    sicher(pauseAnfrage($k)['ok']); sicher(!pauseAnfrage($k)['ok']);
    sicher(count($t->calls) === 1 && $t->calls[0][3] === ['data' => 'AA0DB800000000000002221B00FC']);
    sicher($k->attrs['Schnappschuss'] === $vorher);
    sicher(json_decode($k->attrs['EurekaPauseTest'], true)['zustand'] === 'geschlossen');
    gesperrt(fn() => pauseKontoFreigeben($k));
    // Auch neuer Shadow, neuer Treiber und Attributrollback öffnen den Einmaltest nicht.
    shadowAendern($k, function (&$s): void { $s['zeit'] = time(); $s['roh']['batteryLevel'] = 86; });
    $k->attrs['EurekaBefehlsversuch'] = '{}';
    sicher(!pauseAnfrage($k)['ok'] && count($t->calls) === 1);
};
foreach (['start', 'resume', 'station', 'stopp', 'orten', 'pause-parameter', 'automatisch'] as $fall) {
    $tests['Andere Bedienungen trotz Freigabe gesperrt: ' . $fall] = function () use ($fall): void {
        [$k, $t] = pauseKonto(); pauseKontoFreigeben($k);
        $r = pauseAnfrage($k, in_array($fall, ['pause-parameter', 'automatisch'], true) ? 'pause' : $fall,
            $fall !== 'automatisch', $fall === 'pause-parameter' ? ['x' => 1] : []);
        sicher(!$r['ok'] && $t->calls === []);
        sicher(json_decode($k->attrs['EurekaPauseTest'], true)['zustand'] === 'bereit');
    };
}
foreach (['admin', 'unknown-user', 'user-change', 'account', 'region', 'variable', 'disabled', 'connection',
    'double-id', 'runtime', 'kernel', 'revision', 'token', 'cache', 'shadow', 'cloud-lock', 'offline', 'fault'] as $fall) {
    $tests['Native Bindung vor Versand sperrt: ' . $fall] = function () use ($fall): void {
        [$k, $t] = pauseKonto(); pauseKontoFreigeben($k);
        switch ($fall) {
            case 'admin': $GLOBALS['anzeigeAdmin'] = false; break;
            case 'unknown-user': $GLOBALS['anzeigeBenutzer'] = '@unknown'; break;
            case 'user-change': $GLOBALS['anzeigeBenutzer'] = '@other'; break;
            case 'account': $k->InstanceID = 45175; break;
            case 'region': $k->props['Region'] = 'us'; break;
            case 'variable': $GLOBALS['pauseVariable'] = 22285; break;
            case 'disabled': $GLOBALS['zielDeaktiviert'] = true; break;
            case 'connection': $GLOBALS['ziel']['ConnectionID'] = 45175; break;
            case 'double-id': $g = json_decode($k->attrs['Geraete'], true)[0]; $k->attrs['Geraete'] = json_encode([$g, $g]); break;
            case 'runtime': $k->puffer['EurekaSteuerLaufzeit'] = 'other'; break;
            case 'kernel': $GLOBALS['boot']--; break;
            case 'revision': $k->attrs['BuendelRevision'] = 'other'; break;
            case 'token': $k->attrs['Buendel'] = json_encode(['region' => 'eu', 'access_token' => jwt(time() - 1)]); break;
            case 'cache': $k->attrs['GeraeteStand'] = time() - 121; break;
            case 'shadow': shadowAendern($k, function (&$s): void { $s['zeit'] -= 121; }); break;
            case 'cloud-lock': $k->attrs['WarteBis'] = time() + 60; break;
            case 'offline': geraetAendern($k, function (&$g): void { $g['roh']['connected'] = false; }); break;
            case 'fault': shadowAendern($k, function (&$s): void { $s['roh']['deviceFault'] = [1]; }); break;
        }
        try { sicher(!pauseAnfrage($k)['ok'] && $t->calls === []); }
        finally { unset($GLOBALS['anzeigeAdmin'], $GLOBALS['anzeigeBenutzer'], $GLOBALS['pauseVariable'], $GLOBALS['zielDeaktiviert']); }
    };
}
foreach ([401, 429, 500, 307, 'json', 'timeout', 'exception'] as $fall) {
    $tests['Unklarer Transport endgültig verbraucht, ohne Refresh/Retry: ' . $fall] = function () use ($fall): void {
        [$k, $t] = pauseKonto(); pauseKontoFreigeben($k);
        $t->antwort = $fall === 'exception' ? function () { throw new RuntimeException('fixture-secret'); }
            : ['status' => is_int($fall) ? $fall : 200,
                'response' => $fall === 'json' ? '{' : ($fall === 'timeout' ? '{"errorCode":"0080005"}' : '{"errorCode":0}')];
        sicher(!pauseAnfrage($k)['ok']); sicher(!pauseAnfrage($k)['ok']);
        sicher(count($t->calls) === 1 && $t->calls[0][0] === 'POST');
        gesperrt(fn() => pauseKontoFreigeben($k));
    };
}
$tests['Widerruf bei HTTP-Vorbereitung und Freigabesemaphore'] = function (): void {
    [$k, $t] = pauseKonto(); pauseKontoFreigeben($k);
    $t->vorTransport = fn() => $k->EurekaPauseTestWiderrufen();
    sicher(!pauseAnfrage($k)['ok'] && $t->calls === []);
    $t->vorTransport = null; sicher(!pauseAnfrage($k)['ok']);
    gesperrt(fn() => pauseKontoFreigeben($k));
    [$k, $t] = pauseKonto(); pauseKontoFreigeben($k);
    $GLOBALS['lockFehler'] = 'RoboHubEureka_PauseTest_45174';
    try { sicher(!pauseAnfrage($k)['ok'] && $t->calls === []); } finally { unset($GLOBALS['lockFehler']); }
};
$tests['Neustart und flüchtiges Veto sperren Attributrollback'] = function (): void {
    [$k, $t] = pauseKonto(); pauseKontoFreigeben($k); $bereit = $k->attrs['EurekaPauseTest'];
    sicher(pauseAnfrage($k)['ok']); $k->attrs['EurekaPauseTest'] = $bereit;
    sicher(!pauseAnfrage($k)['ok'] && count($t->calls) === 1);
    $k->puffer['EurekaPauseTest'] = ''; sicher(!pauseAnfrage($k)['ok']);
    gesperrt(fn() => pauseKontoFreigeben($k));
};
foreach ([0, 61] as $dauer) {
    $tests['Ungültige Dauer ' . $dauer] = function () use ($dauer): void {
        $f = pauseFixture(); gesperrt(fn() => pauseFreigeben($f, $dauer)); sicher($f->attr === []);
    };
}
foreach (['wrong-id', 'empty-id', 'confirmation', 'persistent', 'buffer'] as $fall) {
    $tests['Keine implizite Ausgabe: ' . $fall] = function () use ($fall): void {
        $f = pauseFixture();
        if ($fall === 'persistent') { $f->attr = ['zustand' => 'unbekannt']; }
        if ($fall === 'buffer') { $f->buffer = ['zustand' => 'widerrufen']; }
        gesperrt(fn() => $f->test->freigeben($fall === 'wrong-id' ? 'other' : ($fall === 'empty-id' ? '' : 'offline-e20'), 60,
            $fall === 'confirmation' ? '' : RoboHubEurekaPauseTest::BESTAETIGUNG));
    };
}
foreach (['cacheZeit', 'verbindungEmpfangen', 'zeit'] as $zeitfeld) {
    foreach ([-121, -120, 1] as $versatz) {
        $tests['Listen-/Shadow-Frische ' . $zeitfeld . '/' . $versatz] = function () use ($zeitfeld, $versatz): void {
            $f = pauseFixture();
            if ($zeitfeld === 'cacheZeit') { $f->k['cacheZeit'] = $f->k['jetzt'] + $versatz; }
            elseif ($zeitfeld === 'zeit') { $f->k['geraet']['stand']['zeit'] = $f->k['jetzt'] + $versatz; }
            else { $f->k['geraet']['verbindungEmpfangen'] = $f->k['jetzt'] + $versatz; }
            if ($versatz === -120) { pauseFreigeben($f); } else { gesperrt(fn() => pauseFreigeben($f)); }
        };
    }
}
foreach (['absent', true, false, null, 'true'] as $connected) {
    foreach (['absent', true, false, null] as $online) {
        $tests['Shadow-Verbindung ' . json_encode([$connected, $online])] = function () use ($connected, $online): void {
            $f = pauseFixture();
            if ($connected !== 'absent') { $f->k['geraet']['stand']['roh']['connected'] = $connected; }
            if ($online !== 'absent') { $f->k['geraet']['stand']['roh']['online'] = $online; }
            if ($connected === true) { $f->k['geraet']['stand']['online'] = true; }
            $vorher = $f->k;
            if (in_array($connected, ['absent', true], true) && in_array($online, ['absent', true], true)) { pauseFreigeben($f); }
            else { gesperrt(fn() => pauseFreigeben($f)); }
            sicher($vorher === $f->k);
        };
    }
}
foreach (['stop', 'pause', 'charging', 'charging_finish', 'charging_pause', 'wiping_pause', 'error', 'unknown'] as $state) {
    $tests['Nur cleaning: ' . $state] = function () use ($state): void {
        $f = pauseFixture(); $f->k['geraet']['stand']['roh']['state'] = $f->k['geraet']['stand']['roh']['parentState'] = $state;
        gesperrt(fn() => pauseFreigeben($f));
    };
}
foreach (['wall-expiry', 'monotonic-expiry', 'wall-backwards', 'monotonic-backwards', 'reboot', 'session', 'user', 'id',
    'list-unknown', 'list-offline', 'list-online', 'raw-list-id', 'shadow-id', 'model', 'fault', 'list-fault', 'missing-fault', 'inconsistent', 'source', 'fake-online', 'token', 'modified-persistent'] as $fall) {
    $tests['Berechtigungsgegenprobe ' . $fall] = function () use ($fall): void {
        $f = pauseFixture(); pauseFreigeben($f);
        switch ($fall) {
            case 'wall-expiry': $f->k['jetzt'] += 60; break;
            case 'monotonic-expiry': $f->k['monoton'] += 60000; break;
            case 'wall-backwards': $f->k['jetzt']--; break;
            case 'monotonic-backwards': $f->k['monoton']--; break;
            case 'reboot': $f->k['boot'] = 'other'; break;
            case 'session': $f->k['revision'] = 'other'; break;
            case 'user': $f->k['benutzer'] = '@other'; break;
            case 'id': $f->k['geraet']['id'] = 'other'; break;
            case 'list-unknown': unset($f->k['geraet']['roh']['connected']); break;
            case 'list-offline': $f->k['geraet']['roh']['connected'] = false; break;
            case 'list-online': $f->k['geraet']['roh']['online'] = false; break;
            case 'raw-list-id': $f->k['geraet']['roh']['deviceId'] = 'other'; break;
            case 'shadow-id': $f->k['geraet']['stand']['roh']['deviceId'] = 'other'; break;
            case 'model': $f->k['geraet']['roh']['product']['sn8'] = 'other'; break;
            case 'fault': $f->k['geraet']['stand']['roh']['deviceFault'] = [1]; break;
            case 'list-fault': $f->k['geraet']['roh']['deviceFault'] = [1]; break;
            case 'missing-fault': unset($f->k['geraet']['stand']['roh']['deviceFault']); break;
            case 'inconsistent': $f->k['geraet']['stand']['roh']['parentState'] = 'pause'; break;
            case 'source': $f->k['geraet']['stand']['statusquelle']['quelle'] = 'other'; break;
            case 'fake-online': $f->k['geraet']['stand']['online'] = true; break;
            case 'token': $f->k['buendel']['access_token'] = jwt(time() + 15); break;
            case 'modified-persistent': $f->attr['bis']++; break;
        }
        gesperrt(fn() => $f->test->pruefen());
    };
}
foreach (['attrWrite', 'bufferWrite'] as $feld) {
    foreach (['grant', 'consume', 'begin'] as $phase) {
        $tests['Schreibfehler sperrt ' . $feld . '/' . $phase] = function () use ($feld, $phase): void {
            $f = pauseFixture();
            if ($phase !== 'grant') { pauseFreigeben($f); }
            if ($phase === 'begin') { $f->test->verbrauchen(); }
            $f->$feld = false;
            gesperrt(fn() => match ($phase) {
                'grant' => pauseFreigeben($f), 'consume' => $f->test->verbrauchen(), 'begin' => $f->test->vorTransport(true),
            });
            $f->$feld = true;
            gesperrt(fn() => $f->test->verbrauchen());
            gesperrt(fn() => pauseFreigeben($f));
        };
    }
}
$tests['B8-/Dock-Berechtigungen öffnen Pause nicht und bleiben unverändert'] = function (): void {
    [$k, $t] = pauseKonto();
    $k->attrs['EurekaDockVersuch'] = '{"zustand":"bereit"}';
    $k->puffer['EurekaDockLaufzeitfreigabe'] = '{"zustand":"ticket"}';
    $vorher = [$k->attrs['EurekaB8Diagnose'], $k->attrs['EurekaDockVersuch'],
        $k->puffer['EurekaB8Laufzeitfreigabe'], $k->puffer['EurekaDockLaufzeitfreigabe']];
    sicher(!pauseAnfrage($k)['ok'] && $t->calls === []);
    pauseKontoFreigeben($k); sicher(pauseAnfrage($k)['ok']);
    sicher($vorher === [$k->attrs['EurekaB8Diagnose'], $k->attrs['EurekaDockVersuch'],
        $k->puffer['EurekaB8Laufzeitfreigabe'], $k->puffer['EurekaDockLaufzeitfreigabe']]);
};
$tests['Zwei unabhängige Anforderungen konkurrieren unter derselben Gerätesperre'] = function (): void {
    [$k, $t] = pauseKonto(); pauseKontoFreigeben($k); $zweite = null;
    $nachSperre = function () use ($k, &$zweite, &$nachSperre): void {
        $sem = 'RoboHubEureka_Geraet_' . hash('sha256', 'eu:robozone:offline-e20');
        if (isset($GLOBALS['locks'][$sem])) { $zweite = pauseAnfrage($k); }
        else { $GLOBALS['nachSperre'] = $nachSperre; }
    };
    $GLOBALS['nachSperre'] = $nachSperre;
    try {
        sicher(pauseAnfrage($k)['ok'] && $zweite !== null && !$zweite['ok'] && count($t->calls) === 1);
        sicher($GLOBALS['locks'] === []);
    } finally { unset($GLOBALS['nachSperre']); }
};
$tests['Sperrfehler der Gerätesemaphore sendet nichts'] = function (): void {
    [$k, $t] = pauseKonto(); pauseKontoFreigeben($k);
    $GLOBALS['lockFehler'] = 'RoboHubEureka_Geraet_' . hash('sha256', 'eu:robozone:offline-e20');
    try { sicher(!pauseAnfrage($k)['ok'] && $t->calls === []); }
    finally { unset($GLOBALS['lockFehler']); }
};
foreach ([1, 60] as $sekunden) {
    $tests['Kurzfreigabe mit beiden Uhren bis vor der Grenze gültig: ' . $sekunden] = function () use ($sekunden): void {
        $f = pauseFixture(); pauseFreigeben($f, $sekunden);
        $f->k['jetzt'] += $sekunden - 1; $f->k['monoton'] += $sekunden * 1000 - 1;
        $f->test->verbrauchen(); $f->test->vorTransport(true);
        gesperrt(fn() => $f->test->vorTransport(true));
        gesperrt(fn() => $f->test->widerrufen());
    };
}
$tests['Widerruf vor Erteilung und Ablauf erlauben keine neue Freigabe'] = function (): void {
    $f = pauseFixture(); $f->test->widerrufen(); gesperrt(fn() => pauseFreigeben($f));
    $f = pauseFixture(); pauseFreigeben($f); $f->k['jetzt'] += 60;
    gesperrt(fn() => pauseFreigeben($f));
};
$fehler = 0;
foreach ($tests as $name => $fn) {
    try { $fn(); echo 'OK: ' . $name . "\n"; }
    catch (Throwable $e) { $fehler++; echo 'FEHLER: ' . $name . ' (' . $e->getMessage() . ")\n"; }
}
echo count($tests) . ' Pause-Testprüfungen, Fehler: ' . $fehler . "\n";
exit($fehler ? 1 : 0);
