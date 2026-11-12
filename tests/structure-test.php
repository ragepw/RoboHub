<?php
declare(strict_types=1);

// Offline-Abbild der Symcon-Modulerkennung für die Bibliothek (Symcon >= 9.0).
// https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/struktur/
function strukturFehler(string $wurzel): array
{
    $fehler = [];
    foreach (scandir($wurzel) as $name) {
        if ($name[0] === '.' || !is_dir($wurzel . '/' . $name) ||
            in_array($name, ['libs', 'docs', 'imgs', 'tests', 'actions'], true)) {
            continue;
        }
        $pfad = $wurzel . '/' . $name;
        foreach (['module.json', 'module.php'] as $datei) {
            if (!is_file($pfad . '/' . $datei)) {
                $fehler[] = $name . ': ' . $datei . ' fehlt';
            }
        }
        if (is_file($pfad . '/module.json')) {
            try {
                $modul = json_decode(file_get_contents($pfad . '/module.json'), false, 512, JSON_THROW_ON_ERROR);
                if (!$modul instanceof stdClass) {
                    $fehler[] = $name . ': module.json ist kein JSON-Objekt';
                }
            } catch (JsonException $e) {
                $fehler[] = $name . ': module.json ist ungültig';
            }
        }
    }
    return $fehler;
}

// Gegenproben: insbesondere der ursprüngliche tools/-Fehler und alle Ausnahmen.
$temp = sys_get_temp_dir() . '/robohub-struktur-' . bin2hex(random_bytes(8));
if (!mkdir($temp, 0700)) {
    throw new RuntimeException('Struktur-Fixture konnte nicht erstellt werden.');
}
$pruefungen = 0;
try {
    foreach (['.tools' => true, '.github' => true, 'libs' => true, 'docs' => true,
        'imgs' => true, 'tests' => true, 'actions' => true,
        'tools' => false, 'includes' => false, 'Modul' => false] as $name => $ignoriert) {
        $pfad = $temp . '/' . $name;
        mkdir($pfad);
        try {
            if ((strukturFehler($temp) === []) !== $ignoriert) {
                throw new RuntimeException('Fehlerhafte Verzeichniserkennung: ' . $name);
            }
            ++$pruefungen;
            if ($ignoriert) { continue; }
            file_put_contents($pfad . '/module.json', '{}');
            if (strukturFehler($temp) !== [$name . ': module.php fehlt']) {
                throw new RuntimeException('Fehlende module.php nicht erkannt.');
            }
            ++$pruefungen;
            file_put_contents($pfad . '/module.php', '<?php');
            foreach (['{}' => true, '{invalid' => false, '[]' => false] as $json => $gueltig) {
                file_put_contents($pfad . '/module.json', $json);
                if ((strukturFehler($temp) === []) !== $gueltig) {
                    throw new RuntimeException('Fehlerhafte module.json-Prüfung.');
                }
                ++$pruefungen;
            }
        } finally {
            foreach (['module.json', 'module.php'] as $datei) {
                if (is_file($pfad . '/' . $datei)) { unlink($pfad . '/' . $datei); }
            }
            rmdir($pfad);
        }
    }
} finally {
    rmdir($temp);
}

$fehler = strukturFehler(dirname(__DIR__));
if ($fehler !== []) {
    fwrite(STDERR, implode("\n", $fehler) . "\n");
    exit(1);
}
echo 'Modulstruktur gültig; ' . $pruefungen . " Struktur-Gegenproben bestanden.\n";
