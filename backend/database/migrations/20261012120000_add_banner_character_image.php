<?php

declare(strict_types=1);

use think\migration\Migrator;

/** Give each home banner its own transparent foreground character. */
class AddBannerCharacterImage extends Migrator
{
    public function change()
    {
        $table = $this->table('banner');
        if (!$table->hasColumn('character_image')) {
            $table->addColumn('character_image', 'string', [
                'limit' => 500,
                'default' => '',
                'null' => true,
                'comment' => '首页头图透明人物 PNG',
            ]);
            $table->update();
        }
    }
}
