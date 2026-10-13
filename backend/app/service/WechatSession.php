<?php

declare(strict_types=1);
namespace app\service;

/** Authentication traffic must never use the legacy HTTP helper. */
final class WechatSession
{
    public static function exchange(string $appid, string $secret, string $code): array
    {
        $url = 'https://api.weixin.qq.com/sns/jscode2session?' . http_build_query([
            'appid' => $appid, 'secret' => $secret, 'js_code' => $code,
            'grant_type' => 'authorization_code',
        ], '', '&', PHP_QUERY_RFC3986);
        $curl = curl_init($url);
        if ($curl === false) throw new \RuntimeException('微信登录暂不可用');
        try {
            curl_setopt_array($curl, self::options());
            $body = curl_exec($curl);
            if ($body === false || curl_getinfo($curl, CURLINFO_HTTP_CODE) !== 200) {
                throw new \RuntimeException('微信登录服务连接失败，请稍后重试');
            }
            $data = json_decode($body, true);
            if (!is_array($data) || !is_string($data['openid'] ?? null) ||
                $data['openid'] === '' || strlen($data['openid']) > 100 || !empty($data['errcode'])) {
                throw new \RuntimeException('微信登录凭证无效，请重试');
            }
            return $data;
        } finally { curl_close($curl); }
    }

    public static function options(): array
    {
        return [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 12];
    }
}
