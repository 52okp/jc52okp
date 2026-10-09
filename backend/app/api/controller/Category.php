<?php

declare(strict_types=1);

namespace app\api\controller;

use app\model\ArticleCategory;
use think\Response;

/**
 * 分类接口
 */
class Category extends Base
{
    /**
     * 分类列表
     * GET /api/category/list
     */
    public function list(): Response
    {
        $list = ArticleCategory::mk()
            ->where(['is_deleted' => 0, 'status' => 1])
            ->order('sort desc,id desc')
            ->field('id,name,icon')
            ->select()->toArray();
        return $this->ok($list);
    }
}
