<?php
declare(strict_types=1);

require_once __DIR__ . '/e20-fixture.php';
require_once __DIR__ . '/../../libs/RoboHubEureka.php';

// Ausschließlich im isolierten Prüfprozess: Kopie der echten Klasse mit
// Testfreigabe. Keine Quellcodedatei, Instanz oder Produktivkonstante ändern.
// Die Kopie besitzt nur die erfundene Geräte-ID aus der Fixture.
if (!class_exists('OfflineFreigegebenEureka', false)) {
    $quelle = file_get_contents(__DIR__ . '/../../libs/RoboHubEureka.php');
    $ident = RoboHubEurekaProtokoll::identifikation(e20Fixture()['geraet']);
    $ersatz = [
        'class RoboHubEureka implements RoboHubTreiber' => 'class OfflineFreigegebenEureka implements RoboHubTreiber',
        'private const BEFEHLE_FREIGEGEBEN = false;' => 'private const BEFEHLE_FREIGEGEBEN = true;',
        'private const BEFEHLS_ZUORDNUNGEN = [];' => 'private const BEFEHLS_ZUORDNUNGEN = ' . var_export([[
            'identifikation' => $ident, 'familie' => RoboHubEurekaProtokoll::FAMILIE,
            'beleg' => 'Nur Offline-Transportdouble',
        ]], true) . ';',
        "private const BEFEHLS_GERAET_ID = '';" => "private const BEFEHLS_GERAET_ID = 'offline-e20';",
    ];
    foreach ($ersatz as $von => $nach) {
        if (substr_count($quelle, $von) !== 1) { throw new RuntimeException('Offline-Klassenkopie nicht eindeutig.'); }
        $quelle = str_replace($von, $nach, $quelle);
    }
    $quelle = str_replace('__DIR__', var_export(realpath(__DIR__ . '/../../libs'), true), $quelle);
    eval(substr($quelle, 5));
}
