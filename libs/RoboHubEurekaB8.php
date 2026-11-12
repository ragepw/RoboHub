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
        // Optionale Herstellerfelder niemals aus kurzen Frames oder der Prüfsumme lesen.
        $felder = ['arbeit' => $b[12], 'akku' => $b[22],
            'fehlerklasse' => $b[25], 'fehlercode' => $b[26]];
        if (count($b) > 49 && in_array($b[48], [1, 2, 3, 4], true)) {
            $felder['sleep_type'] = $b[48];
        }
        return ['felder' => $felder, 'plausibel' =>
            in_array($b[12], [...range(1, 18), 22, 23, 24], true) && $b[22] <= 100];
    }

    /** Nur lokale Auswertung. Diagnose enthält ausschließlich Struktur und feste Fehler. */
    public static function antwort(array $antwort): array
    {
        $status = $antwort['status'] ?? null;
        $r = ['httpStatus' => is_int($status) ? $status : null,
            'httpErfolg' => is_int($status) && $status >= 200 && $status <= 299,
            'cloudErfolg' => null, 'frameGueltig' => null, 'plausibel' => null,
            'diagnose' => ['struktur' => [], 'kodierung' => null, 'frameLaenge' => null,
                'validierungsfehler' => null]];
        $d = &$r['diagnose'];
        if (!$r['httpErfolg']) {
            $d['validierungsfehler'] = 'Eureka: HTTP-Erfolg fehlt.';
            return $r;
        }
        try {
            $data = self::json(is_string($antwort['response'] ?? null) ? $antwort['response'] : '');
            self::struktur($data, '$', $d['struktur']);
            $r['cloudErfolg'] = $data instanceof stdClass && property_exists($data, 'errorCode') &&
                in_array($data->errorCode, [0, '0'], true);
            if (!$r['cloudErfolg']) {
                $d['validierungsfehler'] = 'Eureka: Expliziter Cloud-Erfolg fehlt.';
                return $r;
            }
            $r['frameGueltig'] = false;
            if (!property_exists($data, 'data')) {
                throw new RoboHubFehler('Eureka: Antwortfeld data fehlt.');
            }
            $kandidaten = [];
            $knoten = 0;
            self::extrahieren($data->data, '$.data', [], $kandidaten, $d, $knoten);
            if (count($kandidaten) !== 1) {
                throw new RoboHubFehler('Eureka: Antwort enthält mehrere Frame-Kandidaten.');
            }
            [$hex, $kodierung] = $kandidaten[0];
            $d['kodierung'] = implode(' > ', $kodierung);
            $d['frameLaenge'] = intdiv(strlen($hex), 2);
            $frame = self::lesen($hex);
            $r['frameGueltig'] = true;
            $r['plausibel'] = $frame['plausibel'];
            $r['felder'] = $frame['felder'];
            if (!$frame['plausibel']) {
                $d['validierungsfehler'] = 'Eureka: Arbeitszustand oder Akkustand unplausibel.';
            }
        } catch (RoboHubFehler $e) {
            // Ausschließlich eigene feste Meldungen, niemals JSON-/Cloud-Freitext.
            $d['validierungsfehler'] = $e->getMessage();
            if ($r['cloudErfolg'] === null) { $r['cloudErfolg'] = false; }
        }
        return $r;
    }

    /** Vollständiges JSON, keine Suche nach JSON-Fragmenten in Freitext. */
    private static function json(string $text): mixed
    {
        if (strlen($text) > 65536) {
            throw new RoboHubFehler('Eureka: JSON-Größenlimit überschritten.');
        }
        try {
            $wert = json_decode($text, false, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RoboHubFehler('Eureka: Ungültiges JSON oder JSON-Tiefenlimit überschritten.');
        }
        // json_decode überschreibt doppelte Schlüssel. Vor jeglicher Interpretation
        // auch escaped gleiche Schlüssel und Duplikate in unbekannten Feldern ablehnen.
        if (preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}\[\]:,]|[^\s{}\[\]:,]+/s', $text, $treffer) === false) {
            throw new RoboHubFehler('Eureka: JSON-Feldprüfung konnte nicht abgeschlossen werden.');
        }
        $stack = [];
        $tokens = $treffer[0];
        foreach ($tokens as $i => $token) {
            if ($token === '{' || $token === '[') { $stack[] = []; }
            elseif ($token === '}' || $token === ']') { array_pop($stack); }
            elseif (str_starts_with($token, '"') && ($tokens[$i + 1] ?? null) === ':') {
                $key = json_decode($token, true, 2, JSON_THROW_ON_ERROR);
                $n = count($stack) - 1;
                if (array_key_exists($key, $stack[$n])) {
                    throw new RoboHubFehler('Eureka: Doppelte JSON-Felder sind mehrdeutig.');
                }
                $stack[$n][$key] = true;
            }
        }
        return $wert;
    }

    /** Unbekannte Feldnamen können selbst Geheimnisse sein: nur Positionspfade ausgeben. */
    private static function struktur(mixed $wert, string $pfad, array &$struktur, int $tiefe = 0): void
    {
        if (count($struktur) >= 48) { return; }
        $typ = $wert instanceof stdClass ? 'object' : get_debug_type($wert);
        $struktur[] = ['pfad' => $pfad, 'typ' => $typ];
        if ($tiefe >= 8) { return; }
        if ($wert instanceof stdClass) {
            $i = 0;
            foreach (get_object_vars($wert) as $key => $v) {
                $segment = in_array($key, ['data', 'errorCode'], true) ? '.' . $key : '.[feld' . $i . ']';
                self::struktur($v, $pfad . $segment, $struktur, $tiefe + 1);
                $i++;
                if (count($struktur) >= 48) { break; }
            }
        } elseif (is_array($wert)) {
            foreach ($wert as $i => $v) {
                self::struktur($v, $pfad . '[' . $i . ']', $struktur, $tiefe + 1);
                if (count($struktur) >= 48) { break; }
            }
        }
    }

    /** Ausschließlich data-Ketten und Listen darunter; niemals andere Felder absuchen. */
    private static function extrahieren(mixed $wert, string $pfad, array $kodierung,
        array &$kandidaten, array &$diagnose, int &$knoten, int $tiefe = 0): void
    {
        if (++$knoten > 128 || $tiefe > 12) {
            throw new RoboHubFehler('Eureka: Antwort-Strukturlimit überschritten.');
        }
        if ($wert instanceof stdClass) {
            if (property_exists($wert, 'errorCode') && !in_array($wert->errorCode, [0, '0'], true)) {
                throw new RoboHubFehler('Eureka: Verschachtelter Cloud-Befund widerspricht dem Erfolg.');
            }
            if (!property_exists($wert, 'data')) {
                throw new RoboHubFehler('Eureka: Kein zugelassenes data-Antwortfeld.');
            }
            self::extrahieren($wert->data, $pfad . '.data', $kodierung, $kandidaten, $diagnose, $knoten, $tiefe + 1);
            return;
        }
        if (is_array($wert)) {
            if ($wert === []) { throw new RoboHubFehler('Eureka: Leere Antwortliste.'); }
            // Nur tatsächliche JSON-Integer, keine Strings, Floats oder booleschen Werte.
            $nurInteger = count(array_filter($wert, 'is_int')) === count($wert);
            if ($nurInteger) {
                $diagnose['kodierung'] = implode(' > ', [...$kodierung, 'bytearray']);
                $diagnose['frameLaenge'] = count($wert);
                if (count($wert) > 256 || min($wert) < 0 || max($wert) > 255) {
                    throw new RoboHubFehler('Eureka: Bytearray-Länge oder Bytebereich ungültig.');
                }
                $hex = strtoupper(bin2hex(pack('C*', ...$wert)));
                $kodierung[] = 'bytearray';
            } else {
                foreach ($wert as $i => $v) {
                    self::extrahieren($v, $pfad . '[' . $i . ']', $kodierung,
                        $kandidaten, $diagnose, $knoten, $tiefe + 1);
                }
                return;
            }
        } elseif (is_string($wert)) {
            $text = trim($wert, " \t\r\n");
            if ($text === '') { throw new RoboHubFehler('Eureka: Leeres Antwortfeld.'); }
            if (in_array($text[0], ['{', '[', '"'], true)) {
                $json = self::json($text);
                self::struktur($json, $pfad . '<json>', $diagnose['struktur']);
                self::extrahieren($json, $pfad . '<json>', [...$kodierung, 'json'],
                    $kandidaten, $diagnose, $knoten, $tiefe + 1);
                return;
            }
            if (strlen($text) > 2048) {
                throw new RoboHubFehler('Eureka: Kodiertes Frame-Größenlimit überschritten.');
            }
            if (preg_match('/\A(?:0x)?([0-9a-f]+)\z/iD', $text, $m)) {
                if (strlen($m[1]) % 2 !== 0) {
                    throw new RoboHubFehler('Eureka: Hexstring hat eine ungerade Stellenzahl.');
                }
                $hex = strtoupper($m[1]);
                $kodierung[] = 'hex';
            } elseif (preg_match('/\A(?:[0-9a-f]{2})(?:[ \t\r\n,:;-]+[0-9a-f]{2})+\z/iD', $text) ||
                preg_match('/\A0x[0-9a-f]{2}(?:[ \t\r\n,:;-]+0x[0-9a-f]{2})+\z/iD', $text)) {
                $hex = strtoupper(preg_replace('/0x|[ \t\r\n,:;-]+/i', '', $text));
                $kodierung[] = 'hex-getrennt';
            } else {
                $bytes = base64_decode($text, true);
                if ($bytes === false || base64_encode($bytes) !== $text ||
                    $bytes === '' || ord($bytes[0]) !== 0xAA) {
                    throw new RoboHubFehler('Eureka: Keine eindeutige B8-Kodierung.');
                }
                $hex = strtoupper(bin2hex($bytes));
                $kodierung[] = 'base64';
            }
        } else {
            throw new RoboHubFehler('Eureka: Antwortdatentyp nicht unterstützt.');
        }
        $kandidaten[] = [$hex, $kodierung];
        if (count($kandidaten) > 1) {
            // Auch identische Duplikate sind kein eindeutig zugeordnetes Ergebnis.
            $diagnose['kodierung'] = null;
            $diagnose['frameLaenge'] = null;
            throw new RoboHubFehler('Eureka: Antwort enthält mehrere Frame-Kandidaten.');
        }
        $diagnose['kodierung'] = implode(' > ', $kodierung);
        $diagnose['frameLaenge'] = intdiv(strlen($hex), 2);
    }
}
