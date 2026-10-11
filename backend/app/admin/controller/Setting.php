<?php

declare(strict_types=1);

namespace app\admin\controller;

use think\admin\Controller;

/**
 * 小程序设置（关于我们 / 关注我们 / 隐私政策）
 * @class Setting
 */
class Setting extends Controller
{
    /**
     * 小程序设置
     * @auth true
     * @menu true
     */
    public function index()
    {
        if ($this->request->isGet()) {
            $this->title = '小程序设置';
            $this->fetch();
        } else {
            $allowed = [
                'wxapp.follow_image' => 500,
                'wxapp.follow_text' => 5000,
                'wxapp.privacy_content' => 20000,
            ];
            $submitted = $this->request->post();
            $changes = 0;
            foreach ($allowed as $key => $maxLength) {
                if (!array_key_exists($key, $submitted)) continue;
                $value = $submitted[$key];
                if (!is_string($value) || strlen($value) > $maxLength) {
                    $this->error('设置内容格式或长度不正确！');
                }
                sysconf($key, $value);
                $changes++;
            }
            if ($changes === 0) $this->error('没有可保存的设置！');
            sysoplog('小程序设置', '修改小程序设置');
            $this->success('保存成功！');
        }
    }
}
