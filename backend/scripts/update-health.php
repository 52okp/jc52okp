<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
chdir(dirname(__DIR__));
require dirname(__DIR__) . '/vendor/autoload.php';

try {
    $app = \think\admin\service\RuntimeService::init();
    $app->initialize();
    \app\service\SchemaHealth::check(dirname(__DIR__));
    $url = (string)env('HEALTHCHECK_URL', '');
    if ($url !== '') {
        if (parse_url($url, PHP_URL_SCHEME) !== 'https') throw new RuntimeException('HEALTHCHECK_URL must use HTTPS');
        $curl = curl_init($url);
        curl_setopt_array($curl, \app\service\WechatSession::options());
        $body = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        $json = is_string($body) ? json_decode($body, true) : null;
        if ($status !== 200 || !is_array($json) || ($json['code'] ?? null) !== 1) throw new RuntimeException('HTTP health check failed');
    }
    if (!is_file(dirname(__DIR__) . '/public/index.php')) exit(1);
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
