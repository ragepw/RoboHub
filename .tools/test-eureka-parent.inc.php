<?php
declare(strict_types=1);

// Bestehende Objekt-IDs, ausschließlich erfundene Geräte- und Sitzungsdaten.
// Echte Konto-/Geräte-/Konfiguratormodule; Transport nur über IntegrationEureka.
abschnitt('Eureka: Start und Timer ohne automatische Parent-Migration');
$eurekaParentStand = [];
foreach (['INST', 'OBJ', 'NAMEN', 'ERZEUGT', 'GELOESCHT', 'CONNECTS', 'SKRIPTE',
    'robohubTreiberFabrik', 'FLOW_MELDUNGEN'] as $key) {
    $eurekaParentStand[$key] = $GLOBALS[$key] ?? [];
}
try {
    foreach (['alter Socket', 'geteilter Socket', 'Virtual IO', 'ohne Parent'] as $fall) {
        $GLOBALS['INST'] = $GLOBALS['OBJ'] = $GLOBALS['NAMEN'] = [];
        $GLOBALS['ERZEUGT'] = $GLOBALS['GELOESCHT'] = $GLOBALS['CONNECTS'] = $GLOBALS['SKRIPTE'] = [];
        $GLOBALS['FLOW_MELDUNGEN'] = true;
        $io = null;
        if ($fall !== 'ohne Parent') {
            $io = new FakeSocket(37512);
            $io->status = 104;
            if ($fall === 'Virtual IO') { $io->modul = '{6179ED6A-FC31-413C-BB8E-1204150CF376}'; }
            IPS_SetName(37512, 'Vorhandene Verbindung');
            if ($fall === 'geteilter Socket') {
                $anderes = new RoboHubKonto(40000);
                $anderes->Create();
                $anderes->parent = $io;
            }
        }
        $et = new IntegrationEureka();
        $GLOBALS['robohubTreiberFabrik'] = static fn(string $marke): RoboHubTreiber => $et;
        $ek = new RoboHubKonto(45174);
        // Persistierte Properties sind beim Neustart bereits vor Create vorhanden.
        $ek->props = ['Hersteller' => 'eureka', 'Region' => 'eu', 'Konto' => 'offline@example.invalid'];
        $ek->parent = $io;
        $ek->Create();
        $token = 'offline.' . rtrim(strtr(base64_encode(json_encode(['exp' => time() + 3600])), '+/', '-_'), '=') . '.fixture';
        $ek->WriteAttributeString('Buendel', json_encode(['region' => 'eu', 'access_token' => $token, 'refresh_token' => 'offline']));
        $ek->WriteAttributeString('BuendelRevision', 'offline-revision');
        $ek->WriteAttributeString('Geraete', json_encode([e20Fixture()['geraet']]));
        $ek->WriteAttributeInteger('GeraeteStand', time());
        $geschuetzt = ['Buendel', 'BuendelRevision', 'EurekaB8Diagnose', 'EurekaDockVersuch', 'EurekaBefehlsversuch', 'EurekaPauseTest'];
        foreach (array_slice($geschuetzt, 2) as $name) {
            $ek->WriteAttributeString($name, '{"zustand":"verbraucht","test":"offline"}');
        }
        $eg = new RoboHubGeraet(22708);
        $eg->Create();
        $eg->parent = $ek;
        $eg->props['GeraetID'] = 'offline-e20';
        $eg->props['Modell'] = 'E20 Evo Plus';
        $cfg = new RoboHubKonfigurator(50911);
        $cfg->Create();
        $ids = IPS_GetInstanceList();
        $eigenschaften = [$ek->props, $eg->props];
        $attribute = array_intersect_key($ek->attrs, array_flip($geschuetzt));
        $ioStand = $io === null ? null : [$io->config, $io->props, $io->status, $io->applyAnzahl, IPS_GetName(37512)];
        $unveraendert = static function () use ($ids, $ek, $eg, $io, $ioStand): bool {
            return IPS_GetInstanceList() === $ids && $GLOBALS['ERZEUGT'] === [] && $GLOBALS['GELOESCHT'] === []
                && $GLOBALS['CONNECTS'] === [] && $GLOBALS['SKRIPTE'] === [] && $ek->parent === $io && $eg->parent === $ek
                && ($io === null || $ioStand === [$io->config, $io->props, $io->status, $io->applyAnzahl, IPS_GetName(37512)]);
        };
        check($fall . ': Create erzeugt keine zusätzlichen Instanzen', $GLOBALS['ERZEUGT'] === []);
        $GLOBALS['KERNEL_RUNLEVEL'] = 10102;
        $ek->ApplyChanges(); $eg->ApplyChanges(); $cfg->ApplyChanges();
        $ek->VerbindungPruefen();
        check($fall . ': Kernelstart erhält Instanzen und Verbindungen', $unveraendert());
        unset($GLOBALS['KERNEL_RUNLEVEL']);
        $ek->MessageSink(0, 0, IPS_KERNELMESSAGE, [KR_READY]);
        $eg->MessageSink(0, 0, IPS_KERNELMESSAGE, [KR_READY]);
        for ($i = 0; $i < 3; ++$i) {
            $ek->ApplyChanges(); $eg->ApplyChanges();
            check($fall . ': ApplyChanges plant keine Parent-Migration ' . $i, $ek->timers['Verbindung'] === 0);
            foreach ([FM_CONNECT, FM_DISCONNECT] as $meldung) {
                $ek->MessageSink(0, 45174, $meldung, []);
                check($fall . ': Flow-Nachricht plant keine Parent-Migration ' . $meldung . '/' . $i, $ek->timers['Verbindung'] === 0);
            }
            // Auch ein bereits eingeplanter oder direkt aufgerufener Timer muss wirkungslos sein.
            $ek->SetTimerInterval('Verbindung', 15000);
            $ek->VerbindungPruefen();
            $ek->GetCompatibleParents(); $ek->GetConfigurationForParent();
            $ek->GetConfigurationForm(); $eg->GetConfigurationForm(); $cfg->GetConfigurationForm();
        }
        check($fall . ': Start, alte Timer und Formulare erhalten alle Instanzen', $unveraendert()
            && $ek->timers['Verbindung'] === 0 && $et->pfade === []);
        // Sämtliche aktuell registrierten Konto-/Gerätetimer, ohne Befehlsaktion.
        check($fall . ': alle Konto- und Gerätetimer im Prüfstand erfasst',
            array_keys($ek->timers) === ['MqttPing', 'Erneuern', 'Verbindung']
            && array_keys($eg->timers) === ['Abruf', 'LiveKarte', 'Zuordnen']);
        $ek->MqttPing(); $ek->Erneuern(); $ek->VerbindungPruefen();
        $eg->Aktualisieren(); $eg->LiveKarteLaden(); $eg->RequestAction('Zuordnen', 0);
        check($fall . ': alle Timer erhalten Instanzen; Status-GET benötigt kein aktives I/O',
            $unveraendert() && $et->pfade === [['GET', '/shadow/device/state']]
            && variable(22708, 'Akku')['value'] === 87);
        check($fall . ': Properties, Sitzung, Verbrauch und Freigaben bleiben erhalten',
            $eigenschaften === [$ek->props, $eg->props]
            && $attribute === array_intersect_key($ek->attrs, array_flip($geschuetzt))
            && $ek->GetBuffer('EurekaB8Laufzeitfreigabe') === '' && $ek->GetBuffer('EurekaDockLaufzeitfreigabe') === '');
        if ($fall === 'geteilter Socket') {
            check('Anderes Konto behält den geteilten Socket', $anderes->parent === $io);
        }
        // Ohne Sitzung ebenso keine Migration und kein Login/Refresh/Status-Transport.
        $ek->WriteAttributeString('Buendel', '');
        $et->pfade = [];
        $ek->ApplyChanges(); $ek->VerbindungPruefen(); $ek->Erneuern();
        $ek->GetConfigurationForm(); $cfg->GetConfigurationForm();
        check($fall . ': fehlende Sitzung erzeugt weder I/O noch Anmeldung', $unveraendert()
            && $ek->timers['Verbindung'] === 0 && $ek->timers['Erneuern'] === 0 && $et->pfade === []);
    }
} finally {
    foreach ($eurekaParentStand as $key => $value) { $GLOBALS[$key] = $value; }
    unset($GLOBALS['KERNEL_RUNLEVEL']);
}
