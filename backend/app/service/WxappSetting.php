<?php

declare(strict_types=1);

namespace app\service;

/** Store longer mini-program copy outside the 2048-byte system_config.value column. */
final class WxappSetting
{
    private const TEXT_KEYS = ['wxapp.follow_text', 'wxapp.privacy_content'];
    private const MAX_FOLLOW_ACCOUNTS = 12;

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

    public static function readFollowAccounts(): array
    {
        $stored = sysdata('wxapp.follow_accounts');
        if (is_array($stored) && isset($stored['items']) && is_array($stored['items'])) {
            $accounts = [];
            foreach ($stored['items'] as $item) {
                if (!is_array($item)) continue;
                $title = $item['title'] ?? '';
                $image = $item['image'] ?? '';
                $description = $item['description'] ?? '';
                if (!is_string($title) || !is_string($image) || !is_string($description)) continue;
                $accounts[] = compact('title', 'image', 'description');
            }
            return $accounts;
        }

        $image = (string)sysconf('wxapp.follow_image|raw');
        $description = self::readText('wxapp.follow_text');
        if ($image === '' && $description === '') return [];
        return [['title' => '公众号', 'image' => $image, 'description' => $description]];
    }

    public static function normalizeFollowAccounts(array $accounts): array
    {
        if (count($accounts) > self::MAX_FOLLOW_ACCOUNTS) {
            throw new \InvalidArgumentException('最多可设置 12 个公众号');
        }
        $normalized = [];
        foreach ($accounts as $account) {
            if (!is_array($account) || array_diff(array_keys($account), ['title', 'image', 'description'])) {
                throw new \InvalidArgumentException('公众号信息格式不正确');
            }
            $title = $account['title'] ?? '';
            $image = $account['image'] ?? '';
            $description = $account['description'] ?? '';
            if (!is_string($title) || !is_string($image) || !is_string($description)) {
                throw new \InvalidArgumentException('公众号信息格式不正确');
            }
            $title = trim($title);
            $image = trim($image);
            $description = trim($description);
            if ($title === '' && $image === '' && $description === '') continue;
            if ($title === '' || ($image === '' && $description === '') ||
                strlen($title) > 150 || strlen($image) > 500 || strlen($description) > 1500) {
                throw new \InvalidArgumentException('请填写公众号名称、二维码或说明，并检查内容长度');
            }
            $normalized[] = compact('title', 'image', 'description');
        }
        $encoded = json_encode([['items' => $normalized]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded) || strlen($encoded) > 60000) {
            throw new \InvalidArgumentException('公众号信息总长度过长');
        }
        return $normalized;
    }

    public static function saveFollowAccounts(array $accounts): void
    {
        if (!sysdata('wxapp.follow_accounts', ['items' => self::normalizeFollowAccounts($accounts)])) {
            throw new \RuntimeException('公众号信息保存失败');
        }
    }

    private static function assertTextKey(string $key): void
    {
        if (!in_array($key, self::TEXT_KEYS, true)) {
            throw new \InvalidArgumentException('不支持的小程序设置字段');
        }
    }
}
