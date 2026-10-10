<?php

declare(strict_types=1);

namespace app\service;

use RuntimeException;
use ZipArchive;

final class UpdateNotFound extends RuntimeException {}

/** Server-only OSS client and package preflight. It never installs a package. */
final class UpdateCenter
{
    private string $origin;
    private string $project;
    private string $token;
    private string $current;

    public function __construct()
    {
        $this->origin = rtrim((string)(getenv('OSS_ORIGIN') ?: env('OSS_ORIGIN', 'https://app.52okp.com')), '/');
        $this->project = (string)(getenv('OSS_PROJECT') ?: env('OSS_PROJECT', ''));
        $this->token = (string)(getenv('OSS_TOKEN') ?: env('OSS_TOKEN', ''));
        $this->current = (string)(getenv('APP_VERSION') ?: env('APP_VERSION', ''));
        $parts = parse_url($this->origin);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['path']) || isset($parts['query']) ||
            !preg_match('/^[a-z][a-z0-9-]{1,47}$/', $this->project) ||
            !preg_match('/^[a-f0-9]{64}$/', $this->token) ||
            !preg_match('/^\d+\.\d+\.\d+$/', $this->current)) {
            throw new RuntimeException('更新中心尚未配置项目、只读令牌或当前版本');
        }
    }

    public function check(): array
    {
        $path = $this->prefix() . '/updates?current_version=' . rawurlencode($this->current) . '&channel=stable';
        try { $data = $this->jsonGet($path); }
        catch (UpdateNotFound $e) {
            return ['current_version' => $this->current, 'update_available' => false,
                'release' => null, 'check_status' => 'not_found'];
        }
        if (($data['project'] ?? '') !== $this->project || !array_key_exists('update_available', $data)) {
            throw new RuntimeException('更新中心响应与项目不匹配');
        }
        if (!empty($data['update_available'])) $this->validateRelease($data['release'] ?? []);
        return [
            'current_version' => $this->current,
            'update_available' => (bool)$data['update_available'],
            'release' => $data['release'] ?? null,
            'check_status' => 'ok',
        ];
    }

    /** Downloads and validates one immutable release ID into a private staging directory. */
    public function stage(string $releaseId, string $stagingDir): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $releaseId)) throw new RuntimeException('发布 ID 无效');
        if (!is_dir($stagingDir) || !is_writable($stagingDir)) throw new RuntimeException('暂存目录不可写');
        $stageRoot = realpath($stagingDir);
        $publicRoot = realpath(dirname(__DIR__, 2) . '/public');
        if (!$stageRoot || ($publicRoot && ($stageRoot === $publicRoot || str_starts_with($stageRoot, $publicRoot . DIRECTORY_SEPARATOR)))) {
            throw new RuntimeException('暂存目录必须位于非公开目录');
        }
        $base = $this->prefix() . '/releases/' . $releaseId;
        $release = $this->jsonGet($base);
        $this->validateRelease($release);
        if ($release['id'] !== $releaseId || $release['from'] !== $this->current) {
            throw new RuntimeException('起始版本或发布 ID 不匹配');
        }
        $manifestAsset = $this->asset($release, 'update-manifest.json');
        $packageName = $this->project . '-update.zip';
        $packageAsset = $this->asset($release, $packageName);
        $manifestPath = $this->download($base . '/manifest', $stagingDir, 1048576);
        $packagePath = null;
        try {
            $this->verifyFile($manifestPath, $manifestAsset);
            $manifest = json_decode((string)file_get_contents($manifestPath), true);
            if (!is_array($manifest) || ($manifest['format'] ?? null) !== 2 ||
                ($manifest['product'] ?? '') !== $this->project ||
                ($manifest['version'] ?? '') !== $release['version'] ||
                ($manifest['from'] ?? '') !== $this->current ||
                ($manifest['package'] ?? '') !== $packageName ||
                ($manifest['size'] ?? null) !== $packageAsset['size'] ||
                ($manifest['sha256'] ?? '') !== $packageAsset['sha256']) {
                throw new RuntimeException('清单与发布信息不一致');
            }
            $packagePath = $this->download($base . '/package', $stagingDir, 314572800);
            $this->verifyFile($packagePath, $packageAsset);
            $this->verifyZip($packagePath, $release['version']);
            return ['release' => $release, 'manifest' => $manifest, 'manifest_path' => $manifestPath, 'package_path' => $packagePath];
        } catch (\Throwable $e) {
            @unlink($manifestPath);
            if ($packagePath) @unlink($packagePath);
            throw $e;
        }
    }

    private function prefix(): string
    {
        return '/api/v1/projects/' . rawurlencode($this->project);
    }

    private function validateRelease(array $release): void
    {
        if (($release['project'] ?? '') !== $this->project ||
            ($release['product'] ?? '') !== $this->project ||
            !preg_match('/^[a-f0-9]{64}$/', (string)($release['id'] ?? '')) ||
            !preg_match('/^\d+\.\d+\.\d+$/', (string)($release['version'] ?? '')) ||
            !preg_match('/^\d+\.\d+\.\d+$/', (string)($release['from'] ?? '')) ||
            ($release['status'] ?? '') !== 'published' ||
            version_compare($release['version'], $this->current, '<=')) {
            throw new RuntimeException('发布信息无效或版本未升级');
        }
    }

    private function asset(array $release, string $name): array
    {
        $assets = $release['assets'] ?? [];
        $asset = $assets[$name] ?? null;
        if (!$asset && is_array($assets)) {
            foreach ($assets as $item) if (($item['name'] ?? '') === $name) $asset = $item;
        }
        if (!is_array($asset) || !is_int($asset['size'] ?? null) || $asset['size'] <= 0 ||
            !preg_match('/^[a-f0-9]{64}$/', (string)($asset['sha256'] ?? ''))) {
            throw new RuntimeException('发布资产元数据缺失');
        }
        return $asset;
    }

    private function jsonGet(string $path): array
    {
        $file = $this->download($path, sys_get_temp_dir(), 1048576);
        try {
            $json = json_decode((string)file_get_contents($file), true);
            if (!is_array($json)) throw new RuntimeException('更新中心返回了无效 JSON');
            return $json;
        } finally { @unlink($file); }
    }

    private function download(string $path, string $dir, int $maxBytes): string
    {
        if (!str_starts_with($path, $this->prefix() . '/')) throw new RuntimeException('下载路径无效');
        $file = tempnam($dir, 'oss-');
        if ($file === false) throw new RuntimeException('无法创建下载暂存文件');
        $handle = fopen($file, 'wb');
        $curl = curl_init($this->origin . $path);
        $bytes = 0;
        curl_setopt_array($curl, [
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->token],
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 120,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use ($handle, $maxBytes, &$bytes): int {
                $bytes += strlen($chunk);
                return $bytes > $maxBytes ? 0 : fwrite($handle, $chunk);
            },
        ]);
        $ok = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        fclose($handle);
        if (!$ok || $status !== 200) {
            @unlink($file);
            if ($bytes > $maxBytes) throw new RuntimeException('更新文件超过允许大小');
            if ($status === 401) throw new RuntimeException('更新中心拒绝项目令牌');
            if ($status === 404) throw new UpdateNotFound('发布不存在或尚未发布');
            throw new RuntimeException($error ? '更新中心网络错误' : '更新中心返回 HTTP ' . $status);
        }
        return $file;
    }

    private function verifyFile(string $path, array $asset): void
    {
        if (filesize($path) !== $asset['size'] || !hash_equals($asset['sha256'], hash_file('sha256', $path))) {
            throw new RuntimeException('更新文件大小或 SHA-256 不匹配');
        }
    }

    private function verifyZip(string $path, string $version): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new RuntimeException('更新包不是有效 ZIP');
        try {
            if ($zip->numFiles > 20000) throw new RuntimeException('更新包文件数超限');
            $seen = [];
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'];
                $key = strtolower($name);
                if (isset($seen[$key]) || str_contains($name, '\\') || str_contains($name, "\0") || str_starts_with($name, '/') ||
                    preg_match('~(^|/)\.\.?(/|$)|^[A-Za-z]:~', $name) || !$this->allowedEntry($name)) {
                    throw new RuntimeException('更新包包含危险或重复路径');
                }
                $seen[$key] = true;
                if (($stat['encryption_method'] ?? ZipArchive::EM_NONE) !== ZipArchive::EM_NONE) {
                    throw new RuntimeException('更新包包含加密文件');
                }
                $stream = $zip->getStreamIndex($i);
                if ($stream === false) throw new RuntimeException('更新包包含无法读取或加密的文件');
                fclose($stream);
                $zip->getExternalAttributesIndex($i, $os, $attr);
                if ($os === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) {
                    throw new RuntimeException('更新包包含符号链接');
                }
                $total += (int)$stat['size'];
                if ($total > 524288000 || ($stat['size'] > 10485760 && $stat['comp_size'] > 0 && $stat['size'] / $stat['comp_size'] > 100)) {
                    throw new RuntimeException('更新包解压大小超限');
                }
            }
            $meta = json_decode((string)$zip->getFromName('update-version.json'), true);
            if (!is_array($meta) || ($meta['product'] ?? '') !== $this->project || ($meta['version'] ?? '') !== $version) {
                throw new RuntimeException('包内版本与发布不匹配');
            }
        } finally { $zip->close(); }
    }

    private function allowedEntry(string $name): bool
    {
        if (str_ends_with($name, '/')) return false;
        if (preg_match('~(^|/)(\.env|\.git|node_modules|runtime|uploads?|backup|sqlite\.db)(/|$)~i', $name)) return false;
        if (in_array($name, ['update-version.json', 'public/index.php', 'public/router.php', 'public/robots.txt', 'think', 'composer.json', 'composer.lock', 'scripts/update-worker.php', 'scripts/update-health.php'], true)) return true;
        foreach (['app/', 'config/', 'route/', 'vendor/', 'database/migrations/', 'public/static/'] as $prefix) {
            if (str_starts_with($name, $prefix)) return true;
        }
        return false;
    }
}
