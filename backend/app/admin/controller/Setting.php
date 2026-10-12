<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\service\WxappSetting;
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
            $this->followText = WxappSetting::readText('wxapp.follow_text');
            $this->privacyContent = WxappSetting::readText('wxapp.privacy_content');
            $this->fetch();
        } else {
            $allowed = [
                'wxapp.follow_image' => 500,
                'wxapp.follow_text' => 5000,
                'wxapp.privacy_content' => 20000,
            ];
            try {
                $submitted = self::normalizeSettingPost($this->request->post());
            } catch (\InvalidArgumentException $e) {
                $this->error($e->getMessage());
                return;
            }
            $changes = [];
            foreach ($allowed as $key => $maxLength) {
                if (!array_key_exists($key, $submitted)) continue;
                $value = $submitted[$key];
                if (!is_string($value) || strlen($value) > $maxLength) {
                    $this->error('设置内容格式或长度不正确！');
                }
                $changes[$key] = $value;
            }
            if (!$changes) $this->error('没有可保存的设置！');
            foreach ($changes as $key => $value) {
                if ($key === 'wxapp.follow_image') {
                    sysconf($key, $value);
                } else {
                    WxappSetting::saveText($key, $value);
                }
            }
            sysoplog('小程序设置', '修改小程序设置');
            $this->success('保存成功！');
        }
    }

    /** ThinkAdmin submits dotted wxapp field names as a nested array. */
    private static function normalizeSettingPost(array $post): array
    {
        if (!array_key_exists('wxapp', $post)) return $post;
        if (!is_array($post['wxapp'])) throw new \InvalidArgumentException('小程序设置格式不正确！');

        $settings = $post['wxapp'];
        unset($post['wxapp']);
        $allowed = ['follow_image', 'follow_text', 'privacy_content'];
        foreach ($settings as $key => $value) {
            if (!is_string($key) || !in_array($key, $allowed, true) || !is_string($value)) {
                throw new \InvalidArgumentException('小程序设置格式不正确！');
            }
            $name = 'wxapp.' . $key;
            if (array_key_exists($name, $post) && $post[$name] !== $value) {
                throw new \InvalidArgumentException('小程序设置字段存在冲突！');
            }
            $post[$name] = $value;
        }
        return $post;
    }
}
