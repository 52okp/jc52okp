<?php

declare(strict_types=1);

use think\migration\Migrator;

@set_time_limit(0);
@ini_set('memory_limit', '-1');

/**
 * 文章表增加 解压密码 字段
 * 填写后小程序详情页「配套资料」区会多出一条可一键复制的解压密码条目
 */
class AddArticleUnzipCode extends Migrator
{
    public function getName(): string
    {
        return 'ArticleUnzipCodePlugin';
    }

    public function change()
    {
        $table = $this->table('article');
        if (!$table->hasColumn('unzip_code')) {
            $table->addColumn('unzip_code', 'string', ['limit' => 100, 'default' => '', 'null' => true, 'comment' => '解压密码(选填)']);
        }
        $table->update();
    }
}
