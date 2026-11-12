<?php
declare(strict_types=1);
abschnitt('Eureka: beaufsichtigter Pause-Einmaltest über vorhandene Aktionsvariable');
function IPS_IsAdministrator(): bool { return $GLOBALS['PAUSE_ADMIN'] ?? true; }
function IPS_GetLoggedInUser(): string { return $GLOBALS['PAUSE_USER'] ?? '@offline-admin'; }
class PauseNativeHttp extends RoboHubEureka
{
    public array $posts = [];
    protected function httpAusfuehren(string $m, string $p, string $t, ?array $b, ?callable $pruefen = null, bool $dock = false): array
    {
        $pruefen(); $pruefen(true);
        $this->posts[] = [$m, $p, $b];
        return ['status' => 200, 'response' => '{"errorCode":0}'];
    }
}
$pauseObj = $GLOBALS['OBJ']; $pauseInst = $GLOBALS['INST']; $pauseFabrik = $GLOBALS['robohubTreiberFabrik'];
try {
    $pt = new PauseNativeHttp(); $GLOBALS['robohubTreiberFabrik'] = fn() => $pt;
    $pk = new RoboHubKonto(45174); $pk->Create();
    $pk->props['Hersteller'] = 'eureka'; $pk->props['Region'] = 'eu';
    $pg = new RoboHubGeraet(22708); $pg->Create(); $pg->parent = $pk;
    $pg->props['GeraetID'] = 'offline-e20'; $pg->props['Modell'] = 'E20 Evo Plus';
    $GLOBALS['OBJ'][22284] = ['ident' => 'Befehl', 'parent' => 22708, 'type' => 1, 'value' => 0, 'action' => true, 'name' => 'Befehl'];
    $pg->ApplyChanges();
    $token = 'e30.' . base64_encode(json_encode(['exp' => time() + 3600])) . '.offline';
    $pk->WriteAttributeString('Buendel', json_encode(['region' => 'eu', 'access_token' => $token]));
    $pk->WriteAttributeString('BuendelRevision', 'offline-revision');
    $g = e20Fixture()['geraet']; unset($g['stand']['roh']['connected']);
    $g['verbindungEmpfangen'] = $g['stand']['zeit'] = time();
    $g['stand']['online'] = false;
    $g['stand']['statusquelle'] = ['quelle' => 'shadow-get', 'geraetezeit' => null];
    $g['stand']['befehlsLaufzeit'] = 'offline-pause-runtime'; $g['stand']['sitzung'] = 'offline-revision';
    $g['stand']['kernelstart'] = IPS_GetKernelStartTime();
    $pk->SetBuffer('EurekaSteuerLaufzeit', 'offline-pause-runtime');
    $pk->WriteAttributeString('Geraete', json_encode([$g]));
    $pk->WriteAttributeString('Schnappschuss', json_encode([$g['id'] => ['p' => $g['stand']]]));
    $pk->WriteAttributeInteger('GeraeteStand', time());
    $pg->RequestAction('Befehl', RoboHubModell::BEFEHL_PAUSE);
    check('Echte Globalsperren verhindern Pause vor lokaler Testfreigabe', $pt->posts === []);
    $vorher = $pk->ReadAttributeString('Schnappschuss');
    $pk->EurekaPauseTestFreigeben('offline-e20', 60, RoboHubEurekaPauseTest::BESTAETIGUNG);
    check('Native Freigabe verändert weder Shadow noch Transport', $pt->posts === [] && $pk->ReadAttributeString('Schnappschuss') === $vorher);
    foreach ([RoboHubModell::BEFEHL_START, RoboHubModell::BEFEHL_STATION, RoboHubModell::BEFEHL_STOPP, RoboHubModell::BEFEHL_ORTEN] as $aktion) {
        $pg->RequestAction('Befehl', $aktion);
        check('Testfreigabe öffnet andere Aktionsvariable nicht: ' . $aktion, $pt->posts === []);
    }
    $pg->Anhalten();
    check('Skript-/Automatikeinstieg bleibt trotz Freigabe gesperrt', $pt->posts === []);
    $pg->RequestAction('Befehl', RoboHubModell::BEFEHL_PAUSE);
    check('22708/22284 Pause nutzt unveränderten regulären Treiber', $pt->posts === [['POST', '/devices/commands/offline-e20', ['data' => 'AA0DB800000000000002221B00FC']]]);
    check('Shadow-connected bleibt fehlend und abgeleitetes online bleibt false', $pk->ReadAttributeString('Schnappschuss') === $vorher);
    $pg->RequestAction('Befehl', RoboHubModell::BEFEHL_PAUSE);
    check('Erneute Aktionsvariablenbedienung sendet nicht erneut', count($pt->posts) === 1);
    $g['stand']['roh']['state'] = $g['stand']['roh']['parentState'] = 'pause';
    $pk->WriteAttributeString('Schnappschuss', json_encode([$g['id'] => ['p' => $g['stand']]]));
    $pg->RequestAction('Befehl', RoboHubModell::BEFEHL_START);
    check('Auch Fortsetzen nach beobachteter Pause bleibt gesperrt', count($pt->posts) === 1);
    check('Reguläre Gerätefreigabe erweitert den fremden Pause-Einmaltest nicht',
        !RoboHubEureka::regulaereBedienungFuer('offline-e20'));
} finally {
    $GLOBALS['OBJ'] = $pauseObj; $GLOBALS['INST'] = $pauseInst; $GLOBALS['robohubTreiberFabrik'] = $pauseFabrik;
}
