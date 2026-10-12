<?php

declare(strict_types=1);

namespace app\api\controller;

use app\service\WxappSetting;
use think\Response;

/**
 * 小程序设置接口
 */
class Setting extends Base
{
    /**
     * 关于我们相关内容
     * GET /api/setting/index
     */
    public function index(): Response
    {
        $accounts = WxappSetting::readFollowAccounts();
        $first = $accounts[0] ?? [];
        return $this->ok([
            'follow_accounts' => $accounts,
            'follow_image'    => $first['image'] ?? '',
            'follow_text'     => $first['description'] ?? '',
            'privacy_content' => WxappSetting::readText('wxapp.privacy_content'),
        ]);
    }
}
