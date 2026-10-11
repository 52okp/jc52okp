<?php

declare(strict_types=1);

namespace app\service;

/** The database keeps only a digest; the mini program receives the bearer token once. */
final class MemberToken
{
    public const LIFETIME = 30 * 86400;

    public static function issue(): string
    {
        return sprintf('%08x', time()) . bin2hex(random_bytes(28));
    }

    public static function isCurrent(string $token): bool
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $token)) {
            return false;
        }
        $issued = hexdec(substr($token, 0, 8));
        return $issued <= time() && $issued > time() - self::LIFETIME;
    }

    public static function digest(string $token): string
    {
        return hash('sha256', $token);
    }
}
