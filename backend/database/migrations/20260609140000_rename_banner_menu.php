<?php

declare(strict_types=1);

use think\migration\Migrator;

@set_time_limit(0);

/**
 * 把「轮播图」菜单改名为「首页头图」
 */
class RenameBannerMenu extends Migrator
{
    public function getName(): string
    {
        return 'RenameBannerMenu';
    }

    public function change()
    {
        $this->execute("UPDATE system_menu SET title = '首页头图' WHERE node = 'admin/banner/index'");
    }
}
