<?php
declare(strict_types=1);

require_once __DIR__ . '/RoboHubEurekaDiagnose.php';
require_once __DIR__ . '/RoboHubEurekaBefehlsweg.php';

/** Lokale Einmalberechtigung für den regulären manuellen Pause-Befehlsweg. Kein Transport. */
final class RoboHubEurekaPauseTest
{
    public const BESTAETIGUNG = 'Ich habe die Cloud-Gerätekennung individuell geprüft und beaufsichtige jetzt vor Ort den einmaligen Pause-Test des reinigenden E20 Evo Plus';
    private $kontext;
    private $lesen;
    private $speichern;
    private $laufzeitLesen;
    private $laufzeitSpeichern;
    private $sperre;

    public function __construct(callable $kontext, callable $lesen, callable $speichern,
        callable $laufzeitLesen, callable $laufzeitSpeichern, callable $sperre)
    {
        $this->kontext = $kontext;
        $this->lesen = $lesen;
        $this->speichern = $speichern;
        $this->laufzeitLesen = $laufzeitLesen;
        $this->laufzeitSpeichern = $laufzeitSpeichern;
        $this->sperre = $sperre;
    }

    public static function zuordnungen(array $k): array
    {
        return [['identifikation' => RoboHubEurekaDiagnose::modellPruefen($k['geraet']),
            'familie' => RoboHubEurekaProtokoll::FAMILIE,
            'beleg' => 'Herstellerbeleg Pause; ausschließlich individuell bestätigter beaufsichtigter Einmaltest']];
    }

    private static function kontextPruefen(array $k): void
    {
        RoboHubEurekaDiagnose::pruefen($k);
        $g = $k['geraet'];
        if (($k['befehlsvariable'] ?? null) !== 22284 ||
            !is_string($k['benutzer'] ?? null) || trim($k['benutzer']) === '' || $k['benutzer'] === '@unknown' ||
            ($g['roh']['deviceId'] ?? null) !== $g['id'] ||
            (array_key_exists('id', $g['roh']) && (string)$g['roh']['id'] !== $g['id']) ||
            (array_key_exists('online', $g['roh']) && $g['roh']['online'] !== true) ||
            ($g['stand']['statusquelle']['quelle'] ?? null) !== 'shadow-get' ||
            ($g['stand']['roh']['state'] ?? null) !== 'cleaning') {
            throw new RoboHubFehler('Eureka: Pause-Test benötigt beaufsichtigte, eindeutig gebundene Reinigung.');
        }
        // Der manuelle Listenbefund darf fehlendes Shadow-connected überbrücken.
        // Der Snapshot und sein unbekannter Online-Status werden niemals verändert.
        RoboHubEurekaBefehlsweg::vorbereiten($g, 'pause', [], self::zuordnungen($k), $k['jetzt'], true);
    }

    private static function bindung(array $k): string
    {
        return hash('sha256', serialize([$k['konto'], $k['instanz'], $k['befehlsvariable'],
            $k['geraet']['id'], $k['boot'], $k['revision'], $k['buendel'], $k['kontoname'], $k['benutzer']]));
    }

    /** Flüchtiges Veto zuerst: Schreibfehler dürfen keinen zweiten Versuch ermöglichen. */
    private function speichern(array $f): void
    {
        ($this->laufzeitSpeichern)($f);
        // Auch bei wirkungslosem Bufferschreiben einen persistenten Verbrauch setzen.
        // Ein Unterschied zwischen beiden Speichern bleibt ein Veto.
        ($this->speichern)($f);
        if (($this->laufzeitLesen)() !== $f || ($this->lesen)() !== $f) {
            throw new RoboHubFehler('Eureka: Pause-Verbrauch nicht bestätigt; kein Versand.');
        }
    }

    public function freigeben(string $cloudGeraetId, int $sekunden, string $bestaetigung): int
    {
        return ($this->sperre)(function () use ($cloudGeraetId, $sekunden, $bestaetigung): int {
            if ($sekunden < 1 || $sekunden > 60 || $bestaetigung !== self::BESTAETIGUNG ||
                ($this->lesen)() !== [] || ($this->laufzeitLesen)() !== []) {
                throw new RoboHubFehler('Eureka: Einmalige beaufsichtigte Pause-Freigabe für 1 bis 60 Sekunden erforderlich.');
            }
            $k = ($this->kontext)();
            self::kontextPruefen($k);
            if ($cloudGeraetId === '' || !hash_equals($k['geraet']['id'], $cloudGeraetId)) {
                throw new RoboHubFehler('Eureka: Individuell bestätigte Cloud-Gerätekennung stimmt nicht überein.');
            }
            $this->speichern(['zustand' => 'bereit', 'cloudGeraetId' => $cloudGeraetId,
                'bindung' => self::bindung($k), 'von' => $k['jetzt'], 'bis' => $k['jetzt'] + $sekunden,
                'monotonVon' => $k['monoton'], 'monotonBis' => $k['monoton'] + $sekunden * 1000]);
            return $k['jetzt'] + $sekunden;
        });
    }

    /** Jedes Mal lokale Frische, Ziel, Sitzung, Bediener und beide Uhren prüfen. */
    private function pruefenGesperrt(string $zustand): array
    {
        $f = ($this->lesen)();
        $k = ($this->kontext)();
        self::kontextPruefen($k);
        if ($f === [] || $f !== ($this->laufzeitLesen)() || ($f['zustand'] ?? null) !== $zustand ||
            ($f['cloudGeraetId'] ?? null) !== $k['geraet']['id'] ||
            !is_string($f['bindung'] ?? null) || !hash_equals($f['bindung'], self::bindung($k)) ||
            !is_int($f['von'] ?? null) || !is_int($f['bis'] ?? null) ||
            $f['bis'] - $f['von'] < 1 || $f['bis'] - $f['von'] > 60 ||
            $k['jetzt'] < $f['von'] || $k['jetzt'] >= $f['bis'] ||
            !is_int($f['monotonVon'] ?? null) || !is_int($f['monotonBis'] ?? null) ||
            $f['monotonBis'] - $f['monotonVon'] !== ($f['bis'] - $f['von']) * 1000 ||
            $k['monoton'] < $f['monotonVon'] || $k['monoton'] >= $f['monotonBis']) {
            throw new RoboHubFehler('Eureka: Pause-Testfreigabe fehlt, ist geändert, verbraucht oder abgelaufen.');
        }
        return [$f, $k];
    }

    public function pruefen(): array
    {
        return ($this->sperre)(function (): array { return $this->pruefenGesperrt('bereit')[1]; });
    }

    public function verbrauchen(): void
    {
        ($this->sperre)(function (): void {
            [$f, $k] = $this->pruefenGesperrt('bereit');
            $this->speichern(array_replace($f, ['zustand' => 'verbraucht', 'verbrauchtAm' => $k['jetzt']]));
        });
    }

    public function vorTransport(bool $transportBeginnt = false): void
    {
        ($this->sperre)(function () use ($transportBeginnt): void {
            [$f, $k] = $this->pruefenGesperrt('verbraucht');
            if ($transportBeginnt) {
                $this->speichern(array_replace($f, ['zustand' => 'geschlossen', 'transportBegonnenAm' => $k['jetzt']]));
            }
        });
    }

    public function widerrufen(): void
    {
        ($this->sperre)(function (): void {
            $f = ($this->lesen)();
            if (($f['zustand'] ?? null) === 'geschlossen') {
                throw new RoboHubFehler('Eureka: Pause-Transport bereits begonnen; Widerruf nicht mehr möglich.');
            }
            $this->speichern(array_replace($f, ['zustand' => 'widerrufen']));
        });
    }
}
