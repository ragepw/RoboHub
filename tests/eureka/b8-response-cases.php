<?php
declare(strict_types=1);

// Bestandteil des vorhandenen Diagnoseprüfstands, ausschließlich synthetische Daten.
$b8Formate = [
    'hex' => [fn($h) => $h, 'hex'],
    'hex-klein' => [fn($h) => strtolower($h), 'hex'],
    'hex-praefix' => [fn($h) => '0x' . $h, 'hex'],
    'hex-byte-praefixe' => [fn($h) => '0x' . implode(', 0x', str_split($h, 2)), 'hex-getrennt'],
    'bytearray' => [fn($h) => array_values(unpack('C*', hex2bin($h))), 'bytearray'],
    'base64' => [fn($h) => base64_encode(hex2bin($h)), 'base64'],
    'data.data' => [fn($h) => ['data' => $h], 'hex'],
    'data-tief' => [fn($h) => ['data' => ['data' => ['data' => $h]]], 'hex'],
    'liste' => [fn($h) => [['data' => $h]], 'hex'],
    'json-hex' => [fn($h) => json_encode($h), 'json > hex'],
    'json-objekt' => [fn($h) => json_encode(['data' => $h]), 'json > hex'],
    'json-liste' => [fn($h) => json_encode([['data' => $h]]), 'json > hex'],
    'json-bytearray' => [fn($h) => json_encode(array_values(unpack('C*', hex2bin($h)))), 'json > bytearray'],
    'json-base64-tief' => [fn($h) => ['data' => json_encode(['data' => base64_encode(hex2bin($h))])], 'json > base64'],
    'json-doppelt' => [fn($h) => json_encode(json_encode(['data' => $h])), 'json > json > hex'],
];
foreach ([' ', "\t", "\r\n", ':', '-', ',', ';', ', '] as $i => $trenner) {
    $b8Formate['hex-trenner-' . $i] = [fn($h) => implode($trenner, str_split($h, 2)), 'hex-getrennt'];
}
foreach ($b8Formate as $name => [$kodieren, $kodierung]) {
    $tests['B8-Antwortformat ' . $name] = function () use ($kodieren, $kodierung): void {
        $r = RoboHubEurekaB8::antwort(antwort($kodieren(frame())));
        sicher($r['httpErfolg'] && $r['cloudErfolg'] && $r['frameGueltig'] && $r['plausibel']);
        sicher($r['felder'] === ['arbeit' => 6, 'akku' => 87, 'fehlerklasse' => 0, 'fehlercode' => 0]);
        sicher($r['diagnose']['kodierung'] === $kodierung && $r['diagnose']['frameLaenge'] === 37
            && $r['diagnose']['validierungsfehler'] === null);
    };
    // Jede Kodierung muss denselben strengen B8-Prüfer erreichen.
    foreach ([
        'pruefsumme' => [substr_replace(frame(), '00', -2), 'Rahmenprüfsumme'],
        'laenge' => [substr_replace(frame(), '01', 2, 2), 'Rahmenlänge'],
        'familie' => [substr_replace(frame(), 'B9', 4, 2), 'Rahmenkopf'],
        'reserviert' => [substr_replace(frame(), '01', 6, 2), 'Reservierte Rahmenbytes'],
        'typ' => [substr_replace(frame(), '04', 18, 2), 'Rahmentyp'],
        'kontrolltyp' => [frame(6, 87, 26, 2), 'Keine B8-Arbeitszustandsantwort'],
        'andere-abfrage' => [RoboHubEurekaProtokoll::rahmen([0x32, 6, ...array_fill(0, 24, 0)], 3), 'Keine B8-Arbeitszustandsantwort'],
        'kurz' => [frame(6, 87, 25), 'Unvollständige B8'],
    ] as $fall => [$hex, $fehlertext]) {
        $tests['B8-Sicherheitsprüfung ' . $name . '/' . $fall] = function () use ($kodieren, $hex, $fehlertext): void {
            $r = RoboHubEurekaB8::antwort(antwort($kodieren($hex)));
            sicher($r['frameGueltig'] === false && $r['plausibel'] === null && !isset($r['felder']));
            sicher(str_contains($r['diagnose']['validierungsfehler'], $fehlertext));
        };
    }
}
$b8Bytes = array_values(unpack('C*', hex2bin(frame())));
foreach ([
    'zwei-frames' => [frame(), frame(8)],
    'doppelt-identisch' => [frame(), frame()],
    'zwei-envelopes' => [['data' => frame()], ['data' => frame()]],
    'gueltig-und-ungueltig' => [frame(), substr_replace(frame(), '00', -2)],
    'ungueltig-und-gueltig' => [substr_replace(frame(), '00', -2), frame()],
    'gemischte-kodierungen' => [frame(), base64_encode(hex2bin(frame()))],
    'verkettete-frames' => frame() . frame(),
    'eingebettete-mehrdeutigkeit' => json_encode([['data' => frame()], ['data' => frame()]]),
    'kein-data' => ['payload' => frame()],
    'innerer-cloud-fehler' => ['errorCode' => 'fixture-secret', 'data' => frame()],
    'beliebiges-feld' => ['result' => ['data' => frame()]],
    'frame-als-schluessel' => [frame() => 'fixture-secret'],
    'kein-json-fragment' => 'prefix ' . json_encode(['data' => frame()]) . ' suffix',
    'negatives-byte' => [-1, ...$b8Bytes],
    'byte-ueberlauf' => [256, ...$b8Bytes],
    'byte-string' => array_replace($b8Bytes, [0 => '170']),
    'byte-float' => array_replace($b8Bytes, [0 => 170.0]),
    'byte-bool' => array_replace($b8Bytes, [0 => true]),
    'byte-objekt' => (object)$b8Bytes,
    'byte-zu-lang' => array_fill(0, 257, 170),
    'ungerades-hex' => frame() . 'A',
    'hex-unvollstaendiges-byte' => 'AA:2:B8',
    'hex-gemischte-praefixe' => '0xAA:24:B8',
    'hex-mit-muell' => frame() . ':garbage',
    'base64-ohne-padding' => rtrim(base64_encode(hex2bin(frame())), '='),
    'base64-fremdinhalt' => base64_encode('fixture-secret'),
    'base64-zeilenumbruch' => chunk_split(base64_encode(hex2bin(frame())), 8, "\n"),
    'base64-nicht-kanonisch' => substr(base64_encode(hex2bin(frame())), 0, -3) . 'B==',
    'zu-grosser-string' => str_repeat('AA', 1025),
    'ungueltiges-json' => '{"data":',
    'doppeltes-json-data' => '{"data":"' . frame() . '","data":"' . frame() . '"}',
    'escaped-json-data' => '{"data":"' . frame() . '","d\\u0061ta":"' . frame() . '"}',
] as $name => $data) {
    $tests['B8-Antwort verweigert ' . $name] = function () use ($data): void {
        // JSON_PRESERVE_ZERO_FRACTION ist hier nötig, um Float-Bytes zu simulieren.
        $a = ['status' => 200, 'response' => json_encode(['errorCode' => 0, 'data' => $data], JSON_PRESERVE_ZERO_FRACTION)];
        $r = RoboHubEurekaB8::antwort($a);
        sicher($r['frameGueltig'] === false && $r['plausibel'] === null && !isset($r['felder']));
        sicher(is_string($r['diagnose']['validierungsfehler']));
    };
}
foreach ([
    '{"errorCode":0,"errorCode":1,"data":"' . frame() . '"}',
    '{"errorCode":1,"errorCode":0,"data":"' . frame() . '"}',
    '{"errorCode":0,"data":"' . frame() . '","data":"' . frame() . '"}',
    '{"errorCode":0,"data":{"data":"' . frame() . '","d\\u0061ta":"' . frame() . '"}}',
    '{"errorCode":0,"data":"' . frame() . '","unknown":{"x":1,"x":2}}',
] as $i => $json) {
    $tests['B8 äußere JSON-Duplikate ' . $i] = function () use ($json): void {
        $r = RoboHubEurekaB8::antwort(['status' => 200, 'response' => $json]);
        sicher($r['cloudErfolg'] === false && $r['frameGueltig'] === null);
        sicher(str_contains($r['diagnose']['validierungsfehler'], 'Doppelte JSON-Felder'));
    };
}
$tests['B8 Strukturdiagnose anonymisiert Namen und enthält keine Werte'] = function (): void {
    $a = ['status' => 200, 'response' => json_encode(['errorCode' => 0,
        'fixture-secret-token' => ['deviceId' => 'fixture-secret-device', 'accessToken' => 'fixture-secret-access'],
        'data' => ['fixture-secret-field' => 'fixture-secret-value', 'data' => frame()]])];
    $r = RoboHubEurekaB8::antwort($a);
    sicher($r['frameGueltig']);
    $s = json_encode($r);
    foreach (['fixture-secret', frame(), 'deviceId', 'accessToken'] as $geheimnis) {
        sicher(!str_contains($s, $geheimnis));
    }
    sicher(in_array(['pfad' => '$.data.data', 'typ' => 'string'], $r['diagnose']['struktur'], true));
    sicher(in_array(['pfad' => '$.[feld1].[feld0]', 'typ' => 'string'], $r['diagnose']['struktur'], true));
};
$tests['B8 JSON-Tokenizer respektiert Strings, gleiche Namen in getrennten Objekten und UTF8'] = function (): void {
    $r = RoboHubEurekaB8::antwort(antwort(['unknown' => '"data":{},[\\\\,ä', 'data' => ['data' => frame()]]));
    sicher($r['frameGueltig']);
};
$tests['B8 Mindest- und Maximallänge behalten unveränderte Akzeptanz'] = function (): void {
    foreach ([26, 245] as $anzahl) {
        $r = RoboHubEurekaB8::antwort(antwort(frame(6, 87, $anzahl)));
        sicher($r['frameGueltig'] && $r['plausibel'] && $r['diagnose']['frameLaenge'] === $anzahl + 11);
    }
};
$tests['B8 rekursive Strukturen und JSON-Strings sind begrenzt'] = function (): void {
    $data = frame();
    for ($i = 0; $i < 14; $i++) { $data = ['data' => $data]; }
    $r = RoboHubEurekaB8::antwort(antwort($data));
    sicher($r['frameGueltig'] === false && str_contains($r['diagnose']['validierungsfehler'], 'Strukturlimit'));
    $data = frame();
    for ($i = 0; $i < 14; $i++) { $data = json_encode($data); }
    $r = RoboHubEurekaB8::antwort(antwort($data));
    sicher($r['frameGueltig'] !== true && count($r['diagnose']['struktur']) <= 48);
    $r = RoboHubEurekaB8::antwort(antwort(['unknown' => str_repeat('x', 65536), 'data' => frame()]));
    sicher($r['frameGueltig'] === null && str_contains($r['diagnose']['validierungsfehler'], 'Größenlimit'));
};
foreach (['hex', 'bytearray', 'base64', 'json-base64-tief'] as $format) {
    $tests['B8 bestehender Kontopfad ohne Speicherung: ' . $format] = function () use ($b8Formate, $format): void {
        [$k, $t] = konto();
        $f = frei($k);
        $t->antwort = antwort($b8Formate[$format][0](frame()));
        $vorher = $k->attrs;
        $r = json_decode($k->EurekaB8Testen($f), true);
        sicher($r['frameGueltig'] && count($t->calls) === 1 && $k->logs === []);
        unset($vorher['EurekaB8Diagnose']);
        $nachher = $k->attrs; unset($nachher['EurekaB8Diagnose']);
        sicher($vorher === $nachher);
        sicher(!str_contains(json_encode([$k->attrs, $k->puffer]), frame()));
        gesperrt(fn() => $k->EurekaB8Testen($f));
        sicher(count($t->calls) === 1);
    };
}
