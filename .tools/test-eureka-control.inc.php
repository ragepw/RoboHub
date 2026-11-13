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
        $this->offline->pauseTest = $echt->pauseTest;
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
    check('Offlineadapter verwendet das echte einzelne E20-Profil',
        (new ReflectionClass(OfflineFreigegebenEureka::class))->getConstant('BEFEHLS_ZUORDNUNGEN') ===
        (new ReflectionClass(RoboHubEureka::class))->getConstant('BEFEHLS_ZUORDNUNGEN'));
    // Variablen-ID -> Standardaktion -> Modul; kein echter Symcon-Aufruf.
    $webfrontDouble = function (int $id, int $wert) use ($cg): string {
        $v = $GLOBALS['OBJ'][$id];
        if (!$v['action'] || $v['type'] !== VARIABLETYPE_INTEGER || $v['parent'] !== $cg->InstanceID) {
            throw new RuntimeException('Offline: keine Integer-Standardaktion');
        }
        ob_start();
        try { $cg->RequestAction($v['ident'], $wert); return ob_get_contents(); }
        finally { ob_end_clean(); }
    };
    $v = $GLOBALS['OBJ'][22284];
    check('Bestehende Befehlsvariable: Integer, aktive Aktion, fünf Aufzählungswerte',
        $v['type'] === VARIABLETYPE_INTEGER && $v['action'] === true &&
        $v['pres']['PRESENTATION'] === VARIABLE_PRESENTATION_ENUMERATION &&
        array_column(json_decode($v['pres']['OPTIONS'], true), 'Value') === [1, 2, 3, 4, 5]);
    $reset(); $cg->debug = [];
    $vorher = $ck->ReadAttributeString('Schnappschuss');
    $ausgabe = $webfrontDouble(22284, 2);
    check('WebFront-Double: Pause erreicht Konto und Transport genau einmal',
        $ausgabe === '' && count($ct->posts) === 1 &&
        $ct->posts[0][2] === ['data' => RoboHubEurekaProtokoll::pause()] &&
        $ck->ReadAttributeString('Schnappschuss') === $vorher && $cg->GetValue('Befehl') === 0);
    check('Bedien-Debug bestätigt Eingang und Versand ohne Rohdaten',
        $cg->debug === ['Bedienung: pause: angefordert', 'Bedienung: pause: gesendet']);
    foreach (['verbraucht', 'zustand', 'bindung', 'deaktiviert', 'alter', 'cloudfehler'] as $fall) {
        $reset(); $cg->debug = [];
        if ($fall === 'verbraucht') { $webfrontDouble(22284, 2); $cg->debug = []; }
        if ($fall === 'zustand') { $shadowAendern(function (&$s) { $s['roh']['state'] = 'pause'; }); }
        if ($fall === 'bindung') { $cg->parent = null; }
        if ($fall === 'deaktiviert') { $cg->props['Deaktiviert'] = true; }
        if ($fall === 'alter') { $shadowAendern(function (&$s) { $s['zeit'] -= 121; }); }
        if ($fall === 'cloudfehler') { $ct->antwort = ['status' => 200, 'response' => '{"errorCode":1,"secret":"offline-secret"}']; }
        $anzahl = count($ct->posts);
        $stand = $ck->ReadAttributeString('Schnappschuss');
        $ausgabe = $webfrontDouble(22284, 2);
        check('WebFront meldet Ablehnung datensparsam: ' . $fall,
            $ausgabe === 'Befehl fehlgeschlagen. Details im RoboHub-Meldungsprotokoll prüfen; vor erneutem Bedienen den Status aktualisieren.' &&
            $cg->debug === ['Bedienung: pause: angefordert', 'Bedienung: pause: fehlgeschlagen'] &&
            count($ct->posts) === $anzahl + ($fall === 'cloudfehler' ? 1 : 0) &&
            $ck->ReadAttributeString('Schnappschuss') === $stand && $cg->GetValue('Befehl') === 0);
    }
    foreach (['geschlossen', 'widerrufen', 'verbraucht'] as $zustand) {
        $reset();
        $historisch = json_encode(['zustand' => $zustand, 'cloudGeraetId' => 'offline-e20']);
        $ck->WriteAttributeString('EurekaPauseTest', $historisch);
        $ck->SetBuffer('EurekaPauseTest', $historisch);
        $cg->RequestAction('Befehl', RoboHubModell::BEFEHL_PAUSE);
        check('Regulärer Versuch lässt alten Pause-Testverbrauch unverändert: ' . $zustand,
            count($ct->posts) === 1 && $ck->ReadAttributeString('EurekaPauseTest') === $historisch
            && $ck->GetBuffer('EurekaPauseTest') === $historisch);
        $cg->RequestAction('Befehl', RoboHubModell::BEFEHL_PAUSE);
        check('Regulärer Verbrauch bleibt trotz historischem Test erhalten: ' . $zustand, count($ct->posts) === 1);
    }
    $ck->WriteAttributeString('EurekaPauseTest', '{}'); $ck->SetBuffer('EurekaPauseTest', '');

    // Zusammenhängende Bedienung ohne Zurücksetzen von Sitzungs-/Versuchsverbrauch.
    $reset('stop');
    $cg->props['EurekaStartSaugstufe'] = 2; $cg->props['EurekaStartWasserstufe'] = 0;
    foreach ([['stop', 1, RoboHubEurekaProtokoll::start(2, 0, 8)],
        ['cleaning', 2, RoboHubEurekaProtokoll::pause()],
        ['pause', 1, RoboHubEurekaProtokoll::fortsetzen()],
        ['cleaning', 4, RoboHubEurekaProtokoll::station()]] as $schritt => [$state, $action, $hex]) {
        $shadowAendern(function (&$s) use ($state, $schritt): void {
            $s['roh']['state'] = $s['roh']['parentState'] = $state;
            // Auch bei gleichem Empfangssekundenwert ist dies ein neuer gelesener Stand.
            $s['roh']['batteryLevel'] = 87 - $schritt;
        });
        if ($schritt > 0) {
            $cg->props['EurekaStartSaugstufe'] = -1; $cg->props['EurekaStartWasserstufe'] = -1;
        }
        $vorher = $ck->ReadAttributeString('Schnappschuss');
        $cg->RequestAction('Befehl', $action);
        check('Vollständige Bedienfolge, Schritt ' . ($schritt + 1),
            count($ct->posts) === $schritt + 1 && $ct->posts[$schritt][2] === ['data' => $hex]
            && $ck->ReadAttributeString('Schnappschuss') === $vorher);
        $cg->RequestAction('Befehl', $action);
        check('Bedienfolge wiederholt Schritt nicht: ' . ($schritt + 1), count($ct->posts) === $schritt + 1);
    }
    foreach (['stop', 'charging', 'charging_finish'] as $state) {
        foreach ([1, 2, 3] as $fan) {
            foreach ([0, 1, 2, 3] as $water) {
                $reset($state);
                $cg->props['EurekaStartSaugstufe'] = $fan; $cg->props['EurekaStartWasserstufe'] = $water;
                $cg->RequestAction('Befehl', RoboHubModell::BEFEHL_START);
                check('Explizite Startauswahl ' . $state . '/' . $fan . '/' . $water,
                    count($ct->posts) === 1 && $ct->posts[0][2] === ['data' => RoboHubEurekaProtokoll::start($fan, $water, 8)]);
            }
        }
    }
    foreach (['stop', 'charging', 'charging_finish', 'cleaning', 'pause', 'charging_pause', 'wiping_pause',
        'sleep', 'charging_back', 'wiping_back', 'collecting_dust', 'upgrading', 'error', 'unknown'] as $state) {
        foreach ([1, 2, 4] as $action) {
            $reset($state);
            $cg->props['EurekaStartSaugstufe'] = 2; $cg->props['EurekaStartWasserstufe'] = 0;
            $cg->RequestAction('Befehl', $action);
            $erlaubt = match ($action) {
                1 => in_array($state, ['stop', 'charging', 'charging_finish', 'pause'], true),
                2 => $state === 'cleaning',
                4 => in_array($state, ['stop', 'pause', 'cleaning'], true),
            };
            check('Native Zustandsgrenze ' . $state . '/' . $action, count($ct->posts) === ($erlaubt ? 1 : 0));
        }
    }
    foreach ([['cleaning', 2], ['pause', 1], ['stop', 4], ['pause', 4], ['cleaning', 4]] as [$state, $action]) {
        $reset($state);
        $shadowAendern(function (&$s): void { unset($s['roh']['batteryLevel']); });
        $cg->props['EurekaStartSaugstufe'] = 999; $cg->props['EurekaStartWasserstufe'] = 999;
        $cg->RequestAction('Befehl', $action);
        check('Keine Startparameter-/Akkusperre für ' . $state . '/' . $action, count($ct->posts) === 1);
    }
    foreach (['EurekaStartSaugstufe', 'EurekaStartWasserstufe'] as $eigenschaft) {
        $reset('stop');
        $cg->props['EurekaStartSaugstufe'] = 2; $cg->props['EurekaStartWasserstufe'] = 0;
        $ct->vorTransport = function () use ($cg, $eigenschaft): void { $cg->props[$eigenschaft] = 3; };
        $cg->RequestAction('Befehl', RoboHubModell::BEFEHL_START);
        check('Geänderte Startauswahl vor I/O sperrt: ' . $eigenschaft, $ct->posts === []);
        $ct->vorTransport = null;
        $cg->RequestAction('Befehl', RoboHubModell::BEFEHL_START);
        check('Geänderte Auswahl setzt Verbrauch nicht zurück: ' . $eigenschaft, $ct->posts === []);
    }
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
    check('Automatischer Einstieg bleibt auch bei explizitem Shadow-Nachweis gesperrt', $ct->posts === []);
    $reset('pause'); $cg->RequestAction('Befehl', RoboHubModell::BEFEHL_START);
    $verbrauch = $ck->ReadAttributeString('EurekaBefehlsversuch');
    $cg->SetValue('Befehl', RoboHubModell::BEFEHL_START); $ck->SetBuffer('EurekaSteuerLaufzeit', '');
    $cg->ApplyChanges(); $ck->MessageSink(0, 0, IPS_KERNELMESSAGE, [KR_READY]);
    check('Neustart wiederholt keinen Befehl und erhält Verbrauch', count($ct->posts) === 1 && $ck->ReadAttributeString('EurekaBefehlsversuch') === $verbrauch);
    $cg->RequestAction('Befehl', RoboHubModell::BEFEHL_START);
    check('Alter Shadow bleibt nach Neustart gesperrt', count($ct->posts) === 1);
    foreach ([1, 2, 4] as $typ) {
        $reset('ERP_model');
        $cg->props['EurekaStartSaugstufe'] = 1; $cg->props['EurekaStartWasserstufe'] = 0;
        $shadowAendern(function (&$s) use ($typ) {
            $s['roh']['parentState'] = 'sleep'; $s['roh']['cleanMissionState'] = 'no_cleaning_mission';
            $s['b8'] = ['zeit' => time(), 'felder' => ['arbeit' => 12, 'akku' => 87,
                'fehlerklasse' => 0, 'fehlercode' => 0, 'sleep_type' => $typ]];
        });
        $cg->RequestAction('Befehl', RoboHubModell::BEFEHL_START);
        $cg->RequestAction('Befehl', RoboHubModell::BEFEHL_START);
        check('Schlaf ' . $typ . ': gebundener RequestAction-Pfad mit Parametern 1/0 und Einmalverbrauch', count($ct->posts) === 1
            && $ct->posts[0][2] === ['data' => $typ === 2 ? RoboHubEurekaProtokoll::fortsetzen() : RoboHubEurekaProtokoll::start(1, 0, 8)]);
        $shadowAendern(function (&$s) { $s['sitzung'] = 'andere-sitzung'; });
        $cg->RequestAction('Befehl', RoboHubModell::BEFEHL_START);
        check('Schlafbefund überwindet keine Sitzungsbindung ' . $typ, count($ct->posts) === 1);
    }
} finally {
    $GLOBALS['robohubTreiberFabrik'] = $controlFabrik;
    $GLOBALS['OBJ'] = $controlObj; $GLOBALS['INST'] = $controlInst;
}
