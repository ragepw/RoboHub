<?php
declare(strict_types=1);

// Der gesamte Transport bleibt offline; Konto, Konfigurator und Gerät sind echt.
require_once __DIR__ . '/../tests/eureka/e20-fixture.php';
require_once __DIR__ . '/../tests/eureka/observed-shadow-fixtures.php';
abschnitt('Eureka: Integration im Lesebetrieb');
class IntegrationEureka extends RoboHubEureka
{
    public array $pfade = [];
    public ?array $shadow = null;
    public bool $fehler = false;
    protected function transport(string $method, string $path, string $token = '', ?array $body = null): array
    {
        if ($this->fehler) { return ['status' => 503, 'response' => '{}']; }
        $this->pfade[] = [$method, explode('?', $path)[0]];
        $payload = rtrim(strtr(base64_encode(json_encode(['exp' => time() + 3600])), '+/', '-_'), '=');
        $data = match (explode('?', $path)[0]) {
            '/auth/login/email-password' => ['accessToken' => 'offline.' . $payload . '.fixture', 'refreshToken' => 'offline-refresh'],
            '/app/devices' => [e20Fixture()['geraet']['roh']],
            '/shadow/device/state' => [$this->shadow ?? e20Fixture()['geraet']['stand']['roh']],
            default => throw new RuntimeException('Unerwarteter Offline-Transport.'),
        };
        return ['status' => 200, 'response' => json_encode(['errorCode' => 0, 'data' => $data])];
    }
}
$eurekaFabrik = $GLOBALS['robohubTreiberFabrik'];
try {
    unset($GLOBALS['robohubTreiberFabrik']);
    $ek = new RoboHubKonto(9600);
    $ek->Create();
    check('Create erteilt keine B8-Laufzeitfreigabe', $ek->GetBuffer('EurekaB8Laufzeitfreigabe') === '');
    $ek->props['Hersteller'] = 'eureka';
    $auswahl = new ReflectionMethod(RoboHubKonto::class, 'treiber');
    check('Eureka ist registriert und wird als echter Treiber ausgewählt', $auswahl->invoke($ek) instanceof RoboHubEureka
        && str_contains($ek->GetConfigurationForm(), 'Eureka'));
    $et = new IntegrationEureka();
    $GLOBALS['robohubTreiberFabrik'] = static fn(string $marke) => $marke === 'eureka' ? $et : $eurekaFabrik($marke);
    $ek->props['Konto'] = 'offline@example.invalid';
    $ek->props['Passwort'] = 'offline-password';
    $ek->props['Region'] = 'eu';
    check('Eureka-Anmeldung über Konto erfolgreich, Passwort verworfen', str_contains($ek->Anmelden(), '✅') && $ek->props['Passwort'] === '');
    check('Anmeldung erteilt keine B8-Laufzeitfreigabe', $ek->GetBuffer('EurekaB8Laufzeitfreigabe') === '');
    $ek->ApplyChanges();
    check('ApplyChanges und Modulübernahme erteilen keine B8-Laufzeitfreigabe', $ek->GetBuffer('EurekaB8Laufzeitfreigabe') === '');
    $ek->MessageSink(0, 0, IPS_KERNELMESSAGE, [KR_READY]);
    check('Kernelstart erteilt keine B8-Laufzeitfreigabe', $ek->GetBuffer('EurekaB8Laufzeitfreigabe') === '');
    $eb = json_decode($ek->ReadAttributeString('Buendel'), true);
    check('Eureka speichert Token-Bündel und Sitzungsrevision ohne Passwort', isset($eb['access_token'], $eb['refresh_token'])
        && $ek->ReadAttributeString('BuendelRevision') !== '' && !str_contains(json_encode($eb), 'offline-password'));
    $ek->VerbindungPruefen();
    $eurekaIo = $ek->parent->InstanceID;
    $el = json_decode($ek->GeraeteListe(), true);
    check('Geräteerkennung erhält Modell E20 Evo Plus', ($el['geraete'][0]['modell'] ?? '') === 'E20 Evo Plus');
    $ef = json_decode(konsole($cfg->GetConfigurationForm()), true);
    $zeile = null;
    foreach ($ef['elements'] as $element) {
        foreach (($element['items'] ?? []) as $item) {
            foreach (($item['values'] ?? []) as $row) {
                if (($row['id'] ?? '') === 'offline-e20') { $zeile = $row; }
            }
        }
    }
    check('Konfigurator erzeugt Eureka-Gerät mit passendem Konto und Virtual IO', ($zeile['create'][0]['configuration']['Modell'] ?? '') === 'E20 Evo Plus'
        && ($zeile['create'][1]['configuration']['Hersteller'] ?? '') === 'eureka'
        && ($zeile['create'][2]['moduleID'] ?? '') === '{6179ED6A-FC31-413C-BB8E-1204150CF376}');
    $eg = new RoboHubGeraet(9601);
    $eg->Create();
    $eg->parent = $ek;
    $eg->props['GeraetID'] = 'offline-e20';
    $eg->props['Modell'] = 'E20 Evo Plus';
    $eg->ApplyChanges();
    $eg->Aktualisieren();
    check('Gerätemodul erhält Eureka-Zustand und Akku über das Konto', variable(9601, 'Zustand')['value'] === RoboHubModell::ZUSTAND_REINIGT
        && variable(9601, 'Akku')['value'] === 87);
    $es = json_decode($ek->ReadAttributeString('Schnappschuss'), true)['offline-e20']['p'] ?? [];
    check('Vorhandener Eureka-GET-Stand bleibt mit Gerätebindung und Zeit für spätere Offline-Planung erhalten',
        ($es['deviceId'] ?? '') === 'offline-e20' && is_int($es['zeit'] ?? null)
        && abs(time() - $es['zeit']) < 5 && ($es['roh']['state'] ?? '') === 'cleaning'
        && ($es['roh']['batteryLevel'] ?? null) === 87);
    check('Lesebetrieb gibt weiterhin keine Eureka-Einstellungen frei', $eg->ReadAttributeString('Schreibbar') === '[]'
        && $et->normalisiere('E20 Evo Plus', ['state' => 'pause'])['werte']['Zustand'] === RoboHubModell::ZUSTAND_PAUSIERT);
    check('Eureka-Statusabruf und Token-Erneuerung behalten ihre Timer', $eg->timers['Abruf'] === 30000
        && ($ek->timers['Erneuern'] ?? 0) > 0);
    foreach ([['pause', 'cleaning', 2, 120000], ['charging_back', 'cleaning', 3, 30000],
        ['charging', 'sleep', 4, 120000], ['charging_finish', 'sleep', 5, 120000],
        ['sleep', '', 8, 120000], ['stop', '', 0, 120000], ['unknown', 'cleaning', -1, 120000],
        ['error', 'cleaning', 7, 120000]] as [$state, $parent, $expected, $timer]) {
        $et->shadow = ['state' => $state, 'parentState' => $parent, 'batteryLevel' => 87,
            'connected' => true, 'deviceFault' => []];
        $eg->Aktualisieren();
        check('Eureka-Variablen und automatischer Takt: ' . $state,
            variable(9601, 'Zustand')['value'] === $expected && variable(9601, 'Erreichbar')['value'] === true
            && variable(9601, 'Akku')['value'] === 87 && $eg->timers['Abruf'] === $timer
            && variable(9601, 'ZustandRoh')['value'] === $state . ' / ' . $parent
            && $eg->ReadAttributeString('Schreibbar') === '[]');
    }
    foreach (observedE20Shadows() as $fixture) {
        $et->shadow = $fixture['shadow'];
        $eg->Aktualisieren();
        $stand = json_decode($ek->ReadAttributeString('Schnappschuss'), true)['offline-e20']['p'];
        $expectedRaw = $et->shadow['state'] . ' / ' . $et->shadow['parentState'];
        if ($fixture['zustand'] === 6) { $expectedRaw = 'Entleert Staub (' . $expectedRaw . ')'; }
        check('Beobachteter E20-Status bis Gerätemodul: ' . $et->shadow['state'],
            variable(9601, 'Zustand')['value'] === $fixture['zustand']
            && variable(9601, 'ZustandRoh')['value'] === $expectedRaw
            && variable(9601, 'Erreichbar')['value'] === false
            && variable(9601, 'Akku')['value'] === 100 && variable(9601, 'Fehler')['value'] === 'Keine Fehler'
            && $stand['roh'] === $et->shadow && $stand['online'] === false
            && $eg->timers['Abruf'] === (in_array($fixture['zustand'], [1, 3, 6], true) ? 30000 : 120000)
            && $eg->ReadAttributeString('Schreibbar') === '[]');
        $before = count($et->pfade);
        foreach (['start', 'pause', 'station'] as $command) {
            $result = json_decode($ek->ForwardData(json_encode(['Aktion' => 'befehl', 'Geraet' => 'offline-e20', 'Befehl' => $command])), true);
            check('Beobachteter E20-Status gibt keinen Befehl frei: ' . $et->shadow['state'] . '/' . $command,
                !$result['ok'] && count($et->pfade) === $before);
        }
    }
    $et->shadow = ['state' => 'pause', 'parentState' => 'cleaning', 'deviceFault' => ['class' => 3, 'code' => 1]];
    $eg->Aktualisieren();
    $offlineStand = json_decode($ek->ReadAttributeString('Schnappschuss'), true)['offline-e20']['p'];
    check('Fehlendes connected belegt keine Erreichbarkeit; Warnung bleibt erhalten und Stand sperrt Befehle',
        variable(9601, 'Erreichbar')['value'] === false && variable(9601, 'Zustand')['value'] === 2
        && json_decode(variable(9601, 'Fehler')['value'], true) === ['class' => 3, 'code' => 1]
        && $offlineStand['online'] === false && $offlineStand['statusquelle']['quelle'] === 'shadow-get'
        && $offlineStand['statusquelle']['geraetezeit'] === null && !array_key_exists('connected', $offlineStand['roh']));
    $et->shadow = ['state' => 'unknown', 'parentState' => 'cleaning', 'connected' => false];
    $eg->Aktualisieren();
    check('Unbekannter neuer Status ersetzt Pause und alte Fehlermeldung', variable(9601, 'Zustand')['value'] === -1
        && variable(9601, 'Fehler')['value'] === 'Unbekannt' && variable(9601, 'Erreichbar')['value'] === false
        && $eg->timers['Abruf'] > 0 && $eg->ReadAttributeString('Schreibbar') === '[]');
    foreach ([true, false] as $cache) {
        $devices = json_decode($ek->ReadAttributeString('Geraete'), true);
        $devices[0]['roh']['connected'] = $cache;
        $devices[0]['verbindungEmpfangen'] = time();
        $ek->WriteAttributeString('Geraete', json_encode($devices));
        $et->shadow = ['state' => 'cleaning', 'parentState' => 'cleaning'];
        $before = count($et->pfade);
        $eg->Aktualisieren();
        $raw = json_decode($eg->Rohdaten(), true);
        $activity = json_decode($eg->GetActivity(), true);
        check('Frischer Cache ergänzt nur die Anzeige ' . json_encode($cache),
            variable(9601, 'Verbindungsstatus')['value'] === ($cache ? 'Online bestätigt' : 'Offline bestätigt')
            && variable(9601, 'CloudStatusAbruf')['value'] === true
            && variable(9601, 'Erreichbar')['value'] === false
            && $activity['state'] === -1 && $activity['validUntil'] === 0
            && $raw['robohubErreichbarkeit']['quelle'] === 'geraetecache.connected'
            && count($et->pfade) === $before + 1);
    }
    foreach ([true, false, null] as $connected) {
        $et->shadow = ['state' => 'cleaning', 'parentState' => 'cleaning'];
        if ($connected !== null) { $et->shadow['connected'] = $connected; }
        // Bestehenden Cache gezielt altern lassen, ohne die Cloud neu abzufragen.
        $devices = json_decode($ek->ReadAttributeString('Geraete'), true);
        $devices[0]['verbindungEmpfangen'] = time() - 86400;
        $ek->WriteAttributeString('Geraete', json_encode($devices));
        $before = count($et->pfade);
        $eg->Aktualisieren();
        check('Eureka Cloud und Dreizustandsanzeige ' . json_encode($connected),
            variable(9601, 'CloudStatusAbruf')['value'] === true
            && variable(9601, 'Verbindungsstatus')['value'] === match ($connected) {
                true => 'Online bestätigt', false => 'Offline bestätigt', null => 'Verbindung unbekannt',
            }
            && variable(9601, 'Erreichbar')['value'] === ($connected === true)
            && count($et->pfade) === $before + 1);
        $before = count($et->pfade);
        $activity = json_decode($eg->GetActivity(), true);
        check('GetActivity bleibt konservativ und lokal ' . json_encode($connected),
            $activity['state'] === ($connected === true ? 1 : -1)
            && ($connected === true || $activity['validUntil'] === 0)
            && count($et->pfade) === $before);
    }
    $et->shadow['connected'] = true;
    $eg->Aktualisieren();
    $et->fehler = true;
    $eg->Aktualisieren();
    check('Cloudfehler ist keine explizite Offline-Meldung',
        variable(9601, 'CloudStatusAbruf')['value'] === false
        && variable(9601, 'Verbindungsstatus')['value'] === 'Verbindung unbekannt'
        && variable(9601, 'Erreichbar')['value'] === false
        && !isset(json_decode($eg->Rohdaten(), true)['robohubErreichbarkeit']));
    $et->fehler = false;
    check('Andere Hersteller erhalten keine Eureka-Variablen', count(array_filter($GLOBALS['OBJ'],
        static fn(array $o): bool => in_array($o['ident'] ?? '', ['CloudStatusAbruf', 'Verbindungsstatus'], true)
            && ($o['parent'] ?? 0) !== 9601)) === 0);
    check('Status und Geräteaktualisierung erteilen keine B8-Laufzeitfreigabe', $ek->GetBuffer('EurekaB8Laufzeitfreigabe') === '');
    $vorher = count($et->pfade);
    $blockiert = json_decode($ek->ForwardData(json_encode(['Aktion' => 'befehl', 'Geraet' => 'offline-e20', 'Befehl' => 'start'])), true);
    check('Steuerbefehle werden ohne Cloud-Aufruf abgelehnt', !$blockiert['ok'] && count($et->pfade) === $vorher);
    check('Eureka-Transport nutzt ausschließlich Anmeldung und GET-Leseendpunkte', $et->pfade !== [] && count(array_filter($et->pfade,
        static fn(array $p): bool => $p[0] !== 'GET' && $p !== ['POST', '/auth/login/email-password'])) === 0);
    $ek->Abmelden();
    check('Eureka-Abmeldung löscht Zugang und Geräteliste', $ek->ReadAttributeString('Buendel') === '' && $ek->ReadAttributeString('Geraete') === '[]');
} finally {
    $GLOBALS['robohubTreiberFabrik'] = $eurekaFabrik;
    unset($GLOBALS['INST'][9600], $GLOBALS['INST'][9601]);
    if (isset($eurekaIo)) { unset($GLOBALS['INST'][$eurekaIo]); }
}
