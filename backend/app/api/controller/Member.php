<?php

declare(strict_types=1);

namespace app\api\controller;

use app\model\Member as MemberModel;
use app\model\MemberFavorite;
use app\model\MemberHistory;
use think\admin\extend\HttpExtend;
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

        $url = "https://api.weixin.qq.com/sns/jscode2session?appid={$appid}&secret={$secret}&js_code={$code}&grant_type=authorization_code";
        $res = json_decode((string)HttpExtend::get($url), true) ?: [];
        if (empty($res['openid'])) {
            return $this->fail('微信登录失败：' . ($res['errmsg'] ?? '未知错误'));
        }

        $openid  = $res['openid'];
        $unionid = $res['unionid'] ?? '';
        $token   = md5($openid . microtime(true) . mt_rand());
        $now     = date('Y-m-d H:i:s');

        $member = MemberModel::mk()->where('openid', $openid)->findOrEmpty();
        if ($member->isExists()) {
            $member->save(['token' => $token, 'login_at' => $now, 'unionid' => $unionid ?: $member->unionid]);
        } else {
            $member = MemberModel::mk();
            $member->save([
                'openid' => $openid, 'unionid' => $unionid, 'token' => $token,
                'nickname' => '', 'avatar' => '', 'status' => 1, 'login_at' => $now,
            ]);
        }

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

        $content = file_get_contents($file->getRealPath());
        $name = 'avatar/' . md5($content) . '.' . $ext;

        try {
            $info = Storage::instance()->set($name, $content);
        } catch (\Throwable $e) {
            return $this->fail('上传失败：' . $e->getMessage());
        }
        $url = $info['url'] ?? '';
        if ($url === '') return $this->fail('上传失败，请检查后台存储配置');

        $m->save(['avatar' => $url]);
        return $this->ok(['avatar' => $url]);
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

        $fav = MemberFavorite::mk()->where(['member_id' => $m->id, 'article_id' => $aid])->findOrEmpty();
        if ($fav->isExists()) {
            $fav->delete();
            return $this->ok(['favorited' => false]);
        }
        MemberFavorite::mk()->save(['member_id' => $m->id, 'article_id' => $aid]);
        return $this->ok(['favorited' => true]);
    }

    /**
     * 我的收藏列表
     * GET /api/member/favorites
     */
    public function favorites(): Response
    {
        $m = $this->getMember();
        if (!$m) return $this->needLogin();
        $rows = MemberFavorite::mk()->alias('f')
            ->join('article a', 'a.id = f.article_id')
            ->where('f.member_id', $m->id)->where('a.is_deleted', 0)->where('a.status', 1)
            ->whereRaw("(a.source_type = 'local' OR a.source_state = 'published')")
            ->order('f.id desc')
            ->field('a.id,a.title,a.cover,a.summary,a.num_read')
            ->select()->toArray();
        return $this->ok($rows);
    }

    /**
     * 浏览历史列表
     * GET /api/member/history
     */
    public function history(): Response
    {
        $m = $this->getMember();
        if (!$m) return $this->needLogin();
        $rows = MemberHistory::mk()->alias('h')
            ->join('article a', 'a.id = h.article_id')
            ->where('h.member_id', $m->id)->where('a.is_deleted', 0)->where('a.status', 1)
            ->whereRaw("(a.source_type = 'local' OR a.source_state = 'published')")
            ->order('h.update_at desc,h.id desc')
            ->field('a.id,a.title,a.cover,a.summary,a.num_read,h.update_at')
            ->select()->toArray();
        return $this->ok($rows);
    }
}
