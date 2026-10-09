<?php

declare(strict_types=1);

use think\migration\Migrator;
use Phinx\Db\Adapter\MysqlAdapter;

/** Keep legacy article/member IDs. Source metadata lives in separate tables. */
class WordpressSync extends Migrator
{
    public function change()
    {
        $article = $this->table('article');
        if (!$article->hasColumn('source_type')) {
            $article->addColumn('source_type', 'string', ['limit' => 20, 'default' => 'local']);
        }
        if (!$article->hasColumn('source_state')) {
            $article->addColumn('source_state', 'string', ['limit' => 20, 'default' => 'published']);
        }
        // The legacy TEXT column caps articles at 64 KiB on MySQL 5.7.
        // WordPress payloads are bounded to 2 MiB by the receiver.
        $article->changeColumn('content', 'text', ['limit' => MysqlAdapter::TEXT_MEDIUM, 'null' => true]);
        $article->update();

        $options = ['id' => false, 'engine' => 'InnoDB', 'collation' => 'utf8mb4_general_ci'];
        $this->table('wp_sync_post', $options + ['primary_key' => ['site_id', 'post_id']])
            ->addColumn('site_id', 'string', ['limit' => 80, 'null' => false])
            ->addColumn('post_id', 'biginteger', ['null' => false])
            ->addColumn('article_id', 'biginteger')
            ->addColumn('source_revision', 'biginteger', ['default' => 0])
            ->addColumn('content_hash', 'string', ['limit' => 64, 'default' => ''])
            ->addColumn('source_state', 'string', ['limit' => 20, 'default' => 'published'])
            ->addColumn('source_url', 'string', ['limit' => 500, 'default' => ''])
            ->create();
        $this->table('wp_sync_event', $options + ['primary_key' => ['site_id', 'event_id']])
            ->addColumn('site_id', 'string', ['limit' => 80, 'null' => false])
            ->addColumn('event_id', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('post_id', 'biginteger')
            ->addColumn('source_revision', 'biginteger')
            ->addColumn('result', 'string', ['limit' => 20])
            ->addColumn('article_id', 'biginteger', ['default' => 0])
            ->addColumn('created_at', 'datetime')
            ->create();
        $this->table('wp_sync_nonce', $options + ['primary_key' => ['site_id', 'nonce']])
            ->addColumn('site_id', 'string', ['limit' => 80, 'null' => false])
            ->addColumn('nonce', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('expires_at', 'biginteger')
            ->create();
        $this->table('wp_sync_category', $options + ['primary_key' => ['site_id', 'term_id']])
            ->addColumn('site_id', 'string', ['limit' => 80, 'null' => false])
            ->addColumn('term_id', 'biginteger', ['null' => false])
            ->addColumn('category_id', 'biginteger')
            ->create();
        $this->table('wp_sync_post_category', $options + ['primary_key' => ['site_id', 'post_id', 'term_id']])
            ->addColumn('site_id', 'string', ['limit' => 80, 'null' => false])
            ->addColumn('post_id', 'biginteger', ['null' => false])
            ->addColumn('term_id', 'biginteger', ['null' => false])
            ->create();
    }
}
