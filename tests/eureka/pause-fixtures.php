<?php
declare(strict_types=1);
require_once __DIR__ . '/../../libs/RoboHubEurekaPauseTest.php';
require_once __DIR__ . '/e20-fixture.php';

/** Ausschließlich erfundene lokale Zustände; kein Dokument, Schlüssel oder Import. */
function pauseFixture(): stdClass
{
    $f = new stdClass();
    $g = e20Fixture()['geraet'];
    $g['stand']['zeit'] = $g['verbindungEmpfangen'] = time();
    unset($g['stand']['roh']['connected']);
    $g['stand']['online'] = false;
    $g['stand']['statusquelle'] = ['quelle' => 'shadow-get', 'geraetezeit' => null];
    $f->k = ['konto' => 45174, 'instanz' => 22708, 'befehlsvariable' => 22284,
        'hersteller' => 'eureka', 'region' => 'eu', 'jetzt' => time(), 'monoton' => 100000,
        'boot' => 'offline-runtime', 'revision' => 'offline-session', 'benutzer' => '@admin',
        'kontoname' => 'offline@example.invalid', 'warteBis' => 0, 'cacheZeit' => time(), 'geraet' => $g,
        'buendel' => ['region' => 'eu', 'access_token' => 'e30.' . base64_encode(json_encode(['exp' => time() + 3600])) . '.offline']];
    $f->attr = $f->buffer = [];
    $f->attrWrite = $f->bufferWrite = true;
    $f->locked = false;
    $f->test = new RoboHubEurekaPauseTest(fn(): array => $f->k,
        fn(): array => $f->attr, function (array $a) use ($f): void { if ($f->attrWrite) { $f->attr = $a; } },
        fn(): array => $f->buffer, function (array $a) use ($f): void { if ($f->bufferWrite) { $f->buffer = $a; } },
        function (callable $fn) use ($f) {
            if ($f->locked) { throw new RoboHubFehler('Belegt'); }
            $f->locked = true;
            try { return $fn(); } finally { $f->locked = false; }
        });
    return $f;
}
function pauseFreigeben(stdClass $f, int $sekunden = 60): void
{
    $f->test->freigeben('offline-e20', $sekunden, RoboHubEurekaPauseTest::BESTAETIGUNG);
}
