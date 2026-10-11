<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/service/AdminPassword.php';
require dirname(__DIR__) . '/app/service/MemberToken.php';

use app\service\AdminPassword;
use app\service\MemberToken;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$legacy = md5('old-passphrase');
check(AdminPassword::verify('old-passphrase', $legacy), 'Existing MD5 password must still work');
check(!AdminPassword::verify($legacy, $legacy), 'A stored hash must not be a login credential');
check(AdminPassword::shouldUpgrade($legacy), 'Existing MD5 password must be upgraded');
$modern = AdminPassword::make('old-passphrase');
check(strlen($modern) > 32, 'Modern hash must fit the widened database column');
check(AdminPassword::verify('old-passphrase', $modern), 'Modern password must verify');
check(!AdminPassword::verify('wrong-password', $modern), 'Wrong password must fail');
check(!AdminPassword::shouldUpgrade($modern), 'Current password hash must not rehash each login');
check(!AdminPassword::validateNew('short'), 'New short passwords must fail');
check(AdminPassword::validateNew('a-long-new-password'), 'New long passwords must pass');
check(!AdminPassword::validateNew(str_repeat('x', 73)), 'Bcrypt-truncated passwords must fail');

$token = MemberToken::issue();
check(strlen($token) === 64 && MemberToken::isCurrent($token), 'New token must be usable');
check(!MemberToken::isCurrent(md5('legacy-token')), 'Old token must require relogin');
check(!MemberToken::isCurrent(sprintf('%08x', time() - MemberToken::LIFETIME - 1) . substr($token, 8)), 'Expired token must fail');
check(!MemberToken::isCurrent(sprintf('%08x', time() + 60) . substr($token, 8)), 'Future token must fail');
check(MemberToken::digest($token) !== $token, 'Database must not store bearer token');
check(MemberToken::issue() !== $token, 'Consecutive tokens must differ');

require dirname(__DIR__) . '/vendor/autoload.php';
$views = ['login/index.html', 'user/form.html', 'user/pass.html', 'member/index.html', 'setting/index.html'];
$template = new think\Template(['view_path' => dirname(__DIR__) . '/app/admin/view/', 'tpl_cache' => false]);
foreach ($views as $view) {
    $html = (string)file_get_contents(dirname(__DIR__) . '/app/admin/view/' . $view);
    $template->parse($html);
}

echo "security-auth: ok\n";
