<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use app\service\UpdateCenter;
use think\admin\service\RuntimeService;

$app = RuntimeService::init();
$app->env->set('OSS_PROJECT', 'ai-tutorial-backend');
$app->env->set('OSS_TOKEN', str_repeat('a', 64));
$app->env->set('APP_VERSION', '1.0.0');
$app->initialize();
$client = new UpdateCenter();
$method = (new ReflectionClass($client))->getMethod('verifyZip');
$method->setAccessible(true);
$file = dirname(__DIR__) . '/runtime/test-update-' . bin2hex(random_bytes(5)) . '.zip';
try {
    $zip = new ZipArchive();
    $zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('update-version.json', '{"product":"ai-tutorial-backend","version":"1.0.1"}');
    $zip->addFromString('app/api/controller/Safe.php', '<?php');
    $zip->addFromString('scripts/update-worker.php', '<?php');
    $zip->close();
    $method->invoke($client, $file, '1.0.1');
    @unlink($file);

    foreach (['../escape.php', 'app/.env', 'public/uploads/avatar.png'] as $badPath) {
        $zip = new ZipArchive();
        $zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('update-version.json', '{"product":"ai-tutorial-backend","version":"1.0.1"}');
        $zip->addFromString($badPath, 'bad');
        $zip->close();
        try {
            $method->invoke($client, $file, '1.0.1');
            throw new RuntimeException("Unsafe ZIP accepted: $badPath");
        } catch (ReflectionException $e) { throw $e; }
        catch (RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'Unsafe ZIP accepted:')) throw $e;
        }
        @unlink($file);
    }
    echo "Update ZIP preflight checks passed\n";
} finally { @unlink($file); }
