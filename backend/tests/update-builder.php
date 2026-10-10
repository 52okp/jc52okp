<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !extension_loaded('zip')) exit(1);

$backend = dirname(__DIR__);
$root = $backend . '/runtime/test-update-builder-' . bin2hex(random_bytes(5));
$baselineDir = $root . '/baseline';
$deltaDir = $root . '/delta';
$nextDir = $root . '/next';
foreach ([$baselineDir, $deltaDir, $nextDir] as $dir) {
    if (!mkdir($dir, 0700, true)) throw new RuntimeException('Cannot create fixture directory');
}
file_put_contents($root . '/notes.txt', 'Incremental builder fixture');
$packageName = 'jc52okp-update.zip';
$builder = $backend . '/scripts/build-update.php';
$run = static function (array $args) use ($builder): array {
    $process = proc_open([PHP_BINARY, $builder, ...$args], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot run update builder');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $output];
};
$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path)) {
        foreach (new DirectoryIterator($path) as $child) {
            if (!$child->isDot()) $remove($child->getPathname());
        }
        rmdir($path);
    } elseif (is_file($path)) unlink($path);
};
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$writeManifest = static function (string $dir) use ($packageName): void {
    $path = $dir . '/update-manifest.json';
    $manifest = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $manifest['size'] = filesize($dir . '/' . $packageName);
    $manifest['sha256'] = hash_file('sha256', $dir . '/' . $packageName);
    file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
};

try {
    [$code, $message] = $run([
        '--project=jc52okp', '--version=1.0.7', '--from=1.0.6',
        '--notes-file=' . $root . '/notes.txt', '--output=' . $baselineDir,
    ]);
    $assert($code === 0, 'Full baseline failed: ' . $message);

    // Simulate a previous release with one older PHP file, keeping its
    // complete inventory and outer manifest internally consistent.
    $name = 'app/service/WordpressSync.php';
    $old = '<?php // previous release fixture';
    $zip = new ZipArchive();
    $assert($zip->open($baselineDir . '/' . $packageName) === true, 'Cannot open baseline');
    $meta = json_decode((string)$zip->getFromName('update-version.json'), true, 512, JSON_THROW_ON_ERROR);
    $meta['files'][$name] = hash('sha256', $old);
    $zip->deleteName($name);
    $zip->addFromString($name, $old);
    $zip->deleteName('update-version.json');
    $zip->addFromString('update-version.json', json_encode($meta, JSON_THROW_ON_ERROR));
    $assert($zip->close(), 'Cannot close baseline');
    $writeManifest($baselineDir);

    [$code, $message] = $run([
        '--project=jc52okp', '--version=1.0.8', '--from=1.0.7',
        '--notes-file=' . $root . '/notes.txt', '--output=' . $deltaDir,
        '--base-package=' . $baselineDir . '/' . $packageName,
        '--base-manifest=' . $baselineDir . '/update-manifest.json',
    ]);
    $assert($code === 0, 'Delta build failed: ' . $message);
    $zip = new ZipArchive();
    $assert($zip->open($deltaDir . '/' . $packageName) === true, 'Cannot open delta');
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) $names[] = $zip->getNameIndex($i);
    sort($names);
    $assert($names === [$name, 'update-version.json'], 'Delta contains unchanged files');
    $meta = json_decode((string)$zip->getFromName('update-version.json'), true, 512, JSON_THROW_ON_ERROR);
    $assert(($meta['kind'] ?? null) === 'delta' && ($meta['files'][$name] ?? null) ===
        hash_file('sha256', $backend . '/' . $name), 'Delta inventory is wrong');
    $zip->close();

    [$code, $message] = $run([
        '--project=jc52okp', '--version=1.0.9', '--from=1.0.8',
        '--notes-file=' . $root . '/notes.txt', '--output=' . $nextDir,
        '--base-package=' . $deltaDir . '/' . $packageName,
        '--base-manifest=' . $deltaDir . '/update-manifest.json',
    ]);
    $assert($code !== 0 && str_contains($message, 'No code files changed') &&
        !is_file($nextDir . '/' . $packageName), 'Delta-to-delta no-op was accepted');

    // An incorrect base version must never produce a seemingly valid package.
    [$code, $message] = $run([
        '--project=jc52okp', '--version=1.0.9', '--from=1.0.6',
        '--notes-file=' . $root . '/notes.txt', '--output=' . $nextDir,
        '--base-package=' . $deltaDir . '/' . $packageName,
        '--base-manifest=' . $deltaDir . '/update-manifest.json',
    ]);
    $assert($code !== 0 && str_contains($message, 'Baseline manifest does not match'),
        'Mismatched base version was accepted');

    $zip = new ZipArchive();
    $assert($zip->open($deltaDir . '/' . $packageName) === true, 'Cannot reopen delta');
    $meta = json_decode((string)$zip->getFromName('update-version.json'), true, 512, JSON_THROW_ON_ERROR);
    $meta['files']['app/retired.php'] = hash('sha256', 'old file');
    $zip->deleteName('update-version.json');
    $zip->addFromString('update-version.json', json_encode($meta, JSON_THROW_ON_ERROR));
    $assert($zip->close(), 'Cannot close modified delta');
    $writeManifest($deltaDir);
    [$code, $message] = $run([
        '--project=jc52okp', '--version=1.0.9', '--from=1.0.8',
        '--notes-file=' . $root . '/notes.txt', '--output=' . $nextDir,
        '--base-package=' . $deltaDir . '/' . $packageName,
        '--base-manifest=' . $deltaDir . '/update-manifest.json',
    ]);
    $assert($code !== 0 && str_contains($message, 'Removed code file') &&
        !is_file($nextDir . '/' . $packageName), 'Removed file was silently ignored');
    echo "Full, delta, chained baseline, version and deletion checks passed\n";
} finally {
    $runtime = realpath($backend . '/runtime');
    $fixture = realpath($root);
    if ($runtime && $fixture && str_starts_with($fixture, $runtime . DIRECTORY_SEPARATOR . 'test-update-builder-')) {
        $remove($fixture);
    }
}
