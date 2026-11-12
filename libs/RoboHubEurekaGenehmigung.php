<?php
declare(strict_types=1);

/** Offline signiertes, zweckgebundenes Dokument. Kein Schlüsselgenerator im Modul. */
final class RoboHubEurekaGenehmigung
{
    public const ZWECK = 'robohub/eureka/b8/runtime/v2';

    public static function pruefen(string $dokument, string $publicKey, string $boot, int $jetzt): array
    {
        try {
            if (strlen($dokument) > 4096 || !function_exists('sodium_crypto_sign_verify_detached')) {
                throw new RuntimeException();
            }
            $h = json_decode($dokument, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($h) || array_keys($h) !== ['nutzlast', 'signatur']) { throw new RuntimeException(); }
            $roh = base64_decode($h['nutzlast'], true);
            $sig = base64_decode($h['signatur'], true);
            $pk = base64_decode(trim($publicKey), true);
            if ($roh === false || $sig === false || $pk === false || strlen($pk) !== 32 || strlen($sig) !== 64 ||
                !sodium_crypto_sign_verify_detached($sig, $roh, $pk)) { throw new RuntimeException(); }
            $g = json_decode($roh, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($g) || array_keys($g) !== ['zweck', 'konto', 'instanz', 'cloudGeraetId', 'modell', 'befehl', 'erwartet', 'von', 'bis'] ||
                $g['zweck'] !== self::ZWECK || $g['konto'] !== 45174 || $g['instanz'] !== 22708 ||
                !is_string($g['cloudGeraetId']) || $g['cloudGeraetId'] === '' || strlen($g['cloudGeraetId']) > 256 ||
                trim($g['cloudGeraetId']) !== $g['cloudGeraetId'] || preg_match('/[\x00-\x1f\x7f]/', $g['cloudGeraetId']) ||
                $g['modell'] !== 'E20 Evo Plus' || $g['befehl'] !== RoboHubEurekaDiagnose::BEFEHL ||
                !is_string($g['erwartet']) || !preg_match('/\A[1-9][0-9]*:[a-f0-9]{64}\z/D', $g['erwartet']) ||
                !hash_equals($boot, $g['erwartet']) || !is_int($g['von']) || !is_int($g['bis']) ||
                $g['bis'] <= $g['von'] || $g['bis'] - $g['von'] > 300 ||
                $jetzt < $g['von'] || $jetzt >= $g['bis']) { throw new RuntimeException(); }
            return $g;
        } catch (Throwable $e) {
            throw new RoboHubFehler('Eureka: Externe Laufzeitgenehmigung ungültig; Bereitstellung gesperrt.');
        }
    }

    /** Fester externer Vertrauensanker; kein Property, Request-Parameter oder Umgebungswert. */
    public static function vertrauensanker(): string
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() === 0) {
            throw new RoboHubFehler('Eureka: Getrennte unprivilegierte Symcon-Laufzeit erforderlich.');
        }
        $pfad = '/etc/robohub/eureka-b8.pub';
        foreach (['/etc', '/etc/robohub', $pfad] as $p) {
            clearstatcache(true, $p);
            $s = @lstat($p);
            if ($s === false || $s['uid'] !== 0 || ($s['mode'] & 0022) !== 0 || is_link($p) || is_writable($p)) {
                throw new RoboHubFehler('Eureka: Administrativer Vertrauensanker fehlt oder ist ungeschützt.');
            }
        }
        if (!is_file($pfad) || filesize($pfad) > 128 || ($k = @file_get_contents($pfad)) === false) {
            throw new RoboHubFehler('Eureka: Administrativer Vertrauensanker unlesbar.');
        }
        return $k;
    }
}
