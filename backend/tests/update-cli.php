<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$installer = new app\service\UpdateInstall();
$probe = new ReflectionMethod($installer, 'probeCli');
$probe->setAccessible(true);

// A Baota site can restrict PHP file access to the site root while the
// executable and /dev/null remain outside that root.
ini_set('open_basedir', dirname(__DIR__));
if (!$probe->invoke($installer, PHP_BINARY)) {
    throw new RuntimeException('PHP CLI probe failed under restricted open_basedir');
}
echo "PHP CLI probe passed under restricted open_basedir\n";
