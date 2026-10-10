<?php

declare(strict_types=1);

/** One-time manual bootstrap for sites whose installed backend has no update installer. */
if (PHP_SAPI !== 'cli' || !extension_loaded('zip')) exit(1);
$output = (string)($argv[1] ?? '');
if ($output === '' || !is_dir(dirname($output)) || file_exists($output)) {
    fwrite(STDERR, "Provide a new ZIP path in an existing directory.\n");
    exit(1);
}
$root = dirname(__DIR__);
$files = [
    'app/admin/controller/Update.php',
    'app/admin/view/update/index.html',
    'app/service/UpdateCenter.php',
    'app/service/UpdateInstall.php',
    'scripts/update-worker.php',
    'scripts/update-health.php',
];
$zip = new ZipArchive();
if ($zip->open($output, ZipArchive::CREATE | ZipArchive::EXCL) !== true) exit(1);
try {
    foreach ($files as $file) {
        if (!is_file($root . '/' . $file) || !$zip->addFile($root . '/' . $file, $file)) {
            throw new RuntimeException('Cannot package ' . $file);
        }
    }
    if (!$zip->close()) throw new RuntimeException('Cannot finish ZIP');
    echo "Built bootstrap package: $output\nSHA-256: " . hash_file('sha256', $output) . "\n";
} catch (Throwable $e) {
    $zip->close();
    @unlink($output);
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
