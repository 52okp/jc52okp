<?php

declare(strict_types=1);
namespace app\service;

final class AdminSecurity
{
    public static function csrf(): string
    {
        $session = app()->session;
        $token = (string)$session->get('admin_csrf', '');
        if ($token === '') $session->set('admin_csrf', $token = bin2hex(random_bytes(32)));
        return $token;
    }

    public static function stamp(array $user): string
    {
        return hash('sha256', json_encode([(int)($user['id'] ?? 0), $user['username'] ?? '',
            $user['password'] ?? '', (int)($user['status'] ?? 0), (int)($user['is_deleted'] ?? 1)]));
    }

    public static function validUser(array $user, string $stamp): bool
    {
        return !empty($user['id']) && !empty($user['status']) && empty($user['is_deleted']) &&
            $stamp !== '' && hash_equals(self::stamp($user), $stamp);
    }

    public static function sameOrigin(string $origin, string $expected): bool
    {
        $parts = static function (string $url): ?array {
            $p = parse_url($url);
            if (!$p || !isset($p['scheme'], $p['host']) || isset($p['user']) ||
                !in_array(strtolower($p['scheme']), ['http', 'https'], true)) return null;
            return [strtolower($p['scheme']), strtolower($p['host']), $p['port'] ?? (strtolower($p['scheme']) === 'https' ? 443 : 80)];
        };
        return $parts($origin) !== null && $parts($origin) === $parts($expected);
    }

    public static function requiresPost(string $controller, string $action): bool
    {
        $node = strtolower(str_replace('.', '/', $controller)) . '/' . strtolower($action);
        return in_array(strtolower($action), ['state', 'remove', 'redo', 'clean', 'distinct', 'out'], true) ||
            in_array($node, ['oplog/clear', 'api/system/push', 'api/system/clear', 'api/system/debug',
                'api/system/editor', 'api/system/config', 'api/queue/start', 'api/queue/stop', 'api/plugs/optimize',
                'api/upload/done', 'api/upload/file', 'update/install'], true);
    }
}
