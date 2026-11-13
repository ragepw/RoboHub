<?php
declare(strict_types=1);

// Im nativen Prüfstand mit gebundenem Konto und ausschließlich Transportdoubles.
abschnitt('Eureka: native Reinigungsauswahl');
$reset('stop');
$cg->SetValue('EurekaReinigungsart', -1);
$cg->ApplyChanges();
check('ApplyChanges sendet keine Befehle', $ct->reads === [] && $ct->posts === []);
foreach (['EurekaReinigungsart', 'EurekaStartSaugstufe', 'EurekaStartWasserstufe'] as $ident) {
    $v = $GLOBALS['OBJ'][IPS_GetObjectIDByIdent($ident, 22708)];
    check('Native aktive Auswahl ' . $ident, $v['action'] === true && $v['type'] === VARIABLETYPE_INTEGER
        && $v['pres']['PRESENTATION'] === VARIABLE_PRESENTATION_ENUMERATION
        && count(json_decode($v['pres']['OPTIONS'], true)) > 2);
}
foreach ([0, 2] as $art) {
    foreach ([1, 2, 3] as $fan) {
        foreach ($art === 0 ? [0] : [1, 2, 3] as $water) {
            $reset('stop');
            $cg->RequestAction('EurekaReinigungsart', $art);
            $cg->RequestAction('EurekaStartSaugstufe', $fan);
            $cg->RequestAction('EurekaStartWasserstufe', $water);
            $cg->ApplyChanges();
            check('Auswahl/ApplyChanges bleiben lokal und persistent', $ct->reads === [] && $ct->posts === []
                && $cg->GetValue('EurekaStartWasserstufe') === $water);
            (new ReflectionMethod(RoboHubGeraet::class, 'uebernimm'))->invoke($cg,
                (new RoboHubEureka())->normalisiere('E20 Evo Plus', observedE20Shadows()[0]['shadow']));
            check('Shadow überschreibt keine Startauswahl', $cg->GetValue('EurekaReinigungsart') === $art
                && $cg->GetValue('EurekaStartSaugstufe') === $fan && $cg->GetValue('EurekaStartWasserstufe') === $water);
            $ct->reads = [];
            $cg->RequestAction('Befehl', 1);
            // Unabhängiger Bytevektor statt Aufruf der getesteten Rahmenfunktion.
            $bytes = [0x15, 0xB8, 0, 0, 0, 0, 0, 0, 2, 0x22, 2, 0, 2, 0, 8, $fan, 0, $water, 0, 0];
            $hex = strtoupper(bin2hex(pack('C*', 0xAA, ...[...$bytes, (-array_sum($bytes)) & 255])));
            check('RequestAction Start mit belegten Bytes ' . $art . '/' . $fan . '/' . $water,
                count($ct->posts) === 1 && $ct->posts[0][2] === ['data' => $hex]);
        }
    }
}
$reset('stop'); $cg->RequestAction('EurekaReinigungsart', 0);
foreach ([['EurekaReinigungsart', 1], ['EurekaReinigungsart', 8], ['EurekaReinigungsart', '0'],
    ['EurekaStartSaugstufe', 0], ['EurekaStartSaugstufe', 4], ['EurekaStartSaugstufe', 5],
    ['EurekaStartSaugstufe', true], ['EurekaStartWasserstufe', 1], ['EurekaStartWasserstufe', 4]] as [$ident, $wert]) {
    $vorher = $cg->GetValue($ident); $abgewiesen = false;
    try { $cg->RequestAction($ident, $wert); } catch (Exception $e) { $abgewiesen = true; }
    check('Ungültige native Auswahl abgewiesen ' . $ident . '/' . json_encode($wert),
        $abgewiesen && $cg->GetValue($ident) === $vorher && $ct->reads === [] && $ct->posts === []);
}
$cg->RequestAction('EurekaReinigungsart', 2);
check('Wechsel zum Wischen verlangt Wasserwahl', $cg->GetValue('EurekaStartWasserstufe') === -1);
$abgewiesen = false;
try { $cg->RequestAction('EurekaStartWasserstufe', 0); } catch (Exception $e) { $abgewiesen = true; }
check('Kombimodus ohne Wasser abgewiesen', $abgewiesen);
foreach ([[0, 2], [2, 0], [1, 2], [-1, 0]] as [$art, $water]) {
    $reset('stop'); $cg->SetValue('EurekaReinigungsart', $art); $cg->SetValue('EurekaStartWasserstufe', $water);
    $cg->SetValue('EurekaStartSaugstufe', 2); $cg->RequestAction('Befehl', 1);
    check('Auch direkt manipulierte Kombination sperrt Start', $ct->posts === []);
}
$reset('stop'); $cg->props['Modell'] = 'anderes Modell'; $abgewiesen = false;
try { $cg->RequestAction('EurekaReinigungsart', 0); } catch (Exception $e) { $abgewiesen = true; }
check('Modellgebundene Auswahl', $abgewiesen && $ct->posts === []);
$cg->props['Modell'] = 'E20 Evo Plus';
$r = (new RoboHubEureka())->normalisiere('E20 Evo Plus', observedE20Shadows()[0]['shadow']);
check('Beobachtete Shadow-Istwerte getrennt angezeigt', $r['werte']['Saugstufe'] === '3'
    && $r['werte']['Wassermenge'] === '2' && $r['werte']['Reinigungsmodus'] === 'room_cleaning');
$r = (new RoboHubEureka())->normalisiere('E20 Evo Plus', []);
check('Fehlende Shadow-Parameter bleiben unbekannt', $r['werte']['Saugstufe'] === 'Unbekannt'
    && $r['werte']['Wassermenge'] === 'Unbekannt' && $r['werte']['Reinigungsmodus'] === 'Unbekannt');

// Zubehörmeldungen ohne belegte HTTPS-Semantik dürfen Modellfunktionen nicht verstecken.
$reset('stop');
(new ReflectionMethod(RoboHubGeraet::class, 'uebernimm'))->invoke($cg,
    (new RoboHubEureka())->normalisiere('E20 Evo Plus', ['has_mop' => 0]));
$cg->ApplyChanges();
$cg->RequestAction('EurekaReinigungsart', 2);
$cg->RequestAction('EurekaStartSaugstufe', 2);
$cg->RequestAction('EurekaStartWasserstufe', 3);
check('Wischwahl unabhängig von unbelegtem Zubehörstatus verfügbar',
    $cg->GetValue('EurekaReinigungsart') === 2 && $cg->GetValue('EurekaStartWasserstufe') === 3
    && $ct->posts === [] && $ct->reads === []);
$shadowAendern(function (&$s) { $s['roh']['deviceFault'] = [1]; });
$cg->RequestAction('Befehl', 1);
check('Gemeldeter Fehler bleibt Startsperre trotz verfügbarer Wischwahl', $ct->posts === []);
