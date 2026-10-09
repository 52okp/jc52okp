<?php

declare(strict_types=1);

namespace app\api\controller;

use app\model\Article as ArticleModel;
use app\model\MemberFavorite;
use app\model\MemberHistory;
use think\Response;

/**
 * 教程文章公开接口（供 uniapp 调用）
 * 统一返回结构：{ code:1成功/0失败, msg:'', data:... }
 */
class Article extends Base
{
    /**
     * 文章列表
     * GET /api/article/list?page=1&limit=10&keyword=&tag=&category_id=&recommend=
     */
    public function list(): Response
    {
        $req      = request();
        $page     = max(1, intval($req->get('page', 1)));
        $limit    = min(50, max(1, intval($req->get('limit', 10))));
        $keyword  = trim((string)$req->get('keyword', ''));
        $tag      = trim((string)$req->get('tag', ''));
        $category = intval($req->get('category_id', 0));
        $recommend = $req->get('recommend', '');

        $query = ArticleModel::mk()
            ->where('is_deleted', 0)
            ->where('status', 1)
            ->whereRaw("(source_type = 'local' OR source_state = 'published')");

        if ($keyword !== '') $query->whereLike('title', "%{$keyword}%");
        if ($tag !== '') $query->whereLike('tags', "%{$tag}%");
        if ($category > 0) $query->where('category_id', $category);
        if ($recommend !== '') $query->where('is_recommend', 1);

        $res = $query->order('sort desc,id desc')
            ->field('id,title,cover,tags,summary,num_read,category_id,source_type,create_at')
            ->paginate(['list_rows' => $limit, 'page' => $page]);

        $items = [];
        foreach ($res->items() as $row) {
            $arr = $row->toArray();
            $arr['tagList'] = $this->splitTags($arr['tags'] ?? '');
            $items[] = $arr;
        }

        return $this->ok([
            'list'  => $items,
            'total' => $res->total(),
            'page'  => $page,
            'limit' => $limit,
        ]);
    }

    /**
     * 文章详情
     * GET /api/article/detail?id=1   （带 token 时返回是否已收藏，并记录浏览历史）
     */
    public function detail(): Response
    {
        $id = intval(request()->get('id', 0));
        if ($id <= 0) return $this->fail('参数错误');

        $article = ArticleModel::mk()
            ->where(['id' => $id, 'is_deleted' => 0, 'status' => 1])
            ->whereRaw("(source_type = 'local' OR source_state = 'published')")
            ->findOrEmpty();

        if ($article->isEmpty()) return $this->fail('文章不存在或已下架');

        // 阅读量 +1
        ArticleModel::mk()->where('id', $id)->inc('num_read')->update();

        $data = $article->toArray();
        $data['tagList'] = $this->splitTags($data['tags'] ?? '');
        if (!is_array($data['links'] ?? null)) $data['links'] = [];

        // 解压密码：拼成一条特殊资料条目，旧版前端无需改动即可渲染出「复制」按钮
        // url 直接放密码本身，前端复制按钮拷贝到的就是纯密码
        if (!empty($data['unzip_code'])) {
            $data['links'][] = ['type' => '解压密码', 'url' => $data['unzip_code'], 'code' => ''];
        }

        // 登录会员：返回收藏状态 + 记录浏览历史
        $data['favorited'] = false;
        $member = $this->getMember();
        if ($member) {
            $data['favorited'] = MemberFavorite::mk()
                ->where(['member_id' => $member->id, 'article_id' => $id])->count() > 0;
            $this->recordHistory($member->id, $id);
        }

        return $this->ok($data);
    }

    /** 记录/更新浏览历史 */
    private function recordHistory(int $memberId, int $articleId): void
    {
        $his = MemberHistory::mk()->where(['member_id' => $memberId, 'article_id' => $articleId])->findOrEmpty();
        if ($his->isExists()) {
            $his->save(['update_at' => date('Y-m-d H:i:s')]);
        } else {
            MemberHistory::mk()->save(['member_id' => $memberId, 'article_id' => $articleId, 'update_at' => date('Y-m-d H:i:s')]);
        }
    }

    private function splitTags(string $tags): array
    {
        $tags = str_replace(['，', ' '], [',', ''], $tags);
        return array_values(array_filter(explode(',', $tags)));
    }
}
