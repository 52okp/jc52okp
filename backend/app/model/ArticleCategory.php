<?php

declare(strict_types=1);

namespace app\model;

use think\admin\Model;

/**
 * 文章分类模型
 * @class ArticleCategory
 */
class ArticleCategory extends Model
{
    protected $name = 'article_category';
    protected $autoWriteTimestamp = false;
    protected $oplogType = '文章分类管理';
    protected $oplogName = '文章分类';

    public function getCreateAtAttr($value)
    {
        return $value ? date('Y-m-d H:i', strtotime($value)) : '';
    }
}
