<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$buildScript = $root . '/scripts/build-app-bundle.php';

if (!is_file($buildScript)) {
    throw new RuntimeException('Der app-isolierende Bundle-Build fehlt.');
}

$temporaryRoot = sys_get_temp_dir() . '/flz-shared-test-' . bin2hex(random_bytes(8));
$first = $temporaryRoot . '/first';
$second = $temporaryRoot . '/second';
$separate = $temporaryRoot . '/separate';
$separateBrowser = $temporaryRoot . '/separate-browser';
$browserOnlySource = $temporaryRoot . '/browser-only-source';
$browserOnlyFirst = $temporaryRoot . '/calendar-browser-first';
$browserOnlySecond = $temporaryRoot . '/calendar-browser-second';

try {
    foreach ([$first, $second] as $target) {
        $command = [
            PHP_BINARY,
            $buildScript,
            '--app-id=flzurlaub',
            '--app-namespace=FlzUrlaub',
            '--target=' . $target,
        ];
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $root);
        if (!is_resource($process)) {
            throw new RuntimeException('Der Bundle-Build konnte nicht gestartet werden.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0) {
            throw new RuntimeException("Der Bundle-Build ist fehlgeschlagen: {$stdout}{$stderr}");
        }
    }

    $firstFiles = bundleFiles($first);
    $secondFiles = bundleFiles($second);
    if ($firstFiles !== $secondFiles) {
        throw new RuntimeException('Zwei Builds derselben Eingabe sind nicht bytegleich.');
    }

    foreach ([
        'php/vendor/autoload.php',
        'php/composer.json',
        'php/src/Controller/ApiResponder.php',
        'php/src/Model/ModelApiTrait.php',
        'php/src/Service/AppLogger.php',
        'browser/api-client.js',
        'browser/model.js',
        'browser/repository.js',
        'browser/ui.js',
        'LICENSE',
        'flz-shared.lock.json',
    ] as $required) {
        if (!array_key_exists($required, $firstFiles)) {
            throw new RuntimeException('Gebündelte Laufzeitdatei fehlt: ' . $required);
        }
    }

    $phpSource = $firstFiles['php/src/Model/ModelApiTrait.php'];
    if (!str_contains($phpSource, 'namespace OCA\\FlzUrlaub\\Vendor\\Filzmann\\Shared\\Model;')) {
        throw new RuntimeException('Der PHP-Namespace ist nicht app-spezifisch isoliert.');
    }

    $browserSource = implode("\n", array_intersect_key($firstFiles, array_flip([
        'browser/api-client.js',
        'browser/model.js',
        'browser/repository.js',
        'browser/ui.js',
    ])));
    if (!str_contains($browserSource, 'window.OCA.FlzUrlaub.Shared')) {
        throw new RuntimeException('Der Browser-Namespace ist nicht app-spezifisch isoliert.');
    }

    foreach ($firstFiles as $path => $content) {
        if (str_contains($content, 'LocalBase')) {
            throw new RuntimeException('Die gebaute Laufzeitkopie enthält weiterhin LocalBase: ' . $path);
        }
    }

    $lock = json_decode($firstFiles['flz-shared.lock.json'], true, flags: JSON_THROW_ON_ERROR);
    if (($lock['schema'] ?? null) !== 1 || ($lock['app_id'] ?? null) !== 'flzurlaub') {
        throw new RuntimeException('Der Bundle-Lock beschreibt die Ziel-App nicht stabil.');
    }
    if (($lock['packages']['php']['name'] ?? null) !== 'filzmann/shared-runtime'
        || ($lock['packages']['browser']['name'] ?? null) !== '@filzmann/shared-browser') {
        throw new RuntimeException('Der Bundle-Lock enthält nicht beide Quellpakete.');
    }
    foreach (['php', 'browser'] as $package) {
        $digest = (string)($lock['packages'][$package]['sha256'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/', $digest)) {
            throw new RuntimeException('Quellpaket-Digest fehlt: ' . $package);
        }
    }

    $separateCommand = [
        PHP_BINARY,
        $buildScript,
        '--app-id=flzurlaub',
        '--app-namespace=FlzUrlaub',
        '--target=' . $separate,
        '--browser-target=' . $separateBrowser,
    ];
    $process = proc_open($separateCommand, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $root);
    if (!is_resource($process)) {
        throw new RuntimeException('Der getrennte Browser-Build konnte nicht gestartet werden.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) {
        throw new RuntimeException("Der getrennte Browser-Build ist fehlgeschlagen: {$stdout}{$stderr}");
    }
    if (is_dir($separate . '/browser') || !is_file($separateBrowser . '/api-client.js')) {
        throw new RuntimeException('Ein explizites Browser-Ziel wird nicht getrennt erzeugt.');
    }

    mkdir($browserOnlySource . '/scripts', 0775, true);
    copy($buildScript, $browserOnlySource . '/scripts/build-app-bundle.php');
    copy($root . '/LICENSE', $browserOnlySource . '/LICENSE');
    copyTree($root . '/packages/browser', $browserOnlySource . '/packages/browser');

    foreach ([$browserOnlyFirst, $browserOnlySecond] as $browserOnlyTarget) {
        $browserOnlyCommand = [
            PHP_BINARY,
            $browserOnlySource . '/scripts/build-app-bundle.php',
            '--component=browser',
            '--app-id=flzcalendar',
            '--app-namespace=FlzCalendar',
            '--target=' . $browserOnlyTarget,
        ];
        $process = proc_open($browserOnlyCommand, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $browserOnlySource);
        if (!is_resource($process)) {
            throw new RuntimeException('Der reine Browser-Build konnte nicht gestartet werden.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0) {
            throw new RuntimeException("Der reine Browser-Build ist fehlgeschlagen: {$stdout}{$stderr}");
        }
    }
    $browserOnlyFiles = bundleFiles($browserOnlyFirst);
    if ($browserOnlyFiles !== bundleFiles($browserOnlySecond)) {
        throw new RuntimeException('Zwei reine Browser-Builds derselben Eingabe sind nicht bytegleich.');
    }
    foreach (['api-client.js', 'model.js', 'repository.js', 'ui.js', 'LICENSE', 'flz-shared.lock.json'] as $required) {
        if (!array_key_exists($required, $browserOnlyFiles)) {
            throw new RuntimeException('Datei im reinen Browser-Build fehlt: ' . $required);
        }
    }
    foreach ($browserOnlyFiles as $path => $content) {
        if (str_starts_with($path, 'php/')) {
            throw new RuntimeException('Der reine Browser-Build enthält unerwartet PHP: ' . $path);
        }
    }
    $browserOnlyLock = json_decode($browserOnlyFiles['flz-shared.lock.json'], true, flags: JSON_THROW_ON_ERROR);
    if (($browserOnlyLock['component'] ?? null) !== 'browser'
        || ($browserOnlyLock['app_id'] ?? null) !== 'flzcalendar'
        || ($browserOnlyLock['browser_global'] ?? null) !== 'window.OCA.FlzCalendar.Shared'
        || array_keys($browserOnlyLock['packages'] ?? []) !== ['browser']) {
        throw new RuntimeException('Der reine Browser-Lock beschreibt Ziel und Paket nicht eindeutig.');
    }
    $browserOnlyRuntimeCommand = [
        'node',
        $root . '/tests/browser-runtime.mjs',
        $browserOnlyFirst,
        'FlzCalendar',
        'flzcalendar',
    ];
    $process = proc_open($browserOnlyRuntimeCommand, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $root);
    if (!is_resource($process)) {
        throw new RuntimeException('Der reine Browser-Laufzeitvertrag konnte nicht gestartet werden.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) {
        throw new RuntimeException("Der reine Browser-Laufzeitvertrag ist fehlgeschlagen: {$stdout}{$stderr}");
    }

    if (array_key_exists('php/autoload.php', $firstFiles)) {
        throw new RuntimeException('Ein paralleler handgeschriebener PHP-Autoloader ist unzulässig.');
    }
    require $first . '/php/vendor/autoload.php';
    $modelClass = 'OCA\\FlzUrlaub\\Vendor\\Filzmann\\Shared\\Model\\ModelApiTrait';
    if (!trait_exists($modelClass)) {
        throw new RuntimeException('Der isolierte Composer-Autoloader löst das Modelltrait nicht auf.');
    }

    $browserCommand = [
        'node',
        $root . '/tests/browser-runtime.mjs',
        $first . '/browser',
        'FlzUrlaub',
        'flzurlaub',
    ];
    $process = proc_open($browserCommand, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $root);
    if (!is_resource($process)) {
        throw new RuntimeException('Der Browser-Laufzeitvertrag konnte nicht gestartet werden.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) {
        throw new RuntimeException("Der Browser-Laufzeitvertrag ist fehlgeschlagen: {$stdout}{$stderr}");
    }

    echo "flz_shared package and isolated bundle tests passed\n";
} finally {
    removeTree($temporaryRoot);
}

/** @return array<string, string> */
function bundleFiles(string $root): array {
    if (!is_dir($root)) {
        throw new RuntimeException('Bundle-Ziel fehlt: ' . $root);
    }
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        $files[$relative] = (string)file_get_contents($file->getPathname());
    }
    ksort($files);

    return $files;
}

function copyTree(string $source, string $target): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $relative = substr($item->getPathname(), strlen($source) + 1);
        $destination = $target . '/' . $relative;
        if ($item->isDir()) {
            if (!mkdir($destination, 0775, true) && !is_dir($destination)) {
                throw new RuntimeException('Temporäres Quellverzeichnis konnte nicht kopiert werden: ' . $destination);
            }
            continue;
        }
        $parent = dirname($destination);
        if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
            throw new RuntimeException('Temporäres Zielverzeichnis konnte nicht angelegt werden: ' . $parent);
        }
        if (!copy($item->getPathname(), $destination)) {
            throw new RuntimeException('Temporäre Quelldatei konnte nicht kopiert werden: ' . $relative);
        }
    }
}

function removeTree(string $path): void {
    if ($path === '' || !str_starts_with($path, sys_get_temp_dir() . '/flz-shared-test-')) {
        throw new RuntimeException('Unsicheres temporäres Löschziel.');
    }
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}
