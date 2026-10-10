<?php

declare(strict_types=1);

namespace app\service;

use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * One-at-a-time, file-only update installer. Database migrations require a
 * separate, backed-up release procedure and are rejected here.
 */
final class UpdateInstall
{
    private string $root;
    private string $work;
    private array $journal = [];

    public function __construct(?string $root = null)
    {
        $this->root = $root ?: dirname(__DIR__, 2);
        $this->work = $this->root . '/runtime/update-jobs';
    }

    public function start(string $releaseId): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $releaseId)) {
            throw new RuntimeException('发布 ID 无效');
        }
        $php = $this->phpCli();
        $this->ensureDir($this->work);
        $lock = $this->lock();
        try {
            $active = $this->activeId();
            if ($active !== '') {
                $previous = $this->read($active);
                if (in_array($previous['state'] ?? '', ['queued', 'running', 'recovery_required'], true)) {
                    throw new RuntimeException('已有更新任务正在运行或需要恢复');
                }
            }
            $id = bin2hex(random_bytes(16));
            $this->ensureDir($this->jobDir($id));
            $this->write($id, [
                'id' => $id, 'release_id' => $releaseId, 'state' => 'queued',
                'phase' => '排队', 'completed' => 0, 'total' => 0,
                'message' => '正在启动后台安装任务', 'updated_at' => time(),
            ]);
            $this->atomicWrite($this->work . '/active', $id);
            try {
                $this->spawn($php, $id);
            } catch (Throwable $e) {
                $this->update($id, 'failed', '启动失败', 0, 0, $e->getMessage());
                throw $e;
            }
            return $this->read($id);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function status(): ?array
    {
        $id = $this->activeId();
        if ($id === '') return null;
        $data = $this->read($id);
        $age = time() - (int)($data['updated_at'] ?? 0);
        $data['stale'] = ($data['state'] ?? '') === 'queued' ? $age > 60 :
            (($data['state'] ?? '') === 'running' && $age > 600);
        unset($data['release_id']);
        return $data;
    }

    public function run(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $id) || $id !== $this->activeId()) {
            throw new RuntimeException('任务不存在');
        }
        $lock = $this->lock(true);
        $applied = [];
        $journalReady = false;
        try {
            $data = $this->read($id);
            if (($data['state'] ?? '') !== 'queued') throw new RuntimeException('任务状态无效');
            $this->update($id, 'running', '下载与校验', 0, 0, '正在下载并验证发布包');
            $stage = $this->jobDir($id) . '/stage';
            $this->ensureDir($stage);
            $verified = (new UpdateCenter())->stage($data['release_id'], $stage);
            $this->update($id, 'running', '预检', 0, 0, '正在检查文件、磁盘和数据库迁移');
            $entries = $this->prepare($id, $verified['package_path']);
            $total = count($entries);
            if ($total === 0) throw new RuntimeException('更新包没有可安装文件');
            $this->update($id, 'running', '备份', 0, $total, '正在备份将被更新的文件');
            $this->backup($id, $entries);
            $journalReady = true;
            $this->update($id, 'running', '安装', 0, $total, '正在替换程序文件');
            foreach ($entries as $index => $entry) {
                $this->replace($id, $entry);
                $applied[] = $entry;
                $this->update($id, 'running', '安装', $index + 1, $total, '已安装 ' . ($index + 1) . ' / ' . $total . ' 个文件');
            }
            $this->update($id, 'running', '健康检查', 0, 0, '正在检查安装后的程序');
            $this->healthCheck();
            $this->setVersion($id, (string)$verified['release']['version']);
            $this->update($id, 'success', '完成', $total, $total, '安装完成，当前版本 ' . $verified['release']['version']);
        } catch (Throwable $e) {
            if ($journalReady) {
                try {
                    $this->update($id, 'running', '恢复', 0, count($applied), '安装失败，正在恢复原文件');
                    $this->restore($id, $applied);
                    $envBackup = $this->jobDir($id) . '/env.backup';
                    if (is_file($envBackup) && !copy($envBackup, $this->root . '/.env')) {
                        throw new RuntimeException('恢复 .env 失败');
                    }
                    $this->update($id, 'failed', '已恢复', 0, 0, '安装失败，原文件已恢复：' . $e->getMessage());
                } catch (Throwable $rollback) {
                    $this->update($id, 'recovery_required', '需要恢复', 0, 0, '自动恢复失败；请保留 runtime/update-jobs 并人工检查');
                }
            } else {
                $this->update($id, 'failed', '失败', 0, 0, $e->getMessage());
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Explicit CLI recovery after a worker or host was interrupted. */
    public function recover(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $id) || $id !== $this->activeId()) {
            throw new RuntimeException('任务不存在');
        }
        $lock = $this->lock();
        try {
            $task = $this->read($id);
            if (!in_array($task['state'] ?? '', ['queued', 'running', 'recovery_required'], true)) {
                throw new RuntimeException('任务无需恢复');
            }
            $journalPath = $this->jobDir($id) . '/journal.json';
            if (is_file($journalPath)) {
                $journal = json_decode((string)file_get_contents($journalPath), true, 512, JSON_THROW_ON_ERROR);
                $applied = [];
                foreach ($journal as $name => $oldFile) {
                    $target = $this->target($name);
                    $source = $this->jobDir($id) . '/extract/' . $name;
                    $currentHash = is_file($target) ? hash_file('sha256', $target) : null;
                    if ($currentHash === ($oldFile['sha256'] ?? null)) continue;
                    if (!is_file($source) || $currentHash !== hash_file('sha256', $source)) {
                        throw new RuntimeException('服务器文件在中断后发生其他变更，请人工检查：' . $name);
                    }
                    $applied[] = $name;
                }
                $this->restore($id, $applied);
            }
            $envBackup = $this->jobDir($id) . '/env.backup';
            if (is_file($envBackup) && !copy($envBackup, $this->root . '/.env')) {
                throw new RuntimeException('恢复 .env 失败');
            }
            $this->update($id, 'failed', '已恢复', 0, 0, '中断任务的原文件已恢复，请核对站点后重新安装');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function prepare(string $id, string $package): array
    {
        $zip = new ZipArchive();
        if ($zip->open($package) !== true) throw new RuntimeException('更新包无法打开');
        $entries = [];
        $unpacked = 0;
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name === 'update-version.json') continue;
                $target = $this->target($name);
                if (is_link($target)) throw new RuntimeException('目标包含符号链接：' . $name);
                if (str_starts_with($name, 'database/migrations/')) {
                    if (!is_file($target) || hash('sha256', $zip->getFromIndex($i)) !== hash_file('sha256', $target)) {
                        throw new RuntimeException('更新包包含数据库迁移，请先备份数据库并采用人工发布流程');
                    }
                    continue;
                }
                $stat = $zip->statIndex($i);
                $unpacked += (int)$stat['size'];
                $entries[] = $name;
            }
            $free = disk_free_space($this->root);
            if ($free !== false && $free < $unpacked * 3 + 52428800) {
                throw new RuntimeException('磁盘空间不足，无法暂存和备份更新文件');
            }
            $extract = $this->jobDir($id) . '/extract';
            $this->ensureDir($extract);
            foreach ($entries as $index => $name) {
                $input = $zip->getStream($name);
                if ($input === false) throw new RuntimeException('无法读取更新包文件');
                $path = $extract . '/' . $name;
                $this->ensureDir(dirname($path));
                $output = fopen($path, 'wb');
                if ($output === false) throw new RuntimeException('无法创建暂存文件');
                $copied = stream_copy_to_stream($input, $output);
                fclose($input);
                fclose($output);
                if ($copied === false || $copied !== $zip->statName($name)['size']) throw new RuntimeException('无法完整解压更新文件');
                if (str_ends_with($name, '.php')) $this->lint($path);
                if (($index + 1) % 10 === 0 || $index + 1 === count($entries)) {
                    $this->update($id, 'running', '预检', $index + 1, count($entries), '已预检 ' . ($index + 1) . ' / ' . count($entries) . ' 个文件');
                }
            }
            return $entries;
        } finally {
            $zip->close();
        }
    }

    private function backup(string $id, array $entries): void
    {
        $journal = [];
        foreach ($entries as $index => $name) {
            $target = $this->target($name);
            $exists = is_file($target);
            if (file_exists($target) && !$exists) throw new RuntimeException('目标不是普通文件：' . $name);
            $parent = dirname($target);
            $this->checkParent($parent);
            if ($exists && !is_readable($target)) throw new RuntimeException('无法读取旧文件：' . $name);
            if (is_dir($parent) && !is_writable($parent)) throw new RuntimeException('目标目录不可写：' . $name);
            if ($exists) {
                $backup = $this->jobDir($id) . '/backup/' . $name;
                $this->ensureDir(dirname($backup));
                if (!copy($target, $backup)) throw new RuntimeException('备份失败：' . $name);
                $journal[$name] = ['sha256' => hash_file('sha256', $backup), 'mode' => fileperms($target) & 0777];
            } else {
                $journal[$name] = null;
            }
            if (($index + 1) % 10 === 0 || $index + 1 === count($entries)) {
                $this->update($id, 'running', '备份', $index + 1, count($entries), '已备份 ' . ($index + 1) . ' / ' . count($entries) . ' 个文件');
            }
        }
        $this->journal = $journal;
        $this->atomicWrite($this->jobDir($id) . '/journal.json', json_encode($journal, JSON_THROW_ON_ERROR));
    }

    private function replace(string $id, string $name): void
    {
        $source = $this->jobDir($id) . '/extract/' . $name;
        $target = $this->target($name);
        $actual = is_file($target) ? hash_file('sha256', $target) : null;
        if (!array_key_exists($name, $this->journal) || $actual !== ($this->journal[$name]['sha256'] ?? null)) {
            throw new RuntimeException('目标文件在备份后已变化：' . $name);
        }
        $this->ensureDir(dirname($target));
        $temporary = $target . '.update-' . $id;
        if (!copy($source, $temporary)) throw new RuntimeException('写入更新文件失败：' . $name);
        if (is_file($target)) chmod($temporary, fileperms($target) & 0777);
        elseif ($name === 'think') chmod($temporary, 0755);
        if (!rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException('替换更新文件失败：' . $name);
        }
    }

    private function restore(string $id, array $applied): void
    {
        $journal = json_decode((string)file_get_contents($this->jobDir($id) . '/journal.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach (array_reverse($applied) as $name) {
            $target = $this->target($name);
            if ($journal[$name] === null) {
                if (is_file($target) && !unlink($target)) throw new RuntimeException('无法删除新文件');
            } else {
                $backup = $this->jobDir($id) . '/backup/' . $name;
                if (!is_file($backup) || hash_file('sha256', $backup) !== $journal[$name]['sha256']) throw new RuntimeException('备份文件损坏');
                $temp = $target . '.restore-' . $id;
                if (is_file($temp)) @unlink($temp);
                if (!copy($backup, $temp) || !rename($temp, $target)) throw new RuntimeException('恢复旧文件失败');
                chmod($target, $journal[$name]['mode']);
            }
        }
    }

    private function setVersion(string $id, string $version): void
    {
        $file = $this->root . '/.env';
        if (!is_file($file) || !is_writable($file)) throw new RuntimeException('服务器 .env 不可写，无法更新版本号');
        $content = (string)file_get_contents($file);
        if (!preg_match('/^APP_VERSION=\d+\.\d+\.\d+[ \t]*\r?$/m', $content)) throw new RuntimeException('.env 中的 APP_VERSION 无效');
        $new = preg_replace('/^APP_VERSION=\d+\.\d+\.\d+[ \t]*\r?$/m', 'APP_VERSION=' . $version, $content, 1);
        $backup = $this->jobDir($id) . '/env.backup';
        if (!copy($file, $backup)) throw new RuntimeException('无法备份 .env');
        try {
            $this->atomicWrite($file, $new);
        } catch (Throwable $e) {
            copy($backup, $file);
            throw $e;
        }
    }

    private function healthCheck(): void
    {
        if (!is_file($this->root . '/vendor/autoload.php') || !is_file($this->root . '/public/index.php') ||
            !is_file($this->root . '/scripts/update-health.php')) {
            throw new RuntimeException('程序入口或依赖文件缺失');
        }
        $process = proc_open([$this->phpCli(), $this->root . '/scripts/update-health.php'], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes, $this->root);
        if (!is_resource($process)) throw new RuntimeException('无法启动健康检查');
        fclose($pipes[0]);
        $exitCode = -1;
        for ($i = 0; $i < 300; $i++) {
            $status = proc_get_status($process);
            if (!$status['running']) { $exitCode = $status['exitcode']; break; }
            usleep(100000);
        }
        if ($exitCode === -1) proc_terminate($process);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        if ($exitCode !== 0) throw new RuntimeException('安装后健康检查未通过，已开始恢复文件');
    }

    private function lint(string $file): void
    {
        $command = escapeshellarg($this->phpCli()) . ' -l ' . escapeshellarg($file);
        $output = [];
        $status = 0;
        exec($command, $output, $status);
        if ($status !== 0) throw new RuntimeException('更新包 PHP 语法错误：' . basename($file));
    }

    private function target(string $name): string
    {
        if ($name === '' || str_contains($name, '\\') || str_starts_with($name, '/') ||
            preg_match('~(^|/)\.\.?(/|$)|^[A-Za-z]:~', $name)) {
            throw new RuntimeException('非法更新路径');
        }
        $parts = explode('/', $name);
        $path = $this->root;
        foreach ($parts as $part) {
            $path .= '/' . $part;
            if (is_link($path)) throw new RuntimeException('更新路径含符号链接');
        }
        return $path;
    }

    private function checkParent(string $path): void
    {
        while ($path !== $this->root && !is_dir($path)) $path = dirname($path);
        if (!is_writable($path)) throw new RuntimeException('目标目录不可写');
    }

    private function spawn(string $php, string $id): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('proc_open')) {
            throw new RuntimeException('服务器无法自动启动更新任务，需要 Linux 与 proc_open');
        }
        $script = $this->root . '/scripts/update-worker.php';
        if (!is_file($script)) throw new RuntimeException('后台更新执行器缺失');
        $shell = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($id)
            . ' </dev/null >' . escapeshellarg($this->jobDir($id) . '/worker.log') . ' 2>&1 &';
        $process = proc_open(['/bin/sh', '-c', $shell], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes, $this->root);
        if (!is_resource($process)) throw new RuntimeException('后台更新执行器启动失败');
        foreach ($pipes as $pipe) fclose($pipe);
        if (proc_close($process) !== 0) throw new RuntimeException('后台更新执行器启动失败');
    }

    private function phpCli(): string
    {
        if (!function_exists('proc_open')) {
            throw new RuntimeException('PHP-FPM 禁用了 proc_open，无法启动后台更新任务');
        }
        $configured = (string)(getenv('UPDATE_PHP_CLI') ?: env('UPDATE_PHP_CLI', ''));
        $candidates = array_filter([
            $configured, PHP_SAPI === 'cli' ? PHP_BINARY : null,
            PHP_BINDIR . '/php', PHP_BINDIR . '/php.exe',
            dirname(PHP_BINDIR) . '/bin/php', dirname(PHP_BINDIR) . '/bin/php.exe',
        ]);
        foreach ($candidates as $candidate) {
            if (preg_match('/[\x00-\x1f]/', $candidate) || !preg_match('~^(?:/|[A-Za-z]:[\\\\/])~', $candidate)) continue;
            if (PHP_SAPI === 'cli' && $candidate === PHP_BINARY) return $candidate;
            if ($this->probeCli($candidate)) return $candidate;
        }
        throw new RuntimeException($configured !== ''
            ? '配置的 PHP CLI 无法由网站 PHP 进程启动，请检查路径、执行权限和 PHP-FPM 日志'
            : '未找到 PHP CLI，请在 .env 中设置 UPDATE_PHP_CLI');
    }

    private function probeCli(string $candidate): bool
    {
        // Pipes avoid opening /dev/null from PHP-FPM, which can be outside
        // a site's open_basedir even when the CLI itself is executable.
        $process = @proc_open([$candidate, '-v'], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) return false;
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return proc_close($process) === 0;
    }

    private function lock(bool $wait = false)
    {
        $this->ensureDir($this->work);
        $handle = fopen($this->work . '/install.lock', 'c+');
        if (!$handle || !flock($handle, $wait ? LOCK_EX : LOCK_EX | LOCK_NB)) throw new RuntimeException('更新任务正在运行');
        return $handle;
    }

    private function activeId(): string
    {
        $file = $this->work . '/active';
        $id = is_file($file) ? trim((string)file_get_contents($file)) : '';
        return preg_match('/^[a-f0-9]{32}$/', $id) ? $id : '';
    }

    private function jobDir(string $id): string
    {
        return $this->work . '/' . $id;
    }

    private function read(string $id): array
    {
        $file = $this->jobDir($id) . '/status.json';
        if (!is_file($file)) throw new RuntimeException('更新任务状态丢失');
        return json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    }

    private function update(string $id, string $state, string $phase, int $completed, int $total, string $message): void
    {
        $data = $this->read($id);
        $data['state'] = $state;
        $data['phase'] = $phase;
        $data['completed'] = $completed;
        $data['total'] = $total;
        $data['message'] = $message;
        $data['updated_at'] = time();
        $this->write($id, $data);
    }

    private function write(string $id, array $data): void
    {
        $this->atomicWrite($this->jobDir($id) . '/status.json', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function atomicWrite(string $path, string $content): void
    {
        $temp = $path . '.tmp-' . bin2hex(random_bytes(5));
        $mode = is_file($path) ? fileperms($path) & 0777 : 0600;
        if (file_put_contents($temp, $content, LOCK_EX) === false) {
            @unlink($temp);
            throw new RuntimeException('无法写入更新任务文件');
        }
        chmod($temp, $mode);
        if (!rename($temp, $path)) {
            @unlink($temp);
            throw new RuntimeException('无法替换更新任务文件');
        }
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('无法创建更新暂存目录');
        }
    }
}
