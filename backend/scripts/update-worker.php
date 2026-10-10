<?php

declare(strict_types=1);

$recover = ($argv[1] ?? '') === 'recover';
$id = (string)($argv[$recover ? 2 : 1] ?? '');
if (PHP_SAPI !== 'cli' || !preg_match('/^[a-f0-9]{32}$/', $id)) {
    exit(1);
}

chdir(dirname(__DIR__));
require dirname(__DIR__) . '/vendor/autoload.php';

try {
    $app = \think\admin\service\RuntimeService::init();
    $app->initialize();
    $installer = new \app\service\UpdateInstall();
    if ($recover) $installer->recover($id);
    else $installer->run($id);
} catch (\Throwable $e) {
    // The installer normally records failures itself. If booting failed,
    // record a generic status without leaking credentials from the exception.
    $file = dirname(__DIR__) . '/runtime/update-jobs/' . $id . '/status.json';
    if (is_file($file)) {
        $data = json_decode((string)file_get_contents($file), true);
        if (is_array($data) && in_array($data['state'] ?? '', ['queued', 'running'], true)) {
            $data['state'] = is_file(dirname($file) . '/journal.json') ? 'recovery_required' : 'failed';
            $data['phase'] = '执行器异常';
            $data['message'] = '后台执行器异常退出，请检查 PHP CLI、扩展及任务日志';
            $data['updated_at'] = time();
            $tmp = $file . '.tmp-' . bin2hex(random_bytes(5));
            if (file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE)) !== false) {
                @chmod($tmp, 0600);
                @rename($tmp, $file);
            }
        }
    }
    fwrite(STDERR, '更新执行器异常：' . get_class($e) . "\n");
    exit(1);
}
