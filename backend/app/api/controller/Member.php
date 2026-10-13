<?php

declare(strict_types=1);

namespace app\api\controller;

use app\model\Member as MemberModel;
use app\model\MemberFavorite;
use app\model\MemberHistory;
use app\service\MemberToken;
use app\service\MemberMutex;
use app\service\AvatarImage;
use app\service\WechatSession;
use think\admin\Storage;
use think\Response;

/**
 * 会员接口（小程序）
 */
class Member extends Base
{
    /**
     * 静默登录：code -> openid -> 自动建会员，返回 token
     * POST /api/member/login   参数：code
     */
    public function login(): Response
    {
        $code = (string)request()->param('code', '');
        if ($code === '') return $this->fail('缺少 code');

        $appid  = (string)env('WXAPP_APPID', '');
        $secret = (string)env('WXAPP_SECRET', '');
        if ($appid === '' || $secret === '') {
            return $this->fail('后端未配置小程序 AppID/Secret（请在 .env 设置 WXAPP_APPID / WXAPP_SECRET）');
        }

        try {
            $res = WechatSession::exchange($appid, $secret, $code);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage());
        }

        $openid  = $res['openid'];
        $unionid = $res['unionid'] ?? '';
        $token   = MemberToken::issue();
        $now     = date('Y-m-d H:i:s');

        try {
            $member = MemberMutex::run('login:' . $openid, function () use ($openid, $unionid, $token, $now) {
                $member = MemberModel::mk()->where('openid', $openid)->findOrEmpty();
                if ($member->isExists()) {
                    if ((int)$member->status !== 1) throw new \RuntimeException('账号已停用');
                    $member->save(['token' => MemberToken::digest($token), 'login_at' => $now, 'unionid' => $unionid ?: $member->unionid]);
                } else {
                    $member = MemberModel::mk();
                    $member->save([
                        'openid' => $openid, 'unionid' => $unionid, 'token' => MemberToken::digest($token),
                        'nickname' => '', 'avatar' => '', 'status' => 1, 'login_at' => $now,
                    ]);
                }
                return $member;
            });
        } catch (\RuntimeException $e) { return $this->fail('登录失败，请稍后重试或联系管理员'); }

        return $this->ok([
            'token'  => $token,
            'member' => ['id' => $member->id, 'nickname' => $member->nickname, 'avatar' => $member->avatar],
        ]);
    }

    /**
     * 当前会员信息
     * GET /api/member/info
     */
    public function info(): Response
    {
        $m = $this->getMember();
        if (!$m) return $this->needLogin();
        return $this->ok(['id' => $m->id, 'nickname' => $m->nickname, 'avatar' => $m->avatar]);
    }

    /**
     * 更新资料（头像/昵称）
     * POST /api/member/profile  参数：nickname, avatar
     */
    public function profile(): Response
    {
        $m = $this->getMember();
        if (!$m) return $this->needLogin();
        $data = [];
        $nick   = request()->param('nickname', null);
        $avatar = request()->param('avatar', null);
        if ($nick !== null) $data['nickname'] = trim((string)$nick);
        if ($avatar !== null) $data['avatar'] = trim((string)$avatar);
        if ($data) $m->save($data);
        return $this->ok(['id' => $m->id, 'nickname' => $m->nickname, 'avatar' => $m->avatar]);
    }

    /**
     * 上传头像（小程序 chooseAvatar 后上传），存到后台配置的存储（又拍云）
     * POST /api/member/upload   表单文件字段：file
     */
    public function upload(): Response
    {
        $m = $this->getMember();
        if (!$m) return $this->needLogin();

        $file = request()->file('file');
        if (empty($file)) return $this->fail('未收到文件');

        $ext = strtolower($file->getOriginalExtension() ?: 'png');
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            return $this->fail('仅支持图片格式');
        }
        if ($file->getSize() > 5 * 1024 * 1024) {
            return $this->fail('图片不能超过 5M');
        }

        try {
            $content = AvatarImage::encode((string)file_get_contents($file->getRealPath()));
            $url = MemberMutex::run('avatar:' . (int)$m->id, function () use ($m, $content) {
                $key = 'avatar-rate-' . (int)$m->id;
                if ((int)app()->cache->get($key, 0) > time() - 60) throw new \RuntimeException('头像更新过于频繁，请一分钟后重试');
                $dailyKey = $key . '-' . date('Ymd');
                $daily = (int)app()->cache->get($dailyKey, 0);
                if ($daily >= 10) throw new \RuntimeException('今日头像上传次数已达上限，请明日再试');
                app()->cache->set($dailyKey, $daily + 1, 86400);
                app()->cache->set($key, time(), 60);
                $info = Storage::instance()->set('avatar/members/' . (int)$m->id . '.png', $content);
                $url = $info['url'] ?? '';
                if ($url === '') throw new \RuntimeException('头像保存失败，请稍后重试');
                $url .= (str_contains($url, '?') ? '&' : '?') . 'v=' . substr(hash('sha256', $content), 0, 12);
                $m->save(['avatar' => $url]);
                return $url;
            });
            return $this->ok(['avatar' => $url]);
        } catch (\RuntimeException $e) { return $this->fail($e->getMessage()); }
        catch (\Throwable $e) { return $this->fail('头像保存失败，请稍后重试'); }
    }

    /**
     * 收藏/取消收藏（切换）
     * POST /api/member/favorite  参数：article_id
     */
    public function favorite(): Response
    {
        $m = $this->getMember();
        if (!$m) return $this->needLogin();
        $aid = intval(request()->param('article_id', 0));
        if ($aid <= 0) return $this->fail('参数错误');

        $wanted = request()->param('favorited', null);
        if ($wanted !== null && !in_array($wanted, [true, false, 1, 0, '1', '0'], true)) return $this->fail('收藏状态无效');
        try {
            $result = MemberMutex::run('favorite:' . $m->id . ':' . $aid, function () use ($m, $aid, $wanted) {
                $key = ['member_id' => $m->id, 'article_id' => $aid];
                $exists = MemberFavorite::mk()->where($key)->count() > 0;
                $desired = $wanted === null ? !$exists : (bool)$wanted;
                if (!$desired) MemberFavorite::mk()->where($key)->delete();
                elseif (!$exists) MemberFavorite::mk()->save($key);
                return $desired;
            });
            return $this->ok(['favorited' => $result]);
        } catch (\RuntimeException $e) { return $this->fail('操作繁忙，请重试'); }
    }

    /**
     * 我的收藏列表
     * GET /api/member/favorites
     */
    public function favorites(): Response
    {
        $m = $this->getMember();
        if (!$m) return $this->needLogin();
        $query = MemberFavorite::mk()->alias('f')
            ->join('article a', 'a.id = f.article_id')
            ->where('f.member_id', $m->id)->where('a.is_deleted', 0)->where('a.status', 1)
            ->whereRaw("(a.source_type = 'local' OR a.source_state = 'published')")
            ->order('f.id desc')
            ->field('a.id,a.title,a.cover,a.summary,a.num_read,a.source_type');
        return $this->collection($query);
    }

    /**
     * 浏览历史列表
     * GET /api/member/history
     */
    public function history(): Response
    {
        $m = $this->getMember();
        if (!$m) return $this->needLogin();
        $query = MemberHistory::mk()->alias('h')
            ->join('article a', 'a.id = h.article_id')
            ->where('h.member_id', $m->id)->where('a.is_deleted', 0)->where('a.status', 1)
            ->whereRaw("(a.source_type = 'local' OR a.source_state = 'published')")
            ->order('h.update_at desc,h.id desc')
            ->field('a.id,a.title,a.cover,a.summary,a.num_read,a.source_type,h.update_at');
        return $this->collection($query);
    }
    private function collection($query): Response
    {
        $page = max(1, (int)request()->get('page', 1));
        $limit = min(50, max(1, (int)request()->get('limit', 20)));
        $rows = $query->limit(($page - 1) * $limit, $limit + 1)->select()->toArray();
        $items = array_slice($rows, 0, $limit);
        // Existing clients keep the array response; new clients explicitly opt in to pagination metadata.
        return $this->ok(request()->get('paginated') === '1'
            ? ['list' => $items, 'page' => $page, 'has_more' => count($rows) > $limit] : $items);
    }

}
