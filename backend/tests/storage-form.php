<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$normalize = new ReflectionMethod(app\admin\controller\Config::class, 'normalizeStoragePost');
$normalize->setAccessible(true);
$data = $normalize->invoke(null, [
    'storage' => ['type' => 'img2', 'img2_token' => '', 'img2_strategy_id' => '3'],
    'img2_email' => 'example@test.invalid',
    'img2_password' => 'temporary-password',
    '_token_' => 'csrf',
]);
if ($data['storage.type'] !== 'img2' || $data['storage.img2_strategy_id'] !== '3' ||
    $data['img2_password'] !== 'temporary-password' || isset($data['storage'])) {
    throw new RuntimeException('Nested storage form was not normalized');
}
try {
    $normalize->invoke(null, ['storage' => ['type' => 'img2'], 'storage.type' => 'local']);
    throw new RuntimeException('Conflicting storage types were accepted');
} catch (InvalidArgumentException $expected) {
}
echo "Storage form field normalization passed\n";

$template = new think\Template([
    'view_path' => dirname(__DIR__) . '/app/admin/view/',
    'tpl_cache' => false,
]);
$html = (string) file_get_contents(dirname(__DIR__) . '/app/admin/view/config/storage-img2.html');
$template->parse($html);
if (!str_contains($html, "data-token-url=\"<?php echo url('token'); ?>\"") ||
    !preg_match('~<script>(.*?)</script>~s', $html, $match)) {
    throw new RuntimeException('Yutu form token refresh URL or script was not compiled');
}
$process = proc_open(['node', '--check'], [
    0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
], $pipes);
if (!is_resource($process)) throw new RuntimeException('Node.js is required for this test');
fwrite($pipes[0], $match[1]);
fclose($pipes[0]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
if (proc_close($process) !== 0) throw new RuntimeException('Yutu form script is invalid: ' . $stderr);
echo "Storage form token refresh script passed\n";
