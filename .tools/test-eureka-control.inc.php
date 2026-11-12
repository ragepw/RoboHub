<?php
declare(strict_types=1);

require_once __DIR__ . '/../tests/eureka/control-driver-fixture.php';
abschnitt('Eureka: normale Steuerung mit Transportdouble');

class ControlEureka extends OfflineFreigegebenEureka
{
    public array $posts = [];
    public array $antwort = ['status' => 200, 'response' => '{"errorCode":0}'];
    public $vorTransport = null;
    protected function httpAusfuehren(string $m, string $p, string $t, ?array $b, ?callable $pruefen = null, bool $dock = false): array
    {
        if (is_callable($this->vorTransport)) { ($this->vorTransport)(); }
        $pruefen();
        $this->posts[] = [$m, $p, $b];
        return $this->antwort;
    }
}

// Die echte Konto-Kontext-/Verbrauchsprüfung bleibt erhalten. Nur die im
// Prüfprozess umbenannte Treiberklasse muss an deren Stelle eingehängt werden.
class ControlKonto extends RoboHubKonto
{
    public ControlEureka $offline;
    protected function treiber(): RoboHubTreiber
    {
        $echt = parent::treiber();
        $this->offline->befehlsKontext = $echt->befehlsKontext;
        $this->offline->befehlsVersuch = $echt->befehlsVersuch;
        $this->offline->beiUnauthorized = $echt->beiUnauthorized;
        return $this->offline;
    }
    public function ForwardData($json)
    {
        $this->offline->manuelleBedienung = (json_decode($json, true)['Bedienung'] ?? null) === 'manuell';
        return parent::ForwardData($json);
    }
}

$controlFabrik = $GLOBALS['robohubTreiberFabrik'];
$controlObj = $GLOBALS['OBJ'];
$controlInst = $GLOBALS['INST'];
try {
    unset($GLOBALS['robohubTreiberFabrik']);
    $ck = new ControlKonto(45174); $ck->Create();
    $ct = $ck->offline = new ControlEureka();
    $ck->props['Hersteller'] = 'eureka'; $ck->props['Region'] = 'eu';
    $cg = new RoboHubGeraet(22708); $cg->Create(); $cg->parent = $ck;
    $cg->props['GeraetID'] = 'offline-e20'; $cg->props['Modell'] = 'E20 Evo Plus';
    // Vorhandene Variablen-ID nachbilden, keine zusätzliche Steuerungsvariable.
    $GLOBALS['OBJ'][22284] = ['ident' => 'Befehl', 'parent' => 22708, 'type' => 1, 'value' => 0, 'action' => true, 'name' => 'Befehl'];
    $cg->ApplyChanges();
    $token = 'offline.' . rtrim(strtr(base64_encode(json_encode(['exp' => time() + 3600])), '+/', '-_'), '=') . '.fixture';
    $bundle = ['region' => 'eu', 'access_token' => $token, 'refresh_token' => 'offline'];
    $ck->WriteAttributeString('Buendel', json_encode($bundle));
    $ck->WriteAttributeString('BuendelRevision', 'offline-revision');
    $nummer = 0;
    $reset = function (string $state = 'cleaning') use ($ck, $cg, $ct, &$nummer): void {
        $nummer++;
        $g = e20Fixture()['geraet']; unset($g['stand']['roh']['connected']);
        $g['stand']['roh']['state'] = $state; $g['stand']['roh']['parentState'] = $state;
        $g['verbindungEmpfangen'] = $g['stand']['zeit'] = time();
        $g['stand']['online'] = false;
        $g['stand']['statusquelle'] = ['quelle' => 'shadow-get', 'geraetezeit' => null];
        $g['stand']['befehlsLaufzeit'] = 'offline-boot-' . $nummer;
        $g['stand']['sitzung'] = 'offline-revision';
        $g['stand']['kernelstart'] = IPS_GetKernelStartTime();
        $ck->SetBuffer('EurekaSteuerLaufzeit', $g['stand']['befehlsLaufzeit']);
        $ck->WriteAttributeString('Geraete', json_encode([$g]));
        $ck->WriteAttributeString('Schnappschuss', json_encode([$g['id'] => ['p' => $g['stand']]]));
        $ck->WriteAttributeInteger('GeraeteStand', time()); $ck->WriteAttributeInteger('WarteBis', 0);
        $ck->WriteAttributeString('EurekaBefehlsversuch', '{}');
        $ct->posts = []; $ct->vorTransport = null;
        $ct->antwort = ['status' => 200, 'response' => '{"errorCode":0}'];
        $cg->props['EurekaStartSaugstufe'] = -1; $cg->props['EurekaStartWasserstufe'] = -1;
        $cg->props['GeraetID'] = 'offline-e20'; $cg->props['Deaktiviert'] = false; $cg->parent = $ck;
    };
    $shadowAendern = function (callable $aendern) use ($ck): void {
        $s = json_decode($ck->ReadAttributeString('Schnappschuss'), true); $aendern($s['offline-e20']['p']);
        $ck->WriteAttributeString('Schnappschuss', json_encode($s));
    };
    $listeAendern = function (callable $aendern) use ($ck): void {
        $l = json_decode($ck->ReadAttributeString('Geraete'), true); $aendern($l[0]);
        $ck->WriteAttributeString('Geraete', json_encode($l));
    };
    foreach ([['stop', RoboHubModell::BEFEHL_START, 'AA15B800000000000002220200020008020000000001'],
        ['cleaning', RoboHubModell::BEFEHL_PAUSE, 'AA0DB800000000000002221B00FC'], ['pause', RoboHubModell::BEFEHL_START, 'AA0DB800000000000002221C00FB'],
        ['cleaning', RoboHubModell::BEFEHL_STATION, 'AA0DB80000000000000222010016']] as [$state, $action, $hex]) {
        $reset($state);
        if ($state === 'stop') { $cg->props['EurekaStartSaugstufe'] = 2; $cg->props['EurekaStartWasserstufe'] = 0; }
        $snapshot = $ck->ReadAttributeString('Schnappschuss');
        $cg->RequestAction('Befehl', $action);
        check('RequestAction 22708/22284: ' . $state . '/' . $action,
            $ct->posts === [['POST', '/devices/commands/offline-e20', ['data' => $hex]]]
            && $ck->ReadAttributeString('Schnappschuss') === $snapshot);
        $cg->RequestAction('Befehl', $action);
        check('Doppelte Bedienaktion nutzt keinen zweiten Transport: ' . $state, count($ct->posts) === 1);
    }
    foreach (['offline', 'unknown', 'invalid-connected', 'list-old', 'list-future', 'cache-old', 'shadow-old', 'shadow-future',
        'shadow-id', 'unknown-state', 'fault', 'missing-fault', 'missing-start', 'invalid-fan', 'invalid-water', 'battery',
        'binding', 'disabled', 'session', 'restart', 'kernelstart', 'shadow-model', 'invalid-consumption', 'double-id', 'wrong-variable', 'station-work', 'stopp', 'orten', 'auto'] as $case) {
        $reset(); $action = RoboHubModell::BEFEHL_PAUSE;
        switch ($case) {
            case 'offline': $listeAendern(function (&$g) { $g['roh']['connected'] = false; }); break;
            case 'unknown': $listeAendern(function (&$g) { unset($g['roh']['connected']); }); break;
            case 'invalid-connected': $shadowAendern(function (&$s) { $s['roh']['connected'] = 'true'; }); break;
            case 'list-old': $listeAendern(function (&$g) { $g['verbindungEmpfangen'] -= 121; }); break;
            case 'list-future': $listeAendern(function (&$g) { $g['verbindungEmpfangen'] += 60; }); break;
            case 'cache-old': $ck->WriteAttributeInteger('GeraeteStand', time() - 121); break;
            case 'shadow-old': $shadowAendern(function (&$s) { $s['zeit'] -= 121; }); break;
            case 'shadow-future': $shadowAendern(function (&$s) { $s['zeit'] += 60; }); break;
            case 'shadow-id': $shadowAendern(function (&$s) { $s['deviceId'] = 'other'; }); break;
            case 'unknown-state': $shadowAendern(function (&$s) { $s['roh']['state'] = 'unknown'; }); break;
            case 'fault': $shadowAendern(function (&$s) { $s['roh']['deviceFault'] = [1]; }); break;
            case 'missing-fault': $shadowAendern(function (&$s) { unset($s['roh']['deviceFault']); }); break;
            case 'missing-start': $reset('stop'); $action = RoboHubModell::BEFEHL_START; break;
            case 'invalid-fan': $reset('stop'); $action = RoboHubModell::BEFEHL_START; $cg->props['EurekaStartSaugstufe'] = 4; $cg->props['EurekaStartWasserstufe'] = 0; break;
            case 'invalid-water': $reset('stop'); $action = RoboHubModell::BEFEHL_START; $cg->props['EurekaStartSaugstufe'] = 2; $cg->props['EurekaStartWasserstufe'] = -1; break;
            case 'battery': $reset('stop'); $action = RoboHubModell::BEFEHL_START; $cg->props['EurekaStartSaugstufe'] = 2; $cg->props['EurekaStartWasserstufe'] = 0;
                $shadowAendern(function (&$s) { $s['roh']['batteryLevel'] = 15; }); break;
            case 'binding': $cg->parent = null; break;
            case 'disabled': $cg->props['Deaktiviert'] = true; break;
            case 'session': $shadowAendern(function (&$s) { $s['sitzung'] = 'other'; }); break;
            case 'restart': $ck->SetBuffer('EurekaSteuerLaufzeit', ''); break;
            case 'kernelstart': $shadowAendern(function (&$s) { $s['kernelstart']--; }); break;
            case 'shadow-model': $shadowAendern(function (&$s) { $s['roh']['productModel'] = 'other'; }); break;
            case 'invalid-consumption': $ck->WriteAttributeString('EurekaBefehlsversuch', '{"stand":"invalid"}'); break;
            case 'double-id': $g = json_decode($ck->ReadAttributeString('Geraete'), true)[0]; $ck->WriteAttributeString('Geraete', json_encode([$g, $g])); break;
            case 'wrong-variable': $GLOBALS['OBJ'][22284]['ident'] = 'other'; break;
            case 'station-work': $shadowAendern(function (&$s) { $s['roh']['state'] = 'collecting_dust'; }); break;
            case 'stopp': $action = RoboHubModell::BEFEHL_STOPP; break;
            case 'orten': $action = RoboHubModell::BEFEHL_ORTEN; break;
        }
        if ($case === 'auto') { $cg->Anhalten(); } else { $cg->RequestAction('Befehl', $action); }
        check('Kein Transport bei ' . $case, $ct->posts === []);
        $GLOBALS['OBJ'][22284]['ident'] = 'Befehl';
    }
    foreach ([401, 429, 500, 302, 0, 'json', 'cloud', 'timeout'] as $case) {
        $reset();
        $ct->antwort = ['status' => is_int($case) ? $case : 200, 'response' => match ($case) {
            'json' => '{', 'cloud' => '{"errorCode":1}', 'timeout' => '{"errorCode":"0080005"}', default => '{"errorCode":0}',
        }];
        // POST-401 darf hier keinen zusätzlichen Auth-Aufruf erzeugen.
        $ct->beiUnauthorized = null;
        $cg->RequestAction('Befehl', RoboHubModell::BEFEHL_PAUSE); $cg->RequestAction('Befehl', RoboHubModell::BEFEHL_PAUSE);
        check('Transportfehler ohne Doppelversand ' . $case, count($ct->posts) === 1);
    }
    foreach (['state', 'age', 'binding', 'session', 'offline'] as $case) {
        $reset();
        $ct->vorTransport = function () use ($case, $shadowAendern, $listeAendern, $cg): void {
            switch ($case) {
                case 'state': $shadowAendern(function (&$s) { $s['roh']['state'] = 'error'; }); break;
                case 'age': $shadowAendern(function (&$s) { $s['zeit'] -= 121; }); break;
                case 'binding': $cg->props['GeraetID'] = 'other'; break;
                case 'session': $shadowAendern(function (&$s) { $s['sitzung'] = 'other'; }); break;
                case 'offline': $listeAendern(function (&$g) { $g['roh']['connected'] = false; }); break;
            }
        };
        $cg->RequestAction('Befehl', RoboHubModell::BEFEHL_PAUSE);
        check('Letzte lokale Prüfung sperrt Änderung: ' . $case, $ct->posts === []);
    }
    $reset(); $cg->RequestAction('Befehl', RoboHubModell::BEFEHL_PAUSE);
    $cg->RequestAction('Befehl', RoboHubModell::BEFEHL_STATION);
    check('Verbrauch gilt auch für einen anderen Befehl mit demselben Stand', count($ct->posts) === 1);
    $shadowAendern(function (&$s) { $s['roh']['state'] = $s['roh']['parentState'] = 'pause'; });
    $cg->RequestAction('Befehl', RoboHubModell::BEFEHL_START);
    check('Neu gelesener zulässiger Stand ermöglicht nächste Bedienaktion', count($ct->posts) === 2
        && $ct->posts[1][2] === ['data' => RoboHubEurekaProtokoll::fortsetzen()]);
    $cg->RequestAction('Befehl', RoboHubModell::BEFEHL_START);
    check('Auch der neue Zustandsstand wird nur einmal verbraucht', count($ct->posts) === 2);
    $reset();
    $shadowAendern(function (&$s) { $s['roh']['connected'] = true; $s['online'] = true; });
    $cg->Anhalten();
    check('Automatischer Einstieg bleibt bei explizitem Shadow-Nachweis vorbereitet', count($ct->posts) === 1);
    $reset('pause'); $cg->RequestAction('Befehl', RoboHubModell::BEFEHL_START);
    $verbrauch = $ck->ReadAttributeString('EurekaBefehlsversuch');
    $cg->SetValue('Befehl', RoboHubModell::BEFEHL_START); $ck->SetBuffer('EurekaSteuerLaufzeit', '');
    $cg->ApplyChanges(); $ck->MessageSink(0, 0, IPS_KERNELMESSAGE, [KR_READY]);
    check('Neustart wiederholt keinen Befehl und erhält Verbrauch', count($ct->posts) === 1 && $ck->ReadAttributeString('EurekaBefehlsversuch') === $verbrauch);
    $cg->RequestAction('Befehl', RoboHubModell::BEFEHL_START);
    check('Alter Shadow bleibt nach Neustart gesperrt', count($ct->posts) === 1);
} finally {
    $GLOBALS['robohubTreiberFabrik'] = $controlFabrik;
    $GLOBALS['OBJ'] = $controlObj; $GLOBALS['INST'] = $controlInst;
}
