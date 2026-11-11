<?php
declare(strict_types=1);

/**
 * Anonymisierte Struktur des live bestätigten Gerätecache laut Auftrag 11.11.2026:
 * ROBOHUB_GeraeteListe, geraete[0].roh.product bzw. .panel.
 * Keine erneute Live-Abfrage; kein Original-Export und kein Gerätefreigabeprofil.
 * SN8 ist eine Produktkennung, keine individuelle Seriennummer.
 * Geräte-ID und Zustandsdaten sind erfundene Offline-Testwerte.
 */
function e20Fixture(): array
{
    return [
        'herkunft' => 'Live bestätigter Gerätecache laut Auftrag; anonymisierte Fixture',
        'geraet' => [
            'id' => 'offline-e20', 'modell' => 'E20 Evo Plus', 'online' => true,
            'roh' => [
                'deviceId' => 'offline-e20', 'nickname' => 'Offline Eureka', 'connected' => true,
                'product' => ['productModel' => 'E20 Evo Plus', 'sn8' => '750Y0015', 'protoVersion' => 0,
                    'platform' => 'robozone', 'stationType' => 'dust_station',
                    'productName' => 'Eureka_E20 Evo Plus_750Y0015',
                    'plugin' => ['name' => 'T0xB8_750Y0015.zip', 'versionName' => '2.5.4']],
                'panel' => ['name' => 'T0xB8_750Y0015.zip', 'versionName' => '2.5.4'],
            ],
            'stand' => ['deviceId' => 'offline-e20', 'zeit' => 1800000000,
                'roh' => ['state' => 'cleaning', 'parentState' => 'cleaning', 'batteryLevel' => 87, 'deviceFault' => [], 'connected' => true]],
        ],
    ];
}
