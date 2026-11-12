<?php
declare(strict_types=1);

require_once __DIR__ . '/RoboHubModell.php';

/** Reine Zustandsbewertung. Beleg und Grenzen: docs/eureka-zustaende.md. */
final class RoboHubEurekaZustand
{
    // Cloudvokabular aus der HA-Referenz; NICHT die B8-Enums des App-Plugins.
    private const SHADOW = [
        'cleaning' => RoboHubModell::ZUSTAND_REINIGT,
        'pause' => RoboHubModell::ZUSTAND_PAUSIERT,
        'charging_pause' => RoboHubModell::ZUSTAND_PAUSIERT,
        'wiping_pause' => RoboHubModell::ZUSTAND_PAUSIERT,
        'charging_back' => RoboHubModell::ZUSTAND_ZURUECK,
        'wiping_back' => RoboHubModell::ZUSTAND_ZURUECK,
        'charging' => RoboHubModell::ZUSTAND_LAEDT,
        'charging_finish' => RoboHubModell::ZUSTAND_GELADEN,
        'stop' => RoboHubModell::ZUSTAND_BEREIT,
        'sleep' => RoboHubModell::ZUSTAND_SCHLAF,
        'upgrading' => RoboHubModell::ZUSTAND_AKTUALISIERUNG,
        'error' => RoboHubModell::ZUSTAND_FEHLER,
    ];

    /**
     * Konkreter Unterzustand vor grobem Parent. Ein unbekannter Unterzustand
     * darf nie durch einen bekannten Parent zu einer bekannten Aktivität werden.
     * Ohne Unterzustand ist cleaning/sleep nur eine unzureichende Sammelgruppe.
     */
    public static function shadow(array $p): array
    {
        $state = $p['state'] ?? '';
        $parent = $p['parentState'] ?? '';
        // Ausschließlich die vom Nutzer beobachteten E20-Shadowkombinationen.
        // Keine allgemeine ERP-/Stationsheuristik aus diesen Einzelbelegen.
        if (($p['cleanMissionState'] ?? null) === 'no_cleaning_mission') {
            if ($state === 'collecting_dust' && $parent === 'collecting_dust') {
                return ['zustand' => RoboHubModell::ZUSTAND_STATIONSARBEIT, 'konsistent' => true,
                    'herstellerstatus' => 'Entleert Staub'];
            }
            if ($state === 'ERP_model' && $parent === 'sleep') {
                return ['zustand' => RoboHubModell::ZUSTAND_SCHLAF, 'konsistent' => true];
            }
        }
        $zustand = RoboHubModell::ZUSTAND_UNBEKANNT;
        $konsistent = is_string($state) && is_string($parent);
        if ($konsistent && isset(self::SHADOW[$state])) {
            $zustand = self::SHADOW[$state];
            $konsistent = $parent === '' || $parent === $state ||
                ($parent === 'cleaning' && in_array($state,
                    ['pause', 'charging_pause', 'wiping_pause', 'charging_back', 'wiping_back', 'error'], true)) ||
                ($parent === 'sleep' && in_array($state,
                    ['charging', 'charging_finish', 'stop', 'error'], true));
        } else {
            $konsistent = false;
        }
        // Ein konkreter Fehler bleibt sichtbar, auch bei widersprüchlichem Parent.
        if (!$konsistent && $state !== 'error') {
            $zustand = RoboHubModell::ZUSTAND_UNBEKANNT;
        }
        return ['zustand' => $zustand, 'konsistent' => $konsistent];
    }

    /**
     * Separate Offline-Auswertung der belegten E20-B8-Werte. Kein Frameparser,
     * kein Transport und kein automatischer Fallback für numerische Shadowwerte.
     * Schlafuntertypen bleiben Diagnosewerte, niemals Auftrag löschen/fortsetzen.
     */
    public static function b8(int $arbeit, ?int $schlaf = null): array
    {
        $zustand = match ($arbeit) {
            1, 17, 22 => RoboHubModell::ZUSTAND_ZURUECK,
            2 => RoboHubModell::ZUSTAND_REINIGT,
            3, 5 => RoboHubModell::ZUSTAND_BEREIT,
            4, 7 => RoboHubModell::ZUSTAND_LAEDT,
            6 => RoboHubModell::ZUSTAND_GELADEN,
            8, 10, 13, 24 => RoboHubModell::ZUSTAND_PAUSIERT,
            9 => RoboHubModell::ZUSTAND_AKTUALISIERUNG,
            11 => RoboHubModell::ZUSTAND_FEHLER,
            12, 18 => RoboHubModell::ZUSTAND_SCHLAF,
            16 => RoboHubModell::ZUSTAND_STATIONSARBEIT,
            23 => RoboHubModell::ZUSTAND_KARTIERUNG,
            // Relokalisierung und Wasseraufbereitung sind keine Reinigung.
            default => RoboHubModell::ZUSTAND_UNBEKANNT,
        };
        return ['zustand' => $zustand, 'arbeitRoh' => $arbeit, 'schlafRoh' => $schlaf,
            'schlaf' => in_array($arbeit, [12, 18], true) ? match ($schlaf) {
                1 => 'charge_sleep', 2 => 'pause_sleep',
                3 => 'pause_charge_sleep', 4 => 'stop_sleep', default => null,
            } : null];
    }

    /** Nur eine explizite boolesche Meldung ist ein Verbindungsnachweis. */
    public static function verbunden(array $p): ?bool
    {
        return is_bool($p['connected'] ?? null) ? $p['connected'] : null;
    }

    /**
     * Nur Anzeige: expliziter Shadowwert vor höchstens 120 s alter Geräteliste.
     * Die Grenze beschränkt lokales Cachealter, beweist keine Gerätefrische.
     * Kein Ersatz für connected im Shadow bei Befehlen oder GetActivity().
     */
    public static function erreichbarkeit(array $shadow, array $geraet, int $jetzt): array
    {
        $wert = self::verbunden($shadow);
        $cache = is_array($geraet['roh'] ?? null) ? self::verbunden($geraet['roh']) : null;
        $zeit = $geraet['verbindungEmpfangen'] ?? null;
        $alter = is_int($zeit) && $zeit > 0 && $zeit <= $jetzt ? $jetzt - $zeit : null;
        $quelle = $wert !== null ? 'shadow.connected' : 'unbekannt';
        if ($wert === null && $cache !== null && $alter !== null && $alter <= 120) {
            $wert = $cache;
            $quelle = 'geraetecache.connected';
        }
        return ['verbindung' => $wert, 'shadow' => self::verbunden($shadow),
            'geraetecache' => $cache, 'cachealter' => $alter,
            'quelle' => $quelle, 'empfangen' => $jetzt];
    }

    /** Einzelziel-GET ohne ID erlaubt; Listen mit mehreren Einträgen brauchen IDs. */
    public static function ziel(array $result, string $id): array
    {
        $liste = array_is_list($result) ? $result : [$result];
        $treffer = [];
        foreach ($liste as $p) {
            if (!is_array($p) || array_is_list($p)) {
                throw new RoboHubFehler('Eureka: Kein gültiger Gerätestatus.');
            }
            $ids = [];
            foreach (['id', 'deviceId'] as $feld) {
                if (array_key_exists($feld, $p)) {
                    if ((!is_string($p[$feld]) && !is_int($p[$feld])) || (string)$p[$feld] === '') {
                        throw new RoboHubFehler('Eureka: Ungültige Status-Gerätekennung.');
                    }
                    $ids[] = (string)$p[$feld];
                }
            }
            if (count(array_unique($ids)) > 1 || ($ids === [] && count($liste) !== 1)) {
                throw new RoboHubFehler('Eureka: Status gehört nicht eindeutig zum Zielgerät.');
            }
            if ($ids === [] || $ids[0] === $id) {
                $treffer[] = $p;
            }
        }
        if ($id === '' || count($treffer) !== 1) {
            throw new RoboHubFehler('Eureka: Kein eindeutig zugeordneter Gerätestatus.');
        }
        return $treffer[0];
    }
}
