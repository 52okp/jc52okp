<?php

declare(strict_types=1);

/** Offline builder. Usage: php scripts/build-update.php --project=id --version=1.2.3 --from=1.2.2 --notes-file=notes.txt --output=/private/path */
if (PHP_SAPI !== 'cli') exit(1);
if (!extension_loaded('zip')) { fwrite(STDERR, "zip extension is required\n"); exit(1); }
$args = getopt('', ['project:', 'version:', 'from:', 'notes-file:', 'output:']);
$project = (string)($args['project'] ?? '');
$version = (string)($args['version'] ?? '');
$from = (string)($args['from'] ?? '');
$notesFile = (string)($args['notes-file'] ?? '');
$output = (string)($args['output'] ?? '');
if (!preg_match('/^[a-z][a-z0-9-]{1,47}$/', $project) ||
    !preg_match('/^\d+\.\d+\.\d+$/', $version) ||
    !preg_match('/^\d+\.\d+\.\d+$/', $from) ||
    version_compare($version, $from, '<=') ||
    !is_file($notesFile) || trim((string)file_get_contents($notesFile)) === '' ||
    !is_dir($output) || !is_writable($output)) {
    fwrite(STDERR, "Provide valid project, ascending semantic versions, notes file, and writable output directory.\n");
    exit(1);
}
$root = dirname(__DIR__);
$zipPath = rtrim($output, '/\\') . DIRECTORY_SEPARATOR . $project . '-update.zip';
$manifestPath = rtrim($output, '/\\') . DIRECTORY_SEPARATOR . 'update-manifest.json';
if (file_exists($zipPath) || file_exists($manifestPath)) {
    fwrite(STDERR, "Output already exists; refusing to overwrite a release.\n"); exit(1);
}
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
    fwrite(STDERR, "Cannot create ZIP.\n"); exit(1);
}
$zip->addFromString('update-version.json', json_encode(['product' => $project, 'version' => $version], JSON_UNESCAPED_SLASHES));
$included = 0;
$addPath = function (string $relative) use ($root, $zip, &$included): void {
    $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (is_link($full)) throw new RuntimeException("Symlink rejected: $relative");
    if (is_file($full)) {
        if (!$zip->addFile($full, $relative)) throw new RuntimeException("Cannot add: $relative");
        $included++;
        return;
    }
    if (!is_dir($full)) throw new RuntimeException("Missing build input: $relative");
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isLink()) throw new RuntimeException('Symlink rejected: ' . $file->getPathname());
        if (!$file->isFile()) continue;
        $entry = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        if (preg_match('~(^|/)(\.env|\.git|node_modules|runtime|uploads?|backup|cache)(/|$)~i', $entry)) continue;
        if (!$zip->addFile($file->getPathname(), $entry)) throw new RuntimeException("Cannot add: $entry");
        $included++;
    }
};
try {
    foreach (['app', 'config', 'route', 'vendor', 'database/migrations', 'public/static',
        'public/index.php', 'public/router.php', 'public/robots.txt', 'think', 'composer.json', 'composer.lock',
        'vendor/topthink/framework/src/think/cache/Driver.php',
        'vendor/topthink/framework/src/think/cache/TagSet.php',
        'vendor/topthink/framework/src/think/cache/driver/File.php',
        'vendor/topthink/framework/src/think/cache/driver/Memcache.php',
        'vendor/topthink/framework/src/think/cache/driver/Memcached.php',
        'vendor/topthink/framework/src/think/cache/driver/Redis.php',
        'vendor/topthink/framework/src/think/cache/driver/Wincache.php'] as $path) {
        $addPath($path);
    }
    if (!$zip->close()) throw new RuntimeException('Cannot finalize ZIP');
    $manifest = [
        'format' => 2, 'product' => $project, 'version' => $version, 'from' => $from,
        'package' => basename($zipPath), 'size' => filesize($zipPath),
        'sha256' => hash_file('sha256', $zipPath),
        'notes' => trim((string)file_get_contents($notesFile)),
    ];
    if (file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n") === false) {
        throw new RuntimeException('Cannot write manifest');
    }
    echo "Built $included code files: $zipPath\nManifest: $manifestPath\n";
} catch (Throwable $e) {
    $zip->close();
    @unlink($zipPath);
    @unlink($manifestPath);
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
