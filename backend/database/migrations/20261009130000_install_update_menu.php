<?php

declare(strict_types=1);

use think\migration\Migrator;

class InstallUpdateMenu extends Migrator
{
    public function change()
    {
        if ($this->fetchRow("SELECT id FROM system_menu WHERE node='admin/update/index' LIMIT 1")) return;
        $this->table('system_menu')->insert([
            'pid' => 0, 'title' => '教程后端更新', 'icon' => 'layui-icon layui-icon-refresh',
            'node' => 'admin/update/index', 'url' => 'admin/update/index',
            'sort' => 30, 'status' => 1,
        ])->saveData();
    }
}
