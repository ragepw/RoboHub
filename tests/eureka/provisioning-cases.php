<?php
declare(strict_types=1);

// Nur im Diagnose-Harness laden. Schlüssel und signierte Dokumente existieren nur im RAM.
function provisionFixture(): array
{
    [$k, $t] = konto();
    unset($k->puffer['EurekaB8Laufzeitfreigabe']);
    $m = new ReflectionMethod(RoboHubKonto::class, 'eurekaB8Diagnose');
    $d = $m->invoke($k);
    $pair = sodium_crypto_sign_keypair();
    $sk = sodium_crypto_sign_secretkey($pair);
    $pk = base64_encode(sodium_crypto_sign_publickey($pair));
    $g = ['zweck' => RoboHubEurekaGenehmigung::ZWECK, 'konto' => 45174, 'instanz' => 22708, 'cloudGeraetId' => $GLOBALS['zielId'],
        'modell' => 'E20 Evo Plus', 'befehl' => RoboHubEurekaDiagnose::BEFEHL,
        'erwartet' => $GLOBALS['boot'] . ':' . $k->puffer['EurekaB8Laufzeit'], 'von' => time(), 'bis' => time() + 300];
    $sign = function (array $a) use ($sk): string {
        $roh = json_encode($a, JSON_THROW_ON_ERROR);
        return json_encode(['nutzlast' => base64_encode($roh),
            'signatur' => base64_encode(sodium_crypto_sign_detached($roh, $sk))], JSON_THROW_ON_ERROR);
    };
    return [$k, $t, $d, $pk, $g, $sign];
}

/** Zweites vollständig gültiges synthetisches Ziel, damit keine andere Prüfung den Wechsel verdeckt. */
function provisionZielWechseln(RoboHubKonto $k): void
{
    $alt = $GLOBALS['zielId'];
    $neu = $alt . '-anderes';
    $geraete = json_decode($k->attrs['Geraete'], true);
    $g = $geraete[0];
    $g['id'] = $g['roh']['deviceId'] = $neu;
    $s = json_decode($k->attrs['Schnappschuss'], true)[$alt];
    $s['p']['deviceId'] = $neu;
    foreach (['id', 'deviceId'] as $feld) {
        if (isset($s['p']['roh'][$feld])) { $s['p']['roh'][$feld] = $neu; }
    }
    $geraete[] = $g;
    $k->attrs['Geraete'] = json_encode($geraete);
    $snap = json_decode($k->attrs['Schnappschuss'], true); $snap[$neu] = $s;
    $k->attrs['Schnappschuss'] = json_encode($snap);
    $GLOBALS['zielId'] = $neu;
    $m = new ReflectionMethod(RoboHubKonto::class, 'eurekaB8Kontext');
    RoboHubEurekaDiagnose::pruefen($m->invoke($k));
}
foreach (['vorImport', 'nachImport', 'nachTicket', 'nachReservierung', 'vorTransport'] as $phase) {
    $tests['Signierte Gerätebindung: Zielwechsel/' . $phase] = function () use ($phase): void {
        [$k, $t, $d, $pk, $g, $sign] = provisionFixture();
        $doc = $sign($g);
        if ($phase !== 'vorImport') { $d->bereitstellen($doc, $pk); }
        if (in_array($phase, ['nachTicket', 'nachReservierung', 'vorTransport'], true)) { $ticket = frei($k); }
        if ($phase === 'nachReservierung') {
            $k->beiSpeichern = function () use ($k): void {
                $k->beiSpeichern = null; provisionZielWechseln($k);
            };
        } elseif ($phase === 'vorTransport') {
            $t->vorTransport = fn() => provisionZielWechseln($k);
        } else { provisionZielWechseln($k); }
        if ($phase === 'vorImport') { gesperrt(fn() => $d->bereitstellen($doc, $pk)); }
        elseif ($phase === 'nachImport') {
            gesperrt(fn() => frei($k));
            $GLOBALS['zielId'] = $g['cloudGeraetId'];
            $k->attrs['EurekaB8Diagnose'] = '{}';
            gesperrt(fn() => frei($k));
            gesperrt(fn() => $d->bereitstellen($doc, $pk));
        } else {
            gesperrt(fn() => $k->EurekaB8Testen($ticket));
            $GLOBALS['zielId'] = $g['cloudGeraetId'];
            $k->attrs['EurekaB8Diagnose'] = '{}';
            gesperrt(fn() => $k->EurekaB8Testen($ticket));
            gesperrt(fn() => frei($k));
        }
        sicher($t->calls === [] && $k->logs === []);
    };
}
foreach (['fehlt', 'leer', 'typ', 'steuerzeichen', 'zuLang', 'altV1'] as $fall) {
    $tests['Signierte Gerätebindung: ungültiges Schema/' . $fall] = function () use ($fall): void {
        [$k, $t, $d, $pk, $g, $sign] = provisionFixture();
        if ($fall === 'fehlt') { unset($g['cloudGeraetId']); }
        elseif ($fall === 'altV1') { unset($g['cloudGeraetId']); $g['zweck'] = 'robohub/eureka/b8/runtime/v1'; }
        else { $g['cloudGeraetId'] = match ($fall) { 'leer' => '', 'typ' => 123, 'steuerzeichen' => "ziel\n", 'zuLang' => str_repeat('x', 257) }; }
        gesperrt(fn() => $d->bereitstellen($sign($g), $pk));
        sicher($t->calls === [] && !isset($k->puffer['EurekaB8Laufzeitfreigabe']));
    };
}
$tests['Signierte Gerätebindung: alter Buffer ohne Gerätebindung bleibt geschlossen'] = function (): void {
    [$k, $t] = konto();
    $l = json_decode($k->puffer['EurekaB8Laufzeitfreigabe'], true); unset($l['cloudGeraetId']);
    $k->puffer['EurekaB8Laufzeitfreigabe'] = json_encode($l);
    gesperrt(fn() => frei($k)); sicher($t->calls === []);
};
$tests['Bereitstellung: Anzeige und fehlende Genehmigung bleiben geschlossen'] = function (): void {
    [$k, $t] = provisionFixture();
    gesperrt(fn() => frei($k));
    sicher(!isset($k->puffer['EurekaB8Laufzeitfreigabe']) && $t->calls === []);
};
$tests['Bereitstellung: signierte Erwartung, Ticket, genau ein Versuch'] = function (): void {
    [$k, $t, $d, $pk, $g, $sign] = provisionFixture();
    $d->bereitstellen($sign($g), $pk);
    sicher($t->calls === []);
    $ticket = frei($k); $k->EurekaB8Testen($ticket);
    gesperrt(fn() => $d->bereitstellen($sign($g), $pk));
    gesperrt(fn() => $k->EurekaB8Testen($ticket));
    sicher(count($t->calls) === 1);
};
foreach (['genehmigt', 'ticket', 'transport', 'timeout', 'widerruf', 'vorabWiderruf'] as $phase) {
    $tests['Bereitstellung: Wiederholung und Attributrollback gesperrt/' . $phase] = function () use ($phase): void {
        [$k, $t, $d, $pk, $g, $sign] = provisionFixture();
        if ($phase !== 'vorabWiderruf') { $d->bereitstellen($sign($g), $pk); }
        if (in_array($phase, ['ticket', 'transport', 'timeout'], true)) { $ticket = frei($k); }
        if ($phase === 'transport') { $k->EurekaB8Testen($ticket); }
        if ($phase === 'timeout') {
            $t->antwort = function () { throw new RuntimeException('timeout'); };
            gesperrt(fn() => $k->EurekaB8Testen($ticket));
        }
        if (in_array($phase, ['widerruf', 'vorabWiderruf'], true)) { $k->EurekaB8Widerrufen(); }
        $k->attrs['EurekaB8Diagnose'] = '{}';
        gesperrt(fn() => $d->bereitstellen($sign($g), $pk));
        if ($phase === 'genehmigt') { frei($k); }
        gesperrt(fn() => frei($k));
    };
}
$tests['Bereitstellung: Neustart auch bei gleicher Kernelzeit sperrt signiertes Replay'] = function (): void {
    [$k, $t, $d, $pk, $g, $sign] = provisionFixture();
    $doc = $sign($g); $d->bereitstellen($doc, $pk);
    $k->puffer = []; $k->attrs['EurekaB8Diagnose'] = '{}';
    gesperrt(fn() => $d->bereitstellen($doc, $pk));
    gesperrt(fn() => frei($k)); sicher($t->calls === []);
};
foreach (['zweck', 'konto', 'instanz', 'modell', 'befehl', 'erwartet', 'abgelaufen', 'zukunft', 'dauer', 'zusatz', 'typ'] as $fall) {
    $tests['Bereitstellung: ungültiger signierter Inhalt/' . $fall] = function () use ($fall): void {
        [$k, $t, $d, $pk, $g, $sign] = provisionFixture();
        if (in_array($fall, ['zweck', 'modell', 'befehl', 'erwartet'], true)) { $g[$fall] = 'ungueltig'; }
        elseif (in_array($fall, ['konto', 'instanz'], true)) { $g[$fall]++; }
        elseif ($fall === 'abgelaufen') { $g['von'] -= 400; $g['bis'] = time(); }
        elseif ($fall === 'zukunft') { $g['von'] += 30; }
        elseif ($fall === 'dauer') { $g['bis'] += 1; }
        elseif ($fall === 'zusatz') { $g['extra'] = true; }
        else { $g['von'] = (string)$g['von']; }
        gesperrt(fn() => $d->bereitstellen($sign($g), $pk));
        sicher(!isset($k->puffer['EurekaB8Laufzeitfreigabe']) && $t->calls === []);
    };
}
$tests['Bereitstellung: Fremdschlüssel, Manipulation und defekte Hülle'] = function (): void {
    [$k, $t, $d, $pk, $g, $sign] = provisionFixture();
    $doc = $sign($g);
    gesperrt(fn() => $d->bereitstellen($doc, base64_encode(random_bytes(32))));
    $h = json_decode($doc, true); $h['nutzlast'] = base64_encode('{}');
    foreach ([json_encode($h), '{}', str_repeat('x', 4097), 'null', '{'] as $bad) {
        gesperrt(fn() => $d->bereitstellen($bad, $pk));
    }
    sicher($t->calls === [] && !isset($k->puffer['EurekaB8Laufzeitfreigabe']));
};
$tests['Bereitstellung: konkurrierender administrativer Aufruf erhält kein zweites Recht'] = function (): void {
    [$k, $t, $d, $pk, $g, $sign] = provisionFixture();
    $doc = $sign($g);
    $GLOBALS['nachSperre'] = function () use ($d, $doc, $pk): void {
        gesperrt(fn() => $d->bereitstellen($doc, $pk));
    };
    $d->bereitstellen($doc, $pk);
    gesperrt(fn() => $d->bereitstellen($doc, $pk));
    sicher($t->calls === []); frei($k); gesperrt(fn() => frei($k));
};
$tests['Bereitstellung: Schreibfehler verhindert Bestätigung und Ticket'] = function (): void {
    [$k, $t, $d, $pk, $g, $sign] = provisionFixture();
    $k->pufferSchreibenSperren = true;
    gesperrt(fn() => $d->bereitstellen($sign($g), $pk));
    gesperrt(fn() => frei($k)); sicher($t->calls === []);
};
$tests['Bereitstellung: monotone Genehmigungsfrist schließt trotz zurückgestellter Wanduhr'] = function (): void {
    [$k, $t, $d, $pk, $g, $sign] = provisionFixture();
    $d->bereitstellen($sign($g), $pk);
    $l = json_decode($k->puffer['EurekaB8Laufzeitfreigabe'], true);
    $l['genehmigtMonotonBis'] = 0;
    $k->puffer['EurekaB8Laufzeitfreigabe'] = json_encode($l);
    gesperrt(fn() => frei($k)); gesperrt(fn() => $d->bereitstellen($sign($g), $pk));
};
function offlineSignieren(array $a, string $sk): array
{
    $proc = proc_open([PHP_BINARY, __DIR__ . '/../../tools/eureka-b8-signieren.php'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'], 3 => ['pipe', 'r']], $pipes);
    sicher(is_resource($proc));
    fwrite($pipes[0], json_encode($a)); fclose($pipes[0]);
    fwrite($pipes[3], base64_encode($sk)); fclose($pipes[3]);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return [proc_close($proc), $out, $err];
}
foreach (['korrekt', 'ohneBestaetigung', 'falscheBestaetigung', 'falscherSchluessel', 'ohneGeraet', 'ohneGeraetBestaetigung', 'falscheGeraetBestaetigung'] as $fall) {
    $tests['Externes Signierwerkzeug/' . $fall] = function () use ($fall): void {
        [$k, $t, $d, $pk, $g] = provisionFixture();
        $pair = sodium_crypto_sign_keypair();
        $sk = sodium_crypto_sign_secretkey($pair);
        $pk = base64_encode(sodium_crypto_sign_publickey($pair));
        $a = ['genehmigung' => $g, 'externGeprueftUndGenehmigt' => $g['erwartet'],
            'cloudGeraetExternGeprueftUndGenehmigt' => $g['cloudGeraetId']];
        if ($fall === 'ohneGeraet') { unset($a['genehmigung']['cloudGeraetId']); }
        if ($fall === 'ohneGeraetBestaetigung') { unset($a['cloudGeraetExternGeprueftUndGenehmigt']); }
        if ($fall === 'falscheGeraetBestaetigung') { $a['cloudGeraetExternGeprueftUndGenehmigt'] = 'falsch'; }
        if ($fall === 'ohneBestaetigung') { unset($a['externGeprueftUndGenehmigt']); }
        if ($fall === 'falscheBestaetigung') { $a['externGeprueftUndGenehmigt'] = 'falsch'; }
        [$code, $out, $err] = offlineSignieren($a, $fall === 'falscherSchluessel' ? 'invalid' : $sk);
        if ($fall === 'korrekt') {
            sicher($code === 0 && $err === ''); $d->bereitstellen(trim($out), $pk); frei($k);
        } else { sicher($code === 1 && $out === '' && !str_contains($err, $g['erwartet']) && !str_contains($err, $g['cloudGeraetId'])); }
        sicher($t->calls === []);
    };
}
$tests['Bereitstellung: öffentliche Signatur verlangt externen Vertrauensanker'] = function (): void {
    [$k, $t] = provisionFixture();
    gesperrt(fn() => $k->EurekaB8Bereitstellen('{}'));
    sicher(!isset($k->puffer['EurekaB8Laufzeitfreigabe']) && $t->calls === []);
};
$tests['Bereitstellung: ForwardData erreicht keinen administrativen Import'] = function (): void {
    [$k, $t, $d, $pk, $g, $sign] = provisionFixture();
    foreach (['EurekaB8Bereitstellen', 'bereitstellen', 'EurekaB8LaufzeitAnzeigen'] as $aktion) {
        $r = json_decode($k->ForwardData(json_encode(['Aktion' => $aktion, 'Geraet' => 'offline-e20', 'genehmigung' => $sign($g)])), true);
        sicher($r['ok'] === false);
    }
    sicher($t->calls === [] && !isset($k->puffer['EurekaB8Laufzeitfreigabe']));
};
