<?php
declare(strict_types=1);

// Im nativen Prüfstand mit gebundenem Konto und ausschließlich Transportdoubles.
abschnitt('Eureka: native Reinigungsauswahl');
$reset('stop');
$cg->SetValue('EurekaReinigungsart', -1);
$cg->ApplyChanges();
check('ApplyChanges sendet keine Befehle', $ct->reads === [] && $ct->posts === []);
$auswahlOptionen = [
    'EurekaReinigungsart' => [-1 => 'Bitte auswählen', 0 => 'Nur Saugen', 2 => 'Saugen und Wischen'],
    'EurekaStartSaugstufe' => [-1 => 'Bitte auswählen', 1 => 'Sanft', 2 => 'Standard', 3 => 'Stark'],
    'EurekaStartWasserstufe' => [-1 => 'Bitte auswählen', 0 => 'Aus', 1 => 'Niedrig', 2 => 'Standard', 3 => 'Hoch'],
];
foreach ($auswahlOptionen as $ident => $optionen) {
    $v = $GLOBALS['OBJ'][IPS_GetObjectIDByIdent($ident, 22708)];
    $profil = IPS_GetVariableProfile($v['pres']);
    $texte = [];
    foreach ($profil['Associations'] as $a) { $texte[$a['Value']] = $a['Name']; }
    check('WebFront: aktive Integer-Assoziationen mit deutschen Texten ' . $ident,
        $v['action'] === true && $v['type'] === VARIABLETYPE_INTEGER
        && $v['pres'] === 'RoboHub.' . $ident && $profil['ProfileType'] === VARIABLETYPE_INTEGER
        && $profil['StepSize'] === 0.0 && $texte === $optionen);
}
check('Bestandsvariable 41381 bleibt an Saugstufe in Instanz 22708 gebunden',
    IPS_GetObjectIDByIdent('EurekaStartSaugstufe', 22708) === 41381);
$webfrontDouble(41381, 3);
check('Integer 3 über Variablenaktion akzeptiert, auch ohne Reinigungsart',
    $cg->GetValue('EurekaStartSaugstufe') === 3 && $cg->GetValue('EurekaReinigungsart') === -1
    && $cg->GetValue('EurekaStartWasserstufe') === -1 && $ct->reads === [] && $ct->posts === []);
// Ein veralteter Profileintrag darf keine unbelegte Auswahl anbieten.
IPS_SetVariableProfileAssociation('RoboHub.EurekaStartSaugstufe', 5, 'Unbelegt', '', -1);
$cg->ApplyChanges();
check('Profilaktualisierung entfernt unbelegte Werte und erhält die Auswahl',
    count(IPS_GetVariableProfile('RoboHub.EurekaStartSaugstufe')['Associations']) === 4
    && $cg->GetValue('EurekaStartSaugstufe') === 3 && $ct->reads === [] && $ct->posts === []);
foreach ([0, 2] as $art) {
    foreach ([1, 2, 3] as $fan) {
        foreach ($art === 0 ? [0] : [1, 2, 3] as $water) {
            $reset('stop');
            $webfrontDouble(IPS_GetObjectIDByIdent('EurekaReinigungsart', 22708), $art);
            $webfrontDouble(41381, $fan);
            $webfrontDouble(IPS_GetObjectIDByIdent('EurekaStartWasserstufe', 22708), $water);
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
foreach (['modell', 'konto', 'hersteller', 'deaktiviert', 'typ'] as $fall) {
    foreach (['EurekaReinigungsart' => 0, 'EurekaStartSaugstufe' => 3, 'EurekaStartWasserstufe' => 0] as $ident => $wert) {
        $reset('stop'); $cg->SetValue('EurekaReinigungsart', 0);
        $vorher = array_map(fn($i) => $cg->GetValue($i), array_keys($auswahlOptionen));
        if ($fall === 'modell') { $cg->props['Modell'] = 'anderes Modell'; }
        if ($fall === 'konto') { $cg->parent = null; }
        if ($fall === 'hersteller') { $ck->props['Hersteller'] = 'dreame'; }
        if ($fall === 'deaktiviert') { $cg->props['Deaktiviert'] = true; }
        $meldung = '';
        try { $cg->RequestAction($ident, $fall === 'typ' ? (string)$wert : $wert); }
        catch (Exception $e) { $meldung = $e->getMessage(); }
        $erwartet = match ($fall) {
            'deaktiviert' => 'Eureka: Geräteinstanz ist deaktiviert.',
            'typ' => 'Eureka: Auswahlwert muss eine Ganzzahl sein.',
            default => 'Eureka: Einstellung erfordert Modell E20 Evo Plus und ein verbundenes Eureka-Konto.',
        };
        check('Auswahl sicher gesperrt mit genauer Ursache ' . $ident . '/' . $fall,
            $meldung === $erwartet && $vorher === array_map(fn($i) => $cg->GetValue($i), array_keys($auswahlOptionen))
            && $ct->reads === [] && $ct->posts === []);
        $cg->props['Modell'] = 'E20 Evo Plus'; $ck->props['Hersteller'] = 'eureka';
    }
}
$reset('stop');
foreach (array_keys($auswahlOptionen) as $ident) {
    foreach ([-1, 99, '3', 3.0, true, null, []] as $wert) {
        $vorher = array_map(fn($i) => $cg->GetValue($i), array_keys($auswahlOptionen));
        $abgewiesen = false;
        try { $cg->RequestAction($ident, $wert); } catch (Exception $e) { $abgewiesen = true; }
        check('Ungültiger Wert/Typ ohne Nebenwirkung ' . $ident . '/' . json_encode($wert),
            $abgewiesen && $vorher === array_map(fn($i) => $cg->GetValue($i), array_keys($auswahlOptionen))
            && $ct->reads === [] && $ct->posts === []);
    }
}
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

abschnitt('Eureka: native Teppichauswahl und ausdrückliches Anwenden');
foreach ([0, 1] as $wert) {
    $reset('stop');
    $cg->RequestAction('EurekaTeppichmodus', $wert);
    $cg->ApplyChanges();
    check('Teppichauswahl und Modulstart bleiben lokal', $ct->posts === [] && $ct->reads === []
        && $cg->GetValue('EurekaTeppichmodus') === $wert);
    $cg->RequestAction('EurekaTeppichAnwenden', 1);
    check('Ausdrückliches Anwenden sendet genau belegten Teppichframe', count($ct->posts) === 1
        && $ct->posts[0][2] === ['data' => RoboHubEurekaProtokoll::teppichmodus($wert)]);
}
foreach ([-1, 2, '1', true, null, [], 1.0] as $wert) {
    $reset('stop'); $vorher = $cg->GetValue('EurekaTeppichmodus'); $fehler = false;
    try { $cg->RequestAction('EurekaTeppichmodus', $wert); } catch (Exception $e) { $fehler = true; }
    check('Ungültige Teppichauswahl bleibt lokal gesperrt', $fehler && $ct->posts === [] && $ct->reads === []
        && $cg->GetValue('EurekaTeppichmodus') === $vorher);
}
$reset('stop'); $cg->SetValue('EurekaTeppichmodus', -1); $fehler = false;
try { $cg->RequestAction('EurekaTeppichAnwenden', 1); } catch (Exception $e) { $fehler = true; }
check('Anwenden ohne Auswahl sendet nichts', $fehler && $ct->posts === [] && $ct->reads === []);
$reset('stop'); $cg->RequestAction('EurekaTeppichmodus', 1);
$ct->vorTransport = function () use ($cg): void { $cg->SetValue('EurekaTeppichmodus', 0); };
try { $cg->RequestAction('EurekaTeppichAnwenden', 1); } catch (Exception $e) { }
check('Geänderte Teppichauswahl vor Transport sperrt Versand', $ct->posts === []);
