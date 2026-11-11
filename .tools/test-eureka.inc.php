<?php
declare(strict_types=1);

// Der gesamte Transport bleibt offline; Konto, Konfigurator und Gerät sind echt.
require_once __DIR__ . '/../tests/eureka/e20-fixture.php';
abschnitt('Eureka: Integration im Lesebetrieb');
class IntegrationEureka extends RoboHubEureka
{
    public array $pfade = [];
    protected function transport(string $method, string $path, string $token = '', ?array $body = null): array
    {
        $this->pfade[] = [$method, explode('?', $path)[0]];
        $payload = rtrim(strtr(base64_encode(json_encode(['exp' => time() + 3600])), '+/', '-_'), '=');
        $data = match (explode('?', $path)[0]) {
            '/auth/login/email-password' => ['accessToken' => 'offline.' . $payload . '.fixture', 'refreshToken' => 'offline-refresh'],
            '/app/devices' => [e20Fixture()['geraet']['roh']],
            '/shadow/device/state' => [e20Fixture()['geraet']['stand']['roh']],
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
        && $et->normalisiere('E20 Evo Plus', ['state' => 'pause'])['werte']['Zustand'] === RoboHubModell::ZUSTAND_UNBEKANNT);
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
