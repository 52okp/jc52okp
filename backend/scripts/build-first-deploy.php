<?php

declare(strict_types=1);

/** Build a code-only archive for the first manual deployment. Usage: php scripts/build-first-deploy.php /private/output.zip */
if (PHP_SAPI !== 'cli' || !extension_loaded('zip')) {
    fwrite(STDERR, "Run with PHP CLI and the zip extension.\n");
    exit(1);
}

$target = $argv[1] ?? '';
if ($target === '' || !is_dir(dirname($target)) || file_exists($target)) {
    fwrite(STDERR, "Provide a new ZIP path in an existing directory.\n");
    exit(1);
}

$root = dirname(__DIR__);
$inputs = [
    'app', 'config', 'route', 'vendor', 'database/migrations', 'public/static',
    'public/index.php', 'public/router.php', 'public/robots.txt', 'public/.htaccess', 'public/install.php',
    'think', 'composer.json', 'composer.lock', 'scripts/update-worker.php', 'scripts/update-health.php',
    // The broad cache exclusion below also matches ThinkPHP's required source files.
    'vendor/topthink/framework/src/think/cache/Driver.php',
    'vendor/topthink/framework/src/think/cache/TagSet.php',
    'vendor/topthink/framework/src/think/cache/driver/File.php',
    'vendor/topthink/framework/src/think/cache/driver/Memcache.php',
    'vendor/topthink/framework/src/think/cache/driver/Memcached.php',
    'vendor/topthink/framework/src/think/cache/driver/Redis.php',
    'vendor/topthink/framework/src/think/cache/driver/Wincache.php',
];
$zip = new ZipArchive();
if ($zip->open($target, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
    fwrite(STDERR, "Cannot create ZIP.\n");
    exit(1);
}

$count = 0;
try {
    foreach ($inputs as $input) {
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $input);
        if (is_link($path)) throw new RuntimeException("Symlink rejected: $input");
        if (is_file($path)) {
            if (!$zip->addFile($path, $input)) throw new RuntimeException("Cannot add: $input");
            $count++;
            continue;
        }
        if (!is_dir($path)) throw new RuntimeException("Missing input: $input");
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->isLink()) throw new RuntimeException('Symlink rejected: ' . $file->getPathname());
            if (!$file->isFile()) continue;
            $entry = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if (preg_match('~(^|/)(\.env|\.git|node_modules|runtime|uploads?|backup|cache)(/|$)~i', $entry)) continue;
            if (!$zip->addFile($file->getPathname(), $entry)) throw new RuntimeException("Cannot add: $entry");
            $count++;
        }
    }
    if (!$zip->close()) throw new RuntimeException('Cannot finish ZIP');
    echo "Built $count files: $target\nSHA-256: " . hash_file('sha256', $target) . "\n";
} catch (Throwable $error) {
    $zip->close();
    @unlink($target);
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
