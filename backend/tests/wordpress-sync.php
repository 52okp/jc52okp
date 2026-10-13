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
if (!is_dir(dirname($copy))) mkdir(dirname($copy), 0700, true);
(new PDO('sqlite:' . $copy))->exec('PRAGMA journal_mode=WAL');
try {
    $app = RuntimeService::init();
    $app->env->set('DB_TYPE', 'sqlite');
    $app->env->set('DB_SQLITE_PATH', $copy);
    $app->initialize();
    set_exception_handler(static function (Throwable $e): void { fwrite(STDERR, (string)$e); exit(1); });
    $database = config('database');
    $database['default'] = 'sqlite';
    $database['connections']['sqlite']['database'] = $copy;
    $app->config->set($database, 'database');
    $app->console->call('migrate:run', ['--target=20260610120000', '--no-interaction']);
    $before = [];
    foreach (['article', 'member', 'member_favorite', 'member_history', 'banner', 'article_category'] as $table) {
        $before[$table] = (int)Db::name($table)->count();
    }
    Db::connect()->close();
    $app->console->call('migrate:run', ['--no-interaction']);
    Db::connect(null, true);
    foreach ($before as $table => $count) {
        expect((int)Db::name($table)->count() === $count, "migration changed existing $table rows");
    }
    $sync = new WordpressSync();
    $article = [
        'title' => '同步测试', 'summary' => '摘要',
        'content' => '<figure><img src="//cdn.example.test/a.jpg?x=1" onerror="bad()"><script>bad()</script></figure>',
        'cover' => 'https://cdn.example.test/a.jpg?x=1',
        'categories' => [['id' => 42, 'name' => 'Primary'], ['id' => 43, 'name' => 'Secondary']], 'tags' => ['教程'],
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
    Db::name('article')->where('id', $id)->update(['status' => 1]);
    $secondary = (int)Db::name('wp_sync_category')->where('term_id', 43)->value('category_id');
    $app->request->withGet(['category_id' => $secondary]);
    $response = (new \app\api\controller\Article())->list()->getData();
    expect(in_array($id, array_column($response['data']['list'], 'id')), 'secondary category cannot find article');
    $token = \app\service\MemberToken::issue();
    $memberId = Db::name('member')->insertGetId(['openid' => 'test-member', 'token' => \app\service\MemberToken::digest($token), 'status' => 1]);
    try {
        Db::name('member')->insert(['openid'=>'test-member']);
        throw new RuntimeException('duplicate member accepted');
    } catch (Throwable $e) { expect($e->getMessage() !== 'duplicate member accepted', $e->getMessage()); }
    for ($i=0; $i<25; $i++) {
        $aid = Db::name('article')->insertGetId(['title'=>'history '.$i,'content'=>'','status'=>1,'is_deleted'=>0,'source_type'=>'local','source_state'=>'published']);
        Db::name('member_history')->insert(['member_id'=>$memberId,'article_id'=>$aid,'update_at'=>'2026-10-13 00:00:00']);
    }
    $app->request->withHeader(['member-token' => $token])->withGet(['paginated'=>'1','page'=>1,'limit'=>20]);
    $firstPage=(new \app\api\controller\Member())->history()->getData()['data'];
    $app->request->withGet(['paginated'=>'1','page'=>2,'limit'=>20]);
    $secondPage=(new \app\api\controller\Member())->history()->getData()['data'];
    expect(count($firstPage['list'])===20 && $firstPage['has_more'] && count($secondPage['list'])===5 && !$secondPage['has_more'],'history pagination failed');
    expect(!array_intersect(array_column($firstPage['list'],'id'),array_column($secondPage['list'],'id')),'history pages overlap');
    $app->request->withGet([]);
    $legacy=(new \app\api\controller\Member())->history()->getData()['data'];
    expect(array_is_list($legacy) && count($legacy)===20,'legacy array contract changed');
    $reads=Db::name('article')->where('id',$id)->value('num_read');
    foreach (['', 'expired', \app\service\MemberToken::issue()] as $publicToken) {
        $app->request->withGet(['id'=>$id])->withHeader(['member-token'=>$publicToken]);
        $public=(new \app\api\controller\Article())->detail()->getData();
        expect($public['code']===1 && (int)$public['data']['id']===$id && $public['data']['favorited']===false, 'public article blocked by absent/stale/unknown credential');
    }
    expect((int)Db::name('member_history')->count()===25, 'anonymous reading wrote member history');
    expect((int)Db::name('article')->where('id',$id)->value('num_read')===(int)$reads+3, 'public reading count changed');
    Db::name('article')->where('id',$id)->update(['status'=>0]);
    expect((new \app\api\controller\Article())->detail()->getData()['code']===0, 'public reading exposed a hidden article');
    Db::name('article')->where('id',$id)->update(['status'=>1]);
    $reads=Db::name('article')->where('id',$id)->value('num_read');
    $app->request->withGet(['id'=>$id,'auth_retry'=>'1'])->withHeader(['member-token'=>'expired']);
    expect((new \app\api\controller\Article())->detail()->getData()['code']===401,'opt-in credential retry signal missing');
    expect(Db::name('article')->where('id',$id)->value('num_read')===$reads,'auth retry double-counts reads');
    $migration = new \MemberUniqueKeys('testing', 20261013120000);
    $migration->setAdapter($app->console->find('migrate:run')->getAdapter());
    $migration->down();
    expect((int)Db::name('member_history')->count()===25, 'constraint rollback deleted history');
    $duplicateId=Db::name('member')->insertGetId(['openid'=>'test-member']);
    try { $migration->up(); throw new RuntimeException('migration accepted duplicate business keys'); }
    catch (RuntimeException $e) { expect(str_contains($e->getMessage(),'Duplicate business keys'),$e->getMessage()); }
    expect(!$migration->table('member_favorite')->hasIndexByName('uq_member_favorite_business'), 'duplicate preflight partially applied DDL');
    Db::name('member')->where('id',$duplicateId)->delete();
    $migration->up();
    expect($migration->table('member')->hasIndexByName('uq_member_business'), 'constraints not restored after rollback');
    \app\service\SchemaHealth::check($base);
    Db::name('migrations')->where('version','20261012120000')->delete();
    try { \app\service\SchemaHealth::check($base); throw new RuntimeException('missing migration accepted'); }
    catch (RuntimeException $e) { expect($e->getMessage() !== 'missing migration accepted', $e->getMessage()); }
    echo "WordPress sync, category, member pagination and migration health checks passed\n";
} finally {
    if (isset($app)) {
        try { Db::connect()->close(); } catch (Throwable $e) {}
        try { $app->console->find('migrate:run')->getAdapter()->disconnect(); } catch (Throwable $e) {}
    }
    @unlink($copy);
}
