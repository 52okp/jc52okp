<?php

declare(strict_types=1);

use think\admin\extend\PhinxExtend;
use think\migration\Migrator;

@set_time_limit(0);
@ini_set('memory_limit', '-1');

/**
 * 分类 / 轮播图 / 会员 / 收藏 / 历史 模块
 */
class InstallMemberCategory extends Migrator
{
    public function getName(): string
    {
        return 'MemberCategoryPlugin';
    }

    public function change()
    {
        $this->_create_article_category();
        $this->_create_banner();
        $this->_create_member();
        $this->_create_member_favorite();
        $this->_create_member_history();
        $this->_alter_article();
        $this->_init_menu();
        $this->_init_sample();
    }

    /** 文章分类 */
    private function _create_article_category()
    {
        $table = $this->table('article_category', ['engine' => 'InnoDB', 'collation' => 'utf8mb4_general_ci', 'comment' => '教程-文章分类']);
        PhinxExtend::upgrade($table, [
            ['name', 'string', ['limit' => 100, 'default' => '', 'null' => true, 'comment' => '分类名称']],
            ['icon', 'string', ['limit' => 500, 'default' => '', 'null' => true, 'comment' => '分类图标']],
            ['sort', 'biginteger', ['limit' => 20, 'default' => 0, 'null' => true, 'comment' => '排序权重']],
            ['status', 'integer', ['limit' => 1, 'default' => 1, 'null' => true, 'comment' => '状态(1启用,0禁用)']],
            ['is_deleted', 'integer', ['limit' => 1, 'default' => 0, 'null' => true, 'comment' => '删除(1是,0否)']],
            ['create_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'null' => true, 'comment' => '创建时间']],
        ], ['sort', 'status', 'is_deleted'], true);
    }

    /** 轮播图 */
    private function _create_banner()
    {
        $table = $this->table('banner', ['engine' => 'InnoDB', 'collation' => 'utf8mb4_general_ci', 'comment' => '首页-轮播图']);
        PhinxExtend::upgrade($table, [
            ['title', 'string', ['limit' => 200, 'default' => '', 'null' => true, 'comment' => '标题']],
            ['image', 'string', ['limit' => 500, 'default' => '', 'null' => true, 'comment' => '图片']],
            ['article_id', 'biginteger', ['limit' => 20, 'default' => 0, 'null' => true, 'comment' => '关联文章ID(0不跳转)']],
            ['sort', 'biginteger', ['limit' => 20, 'default' => 0, 'null' => true, 'comment' => '排序权重']],
            ['status', 'integer', ['limit' => 1, 'default' => 1, 'null' => true, 'comment' => '状态(1启用,0禁用)']],
            ['is_deleted', 'integer', ['limit' => 1, 'default' => 0, 'null' => true, 'comment' => '删除(1是,0否)']],
            ['create_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'null' => true, 'comment' => '创建时间']],
        ], ['sort', 'status', 'is_deleted'], true);
    }

    /** 会员（小程序用户） */
    private function _create_member()
    {
        $table = $this->table('member', ['engine' => 'InnoDB', 'collation' => 'utf8mb4_general_ci', 'comment' => '会员-用户']);
        PhinxExtend::upgrade($table, [
            ['openid', 'string', ['limit' => 100, 'default' => '', 'null' => true, 'comment' => '微信openid']],
            ['unionid', 'string', ['limit' => 100, 'default' => '', 'null' => true, 'comment' => '微信unionid']],
            ['nickname', 'string', ['limit' => 100, 'default' => '', 'null' => true, 'comment' => '昵称']],
            ['avatar', 'string', ['limit' => 500, 'default' => '', 'null' => true, 'comment' => '头像']],
            ['gender', 'integer', ['limit' => 1, 'default' => 0, 'null' => true, 'comment' => '性别(0未知1男2女)']],
            ['token', 'string', ['limit' => 64, 'default' => '', 'null' => true, 'comment' => '登录令牌']],
            ['status', 'integer', ['limit' => 1, 'default' => 1, 'null' => true, 'comment' => '状态(1正常,0禁用)']],
            ['login_at', 'timestamp', ['null' => true, 'comment' => '最后登录时间']],
            ['create_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'null' => true, 'comment' => '注册时间']],
        ], ['openid', 'token', 'status'], true);
    }

    /** 会员收藏 */
    private function _create_member_favorite()
    {
        $table = $this->table('member_favorite', ['engine' => 'InnoDB', 'collation' => 'utf8mb4_general_ci', 'comment' => '会员-收藏']);
        PhinxExtend::upgrade($table, [
            ['member_id', 'biginteger', ['limit' => 20, 'default' => 0, 'null' => true, 'comment' => '会员ID']],
            ['article_id', 'biginteger', ['limit' => 20, 'default' => 0, 'null' => true, 'comment' => '文章ID']],
            ['create_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'null' => true, 'comment' => '收藏时间']],
        ], ['member_id', 'article_id'], true);
    }

    /** 会员浏览历史 */
    private function _create_member_history()
    {
        $table = $this->table('member_history', ['engine' => 'InnoDB', 'collation' => 'utf8mb4_general_ci', 'comment' => '会员-浏览历史']);
        PhinxExtend::upgrade($table, [
            ['member_id', 'biginteger', ['limit' => 20, 'default' => 0, 'null' => true, 'comment' => '会员ID']],
            ['article_id', 'biginteger', ['limit' => 20, 'default' => 0, 'null' => true, 'comment' => '文章ID']],
            ['update_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'null' => true, 'comment' => '最近浏览时间']],
        ], ['member_id', 'article_id'], true);
    }

    /** 文章表增加 分类、推荐 字段 */
    private function _alter_article()
    {
        $table = $this->table('article');
        if (!$table->hasColumn('category_id')) {
            $table->addColumn('category_id', 'biginteger', ['limit' => 20, 'default' => 0, 'null' => true, 'comment' => '分类ID']);
        }
        if (!$table->hasColumn('is_recommend')) {
            $table->addColumn('is_recommend', 'integer', ['limit' => 1, 'default' => 0, 'null' => true, 'comment' => '推荐(1是,0否)']);
        }
        $table->update();
    }

    /** 后台菜单 */
    private function _init_menu()
    {
        // 内容管理 下增加：文章分类、轮播图
        $content = $this->fetchRow("SELECT id FROM system_menu WHERE title = '内容管理' AND pid = 0 ORDER BY id DESC LIMIT 1");
        $cpid = intval($content['id'] ?? 0);
        if ($cpid > 0) {
            if (empty($this->fetchRow("SELECT id FROM system_menu WHERE node='admin/article_category/index' LIMIT 1"))) {
                $this->table('system_menu')->insert(['pid' => $cpid, 'title' => '文章分类', 'icon' => 'layui-icon layui-icon-app', 'node' => 'admin/article_category/index', 'url' => 'admin/article_category/index', 'sort' => 90, 'status' => 1])->saveData();
            }
            if (empty($this->fetchRow("SELECT id FROM system_menu WHERE node='admin/banner/index' LIMIT 1"))) {
                $this->table('system_menu')->insert(['pid' => $cpid, 'title' => '轮播图', 'icon' => 'layui-icon layui-icon-picture', 'node' => 'admin/banner/index', 'url' => 'admin/banner/index', 'sort' => 80, 'status' => 1])->saveData();
            }
        }
        // 会员管理 顶级 + 会员列表
        if (empty($this->fetchRow("SELECT id FROM system_menu WHERE node='admin/member/index' LIMIT 1"))) {
            $this->table('system_menu')->insert(['pid' => 0, 'title' => '会员管理', 'icon' => 'layui-icon layui-icon-username', 'node' => '', 'url' => '#', 'sort' => 40, 'status' => 1])->saveData();
            $mpid = intval(($this->fetchRow("SELECT id FROM system_menu WHERE title='会员管理' AND pid=0 ORDER BY id DESC LIMIT 1"))['id'] ?? 0);
            if ($mpid > 0) {
                $this->table('system_menu')->insert(['pid' => $mpid, 'title' => '会员列表', 'icon' => 'layui-icon layui-icon-friends', 'node' => 'admin/member/index', 'url' => 'admin/member/index', 'sort' => 100, 'status' => 1])->saveData();
            }
        }
    }

    /** 种子数据：分类 + 把示例文章归类 + 一张轮播图 */
    private function _init_sample()
    {
        if (empty($this->fetchRow("SELECT id FROM article_category LIMIT 1"))) {
            foreach (['基础入门' => 100, '进阶教程' => 90, '模型资源' => 80, '工具下载' => 70] as $name => $sort) {
                $this->table('article_category')->insert(['name' => $name, 'icon' => '', 'sort' => $sort, 'status' => 1, 'is_deleted' => 0])->saveData();
            }
            // 把已有示例文章归到第一个分类、设为推荐
            $cat = $this->fetchRow("SELECT id FROM article_category ORDER BY id ASC LIMIT 1");
            if (!empty($cat['id'])) {
                $this->execute("UPDATE article SET category_id = " . intval($cat['id']) . ", is_recommend = 1 WHERE category_id = 0 OR category_id IS NULL");
            }
        }
    }
}
