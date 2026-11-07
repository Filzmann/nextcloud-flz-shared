<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$options = parseOptions(array_slice($argv, 1));
$appId = $options['app-id'] ?? '';
$appNamespace = $options['app-namespace'] ?? '';
$target = $options['target'] ?? '';
$explicitBrowserTarget = $options['browser-target'] ?? '';
$composerBinary = $options['composer-bin'] ?? 'composer';

if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', $appId)) {
    fail('Ungültige App-ID.');
}
if (!preg_match('/^[A-Z][A-Za-z0-9]{1,63}$/', $appNamespace)) {
    fail('Ungültiger App-Namespace.');
}
if ($target === '' || $target[0] !== '/') {
    fail('Das Bundle-Ziel muss ein absoluter Pfad sein.');
}
if (file_exists($target)) {
    fail('Das Bundle-Ziel existiert bereits und wird nicht überschrieben.');
}
if ($explicitBrowserTarget !== '' && $explicitBrowserTarget[0] !== '/') {
    fail('Das Browser-Ziel muss ein absoluter Pfad sein.');
}
if ($explicitBrowserTarget !== '' && file_exists($explicitBrowserTarget)) {
    fail('Das Browser-Ziel existiert bereits und wird nicht überschrieben.');
}
if ($composerBinary === '' || str_contains($composerBinary, "\0")) {
    fail('Das Composer-Binary ist ungültig.');
}

$phpPackage = $root . '/packages/php-runtime';
$browserPackage = $root . '/packages/browser';
$phpManifest = readJson($phpPackage . '/composer.json');
$browserManifest = readJson($browserPackage . '/package.json');
$phpVersion = trim(readRequired($phpPackage . '/VERSION'));

$phpTarget = $target . '/php/src';
$browserTarget = $explicitBrowserTarget !== '' ? $explicitBrowserTarget : $target . '/browser';
foreach ([$phpTarget . '/Controller', $phpTarget . '/Model', $phpTarget . '/Service', $browserTarget] as $directory) {
    if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
        fail('Bundle-Verzeichnis konnte nicht angelegt werden: ' . $directory);
    }
}

$prefix = 'OCA\\' . $appNamespace . '\\Vendor\\Filzmann\\Shared';
foreach ([
    'Controller/ApiResponder.php',
    'Model/ModelApiTrait.php',
    'Service/AppLogger.php',
] as $relative) {
    $source = readRequired($phpPackage . '/src/' . $relative);
    $source = str_replace('namespace Filzmann\\Shared\\', 'namespace ' . $prefix . '\\', $source);
    writeFile($phpTarget . '/' . $relative, $source);
}

$consumerComposer = [
    'name' => $appId . '/flz-shared-runtime-bundle',
    'description' => 'Generated app-local FLZ shared runtime bundle.',
    'type' => 'library',
    'license' => 'AGPL-3.0-or-later',
    'require' => [
        'php' => '>=8.3',
    ],
    'autoload' => [
        'psr-4' => [
            $prefix . '\\' => 'src/',
        ],
    ],
    'config' => [
        'autoloader-suffix' => $appNamespace . 'FlzShared' . substr(hash('sha256', $appId . ':' . $phpVersion), 0, 16),
    ],
];
writeFile(
    $target . '/php/composer.json',
    json_encode($consumerComposer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
);
runComposer($composerBinary, $target . '/php');

foreach (['api-client.js', 'model.js', 'repository.js', 'ui.js'] as $file) {
    $source = readRequired($browserPackage . '/src/' . $file);
    writeFile($browserTarget . '/' . $file, str_replace('__APP_NAMESPACE__', $appNamespace, $source));
}

writeFile($target . '/LICENSE', readRequired($root . '/LICENSE'));
$lock = [
    'schema' => 1,
    'app_id' => $appId,
    'app_namespace' => $appNamespace,
    'php_namespace' => $prefix,
    'browser_global' => 'window.OCA.' . $appNamespace . '.Shared',
    'packages' => [
        'php' => [
            'name' => $phpManifest['name'] ?? null,
            'version' => $phpVersion,
            'sha256' => directoryDigest($phpPackage),
        ],
        'browser' => [
            'name' => $browserManifest['name'] ?? null,
            'version' => $browserManifest['version'] ?? null,
            'sha256' => directoryDigest($browserPackage),
        ],
    ],
];
writeFile($target . '/flz-shared.lock.json', json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

echo 'Built isolated bundle for ' . $appId . "\n";

/** @return array<string, string> */
function parseOptions(array $arguments): array {
    $options = [];
    foreach ($arguments as $argument) {
        if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
            fail('Optionen müssen als --name=wert angegeben werden.');
        }
        [$name, $value] = explode('=', substr($argument, 2), 2);
        $options[$name] = $value;
    }
    return $options;
}

/** @return array<string, mixed> */
function readJson(string $path): array {
    $decoded = json_decode(readRequired($path), true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        fail('Ungültiges JSON: ' . $path);
    }
    return $decoded;
}

function readRequired(string $path): string {
    $content = @file_get_contents($path);
    if ($content === false) {
        fail('Quellbestandteil fehlt: ' . $path);
    }
    return $content;
}

function writeFile(string $path, string $content): void {
    if (@file_put_contents($path, $content) === false) {
        fail('Bundle-Datei konnte nicht geschrieben werden: ' . $path);
    }
}

function directoryDigest(string $directory): string {
    $entries = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1));
        $entries[$relative] = hash_file('sha256', $file->getPathname());
    }
    ksort($entries);
    return hash('sha256', json_encode($entries, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function runComposer(string $composerBinary, string $workingDirectory): void {
    $command = [
        $composerBinary,
        'dump-autoload',
        '--no-dev',
        '--classmap-authoritative',
        '--no-interaction',
        '--no-plugins',
        '--no-scripts',
    ];
    $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $workingDirectory);
    if (!is_resource($process)) {
        fail('Composer konnte für das app-lokale Bundle nicht gestartet werden.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0) {
        fail('Composer-Autoloader konnte nicht erzeugt werden: ' . trim($stdout . "\n" . $stderr));
    }
}

function fail(string $message): never {
    fwrite(STDERR, $message . "\n");
    exit(1);
}
