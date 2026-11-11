<?php
declare(strict_types=1);

require_once __DIR__ . '/RoboHubModell.php';

/** Reine Offline-Funktionen; Herkunft und MIT-Hinweis: docs/eureka-steuerung.md. */
final class RoboHubEurekaProtokoll
{
    public const FAMILIE = 'midea-b8-v0';

    /** AA, Länge ohne AA, B8, sechs reservierte Bytes, Typ, Body, Prüfsumme. */
    public static function rahmen(array $body, int $typ): string
    {
        if (!array_is_list($body) || count($body) < 1 || count($body) > 245 ||
            !in_array($typ, [2, 3], true)) {
            throw new RoboHubFehler('Eureka: Ungültige Rahmenparameter.');
        }
        foreach ($body as $byte) {
            if (!is_int($byte) || $byte < 0 || $byte > 255) {
                throw new RoboHubFehler('Eureka: Ungültiges Protokollbyte.');
            }
        }
        $bytes = [0xAA, 10 + count($body), 0xB8, 0, 0, 0, 0, 0, 0, $typ, ...$body];
        $bytes[] = (-array_sum(array_slice($bytes, 1))) & 0xFF;
        return strtoupper(bin2hex(pack('C*', ...$bytes)));
    }

    public static function pruefeRahmen(string $hex): void
    {
        if (strlen($hex) < 24 || strlen($hex) > 512 || strlen($hex) % 2 !== 0 || !ctype_xdigit($hex)) {
            throw new RoboHubFehler('Eureka: Ungültiger Protokollrahmen.');
        }
        $b = array_values(unpack('C*', hex2bin($hex)));
        if ($b[0] !== 0xAA || $b[1] !== count($b) - 1 || $b[2] !== 0xB8 ||
            array_slice($b, 3, 6) !== [0, 0, 0, 0, 0, 0] ||
            !in_array($b[9], [2, 3], true) || (array_sum(array_slice($b, 1)) & 0xFF) !== 0) {
            throw new RoboHubFehler('Eureka: Rahmenkopf, Länge, Typ oder Prüfsumme ungültig.');
        }
    }

    public static function start(int $fan, int $water, int $mode): string
    {
        // Ausschließlich expliziter Ganzhausauftrag. Keine Referenz-Defaults.
        if (!in_array($fan, [0, 1, 2, 3, 4, 5], true) || $water < 0 || $water > 3 || $mode !== 8) {
            throw new RoboHubFehler('Eureka: Nicht bestätigte Reinigungsparameter.');
        }
        return self::rahmen([0x22, 0x02, 0, 2, 0, $mode, $fan, 0, $water, 0, 0], 2);
    }

    public static function pause(): string { return self::rahmen([0x22, 0x1B, 0], 2); }
    public static function fortsetzen(): string { return self::rahmen([0x22, 0x1C, 0], 2); }
    public static function station(): string { return self::rahmen([0x22, 0x01, 0], 2); }

    /** Fehlende Werte bleiben fehlend; widersprüchliche Identitäten werden abgewiesen. */
    public static function identifikation(array $geraet): array
    {
        $roh = array_key_exists('roh', $geraet) ? $geraet['roh'] : [];
        if (!is_array($roh) || (array_key_exists('product', $roh) && !is_array($roh['product']))) {
            throw new RoboHubFehler('Eureka: Ungültige Geräteidentifikation.');
        }
        $product = $roh['product'] ?? [];
        $id = $geraet['id'] ?? null;
        $rohId = $roh['deviceId'] ?? null;
        if (!is_string($id) || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $id) ||
            ((!is_string($rohId) && !is_int($rohId)) || (string)$rohId !== $id)) {
            throw new RoboHubFehler('Eureka: Ungültige oder widersprüchliche Geräte-ID.');
        }
        $ident = [];
        foreach (['productModel', 'sn8', 'protoVersion', 'platform'] as $feld) {
            $werte = [];
            foreach ([$product, $roh] as $quelle) {
                if (array_key_exists($feld, $quelle)) {
                    $wert = $quelle[$feld];
                    if ($feld === 'protoVersion' && ($wert === 0 || $wert === '0')) {
                        $wert = 0;
                    }
                    if (($feld !== 'protoVersion' && (!is_string($wert) || trim($wert) === '')) ||
                        ($feld === 'protoVersion' && !is_int($wert))) {
                        throw new RoboHubFehler('Eureka: Ungültige Protokollidentifikation.');
                    }
                    $werte[] = $wert;
                }
            }
            if (count($werte) === 2 && $werte[0] !== $werte[1]) {
                throw new RoboHubFehler('Eureka: Widersprüchliche Protokollidentifikation.');
            }
            $ident[$feld] = $werte[0] ?? null;
        }
        if (isset($geraet['modell']) && $geraet['modell'] !== $ident['productModel']) {
            throw new RoboHubFehler('Eureka: Widersprüchliches Gerätemodell.');
        }
        $plugins = [];
        foreach ([[$roh, 'panel'], [$product, 'plugin']] as [$quelle, $feld]) {
            if (!array_key_exists($feld, $quelle)) { continue; }
            $plugin = $quelle[$feld];
            if (!is_array($plugin) || !isset($plugin['name'], $plugin['versionName']) ||
                !is_string($plugin['name']) || trim($plugin['name']) === '' ||
                !is_string($plugin['versionName']) || trim($plugin['versionName']) === '') {
                throw new RoboHubFehler('Eureka: Ungültige Steuerplugin-Identifikation.');
            }
            $plugins[] = ['name' => $plugin['name'], 'versionName' => $plugin['versionName']];
        }
        if (count($plugins) === 2 && $plugins[0] !== $plugins[1]) {
            throw new RoboHubFehler('Eureka: Widersprüchliche Steuerplugins.');
        }
        $ident['plugin'] = $plugins[0] ?? null;
        return $ident;
    }

    /** Nur Familienindiz, niemals Gerätefreigabe; fehlende SN8 wird nicht ergänzt. */
    public static function referenzFamilie(array $ident): ?string
    {
        if (($ident['protoVersion'] ?? null) !== 0) {
            return null;
        }
        $plugin = $ident['plugin'] ?? null;
        // Ein vorhandenes fremdes/ungültiges Plugin widerspricht der B8-Zuordnung.
        if ($plugin !== null && (!is_array($plugin) ||
            !is_string($plugin['name'] ?? null) ||
            !preg_match('/\AT0xB8_[A-Za-z0-9]+(?:\.zip)?\z/D', $plugin['name']) ||
            !is_string($plugin['versionName'] ?? null) || trim($plugin['versionName']) === '')) {
            return null;
        }
        $sn8 = $ident['sn8'] ?? null;
        if ($sn8 !== null && (!is_string($sn8) || trim($sn8) === '')) {
            return null;
        }
        return $plugin !== null || in_array($sn8, ['610VR510', '620VR520', '9A0VR870'], true)
            ? self::FAMILIE : null;
    }

    /** Exakte, separat belegte Zuordnung aller Merkmale; niemals aus Modellnamen raten. */
    public static function pruefeZuordnung(array $geraet, array $zuordnungen): void
    {
        $ident = self::identifikation($geraet);
        if (in_array(null, $ident, true)) {
            throw new RoboHubFehler('Eureka: Geräteidentifikation unvollständig; keine Gerätefreigabe.');
        }
        if (self::referenzFamilie($ident) === null) {
            throw new RoboHubFehler('Eureka: Protokollfamilie nicht bestätigt.');
        }
        foreach ($zuordnungen as $zuordnung) {
            if (($zuordnung['identifikation'] ?? null) === $ident &&
                ($zuordnung['familie'] ?? null) === self::FAMILIE &&
                is_string($zuordnung['beleg'] ?? null) && trim($zuordnung['beleg']) !== '') {
                return;
            }
        }
        throw new RoboHubFehler('Eureka: Geräte-Protokollzuordnung nicht bestätigt.');
    }
}
