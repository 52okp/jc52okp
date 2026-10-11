<?php

declare(strict_types=1);

namespace app\api\controller;

use app\model\Member;
use app\service\MemberToken;
use think\Response;

/**
 * API 基础控制器：统一返回结构 + 会员鉴权
 */
class Base
{
    /** 成功返回 */
    protected function ok($data = null, string $msg = 'ok'): Response
    {
        return json(['code' => 1, 'msg' => $msg, 'data' => $data]);
    }

    /** 失败返回 */
    protected function fail(string $msg = 'error', $data = null): Response
    {
        return json(['code' => 0, 'msg' => $msg, 'data' => $data]);
    }

    /** 需要登录返回 */
    protected function needLogin(): Response
    {
        return json(['code' => 401, 'msg' => '请先登录', 'data' => null]);
    }

    /**
     * 解析当前登录会员（仅从 Member-Token 请求头读取）
     * @return Member|null
     */
    protected function getMember()
    {
        $req = request();
        $token = (string)$req->header('Member-Token', '');
        if (!MemberToken::isCurrent($token)) return null;
        $m = Member::mk()->where(['token' => MemberToken::digest($token), 'status' => 1])->findOrEmpty();
        return $m->isExists() ? $m : null;
    }
}
