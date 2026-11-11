<?php
declare(strict_types=1);

/**
 * Rekonstruktion nach früherer Cloud-Untersuchung (Auftragsangaben 11.11.2026).
 * KEIN gespeicherter Cloud-Response und KEIN Gerätefreigabeprofil.
 * productModel/protoVersion und panel.name/versionName: Struktur aus lokaler
 * Referenz coordinator.py ce933e6; die E20-Werte belegt diese Referenz nicht.
 * platform: bisherige lokale Parserstruktur, kein E20-JSON-Beleg.
 * Produktkennung/stationType bleiben separat, da ursprünglicher JSON-Pfad fehlt.
 * Geräte-ID und Zustandsdaten sind ausschließlich erfundene Offline-Testwerte.
 */
function e20Fixture(): array
{
    return [
        'herkunft' => 'Frühere Cloud-Untersuchung laut Auftrag; lokal rekonstruierte Fixture',
        'beobachteteMetadaten' => [
            'Produktkennung' => '750Y0015',
            'Stationstyp' => 'dust_station',
        ],
        'geraet' => [
            'id' => 'offline-e20', 'modell' => 'E20 Evo Plus', 'online' => true,
            'roh' => [
                'deviceId' => 'offline-e20', 'nickname' => 'Offline Eureka', 'connected' => true,
                'product' => ['productModel' => 'E20 Evo Plus', 'protoVersion' => 0, 'platform' => 'robozone'],
                'panel' => ['name' => 'T0xB8_750Y0015.zip', 'versionName' => '2.5.4'],
            ],
            'stand' => ['deviceId' => 'offline-e20', 'zeit' => 1800000000,
                'roh' => ['state' => 'cleaning', 'parentState' => 'cleaning', 'batteryLevel' => 87, 'deviceFault' => []]],
        ],
    ];
}
