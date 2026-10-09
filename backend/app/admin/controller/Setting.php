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
            foreach ($this->request->post() as $k => $v) {
                sysconf($k, $v);
            }
            sysoplog('小程序设置', '修改小程序设置');
            $this->success('保存成功！');
        }
    }
}
