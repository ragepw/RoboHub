<?php
declare(strict_types=1);

// Echter Geräte-/Konto-/GET-/Planpfad, ausschließlich erfundene Transportdoubles.
abschnitt('Eureka: neuer Auftrag aus Schlaf gegenüber Fortsetzen');
foreach ([
    ['ERP_model', 'sleep', 'no_cleaning_mission', 'start'],
    ['ERP_model', 'sleep', 'have_cleaning_mission', null],
    ['ERP_model', 'sleep', null, null],
    ['sleep_pause', 'sleep', 'have_cleaning_mission', 'resume'],
    ['sleep_pause', 'sleep', 'no_cleaning_mission', null],
    ['charging', 'charging', 'no_cleaning_mission', 'start'],
    ['charging_finish', 'charging_finish', 'no_cleaning_mission', 'start'],
    ['unknown_sleep', 'sleep', 'no_cleaning_mission', null],
    ['charging', 'cleaning', 'no_cleaning_mission', null],
] as [$state, $parent, $mission, $aktion]) {
    foreach (['regulaer', 'bedienrefresh'] as $weg) {
        foreach (['stark-aus', 'fan-ungueltig', 'art-wasser-widerspruch'] as $auswahl) {
            $reset('stop');
            $cg->RequestAction('EurekaReinigungsart', 0);
            $cg->RequestAction('EurekaStartSaugstufe', 3);
            $cg->RequestAction('EurekaStartWasserstufe', 0);
            // Manipulierte Bestandswerte müssen auch hinter der Variablenaktion sperren.
            if ($auswahl === 'fan-ungueltig') { $cg->SetValue('EurekaStartSaugstufe', 4); }
            if ($auswahl === 'art-wasser-widerspruch') { $cg->SetValue('EurekaStartWasserstufe', 2); }
            $roh = e20Fixture()['geraet']['stand']['roh'];
            unset($roh['connected'], $roh['cleanMissionState']);
            $roh['state'] = $state; $roh['parentState'] = $parent;
            $roh['batteryLevel'] = 100; $roh['deviceFault'] = [];
            if ($mission !== null) { $roh['cleanMissionState'] = $mission; }
            $ct->leseAntwort = static fn(): array => $getAntwort($roh);
            if ($weg === 'regulaer') {
                $ck->ForwardData(json_encode(['Aktion' => 'zustand', 'Geraet' => 'offline-e20']));
            } else {
                $shadowAendern(static function (&$s): void { $s['zeit'] -= 121; });
            }
            $logStart = count($GLOBALS['LOG']);
            $cg->RequestAction('Befehl', 1);
            $s = json_decode($ck->ReadAttributeString('Schnappschuss'), true)['offline-e20']['p'];
            $name = $state . '/' . $parent . '/' . ($mission ?? 'fehlt') . '/' . $weg . '/' . $auswahl;
            check('Sleep-Start: vollständiger GET-Stand ohne erfundenen B8-Zusatz ' . $name,
                $ct->reads === [$shadowPfad] && $s['roh'] === $roh && !isset($s['b8']));
            $erwartet = $aktion === 'resume' ? 'AA0DB800000000000002221C00FB' :
                ($aktion === 'start' && $auswahl === 'stark-aus' ? 'AA15B800000000000002220200020008030000000000' : null);
            check('Sleep-Start: Neuauftrag mit Stark/Aus oder parameterfreies Continue ' . $name,
                $erwartet === null ? $ct->posts === [] :
                count($ct->posts) === 1 && $ct->posts[0][2] === ['data' => $erwartet]);
            if ($state === 'ERP_model' && $mission === 'no_cleaning_mission') {
                $meldung = implode('\n', array_slice($GLOBALS['LOG'], $logStart));
                check('Sleep-Start: ERP-Neuauftrag eindeutig, Auswahl separat geprüft ' . $name,
                    !str_contains($meldung, 'zustandsgruppe-nicht-belegt') &&
                    RoboHubEurekaZustand::schlafAktion($s, time()) === 'start');
            }
        }
    }
}
