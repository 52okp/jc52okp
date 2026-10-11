<?php

declare(strict_types=1);

namespace app\service;

/** Password handling shared by every administrator password entry point. */
final class AdminPassword
{
    public static function make(string $password): string
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($hash)) {
            throw new \RuntimeException('无法生成密码哈希');
        }
        return $hash;
    }

    public static function validateNew(string $password): bool
    {
        // PASSWORD_DEFAULT currently uses bcrypt; reject inputs it would truncate.
        return strlen($password) >= 12 && strlen($password) <= 72;
    }

    public static function verify(string $password, string $stored): bool
    {
        if (preg_match('/\A[a-f0-9]{32}\z/iD', $stored)) {
            return hash_equals(strtolower($stored), md5($password));
        }
        return password_verify($password, $stored);
    }

    public static function shouldUpgrade(string $stored): bool
    {
        return (bool)preg_match('/\A[a-f0-9]{32}\z/iD', $stored)
            || password_needs_rehash($stored, PASSWORD_DEFAULT);
    }
}
