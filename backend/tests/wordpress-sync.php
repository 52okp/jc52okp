<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use app\service\WordpressSync;
use think\admin\service\RuntimeService;
use think\facade\Db;

function expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function event(int $revision, string $action, ?array $article = null): array
{
    static $sequence = 0;
    $e = [
        'schema_version' => 1, 'site_id' => 'test-site',
        'event_id' => str_pad(dechex(++$sequence), 32, '0', STR_PAD_LEFT),
        'post_id' => 987654321, 'source_revision' => $revision,
        'action' => $action, 'content_hash' => hash('sha256', json_encode([$action, $article])),
    ];
    if ($article !== null) $e['article'] = $article;
    return $e;
}

$base = dirname(__DIR__);
$copy = $base . '/runtime/test-sync-' . bin2hex(random_bytes(5)) . '.sqlite';
if (!copy($base . '/database/sqlite.db', $copy)) throw new RuntimeException('Cannot copy database fixture');
try {
    $fixture = new PDO('sqlite:' . $copy);
    $before = [];
    foreach (['article', 'member', 'member_favorite', 'member_history', 'banner', 'article_category'] as $table) {
        $before[$table] = (int)$fixture->query("SELECT COUNT(*) FROM $table")->fetchColumn();
    }
    $fixture = null;
    $app = RuntimeService::init();
    $app->env->set('DB_TYPE', 'sqlite');
    $app->env->set('DB_SQLITE_PATH', $copy);
    $app->initialize();
    $database = config('database');
    $database['default'] = 'sqlite';
    $database['connections']['sqlite']['database'] = $copy;
    $app->config->set($database, 'database');
    $app->console->call('migrate:run', ['--no-interaction' => true]);
    foreach ($before as $table => $count) {
        expect((int)Db::name($table)->count() === $count, "migration changed existing $table rows");
    }
    $sync = new WordpressSync();
    $article = [
        'title' => '同步测试', 'summary' => '摘要',
        'content' => '<figure><img src="//cdn.example.test/a.jpg?x=1" onerror="bad()"><script>bad()</script></figure>',
        'cover' => 'https://cdn.example.test/a.jpg?x=1',
        'categories' => [['id' => 42, 'name' => '同步分类']], 'tags' => ['教程'],
        'source_url' => 'https://wordpress.example.test/p/1',
        'links' => [['type' => '网盘', 'url' => 'https://pan.example.test/s/1', 'code' => 'abc']],
    ];
    $first = event(2, 'upsert', $article);
    $done = $sync->apply($first);
    $id = $done['article_id'];
    expect($id > 0 && $done['result'] === 'applied', 'first upsert failed');
    expect($sync->apply($first)['duplicate'] === true, 'duplicate event was not idempotent');
    $row = Db::name('article')->where('id', $id)->find();
    expect(strpos($row['content'], 'https://cdn.example.test/a.jpg?x=1') !== false, 'image URL changed');
    expect(strpos($row['content'], 'onerror') === false && strpos($row['content'], '<script') === false, 'unsafe HTML survived');
    expect($sync->apply(event(1, 'unpublish'))['result'] === 'stale', 'stale event applied');
    expect($sync->apply(event(3, 'unpublish'))['result'] === 'applied', 'unpublish failed');
    expect(Db::name('article')->where('id', $id)->value('source_state') === 'unpublished', 'withdrawn article still published');
    expect($sync->apply(event(2, 'upsert', $article))['result'] === 'stale', 'old upsert resurrected article');
    Db::name('article')->where('id', $id)->update(['status' => 0]);
    unset($article['links']);
    $sync->apply(event(4, 'upsert', $article));
    $row = Db::name('article')->where('id', $id)->find();
    expect((int)$row['status'] === 0, 'source update erased local hide');
    expect(strpos($row['links'], 'pan.example.test') !== false, 'missing links erased local data');
    echo "WordPress sync transaction checks passed\n";
} finally {
    if (isset($app)) {
        try { Db::connect()->close(); } catch (Throwable $e) {}
    }
    @unlink($copy);
}
