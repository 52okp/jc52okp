<?php

declare(strict_types=1);
namespace app\service;
use think\facade\Db;

final class SchemaHealth
{
    public static function requireMigration(string $file): void
    {
        if (!preg_match('/^(\d{14})_/', basename($file), $match) ||
            !Db::name('migrations')->where('version', $match[1])->count()) {
            throw new \RuntimeException('数据库迁移尚未执行：' . basename($file));
        }
    }
    public static function check(string $root): void
    {
        foreach (glob($root . '/database/migrations/*.php') as $file) self::requireMigration($file);
        // These reads validate the actual columns used by the primary API, including empty tables.
        Db::name('banner')->field('id,image,character_image,status,is_deleted')->limit(1)->select();
        Db::name('article')->field('id,category_id,source_type,source_state,status,is_deleted,links')->limit(1)->select();
        Db::name('member')->field('id,openid,token,status')->limit(1)->select();
        Db::name('wp_sync_post_category')->field('site_id,post_id,term_id')->limit(1)->select();
    }
}
