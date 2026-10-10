<?php

declare(strict_types=1);
/**
 * +----------------------------------------------------------------------
 * | ThinkAdmin Plugin for ThinkAdmin
 * +----------------------------------------------------------------------
 * | 版权所有 2014~2026 ThinkAdmin [ thinkadmin.top ]
 * +----------------------------------------------------------------------
 * | 官方网站: https://thinkadmin.top
 * +----------------------------------------------------------------------
 * | 开源协议 ( https://mit-license.org )
 * | 免责声明 ( https://thinkadmin.top/disclaimer )
 * | 会员特权 ( https://thinkadmin.top/vip-introduce )
 * +----------------------------------------------------------------------
 * | gitee 代码仓库：https://gitee.com/zoujingli/ThinkAdmin
 * | github 代码仓库：https://github.com/zoujingli/ThinkAdmin
 * +----------------------------------------------------------------------
 */

namespace app\admin\controller;

use think\admin\Controller;
use think\admin\Plugin;
use think\admin\service\AdminService;
use think\admin\service\ModuleService;
use think\admin\service\RuntimeService;
use think\admin\service\SystemService;
use think\admin\Storage;
use think\admin\storage\AliossStorage;
use think\admin\storage\QiniuStorage;
use think\admin\storage\TxcosStorage;

/**
 * 系统参数配置.
 * @class Config
 */
class Config extends Controller
{
    public const themes = [
        'default' => '默认色0',
        'white' => '简约白0',
        'red-1' => '玫瑰红1',
        'blue-1' => '深空蓝1',
        'green-1' => '小草绿1',
        'black-1' => '经典黑1',
        'red-2' => '玫瑰红2',
        'blue-2' => '深空蓝2',
        'green-2' => '小草绿2',
        'black-2' => '经典黑2',
    ];

    /**
     * 系统参数配置.
     * @auth true
     * @menu true
     */
    public function index()
    {
        // 已移除的又拍云不再作为默认上传引擎；历史图片 URL 不受影响。
        if (sysconf('storage.type|raw') === 'upyun') {
            sysconf('storage.type', 'local');
        }
        $this->title = '系统参数配置';
        $this->files = Storage::types();
        $this->plugins = Plugin::get(null, true);
        $this->issuper = AdminService::isSuper();
        $this->systemid = ModuleService::getRunVar('uni');
        $this->framework = ModuleService::getLibrarys('topthink/framework');
        $this->thinkadmin = ModuleService::getLibrarys('zoujingli/think-library');
        if (AdminService::isSuper() && $this->app->session->get('user.password') === md5('admin')) {
            $url = url('admin/index/pass', ['id' => AdminService::getUserId()]);
            $this->showErrorMessage = lang("超级管理员账号的密码未修改，建议立即<a data-modal='%s'>修改密码</a>！", [$url]);
        }
        uasort($this->plugins, static function ($a, $b) {
            if ($a['space'] === $b['space']) {
                return 0;
            }
            return $a['space'] > $b['space'] ? 1 : -1;
        });
        $this->fetch();
    }

    /**
     * 修改系统参数.
     * @auth true
     * @throws \think\admin\Exception
     */
    public function system()
    {
        if ($this->request->isGet()) {
            $this->title = '修改系统参数';
            $this->themes = static::themes;
            $this->fetch();
        } else {
            $post = $this->request->post();
            // 修改网站后台入口路径
            if (!empty($post['xpath'])) {
                if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $post['xpath'])) {
                    $this->error('后台入口格式错误！');
                }
                if ($post['xpath'] !== 'admin') {
                    if (is_dir(syspath("app/{$post['xpath']}")) || !empty(Plugin::get($post['xpath']))) {
                        $this->error(lang('已存在 %s 应用！', [$post['xpath']]));
                    }
                }
                RuntimeService::set(null, [$post['xpath'] => 'admin']);
            }
            // 修改网站 ICON 图标，替换 public/favicon.ico
            if (preg_match('#^https?://#', $post['site_icon'] ?? '')) {
                try {
                    SystemService::setFavicon($post['site_icon'] ?? '');
                } catch (\Exception $exception) {
                    trace_file($exception);
                }
            }
            // 数据数据到系统配置表
            foreach ($post as $k => $v) {
                sysconf($k, $v);
            }
            sysoplog('系统配置管理', '修改系统参数成功');
            $this->success('数据保存成功！', admuri('admin/config/index'));
        }
    }

    /**
     * 修改文件存储.
     * @auth true
     * @throws \think\admin\Exception
     */
    public function storage()
    {
        $this->_applyFormToken();
        if ($this->request->isGet()) {
            $this->type = input('type', 'local');
            if (!array_key_exists($this->type, Storage::types())) {
                $this->error('不支持的存储引擎。');
            }
            $this->img2Configured = trim((string) sysconf('storage.img2_token|raw')) !== '';
            if ($this->type === 'alioss') {
                $this->points = AliossStorage::region();
            } elseif ($this->type === 'qiniu') {
                $this->points = QiniuStorage::region();
            } elseif ($this->type === 'txcos') {
                $this->points = TxcosStorage::region();
            }
            $this->fetch("storage-{$this->type}");
        } else {
            $post = $this->request->post();
            $type = strtolower((string) ($post['storage.type'] ?? ''));
            if (!array_key_exists($type, Storage::types())) {
                $this->error('不支持的存储引擎。');
            }
            if ($type === 'img2') {
                $token = trim((string) ($post['storage.img2_token'] ?? ''));
                if ($token === '' && trim((string) sysconf('storage.img2_token|raw')) === '') {
                    $this->error('请填写 Yutu 图床 API Token。');
                }
                if ($token === '') {
                    unset($post['storage.img2_token']);
                } elseif (preg_match('/[\r\n]/', $token)) {
                    $this->error('Token 格式不正确。');
                } else {
                    $post['storage.img2_token'] = $token;
                }
                $strategy = trim((string) ($post['storage.img2_strategy_id'] ?? ''));
                if ($strategy !== '' && (!ctype_digit($strategy) || (int) $strategy < 1)) {
                    $this->error('存储策略 ID 必须是正整数。');
                }
                $post['storage.img2_strategy_id'] = $strategy;
            }
            if (isset($post['storage.allow_exts'])) {
                $deny = ['sh', 'asp', 'bat', 'cmd', 'exe', 'php'];
                $exts = array_unique(str2arr(strtolower((string) $post['storage.allow_exts'])));
                if (count(array_intersect($deny, $exts)) > 0) {
                    $this->error('禁止上传可执行的文件！');
                }
                if ($type === 'img2') {
                    $exts = array_unique(array_merge($exts, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp']));
                }
                $post['storage.allow_exts'] = join(',', $exts);
            }
            foreach ($post as $name => $value) {
                sysconf($name, $value);
            }
            sysoplog('系统配置管理', '修改系统存储参数');
            $this->success('修改文件存储成功！');
        }
    }
}
