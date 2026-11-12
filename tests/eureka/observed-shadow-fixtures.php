<?php
declare(strict_types=1);

/**
 * Vom Nutzer am 12.11.2026 gemeldete reale E20-Evo-Plus-Kombinationen.
 * Nur beobachtete Statusfelder, keine Kennungen, Tokens oder Zeitstempel.
 * connected war in keiner der gelieferten Antworten enthalten.
 */
function observedE20Shadows(): array
{
    $common = ['batteryLevel' => 100, 'deviceFault' => [], 'fanLevel' => '3',
        'waterLevel' => '2', 'autoType' => 'room_cleaning'];
    return array_map(static fn(array $row): array => [
        'shadow' => $common + ['state' => $row[0], 'parentState' => $row[1], 'cleanMissionState' => $row[2]],
        'zustand' => $row[3], 'anzeige' => $row[4],
    ], [
        ['cleaning', 'cleaning', 'no_cleaning_mission', 1, 'Reinigt'],
        ['pause', 'pause', 'have_cleaning_mission', 2, 'Pausiert'],
        ['charging_back', 'charging_back', 'no_cleaning_mission', 3, 'Fährt zur Station'],
        ['charging', 'charging', 'no_cleaning_mission', 4, 'Lädt'],
        ['collecting_dust', 'collecting_dust', 'no_cleaning_mission', 6, 'Entleert Staub'],
        ['ERP_model', 'sleep', 'no_cleaning_mission', 8, 'Schläft'],
    ]);
}
