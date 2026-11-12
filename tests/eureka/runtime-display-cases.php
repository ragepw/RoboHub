<?php
declare(strict_types=1);

foreach (['alt', 'fehlend', 'ungueltig', 'sitzungFehlt', 'cloudSperre'] as $fall) {
    $tests['Lokale Laufzeitanzeige ohne Cloud-Kontext: ' . $fall] = function () use ($fall): void {
        [$k, $t] = konto();
        if ($fall === 'alt') {
            $k->attrs['GeraeteStand'] = time() - 3600;
            shadowAendern($k, function (&$s): void { $s['zeit'] = time() - 3600; });
        } elseif ($fall === 'cloudSperre') {
            $k->attrs['WarteBis'] = time() + 3600;
        } elseif ($fall === 'sitzungFehlt') {
            $k->attrs['Buendel'] = ''; $k->attrs['BuendelRevision'] = '';
        } else {
            $k->attrs['Geraete'] = $k->attrs['Schnappschuss'] = $fall === 'fehlend' ? '' : '{invalid';
        }
        $attrs = $k->attrs; $puffer = $k->puffer;
        sicher($k->EurekaB8LaufzeitAnzeigen() === $GLOBALS['boot'] . ':' . $puffer['EurekaB8Laufzeit']);
        sicher($k->attrs === $attrs && $k->puffer === $puffer && $k->logs === [] && $t->calls === []);
        gesperrt(fn() => frei($k));
        gesperrt(fn() => $k->EurekaB8Testen(str_repeat('b', 64)));
        sicher($t->calls === []);
    };
}
foreach (['fehlt', 'ungueltig', 'keinAdmin', 'unbekannt', 'konto', 'hersteller', 'region', 'kernel', 'start', 'zuordnung', 'modul', 'geraetFehlt'] as $fall) {
    $tests['Lokale Laufzeitanzeige sperrt unverändert: ' . $fall] = function () use ($fall): void {
        [$k, $t] = konto();
        switch ($fall) {
            case 'fehlt': unset($k->puffer['EurekaB8Laufzeit']); break;
            case 'ungueltig': $k->puffer['EurekaB8Laufzeit'] = 'ungueltig'; break;
            case 'keinAdmin': $GLOBALS['anzeigeAdmin'] = false; break;
            case 'unbekannt': $GLOBALS['anzeigeBenutzer'] = '@unknown'; break;
            case 'konto': $k->InstanceID = 1; break;
            case 'hersteller': $k->props['Hersteller'] = 'anderer'; break;
            case 'region': $k->props['Region'] = 'us'; break;
            case 'kernel': $GLOBALS['runlevel'] = 0; break;
            case 'zuordnung': $GLOBALS['ziel']['ConnectionID'] = 1; break;
            case 'modul': $GLOBALS['ziel']['ModuleInfo']['ModuleID'] = 'anderes'; break;
            case 'geraetFehlt': $GLOBALS['fehlt'] = true; break;
            case 'start': $GLOBALS['boot'] = 0; break;
        }
        $attrs = $k->attrs; $puffer = $k->puffer;
        try {
            gesperrt(fn() => $k->EurekaB8LaufzeitAnzeigen());
            sicher($attrs === $k->attrs && $puffer === $k->puffer && $k->logs === [] && $t->calls === []);
        } finally { unset($GLOBALS['anzeigeAdmin'], $GLOBALS['anzeigeBenutzer']); }
    };
}
