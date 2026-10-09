<?php

declare(strict_types=1);

namespace app\api\controller;

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
        return $this->ok([
            'follow_image'    => (string)sysconf('wxapp.follow_image'),
            'follow_text'     => (string)sysconf('wxapp.follow_text'),
            'privacy_content' => (string)sysconf('wxapp.privacy_content'),
        ]);
    }
}
