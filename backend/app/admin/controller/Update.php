<?php

declare(strict_types=1);

namespace app\admin\controller;

use app\service\UpdateCenter;
use think\admin\Controller;

/** Server-side, read-only update check. Installation is disabled until a verified deployment layout exists. */
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
        $this->available = false;
        $this->releaseVersion = '';
        $this->fromVersion = '';
        $this->releaseNotes = '';
        $this->checkMessage = '';
        try {
            $result = (new UpdateCenter())->check();
            $this->available = $result['update_available'];
            if ($this->available) {
                $release = $result['release'];
                $this->releaseVersion = (string)$release['version'];
                $this->fromVersion = (string)$release['from'];
                $this->releaseNotes = nl2br(htmlspecialchars((string)($release['notes'] ?? ''), ENT_QUOTES, 'UTF-8'));
            } else {
                $this->checkMessage = ($result['check_status'] ?? '') === 'not_found'
                    ? '更新中心返回 404：未找到可用发布，请核对项目 ID 与发布状态。'
                    : '当前没有可用更新';
            }
        } catch (\Throwable $e) {
            $this->checkMessage = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        }
        $this->fetch();
    }
}
