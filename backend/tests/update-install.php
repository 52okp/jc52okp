<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$app = \think\admin\service\RuntimeService::init();
$app->initialize();

use app\service\UpdateInstall;

$base = dirname(__DIR__) . '/runtime/test-update-install-' . bin2hex(random_bytes(5));
$id = str_repeat('a', 32);
$job = $base . '/runtime/update-jobs/' . $id;
mkdir($job, 0700, true);
mkdir($base . '/app', 0700, true);
mkdir($base . '/database/migrations', 0700, true);
file_put_contents($base . '/app/demo.php', '<?php echo "old";');
file_put_contents($base . '/.env', "APP_VERSION=1.0.0\nOTHER=value\n");
file_put_contents($job . '/status.json', json_encode(['id' => $id, 'state' => 'running']));
$package = $job . '/package.zip';

$call = static function (UpdateInstall $installer, string $method, ...$args) {
    $reflection = new ReflectionMethod($installer, $method);
    $reflection->setAccessible(true);
    return $reflection->invoke($installer, ...$args);
};
$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path)) {
        foreach (new DirectoryIterator($path) as $child) {
            if ($child->isDot()) continue;
            $remove($child->getPathname());
        }
        rmdir($path);
    } elseif (is_file($path)) unlink($path);
};

try {
    $installer = new UpdateInstall($base);
    $zip = new ZipArchive();
    $zip->open($package, ZipArchive::CREATE);
    $zip->addFromString('update-version.json', '{"product":"test","version":"1.0.1"}');
    $zip->addFromString('app/demo.php', '<?php echo "new";');
    $zip->close();

    $entries = $call($installer, 'prepare', $id, $package);
    if ($entries !== ['app/demo.php']) throw new RuntimeException('Unexpected entries');
    $call($installer, 'backup', $id, $entries);
    $call($installer, 'replace', $id, $entries[0]);
    if (file_get_contents($base . '/app/demo.php') !== '<?php echo "new";') throw new RuntimeException('Replace failed');
    $call($installer, 'restore', $id, $entries);
    if (file_get_contents($base . '/app/demo.php') !== '<?php echo "old";') throw new RuntimeException('Restore failed');

    file_put_contents($base . '/runtime/update-jobs/active', $id);
    $call($installer, 'replace', $id, $entries[0]);
    (new UpdateInstall($base))->recover($id);
    if (file_get_contents($base . '/app/demo.php') !== '<?php echo "old";') {
        throw new RuntimeException('Interrupted job recovery failed');
    }

    $call($installer, 'setVersion', $id, '1.0.1');
    if (!str_contains((string)file_get_contents($base . '/.env'), "APP_VERSION=1.0.1\nOTHER=value")) {
        throw new RuntimeException('Version update damaged environment');
    }

    $zip = new ZipArchive();
    $zip->open($package, ZipArchive::OVERWRITE);
    $zip->addFromString('update-version.json', '{"product":"test","version":"1.0.1"}');
    $zip->addFromString('database/migrations/new.php', '<?php');
    $zip->close();
    try {
        $call($installer, 'prepare', $id, $package);
        throw new RuntimeException('Database migration was accepted');
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'Database migration was accepted') throw $e;
    }
    echo "Update install replacement, rollback, and interrupted recovery passed\n";
} finally {
    // This test owns one randomly named directory directly under backend/runtime.
    $real = realpath($base);
    $runtime = realpath(dirname(__DIR__) . '/runtime');
    if ($real && $runtime && str_starts_with($real, $runtime . DIRECTORY_SEPARATOR . 'test-update-install-')) $remove($real);
}
