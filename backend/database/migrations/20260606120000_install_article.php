<?php

declare(strict_types=1);

use think\admin\extend\PhinxExtend;
use think\migration\Migrator;

@set_time_limit(0);
@ini_set('memory_limit', '-1');

/**
 * 教程文章模块数据表与菜单
 */
class InstallArticle extends Migrator
{
    public function getName(): string
    {
        return 'ArticlePlugin';
    }

    public function change()
    {
        $this->_create_article();
        $this->_init_menu();
        $this->_init_sample();
    }

    /**
     * 创建文章数据表
     * @class Article
     * @table article
     */
    private function _create_article()
    {
        $table = $this->table('article', [
            'engine' => 'InnoDB', 'collation' => 'utf8mb4_general_ci', 'comment' => '教程-文章',
        ]);
        PhinxExtend::upgrade($table, [
            ['title', 'string', ['limit' => 200, 'default' => '', 'null' => true, 'comment' => '文章标题']],
            ['cover', 'string', ['limit' => 500, 'default' => '', 'null' => true, 'comment' => '封面图片']],
            ['tags', 'string', ['limit' => 200, 'default' => '', 'null' => true, 'comment' => '标签(逗号分隔)']],
            ['summary', 'string', ['limit' => 500, 'default' => '', 'null' => true, 'comment' => '文章摘要']],
            ['content', 'text', ['null' => true, 'comment' => '文章正文(HTML)']],
            ['links', 'text', ['null' => true, 'comment' => '下载链接(JSON)']],
            ['num_read', 'biginteger', ['limit' => 20, 'default' => 0, 'null' => true, 'comment' => '阅读量']],
            ['sort', 'biginteger', ['limit' => 20, 'default' => 0, 'null' => true, 'comment' => '排序权重']],
            ['status', 'integer', ['limit' => 1, 'default' => 1, 'null' => true, 'comment' => '状态(1上架,0下架)']],
            ['is_deleted', 'integer', ['limit' => 1, 'default' => 0, 'null' => true, 'comment' => '删除(1是,0否)']],
            ['create_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'null' => true, 'comment' => '创建时间']],
        ], [
            'sort', 'status', 'is_deleted',
        ], true);
    }

    /**
     * 初始化后台菜单
     */
    private function _init_menu()
    {
        // 避免重复插入
        $exist = $this->fetchRow("SELECT id FROM system_menu WHERE node = 'admin/article/index' LIMIT 1");
        if (!empty($exist)) return;

        // 顶级菜单：内容管理
        $this->table('system_menu')->insert([
            'pid' => 0, 'title' => '内容管理', 'icon' => 'layui-icon layui-icon-read', 'node' => '', 'url' => '#', 'sort' => 50, 'status' => 1,
        ])->saveData();

        $parent = $this->fetchRow("SELECT id FROM system_menu WHERE title = '内容管理' AND pid = 0 ORDER BY id DESC LIMIT 1");
        $pid = intval($parent['id'] ?? 0);

        // 子菜单：教程文章
        $this->table('system_menu')->insert([
            'pid' => $pid, 'title' => '教程文章', 'icon' => 'layui-icon layui-icon-file', 'node' => 'admin/article/index', 'url' => 'admin/article/index', 'sort' => 100, 'status' => 1,
        ])->saveData();
    }

    /**
     * 初始化一条示例文章
     */
    private function _init_sample()
    {
        $exist = $this->fetchRow("SELECT id FROM article LIMIT 1");
        if (!empty($exist)) return;

        $links = json_encode([
            ['type' => '百度网盘', 'url' => 'https://pan.baidu.com/s/xxxxxx', 'code' => 'sd01'],
            ['type' => '夸克网盘', 'url' => 'https://pan.quark.cn/s/xxxxxx', 'code' => ''],
            ['type' => '阿里云盘', 'url' => 'https://www.alipan.com/s/xxxxxx', 'code' => ''],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $content = '<h3>一、安装前准备</h3><p>建议显卡显存 6G 以上，N 卡体验最佳。准备好 Python 3.10.x 和 Git。</p>'
            . '<h3>二、下载整合包</h3><p>新手推荐直接用整合包，免去配置环境的麻烦。整合包下载见本文底部的下载区。</p>'
            . '<h3>三、第一次出图</h3><p>填入提示词，点击生成。恭喜，你已经迈出第一步！</p>';

        $this->table('article')->insert([
            'title'    => 'Stable Diffusion 本地安装入门',
            'cover'    => '',
            'tags'     => '入门,安装',
            'summary'  => '从零开始，在自己电脑上跑起来 SD WebUI，并附整合包下载。',
            'content'  => $content,
            'links'    => $links,
            'num_read' => 0,
            'sort'     => 100,
            'status'   => 1,
            'is_deleted' => 0,
        ])->saveData();
    }
}
