<?php

declare(strict_types=1);

namespace app\service;

/** Store longer mini-program copy outside the 2048-byte system_config.value column. */
final class WxappSetting
{
    private const TEXT_KEYS = ['wxapp.follow_text', 'wxapp.privacy_content'];

    public static function readText(string $key): string
    {
        self::assertTextKey($key);
        $stored = sysdata($key);
        return is_string($stored) ? $stored : (string)sysconf($key . '|raw');
    }

    public static function saveText(string $key, string $value): void
    {
        self::assertTextKey($key);
        if (!sysdata($key, $value)) {
            throw new \RuntimeException('小程序设置保存失败');
        }
    }

    private static function assertTextKey(string $key): void
    {
        if (!in_array($key, self::TEXT_KEYS, true)) {
            throw new \InvalidArgumentException('不支持的小程序设置字段');
        }
    }
}
