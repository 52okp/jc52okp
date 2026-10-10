<?php

declare(strict_types=1);

/** Validate the two offline assets before creating a GitHub release. */
if (PHP_SAPI !== 'cli' || count($argv) !== 6) {
    fwrite(STDERR, "Usage: php tests/release-artifacts.php ZIP MANIFEST PROJECT FROM VERSION\n");
    exit(1);
}
[, $zipPath, $manifestPath, $project, $from, $version] = $argv;
require dirname(__DIR__) . '/vendor/autoload.php';

$app = \think\admin\service\RuntimeService::init();
$app->env->set('OSS_PROJECT', $project);
$app->env->set('OSS_TOKEN', str_repeat('a', 64));
$app->env->set('APP_VERSION', $from);
$app->initialize();

$manifest = json_decode((string)file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
if (($manifest['format'] ?? null) !== 2 || ($manifest['product'] ?? null) !== $project ||
    ($manifest['version'] ?? null) !== $version || ($manifest['from'] ?? null) !== $from ||
    ($manifest['package'] ?? null) !== $project . '-update.zip' ||
    ($manifest['size'] ?? null) !== filesize($zipPath) ||
    ($manifest['sha256'] ?? null) !== hash_file('sha256', $zipPath)) {
    throw new RuntimeException('Manifest does not match the release ZIP');
}

$client = new \app\service\UpdateCenter();
$verify = new ReflectionMethod($client, 'verifyZip');
$verify->setAccessible(true);
$verify->invoke($client, $zipPath, $version);

$zip = new ZipArchive();
if ($zip->open($zipPath) !== true) throw new RuntimeException('Cannot reopen ZIP');
try {
    if ($zip->numFiles > 10000) throw new RuntimeException('Too many ZIP entries');
    $entries = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (preg_match('~(^|/)(\.env|\.git|runtime|uploads?|backup|sqlite\.db)(/|$)~i', $name) ||
            in_array($name, ['install.lock', 'public/install.php'], true)) {
            throw new RuntimeException('Forbidden release entry: ' . $name);
        }
        $entries[$name] = true;
    }
    foreach (['update-version.json', 'app/service/UpdateInstall.php', 'app/admin/Img2Storage.php',
        'scripts/update-worker.php', 'scripts/update-health.php'] as $required) {
        if (!isset($entries[$required])) throw new RuntimeException('Missing release entry: ' . $required);
    }
    echo "Release artifacts verified: {$zip->numFiles} entries, " . filesize($zipPath) . " ZIP bytes\n";
} finally {
    $zip->close();
}
