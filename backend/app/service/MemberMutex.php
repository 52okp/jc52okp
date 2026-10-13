<?php

declare(strict_types=1);
namespace app\service;
use think\facade\Db;

/** Serializes identity/relationship writes without modifying legacy data or schema. */
final class MemberMutex
{
    public static function run(string $key, callable $action)
    {
        $db = Db::connect();
        $name = 'member:' . substr(hash('sha256', $db->getConfig('database') . ':' . $key), 0, 54);
        if ($db->getConfig('type') === 'mysql') {
            $row = $db->query('SELECT GET_LOCK(?, 5) AS acquired', [$name]);
            if ((int)($row[0]['acquired'] ?? 0) !== 1) throw new \RuntimeException('操作繁忙，请稍后重试');
            try { return $action(); }
            finally { $db->query('SELECT RELEASE_LOCK(?)', [$name]); }
        }
        // SQLite is a local test/single-host deployment; OS locks also survive worker crashes.
        $dir = app()->getRuntimePath() . 'member-locks';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new \RuntimeException('无法建立操作锁');
        $file = fopen($dir . '/' . hash('sha256', $name) . '.lock', 'c');
        if (!$file) throw new \RuntimeException('无法建立操作锁');
        try {
            $deadline = microtime(true) + 5;
            while (!flock($file, LOCK_EX | LOCK_NB)) {
                if (microtime(true) > $deadline) throw new \RuntimeException('操作繁忙，请稍后重试');
                usleep(10000);
            }
            return $action();
        } finally { flock($file, LOCK_UN); fclose($file); }
    }
}
