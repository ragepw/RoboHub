<?php
declare(strict_types=1);

require_once __DIR__ . '/RoboHubEurekaProtokoll.php';

/** Kleine, reine Diagnoseauswertung; Offsets: docs/eureka-zustaende.md. */
final class RoboHubEurekaB8
{
    public static function lesen(string $hex): array
    {
        RoboHubEurekaProtokoll::pruefeRahmen($hex);
        $b = array_values(unpack('C*', hex2bin($hex)));
        if ($b[9] !== 3 || array_slice($b, 10, 2) !== [0x32, 1]) {
            throw new RoboHubFehler('Eureka: Keine B8-Arbeitszustandsantwort.');
        }
        // Bis einschließlich Byte 35 plus Prüfsumme. Kürzere Frames sind
        // syntaktisch möglich, aber kein ausreichender Arbeitsstatusnachweis.
        if (count($b) < 37) {
            throw new RoboHubFehler('Eureka: Unvollständige B8-Arbeitszustandsantwort.');
        }
        // Bewusst nur Arbeitsstatus, Akku und Fehlerbytes; keine unbekannten Felder.
        $felder = ['arbeit' => $b[12], 'akku' => $b[22],
            'fehlerklasse' => $b[25], 'fehlercode' => $b[26]];
        return ['felder' => $felder, 'plausibel' =>
            in_array($b[12], [...range(1, 18), 22, 23, 24], true) && $b[22] <= 100];
    }

    /** Vier getrennte Befunde, keine Rohdaten oder Cloud-Freitexte nach außen. */
    public static function antwort(array $antwort): array
    {
        $status = $antwort['status'] ?? null;
        $r = ['httpStatus' => is_int($status) ? $status : null,
            'httpErfolg' => is_int($status) && $status >= 200 && $status <= 299,
            'cloudErfolg' => null, 'frameGueltig' => null, 'plausibel' => null];
        if (!$r['httpErfolg']) { return $r; }
        $data = json_decode(is_string($antwort['response'] ?? null) ? $antwort['response'] : '', true);
        $r['cloudErfolg'] = is_array($data) && array_key_exists('errorCode', $data) &&
            in_array($data['errorCode'], [0, '0'], true);
        if (!$r['cloudErfolg']) { return $r; }
        $hex = $data['data'] ?? null;
        if (is_array($hex)) { $hex = $hex['data'] ?? null; }
        $r['frameGueltig'] = false;
        if (!is_string($hex)) { return $r; }
        try {
            $frame = self::lesen($hex);
            $r['frameGueltig'] = true;
            $r['plausibel'] = $frame['plausibel'];
            $r['felder'] = $frame['felder'];
        } catch (RoboHubFehler $e) {
            // Ungültige/unvollständige Frames werden weder gespeichert noch geloggt.
        }
        return $r;
    }
}
