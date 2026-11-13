<?php
declare(strict_types=1);

// Eigenständiges natives Symcon-Skript, keine Moduländerung nötig.
// Ausschließlich letzter nativer GET-Cache: kein Aktualisieren/ForwardData/Befehl.
// Nur vorbereitet; vollständig in der PHP-Konsole ausführen, niemals den Snapshot selbst ausgeben.
set_error_handler(static function (): never { throw new RuntimeException(); });
try {
    $konto = 45174;
    $geraet = 22708;
    if (IPS_GetInstance($konto)['ModuleInfo']['ModuleID'] !== '{DB269BEC-0CA6-4E6A-83B8-78FF8CFB2DB0}') {
        throw new RuntimeException();
    }
    if (IPS_GetInstance($geraet)['ModuleInfo']['ModuleID'] !== '{631DA85B-5985-4C25-B222-E47FDD49E302}' ||
        IPS_GetInstance($geraet)['ConnectionID'] !== $konto ||
        IPS_GetProperty($konto, 'Hersteller') !== 'eureka' || IPS_GetProperty($konto, 'Region') !== 'eu') {
        throw new RuntimeException();
    }
    $id = IPS_GetProperty($geraet, 'GeraetID');
    if (!is_string($id) || $id === '') { throw new RuntimeException(); }
    $snapshot = json_decode(IPS_GetSnapshot(), false, 512, JSON_THROW_ON_ERROR);
    $attribute = $snapshot->objects->{'ID' . $konto}->data->attributes ?? null;
    if (!$attribute instanceof stdClass || !is_string($attribute->Schnappschuss ?? null)) {
        throw new RuntimeException();
    }
    // stdClass erhält den Unterschied zwischen JSON-Objekt und JSON-Liste.
    $cache = json_decode($attribute->Schnappschuss, false, 512, JSON_THROW_ON_ERROR);
    $stand = $cache->{$id}->p ?? null;
    if (!$stand instanceof stdClass || ($stand->deviceId ?? null) !== $id ||
        ($stand->statusquelle->quelle ?? null) !== 'shadow-get' ||
        !$stand->roh instanceof stdClass) {
        throw new RuntimeException();
    }
    $soll = [];
    foreach (['EurekaReinigungsart', 'EurekaStartSaugstufe', 'EurekaStartWasserstufe', 'EurekaTeppichmodus'] as $ident) {
        $vid = @IPS_GetObjectIDByIdent($ident, $geraet);
        $wert = $vid === false ? null : GetValue($vid);
        $soll[$ident] = is_int($wert) && in_array($wert, match ($ident) {
            'EurekaReinigungsart' => [0, 2], 'EurekaStartSaugstufe' => [1, 2, 3],
            'EurekaStartWasserstufe' => [0, 1, 2, 3], default => [0, 1],
        }, true) ? $wert : null;
    }
    $rueckmeldung = [];
    foreach (['fanLevel' => [0, 1, 2, 3, 4, 5], 'waterLevel' => [0, 1, 2, 3]] as $feld => $werte) {
        $wert = $stand->roh->{$feld} ?? null;
        $rueckmeldung[$feld] = (is_int($wert) || is_string($wert)) &&
            in_array($wert, [...$werte, ...array_map('strval', $werte)], true) ? (int)$wert : null;
    }
    $auto = $stand->roh->autoType ?? null;
    $rueckmeldung['autoType'] = is_string($auto) && in_array($auto,
        ['auto_cleaning', 'room_cleaning', 'area_cleaning'], true) ? $auto : null;
    // Keine Umdeutung von Cloudstrings in den B8-Modus und kein Teppich-Shadow-Alias.
    $ausgabe = ['quelle' => 'letzter-nativer-shadow-get-cache',
        'empfangen' => is_int($stand->zeit ?? null) ? $stand->zeit : null,
        'frische' => !is_int($stand->zeit ?? null) || $stand->zeit <= 0 || $stand->zeit > time()
            ? 'lokale-zeit-ungueltig' : (time() - $stand->zeit <= 120 ? 'lokal-bis-120s' : 'lokal-aelter-120s'),
        'lokaleAuswahl' => $soll,
        'startMode' => 8,
        'zuletztGesendeteParameter' => null,
        'letzteHerstellerRueckmeldung' => $rueckmeldung,
        'bestaetigterAktuellerIstwert' => null,
        'vergleich' => [
            'fan' => $soll['EurekaStartSaugstufe'] === null || $rueckmeldung['fanLevel'] === null
                ? null : $soll['EurekaStartSaugstufe'] === $rueckmeldung['fanLevel'],
            'water' => $soll['EurekaStartWasserstufe'] === null || $rueckmeldung['waterLevel'] === null
                ? null : $soll['EurekaStartWasserstufe'] === $rueckmeldung['waterLevel'],
            'mode' => null, 'teppichmodus' => null,
        ]];
    // Snapshot enthält sensible Daten: weder loggen noch speichern/weiterreichen.
    unset($snapshot, $attribute, $cache, $stand, $id);
    echo json_encode($ausgabe, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $e) {
    // Keine Exceptions oder nativen Fehlermeldungen mit möglichen Rohwerten ausgeben.
    echo "{\"fehler\":\"Lokaler Shadow nicht eindeutig lesbar; keine Aktualisierung ausgeloest.\"}\n";
} finally {
    restore_error_handler();
}
