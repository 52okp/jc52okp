<?php

declare(strict_types=1);

use think\migration\Migrator;

@set_time_limit(0);

/**
 * 允许上传 webp 格式（把 webp 追加进 storage.allow_exts 配置）
 */
class AllowWebpUpload extends Migrator
{
    public function getName(): string
    {
        return 'AllowWebpUpload';
    }

    public function change()
    {
        $row = $this->fetchRow("SELECT id, value FROM system_config WHERE type = 'storage' AND name = 'allow_exts' LIMIT 1");
        if (!empty($row)) {
            $exts = array_values(array_filter(array_map('trim', explode(',', (string)$row['value']))));
            if (!in_array('webp', $exts, true)) {
                $exts[] = 'webp';
                $val = implode(',', $exts);
                $this->execute("UPDATE system_config SET value = '{$val}' WHERE id = " . intval($row['id']));
            }
        } else {
            // 不存在则插入一个含常见图片类型的默认值
            $this->table('system_config')->insert([
                'type' => 'storage', 'name' => 'allow_exts', 'value' => 'jpg,jpeg,png,gif,bmp,webp',
            ])->saveData();
        }
    }
}
