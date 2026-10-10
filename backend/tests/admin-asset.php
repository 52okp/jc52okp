<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$source = $root . '/public/static/admin.js';
$fingerprint = substr(hash_file('sha256', $source), 0, 12);
$name = 'admin.' . $fingerprint . '.js';
$asset = $root . '/public/static/' . $name;
if (!is_file($asset) || hash_file('sha256', $asset) !== hash_file('sha256', $source)) {
    throw new RuntimeException('Fingerprint admin script is missing or stale');
}
foreach (['app/admin/view/index/index.html', 'app/admin/view/full.html'] as $view) {
    $html = (string) file_get_contents($root . '/' . $view);
    if (!str_contains($html, '/static/' . $name) || str_contains($html, 'src="__ROOT__/static/admin.js"')) {
        throw new RuntimeException('Admin layout still references a cached script: ' . $view);
    }
}
$script = (string) file_get_contents($asset);
if (!str_contains($script, '$.openImagePicker = function') ||
    !str_contains($script, '暂无图片，可点击右上角“上传图片”')) {
    throw new RuntimeException('Fingerprinted script lacks the repaired image picker');
}
$banner = (string) file_get_contents($root . '/app/admin/view/banner/form.html');
if (!str_contains($banner, 'find(\'[data-file="image"]\').attr(\'data-file\', \'one\')')) {
    throw new RuntimeException('Banner preview does not open the direct file uploader');
}
echo "Admin image picker asset fingerprint passed\n";
