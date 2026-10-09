<?php

declare(strict_types=1);

/** Integration test for a disposable MySQL 5.7 clone. Never accepts the application database name. */
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/vendor/autoload.php';

use app\service\WordpressSync;
use think\admin\service\RuntimeService;
use think\facade\Db;

$target = (string)getenv('TEST_MYSQL_DATABASE');
if (!preg_match('/^ai_tutorial_test_[a-z0-9_]{4,32}$/', $target)) {
    throw new RuntimeException('TEST_MYSQL_DATABASE must name a disposable test database');
}
$app = RuntimeService::init();
$app->initialize();
$database = config('database');
$database['default'] = 'mysql';
$database['connections']['mysql']['database'] = $target;
$app->config->set($database, 'database');
if (Db::query('SELECT DATABASE() AS db')[0]['db'] !== $target ||
    !str_starts_with((string)Db::query('SELECT VERSION() AS version')[0]['version'], '5.7.')) {
    throw new RuntimeException('Test connection is not the expected MySQL 5.7 clone');
}
$before = (int)Db::name('article')->count();
$postId = random_int(100000000, 999999999);
$article = [
    'title' => 'MySQL 5.7 集成测试', 'summary' => '正文超过旧 TEXT 上限',
    'content' => '<p>' . str_repeat('这是长文章。', 9000) . '</p>',
    'cover' => 'https://cdn.example.test/cover.jpg?x=1',
    'categories' => [['id' => 9001, 'name' => '集成测试分类']],
    'tags' => ['测试'], 'source_url' => 'https://wordpress.example.test/test',
    'links' => [['type' => '资料', 'url' => 'https://example.test/file', 'code' => 'abc']],
];
$event = static function (int $revision, string $action, ?array $payload = null) use ($postId): array {
    $value = [
        'schema_version' => 1, 'site_id' => 'mysql-test-site',
        'event_id' => bin2hex(random_bytes(16)), 'post_id' => $postId,
        'source_revision' => $revision, 'action' => $action,
        'content_hash' => hash('sha256', json_encode([$action, $payload], JSON_UNESCAPED_UNICODE)),
    ];
    if ($payload !== null) $value['article'] = $payload;
    return $value;
};
$sync = new WordpressSync();
$upsert = $event(1, 'upsert', $article);
$first = $sync->apply($upsert);
$id = (int)$first['article_id'];
if ($first['result'] !== 'applied' || $id <= 0 ||
    (int)Db::name('article')->count() !== $before + 1 ||
    strlen((string)Db::name('article')->where('id', $id)->value('content')) <= 65535) {
    throw new RuntimeException('MySQL 5.7 long article import failed');
}
if (!$sync->apply($upsert)['duplicate']) throw new RuntimeException('Duplicate event was not idempotent');
$sync->apply($event(2, 'unpublish'));
if (Db::name('article')->where('id', $id)->value('source_state') !== 'unpublished') {
    throw new RuntimeException('Unpublish did not hide article');
}
if ($sync->apply($event(1, 'upsert', $article))['result'] !== 'stale') {
    throw new RuntimeException('Stale event resurrected article');
}
Db::name('article')->where('id', $id)->update(['status' => 0]);
$sync->apply($event(3, 'upsert', $article));
if ((int)Db::name('article')->where('id', $id)->value('status') !== 0) {
    throw new RuntimeException('Source update erased local hide');
}
echo "MySQL 5.7 sync integration passed\n";
