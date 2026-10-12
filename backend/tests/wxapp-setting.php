<?php

declare(strict_types=1);

namespace app\service {
    function sysdata(string $name, $value = null)
    {
        if ($value === null) return $GLOBALS['wxapp_test_data'][$name] ?? [];
        $GLOBALS['wxapp_test_data'][$name] = $value;
        return true;
    }

    function sysconf(string $name): string
    {
        return $GLOBALS['wxapp_test_config'][$name] ?? '';
    }
}

namespace {
    require dirname(__DIR__) . '/vendor/autoload.php';

    $GLOBALS['wxapp_test_data'] = [];
    $GLOBALS['wxapp_test_config'] = [
        'wxapp.follow_text|raw' => '旧的关注说明',
        'wxapp.privacy_content|raw' => '旧的隐私政策',
    ];

    if (\app\service\WxappSetting::readText('wxapp.privacy_content') !== '旧的隐私政策') {
        throw new \RuntimeException('Legacy privacy setting fallback failed');
    }

    $policy = file_get_contents(dirname(__DIR__) . '/PRIVACY_POLICY_ZH.txt');
    $encoded = json_encode([$policy], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($policy) || strlen($policy) <= 2048 || !is_string($encoded) || strlen($encoded) > 65535) {
        throw new \RuntimeException('Privacy policy size does not reproduce the old column limit');
    }
    \app\service\WxappSetting::saveText('wxapp.privacy_content', $policy);
    if (\app\service\WxappSetting::readText('wxapp.privacy_content') !== $policy) {
        throw new \RuntimeException('Long privacy policy was not preserved');
    }

    \app\service\WxappSetting::saveText('wxapp.follow_text', '');
    if (\app\service\WxappSetting::readText('wxapp.follow_text') !== '') {
        throw new \RuntimeException('Empty saved text must not fall back to legacy config');
    }

    try {
        \app\service\WxappSetting::saveText('wxapp.follow_image', 'bad');
        throw new \RuntimeException('Unexpected setting field was accepted');
    } catch (\InvalidArgumentException $expected) {
    }

    echo "Long mini-program settings and legacy fallback passed\n";
}
