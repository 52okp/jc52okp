<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
chdir(dirname(__DIR__));
require dirname(__DIR__) . '/vendor/autoload.php';

try {
    $app = \think\admin\service\RuntimeService::init();
    $app->initialize();
    \think\facade\Db::query('SELECT 1');
    if (!is_file(dirname(__DIR__) . '/public/index.php')) exit(1);
    exit(0);
} catch (\Throwable $e) {
    exit(1);
}
