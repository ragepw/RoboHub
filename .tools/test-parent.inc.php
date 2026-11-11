<?php
declare(strict_types=1);

// Echte Module, nur die Symcon-Laufzeit ist nachgebildet. Keine Anmeldung und kein Netz.
abschnitt('Parent-Migration: Virtual IO, Rückfall und Herstellerwechsel');
$parentStand = [];
foreach (['INST', 'NAMEN', 'ERZEUGT', 'GELOESCHT', 'CONNECTS', 'SKRIPTE', 'robohubTreiberFabrik'] as $key) {
    $parentStand[$key] = $GLOBALS[$key] ?? [];
}
$GLOBALS['INST'] = [];
$GLOBALS['NAMEN'] = [];
$GLOBALS['FLOW_MELDUNGEN'] = true;
$GLOBALS['robohubTreiberFabrik'] = static function (string $marke): RoboHubTreiber {
    return match ($marke) {
        'eureka' => new IntegrationEureka(),
        'roborock' => new RoboHubRoborock(),
        'husqvarna' => new FakeHusqvarna(),
        'gardena' => new FakeGardena(),
        default => new FakeCloud($marke),
    };
};
function parentKonto(string $marke, ?FakeSocket $io = null): RoboHubKonto
{
    static $id = 200000;
    $konto = new RoboHubKonto(++$id);
    $konto->props['Hersteller'] = $marke;
    $konto->Create();
    $konto->parent = $io;
    return $konto;
}
try {
    $client = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';
    $virtual = '{6179ED6A-FC31-413C-BB8E-1204150CF376}';
    $websocket = '{D68FD31F-0E90-7019-F16C-1949BD3079EF}';
    $alt = new FakeSocket(200100);
    $alt->status = 104;
    IPS_SetName($alt->InstanceID, 'Client Socket (RoboHub Konto)');
    $ek = parentKonto('eureka', $alt);
    $ek->ApplyChanges();
    check('Eureka: Konsole erhält Zeit vor dem Parent-Wechsel', $ek->parent === $alt && $ek->timers['Verbindung'] === 15000);
    $ek->VerbindungPruefen();
    $io = $ek->parent;
    check('Eureka: inaktiver Client Socket ohne RoboHub-Präfix wird durch aktiven Virtual IO abgelöst', $io !== $alt
        && $io->modul === $virtual && $io->status === IS_ACTIVE);
    check('Alter fremder Socket bleibt unverändert erhalten', IPS_InstanceExists($alt->InstanceID)
        && IPS_GetName($alt->InstanceID) === 'Client Socket (RoboHub Konto)' && $alt->status === 104 && $alt->config === ['Open' => false]);
    check('Beobachtung folgt dem aktuellen Parent trotz synchroner Flow-Meldungen', $ek->ReadAttributeInteger('MqttEltern') === $io->InstanceID
        && isset($ek->messages[$io->InstanceID][IM_CHANGESTATUS]) && !isset($ek->messages[$alt->InstanceID][IM_CHANGESTATUS]));
    $anzahl = count($GLOBALS['INST']);
    for ($i = 0; $i < 3; $i++) { $ek->ApplyChanges(); $ek->VerbindungPruefen(); }
    check('Wiederholtes ApplyChanges und VerbindungPruefen erzeugt keine Duplikate', $ek->parent === $io && count($GLOBALS['INST']) === $anzahl);
    check('Eureka erhält keine Socket-Einstellungen oder Ping-Timer', $ek->GetConfigurationForParent() === '{}'
        && $ek->timers['MqttPing'] === 0 && $io->gesendet === []);

    $ek->WriteAttributeString('Mqtt', '{"zustand":"verbunden","host":"alt","topics":4}');
    $ek->SetBuffer('MqttEingang', 'veraltet');
    $ek->SetBuffer('WsZiel', 'veraltet');
    $ek->WriteAttributeBoolean('EchtzeitJeVerbunden', true);
    $ek->WriteAttributeInteger('EchtzeitGesperrtBis', time() + 86400);
    $ek->ApplyChanges();
    $ek->ReceiveData('{"Buffer":"verspaetete Daten"}');
    $ek->MqttPing();
    $ek->MessageSink(0, $alt->InstanceID, IM_CHANGESTATUS, [IS_ACTIVE]);
    check('Eureka verwirft alte Echtzeitdaten und ignoriert Socket-Eingänge', $ek->GetBuffer('MqttEingang') === '' && $ek->GetBuffer('WsZiel') === ''
        && !isset(json_decode($ek->ReadAttributeString('Mqtt'), true)['host']) && !$ek->ReadAttributeBoolean('EchtzeitJeVerbunden')
        && $ek->ReadAttributeInteger('EchtzeitGesperrtBis') === 0 && $io->gesendet === []);

    foreach (['dreame', 'eureka', 'mova', 'eureka'] as $marke) {
        $ek->props['Hersteller'] = $marke;
        $ek->ApplyChanges();
        $ek->VerbindungPruefen();
        check('Herstellerwechsel zu ' . $marke . ': passender Parent ohne alten Echtzeitstand', $ek->parent === ($marke === 'eureka' ? $io : $alt)
            && $ek->GetBuffer('WsZiel') === '' && (json_decode($ek->ReadAttributeString('Mqtt'), true)['zustand'] ?? '') !== 'verbunden');
    }
    check('Herstellerwechsel verwendet vorherige Parents wieder', count($GLOBALS['INST']) === $anzahl);

    $frei = new FakeSocket(200101);
    $frei->modul = $virtual;
    IPS_SetName($frei->InstanceID, 'Mein freier Virtual IO');
    $neu = parentKonto('roborock');
    $neu->ApplyChanges();
    $anzahl = count($GLOBALS['INST']);
    $neu->VerbindungPruefen();
    check('Anderer Hersteller ohne Echtzeit verwendet vorhandenen freien Virtual IO', $neu->parent === $frei
        && count($GLOBALS['INST']) === $anzahl && IPS_GetName($frei->InstanceID) === 'Mein freier Virtual IO');

    // Vorhandene I/Os sind auch bei scheinbar eigenem Namen kein Löschkandidat.
    $geteilt = new FakeSocket(200102);
    IPS_SetName($geteilt->InstanceID, 'RoboHub Echtzeit (geteilt)');
    $anderes = parentKonto('dreame', $geteilt);
    $wechsel = parentKonto('eureka', $geteilt);
    $wechsel->ApplyChanges();
    $wechsel->VerbindungPruefen();
    check('Geteilter Socket bleibt für das andere Konto verbunden und unverändert', $anderes->parent === $geteilt
        && IPS_InstanceExists($geteilt->InstanceID) && $geteilt->config === ['Open' => false]
        && IPS_GetName($geteilt->InstanceID) === 'RoboHub Echtzeit (geteilt)' && $wechsel->parent->modul === $virtual);

    $zweites = parentKonto('mova', $geteilt);
    $GLOBALS['SKRIPTE'] = [];
    $zweites->ApplyChanges();
    check('ApplyChanges und native Konfigurationsübernahme ändern keinen geteilten Socket', $GLOBALS['SKRIPTE'] === []
        && $zweites->GetConfigurationForParent() === '{}');
    $zweites->VerbindungPruefen();
    check('Echtzeitkonto erhält eigenen Socket statt fremden geteilten Socket zu ändern', $zweites->parent !== $geteilt
        && $zweites->parent->modul === $client && $anderes->parent === $geteilt && $geteilt->config === ['Open' => false]);

    $fehler = parentKonto('eureka', $alt);
    $fehler->ApplyChanges();
    $GLOBALS['CONNECT_FEHLER'] = [true, false];
    $fehler->VerbindungPruefen();
    check('Fehlgeschlagene Verbindung stellt bisherigen Parent wieder her und plant Wiederholung', $fehler->parent === $alt
        && $fehler->timers['Verbindung'] === 5000 && $fehler->ReadAttributeInteger('MqttEltern') === $alt->InstanceID);
    $fehler->VerbindungPruefen();
    check('Wiederholung schließt Migration ab', $fehler->parent->modul === $virtual && $fehler->timers['Verbindung'] === 0);

    $fehler2 = parentKonto('eureka', $alt);
    $fehler2->ApplyChanges();
    $anzahl = count($GLOBALS['INST']);
    $GLOBALS['CONNECT_FEHLER'] = ['exception', false];
    $fehler2->VerbindungPruefen();
    check('Exception beim Verbinden: Rückfall und Aufräumen des neuen Kandidaten', $fehler2->parent === $alt
        && count($GLOBALS['INST']) === $anzahl && $fehler2->GetBuffer('ParentWechsel') === '');
    $GLOBALS['DISCONNECT_FEHLER'] = true;
    $fehler2->VerbindungPruefen();
    check('Fehlgeschlagenes Trennen lässt alte Verbindung intakt', $fehler2->parent === $alt && count($GLOBALS['INST']) === $anzahl);
    $GLOBALS['DISCONNECT_FEHLER'] = false;
    $GLOBALS['CREATE_FEHLER'] = true;
    $fehler2->VerbindungPruefen();
    check('Nach drei erfolglosen Versuchen endet der Migrationstimer', $fehler2->timers['Verbindung'] === 0
        && $fehler2->ReadAttributeInteger('VerbindungsVersuche') === 3 && $fehler2->parent === $alt);
    $fehler2->MessageSink(0, $fehler2->InstanceID, FM_CONNECT, []);
    check('Flow-Nachrichten starten nach ausgeschöpften Versuchen keine Endlosschleife', $fehler2->timers['Verbindung'] === 0);
    $GLOBALS['CREATE_FEHLER'] = false;
    $fehler2->ApplyChanges();
    $fehler2->VerbindungPruefen();
    check('Explizites ApplyChanges erlaubt nach Fehlerbehebung neue Migration', $fehler2->parent->modul === $virtual);

    $rueckfall = parentKonto('eureka', $alt);
    $rueckfall->ApplyChanges();
    $GLOBALS['CONNECT_FEHLER'] = [true, 'exception'];
    $rueckfall->VerbindungPruefen();
    check('Auch gescheiterter Rückfall erhält die alte Parent-ID für Wiederherstellung', $rueckfall->parent === null
        && $rueckfall->ReadAttributeInteger('VerbindungsRueckfall') === $alt->InstanceID);
    $rueckfall->VerbindungPruefen();
    check('Nächster Versuch stellt erst den Rückfall her und migriert dann', $rueckfall->parent->modul === $virtual
        && $rueckfall->ReadAttributeInteger('VerbindungsRueckfall') === 0);

    $behalten = new FakeSocket(200103);
    $behalten->modul = $virtual;
    $f = parentKonto('eureka', $alt);
    $GLOBALS['CONNECT_FEHLER'] = [true, false];
    $f->VerbindungPruefen();
    check('Wiederverwendeter fremder Virtual IO wird bei Fehler nicht gelöscht', IPS_InstanceExists($behalten->InstanceID) && $f->parent === $alt);
    $f->VerbindungPruefen();
    check('Wiederverwendeter Kandidat bleibt für den nächsten Versuch verfügbar', $f->parent === $behalten);

    $f->WriteAttributeString('Mqtt', '{"zustand":"verbunden"}');
    IPS_DisconnectInstance($f->InstanceID);
    check('Externes Trennen entfernt Beobachtung und alten Echtzeitstand', $f->ReadAttributeInteger('MqttEltern') === 0
        && !isset($f->messages[$behalten->InstanceID][IM_CHANGESTATUS]) && $f->ReadAttributeString('Mqtt') === '{}');
    IPS_ConnectInstance($f->InstanceID, $behalten->InstanceID);
    check('Externes Verbinden registriert neuen Parent und Nachprüfung', $f->ReadAttributeInteger('MqttEltern') === $behalten->InstanceID
        && isset($f->messages[$behalten->InstanceID][IM_CHANGESTATUS]) && $f->timers['Verbindung'] === 15000);

    $fallback = new ReflectionMethod(RoboHubKonfigurator::class, 'verbindungsModul');
    $konfigurator = new RoboHubKonfigurator(200200);
    foreach (['eureka' => $virtual, 'roborock' => $virtual, 'dreame' => $client, 'mova' => $client, 'husqvarna' => $websocket, 'gardena' => $websocket] as $marke => $guid) {
        $k = parentKonto($marke);
        check('Konfigurator-Fallback für ' . $marke, $fallback->invoke($konfigurator, $k->InstanceID) === $guid);
        if ($marke === 'eureka') {
            $k->parent = $alt;
            check('Konfigurator übernimmt keinen noch nicht migrierten Eureka-Socket', $fallback->invoke($konfigurator, $k->InstanceID) === $virtual);
        }
    }
    foreach (['husqvarna', 'gardena'] as $marke) {
        $k = parentKonto($marke, $behalten);
        $k->WriteAttributeString('VerbindungsParents', json_encode([$virtual => $behalten->InstanceID]));
        $k->ApplyChanges();
        $k->VerbindungPruefen();
        check('Wechsel Virtual IO zu ' . $marke . ' verwendet WebSocket und erhält alten Parent', $k->parent->modul === $websocket
            && IPS_InstanceExists($behalten->InstanceID) && $k->GetBuffer('WsZiel') === '');
        $k->props['Hersteller'] = 'eureka';
        $k->ApplyChanges();
        $k->VerbindungPruefen();
        check('Wechsel ' . $marke . ' zu Eureka verwendet vorherigen Virtual IO', $k->parent === $behalten && $k->timers['MqttPing'] === 0);
    }

    $aktivIo = new FakeSocket(200104);
    $aktiv = parentKonto('dreame', $aktivIo);
    $aktiv->WriteAttributeString('Buendel', json_encode(['access_token' => 'offline', 'refresh_token' => 'offline', 'expires_at' => time() + 3600, 'region' => 'eu']));
    $aktiv->ApplyChanges();
    $aktiv->VerbindungPruefen();
    $aktivIo->config = json_decode($aktiv->GetConfigurationForParent(), true);
    $aktiv->props['Hersteller'] = 'eureka';
    $aktiv->ApplyChanges();
    $aktiv->VerbindungPruefen();
    $aktiv->props['Hersteller'] = 'dreame';
    $aktiv->ApplyChanges();
    $GLOBALS['SKRIPTE'] = [];
    $aktiv->VerbindungPruefen();
    $skript = implode('\n', $GLOBALS['SKRIPTE']);
    check('Wiederverwendeter offener MQTT-Socket wird auch bei gleicher Konfiguration neu verbunden', $aktiv->parent === $aktivIo
        && str_contains($skript, '"Open", false') && str_contains($skript, "'Open', true"));
    $aktiv->WriteAttributeString('Mqtt', '{"zustand":"verbunden","topics":4}');
    $aktiv->SetBuffer('MqttEingang', 'veraltet');
    $aktiv->props['Hersteller'] = 'mova';
    $aktiv->ApplyChanges();
    check('Dreame zu MOVA am selben Socket verwirft alte Sitzung und konfiguriert neues Ziel', $aktiv->parent === $aktivIo
        && $aktiv->GetBuffer('MqttEingang') === '' && !isset(json_decode($aktiv->ReadAttributeString('Mqtt'), true)['topics']));

    $vorbereitung = parentKonto('dreame');
    $GLOBALS['CREATE_FEHLER'] = true;
    $vorbereitung->VerbindungPruefen();
    check('Fehler vor dem Verbinden plant begrenzten Neuversuch', $vorbereitung->parent === null && $vorbereitung->timers['Verbindung'] === 5000);
    $GLOBALS['CREATE_FEHLER'] = false;
    $GLOBALS['PARENT_LOCK_FEHLER'] = true;
    $anzahl = count($GLOBALS['INST']);
    $vorbereitung->VerbindungPruefen();
    check('Besetzte Migrationssperre erzeugt keinen weiteren I/O', count($GLOBALS['INST']) === $anzahl && $vorbereitung->timers['Verbindung'] === 10000);
    $GLOBALS['PARENT_LOCK_FEHLER'] = false;
    $vorbereitung->VerbindungPruefen();
    check('Nach Freigabe der Sperre wird die Verbindung hergestellt', $vorbereitung->parent->modul === $client);

    $start = parentKonto('eureka', $alt);
    $GLOBALS['KERNEL_RUNLEVEL'] = 10102;
    $start->ApplyChanges();
    $start->VerbindungPruefen();
    check('Kernel noch nicht bereit: keine Migration', $start->parent === $alt && isset($start->messages[0][IPS_KERNELMESSAGE]));
    unset($GLOBALS['KERNEL_RUNLEVEL']);
    $start->MessageSink(0, 0, IPS_KERNELMESSAGE, [KR_READY]);
    $start->VerbindungPruefen();
    check('Kernel bereit: verzögerte Virtual-IO-Migration wird nachgeholt', $start->parent->modul === $virtual);

    $fremdIo = new FakeSocket(200105);
    $fremdIo->modul = '{00000000-0000-0000-0000-000000000001}';
    $fremdKonto = parentKonto('dreame', $fremdIo);
    $GLOBALS['SKRIPTE'] = [];
    $fremdKonto->ApplyChanges();
    $fremdKonto->VerbindungPruefen();
    check('Unbekannter fremder I/O-Typ bleibt verbunden, unkonfiguriert und erhalten', $fremdKonto->parent === $fremdIo
        && $fremdKonto->GetConfigurationForParent() === '{}' && $GLOBALS['SKRIPTE'] === [] && IPS_InstanceExists($fremdIo->InstanceID));

    $konsoleIo = new FakeSocket(200106);
    $rennen = parentKonto('eureka', $alt);
    $anzahl = count($GLOBALS['INST']);
    $GLOBALS['CONNECT_HOOK'] = static function (int $kind, int $ziel) use ($rennen, $konsoleIo): void {
        if ($kind === $rennen->InstanceID) {
            $rennen->parent = $konsoleIo; // Konsole verbindet genau zwischen Trennen und Verbinden
            unset($GLOBALS['CONNECT_HOOK']);
        }
    };
    $rennen->VerbindungPruefen();
    check('Konkurrierende Konsolenverbindung wird nicht überschrieben oder gelöscht', $rennen->parent === $konsoleIo
        && IPS_InstanceExists($alt->InstanceID) && IPS_InstanceExists($konsoleIo->InstanceID) && count($GLOBALS['INST']) === $anzahl);
} finally {
    foreach ($parentStand as $key => $value) { $GLOBALS[$key] = $value; }
    $GLOBALS['CONNECT_FEHLER'] = [];
    $GLOBALS['DISCONNECT_FEHLER'] = false;
    $GLOBALS['CREATE_FEHLER'] = false;
    $GLOBALS['FLOW_MELDUNGEN'] = false;
    unset($GLOBALS['CONNECT_HOOK'], $GLOBALS['KERNEL_RUNLEVEL'], $GLOBALS['PARENT_LOCK_FEHLER']);
}
