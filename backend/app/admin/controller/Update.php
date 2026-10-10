<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\service\UpdateCenter;
use app\service\UpdateInstall;
use think\admin\Controller;
use think\admin\service\AdminService;

/** Server-side update check and background installation. */
class Update extends Controller
{
    /**
     * 检查教程后端更新
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '教程后端更新';
        $this->currentVersion = (string)(getenv('APP_VERSION') ?: env('APP_VERSION', '未配置'));
        $this->canInstall = AdminService::isSuper();
        $this->fetch();
    }

    /**
     * 在当前后台页面异步检查更新，不暴露项目令牌。
     * @auth true
     */
    public function check()
    {
        try {
            $result = (new UpdateCenter())->check();
            $release = $result['update_available'] ? $result['release'] : null;
            $data = [
                'current_version' => (string)$result['current_version'],
                'update_available' => (bool)$result['update_available'],
                'check_status' => (string)$result['check_status'],
                'release' => $release ? [
                    'id' => (string)$release['id'],
                    'version' => (string)$release['version'],
                    'from' => (string)$release['from'],
                    'notes' => (string)($release['notes'] ?? ''),
                ] : null,
            ];
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
        $this->success('检查完成', $data);
    }

    /**
     * 为每次安装尝试生成新的表单令牌，避免失败重试使用已消耗的令牌。
     * @auth true
     */
    public function token()
    {
        if (!$this->request->isGet()) $this->error('只允许 GET 请求');
        if (!AdminService::isSuper()) $this->error('仅超级管理员可以安装更新');
        $this->success('令牌已刷新', ['token' => systoken()]);
    }

    /**
     * 创建后台安装任务。
     * @auth true
     */
    public function install()
    {
        if (!$this->request->isPost()) $this->error('只允许 POST 安装请求');
        $this->_applyFormToken();
        if (!AdminService::isSuper()) $this->error('仅超级管理员可以安装更新');
        try {
            $data = (new UpdateInstall())->start((string)$this->request->post('release_id', ''));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
        $this->success('安装任务已启动', $data);
    }

    /**
     * 查询当前安装任务的真实状态。
     * @auth true
     */
    public function status()
    {
        if (!AdminService::isSuper()) $this->error('仅超级管理员可以查看安装任务');
        try {
            $data = (new UpdateInstall())->status();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
        }
        $this->success('任务状态', $data);
    }
}
