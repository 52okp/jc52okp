<?php

declare(strict_types=1);

namespace app\admin;

use think\admin\contract\StorageInterface;
use think\admin\contract\StorageUsageTrait;
use think\admin\Exception;
use think\admin\model\SystemFile;

/** Yutu 图床服务端代理：Token 只在 PHP 进程中使用。 */
class Img2Storage implements StorageInterface
{
    use StorageUsageTrait;

    private const API = 'https://img2.mm-8.cn/api/v1';

    public function set(string $name, string $file, bool $safe = false, ?string $attname = null): array
    {
        if ($safe) {
            throw new Exception('私有文件不能上传到公开图床。');
        }
        $temp = tempnam(sys_get_temp_dir(), 'img2-');
        if ($temp === false) {
            throw new Exception('无法创建图片上传临时文件。');
        }
        try {
            if (file_put_contents($temp, $file) !== strlen($file)) {
                throw new Exception('无法写入图片上传临时文件。');
            }
            $filename = basename(str_replace('\\', '/', $attname ?: $name));
            $mime = function_exists('mime_content_type') ? mime_content_type($temp) : false;
            $fields = ['file' => new \CURLFile($temp, $mime ?: 'application/octet-stream', $filename)];
            $strategy = (int) sysconf('storage.img2_strategy_id|raw');
            if ($strategy > 0) {
                $fields['strategy_id'] = (string) $strategy;
            }
            [$status, $body] = $this->request('POST', '/upload', $fields);
            $result = json_decode($body, true);
            if ($status !== 200 && $status !== 201) {
                throw new Exception('图床上传失败（HTTP ' . $status . '）：' . $this->message($result));
            }
            if (!is_array($result) || ($result['status'] ?? false) !== true) {
                throw new Exception('图床上传失败：' . $this->message($result));
            }
            $url = $result['data']['links']['url'] ?? '';
            $key = $result['data']['key'] ?? '';
            if (!is_string($url) || !filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https' || !is_string($key) || $key === '') {
                throw new Exception('图床未返回有效的 HTTPS 图片地址或图片密钥。');
            }
            return ['url' => $url, 'key' => $key, 'file' => $url];
        } finally {
            @unlink($temp);
        }
    }

    public function get(string $name, bool $safe = false): string
    {
        $url = $this->url($name, $safe);
        if ($url === '') {
            return '';
        }
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
        $body = curl_exec($curl);
        curl_close($curl);
        return is_string($body) ? $body : '';
    }

    public function del(string $name, bool $safe = false): bool
    {
        if ($safe || $name === '') {
            return false;
        }
        try {
            [$status, $body] = $this->request('DELETE', '/images/' . rawurlencode($name));
            $result = json_decode($body, true);
            return $status === 200 && is_array($result) && ($result['status'] ?? false) === true;
        } catch (\Throwable $exception) {
            return false;
        }
    }

    public function has(string $name, bool $safe = false): bool
    {
        return $this->url($name, $safe) !== '';
    }

    public function url(string $name, bool $safe = false, ?string $attname = null): string
    {
        if ($safe) {
            return '';
        }
        $file = SystemFile::mk()->where(['type' => 'img2', 'xkey' => $name, 'status' => 2])->findOrEmpty();
        return $file->isEmpty() ? '' : (string) $file->getAttr('xurl');
    }

    public function path(string $name, bool $safe = false): string
    {
        return $this->url($name, $safe);
    }

    public function info(string $name, bool $safe = false, ?string $attname = null): array
    {
        $url = $this->url($name, $safe);
        return $url === '' ? [] : ['url' => $url, 'key' => $name, 'file' => $url];
    }

    public function upload(): string
    {
        return url('admin/api.upload/file', [], false, true)->build();
    }

    public static function region(): array
    {
        return [];
    }

    private function request(string $method, string $path, ?array $fields = null): array
    {
        $token = trim((string) sysconf('storage.img2_token|raw'));
        if ($token === '') {
            throw new Exception('请先配置 Yutu 图床 API Token。');
        }
        $curl = curl_init(self::API . $path);
        $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $token],
            CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2];
        if ($fields !== null) {
            $options[CURLOPT_POSTFIELDS] = $fields;
        }
        curl_setopt_array($curl, $options);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if (!is_string($body)) {
            throw new Exception('图床接口连接失败：' . $error);
        }
        return [$status, $body];
    }

    private function message($result): string
    {
        $message = is_array($result) ? ($result['message'] ?? '') : '';
        return mb_substr(strip_tags((string) ($message ?: '请检查 Token、存储策略和图床接口状态。')), 0, 160);
    }
}
