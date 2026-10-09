<?php

declare(strict_types=1);

/** Read-only inventory before and after a data migration. Never prints credentials or article content. */
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/vendor/autoload.php';

use think\admin\service\RuntimeService;
use think\facade\Db;

$app = RuntimeService::init();
$app->initialize();
$type = (string)config('database.default');
$connection = Db::connect();
$connection->query('SELECT 1');
$pdo = $connection->getPdo();
$driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
$version = $driver === 'mysql' ? (string)$pdo->query('SELECT VERSION()')->fetchColumn() : (string)$pdo->query('SELECT sqlite_version()')->fetchColumn();
$tables = ['article', 'article_category', 'banner', 'member', 'member_favorite', 'member_history',
    'system_config', 'system_data', 'system_menu', 'system_user', 'system_file'];
$inventory = [
    'connection' => $type,
    'driver' => $driver,
    'database_version' => $version,
    'mysql57_target' => $driver === 'mysql' && preg_match('/^5\.7\./', $version) === 1,
    'tables' => [],
    'integrity' => [],
];
foreach ($tables as $table) {
    $inventory['tables'][$table] = [
        'rows' => (int)Db::name($table)->count(),
        'max_id' => (int)Db::name($table)->max('id'),
    ];
}
foreach (['member_favorite', 'member_history'] as $table) {
    $inventory['integrity'][$table] = [
        'missing_member' => (int)Db::query("SELECT COUNT(*) AS n FROM {$table} r LEFT JOIN member m ON m.id=r.member_id WHERE m.id IS NULL")[0]['n'],
        'missing_article' => (int)Db::query("SELECT COUNT(*) AS n FROM {$table} r LEFT JOIN article a ON a.id=r.article_id WHERE a.id IS NULL")[0]['n'],
        'duplicate_pairs' => (int)Db::query("SELECT COALESCE(SUM(n-1),0) AS n FROM (SELECT COUNT(*) AS n FROM {$table} GROUP BY member_id,article_id HAVING COUNT(*)>1) d")[0]['n'],
    ];
}
echo json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
