<?php

declare(strict_types=1);

namespace app\api\controller;

use app\model\Article as ArticleModel;
use app\model\ArticleCategory;
use app\model\Banner;
use think\Response;

/**
 * 首页聚合接口
 */
class Home extends Base
{
    /**
     * 首页数据：轮播图 + 分类入口 + 推荐文章
     * GET /api/home/index
     */
    public function index(): Response
    {
        // 轮播图
        $banners = Banner::mk()
            ->where(['is_deleted' => 0, 'status' => 1])
            ->order('sort desc,id desc')
            ->field('id,title,image,article_id')
            ->select()->toArray();

        // 分类入口
        $categories = ArticleCategory::mk()
            ->where(['is_deleted' => 0, 'status' => 1])
            ->order('sort desc,id desc')
            ->field('id,name,icon')
            ->select()->toArray();

        // 推荐文章
        $recommend = ArticleModel::mk()
            ->where(['is_deleted' => 0, 'status' => 1, 'is_recommend' => 1])
            ->whereRaw("(source_type = 'local' OR source_state = 'published')")
            ->order('sort desc,id desc')
            ->field('id,title,cover,tags,summary,num_read,create_at')
            ->limit(10)->select()->toArray();

        foreach ($recommend as &$row) {
            $row['tagList'] = $this->splitTags($row['tags'] ?? '');
        }

        return $this->ok([
            'banners'    => $banners,
            'categories' => $categories,
            'recommend'  => $recommend,
        ]);
    }

    private function splitTags(string $tags): array
    {
        $tags = str_replace(['，', ' '], [',', ''], $tags);
        return array_values(array_filter(explode(',', $tags)));
    }
}
