<?php

declare(strict_types=1);

use think\migration\Migrator;

@set_time_limit(0);

/**
 * 增加「小程序设置」菜单
 */
class AddSettingMenu extends Migrator
{
    public function getName(): string
    {
        return 'SettingMenu';
    }

    public function change()
    {
        if (!empty($this->fetchRow("SELECT id FROM system_menu WHERE node = 'admin/setting/index' LIMIT 1"))) {
            return;
        }
        // 放在「内容管理」下
        $content = $this->fetchRow("SELECT id FROM system_menu WHERE title = '内容管理' AND pid = 0 ORDER BY id DESC LIMIT 1");
        $pid = intval($content['id'] ?? 0);
        if ($pid > 0) {
            $this->table('system_menu')->insert([
                'pid' => $pid, 'title' => '小程序设置', 'icon' => 'layui-icon layui-icon-set',
                'node' => 'admin/setting/index', 'url' => 'admin/setting/index', 'sort' => 60, 'status' => 1,
            ])->saveData();
        }
    }
}
