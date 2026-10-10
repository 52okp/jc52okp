<?php

declare(strict_types=1);

/**
 * Offline builder. Pass --base-package and --base-manifest to make a delta
 * from the exact preceding release. Omit both for a full initial package.
 */
if (PHP_SAPI !== 'cli') exit(1);
if (!extension_loaded('zip')) { fwrite(STDERR, "zip extension is required\n"); exit(1); }

$args = getopt('', ['project:', 'version:', 'from:', 'notes-file:', 'output:', 'base-package:', 'base-manifest:']);
$project = (string)($args['project'] ?? '');
$version = (string)($args['version'] ?? '');
$from = (string)($args['from'] ?? '');
$notesFile = (string)($args['notes-file'] ?? '');
$output = (string)($args['output'] ?? '');
$basePackage = (string)($args['base-package'] ?? '');
$baseManifest = (string)($args['base-manifest'] ?? '');
$delta = $basePackage !== '' || $baseManifest !== '';
if (!preg_match('/^[a-z][a-z0-9-]{1,47}$/', $project) ||
    !preg_match('/^\d+\.\d+\.\d+$/', $version) ||
    !preg_match('/^\d+\.\d+\.\d+$/', $from) ||
    version_compare($version, $from, '<=') ||
    !is_file($notesFile) || trim((string)file_get_contents($notesFile)) === '' ||
    !is_dir($output) || !is_writable($output) ||
    ($delta && (!is_file($basePackage) || !is_file($baseManifest)))) {
    fwrite(STDERR, "Provide valid project, ascending versions, notes, output, and both baseline files for a delta.\n");
    exit(1);
}

$root = dirname(__DIR__);
$zipPath = rtrim($output, '/\\') . DIRECTORY_SEPARATOR . $project . '-update.zip';
$manifestPath = rtrim($output, '/\\') . DIRECTORY_SEPARATOR . 'update-manifest.json';
if (file_exists($zipPath) || file_exists($manifestPath)) {
    fwrite(STDERR, "Output already exists; refusing to overwrite a release.\n");
    exit(1);
}

// Keep this list in step with UpdateCenter::allowedEntry(). Runtime data,
// server configuration and uploaded files are intentionally never packaged.
$inputs = ['app', 'config', 'route', 'vendor', 'database/migrations', 'public/static',
    'public/index.php', 'public/router.php', 'public/robots.txt', 'think', 'composer.json', 'composer.lock',
    'scripts/update-worker.php', 'scripts/update-health.php',
    'vendor/topthink/framework/src/think/cache/Driver.php',
    'vendor/topthink/framework/src/think/cache/TagSet.php',
    'vendor/topthink/framework/src/think/cache/driver/File.php',
    'vendor/topthink/framework/src/think/cache/driver/Memcache.php',
    'vendor/topthink/framework/src/think/cache/driver/Memcached.php',
    'vendor/topthink/framework/src/think/cache/driver/Redis.php',
    'vendor/topthink/framework/src/think/cache/driver/Wincache.php'];
$files = [];
$addPath = static function (string $relative) use ($root, &$files): void {
    $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (is_link($full)) throw new RuntimeException("Symlink rejected: $relative");
    if (is_file($full)) {
        $files[$relative] = $full;
        return;
    }
    if (!is_dir($full)) throw new RuntimeException("Missing build input: $relative");
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isLink()) throw new RuntimeException('Symlink rejected: ' . $file->getPathname());
        if (!$file->isFile()) continue;
        $entry = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        if (preg_match('~(^|/)(\.env|\.git|node_modules|runtime|uploads?|backup|cache)(/|$)~i', $entry)) continue;
        $files[$entry] = $file->getPathname();
    }
};

$baseline = [];
$readBaseline = static function () use ($basePackage, $baseManifest, $project, $from): array {
    $manifest = json_decode((string)file_get_contents($baseManifest), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($manifest) || ($manifest['format'] ?? null) !== 2 ||
        ($manifest['product'] ?? '') !== $project || ($manifest['version'] ?? '') !== $from ||
        ($manifest['package'] ?? '') !== basename($basePackage) ||
        ($manifest['size'] ?? null) !== filesize($basePackage) ||
        !hash_equals((string)($manifest['sha256'] ?? ''), hash_file('sha256', $basePackage))) {
        throw new RuntimeException('Baseline manifest does not match the previous release ZIP and --from version');
    }
    $zip = new ZipArchive();
    if ($zip->open($basePackage) !== true) throw new RuntimeException('Cannot open baseline ZIP');
    try {
        $meta = json_decode((string)$zip->getFromName('update-version.json'), true);
        if (!is_array($meta) || ($meta['product'] ?? '') !== $project || ($meta['version'] ?? '') !== $from) {
            throw new RuntimeException('Baseline ZIP has the wrong product or version');
        }
        $snapshot = $meta['files'] ?? null;
        if ($snapshot !== null && (!is_array($snapshot) || array_is_list($snapshot))) {
            throw new RuntimeException('Baseline file inventory is invalid');
        }
        $archiveFiles = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === 'update-version.json') continue;
            if ($name === '' || str_contains($name, '\\') || str_starts_with($name, '/') ||
                preg_match('~(^|/)\.\.?(/|$)|^[A-Za-z]:~', $name) || isset($archiveFiles[$name])) {
                throw new RuntimeException('Baseline ZIP contains an unsafe or duplicate path');
            }
            $content = $zip->getFromIndex($i);
            if ($content === false) throw new RuntimeException('Cannot read baseline ZIP entry: ' . $name);
            $archiveFiles[$name] = hash('sha256', $content);
        }
        if ($snapshot === null) return $archiveFiles; // Existing full releases lack an inventory.
        foreach ($snapshot as $name => $hash) {
            if (!is_string($name) || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/', $hash) ||
                $name === '' || str_contains($name, '\\') || str_starts_with($name, '/') ||
                preg_match('~(^|/)\.\.?(/|$)|^[A-Za-z]:~', $name)) {
                throw new RuntimeException('Baseline file inventory contains an invalid path or hash');
            }
        }
        foreach ($archiveFiles as $name => $hash) {
            if (($snapshot[$name] ?? null) !== $hash) {
                throw new RuntimeException('Baseline ZIP entry does not match its file inventory: ' . $name);
            }
        }
        return $snapshot;
    } finally {
        $zip->close();
    }
};

$zip = null;
try {
    foreach ($inputs as $path) $addPath($path);
    ksort($files, SORT_STRING);
    $snapshot = [];
    foreach ($files as $name => $path) {
        $hash = hash_file('sha256', $path);
        if ($hash === false) throw new RuntimeException('Cannot hash build input: ' . $name);
        $snapshot[$name] = $hash;
    }
    if ($delta) $baseline = $readBaseline();
    $removed = array_diff_key($baseline, $snapshot);
    if ($removed) {
        $name = array_key_first($removed);
        throw new RuntimeException('Removed code file requires an explicit cleanup procedure: ' . $name .
            ' (' . count($removed) . ' removed). No update was built.');
    }
    $changed = [];
    foreach ($files as $name => $path) {
        if (!$delta || ($baseline[$name] ?? null) !== $snapshot[$name]) $changed[$name] = $path;
    }
    if (!$changed) throw new RuntimeException('No code files changed since the baseline release');
    foreach ($changed as $name => $_) {
        if ($delta && str_starts_with($name, 'database/migrations/')) {
            throw new RuntimeException('Database migration changed; use a backed-up manual release: ' . $name);
        }
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
        throw new RuntimeException('Cannot create ZIP');
    }
    $meta = ['product' => $project, 'version' => $version, 'from' => $from,
        'kind' => $delta ? 'delta' : 'full', 'files' => $snapshot];
    if (!$zip->addFromString('update-version.json', json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))) {
        throw new RuntimeException('Cannot write package metadata');
    }
    foreach ($changed as $name => $path) {
        if (!$zip->addFile($path, $name)) throw new RuntimeException('Cannot add: ' . $name);
    }
    if (!$zip->close()) throw new RuntimeException('Cannot finalize ZIP');
    $zip = null;
    $manifest = [
        'format' => 2, 'product' => $project, 'version' => $version, 'from' => $from,
        'package' => basename($zipPath), 'size' => filesize($zipPath),
        'sha256' => hash_file('sha256', $zipPath),
        'notes' => trim((string)file_get_contents($notesFile)),
    ];
    if (file_put_contents($manifestPath, json_encode($manifest,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n") === false) {
        throw new RuntimeException('Cannot write manifest');
    }
    echo 'Built ' . count($changed) . ' ' . ($delta ? 'changed' : 'code') . " files: $zipPath\nManifest: $manifestPath\n";
} catch (Throwable $e) {
    if ($zip instanceof ZipArchive) $zip->close();
    @unlink($zipPath);
    @unlink($manifestPath);
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
