<?php

declare(strict_types=1);

/** Read-only URL and local-file inventory for the imported article baseline. */
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/vendor/autoload.php';

use think\admin\service\RuntimeService;
use think\facade\Db;

$app = RuntimeService::init();
$app->initialize();
$root = realpath(dirname(__DIR__) . '/public');
$result = [];
$classify = static function (string $url) use ($root): string {
    if ($url === '') return 'empty';
    if (str_starts_with($url, 'https://') || str_starts_with($url, 'http://')) {
        return 'remote:' . strtolower((string)parse_url($url, PHP_URL_HOST));
    }
    if (str_starts_with($url, '//')) return 'remote:' . strtolower((string)parse_url('https:' . $url, PHP_URL_HOST));
    $path = parse_url($url, PHP_URL_PATH);
    if (!is_string($path) || !str_starts_with($path, '/')) return 'relative';
    $decoded = rawurldecode($path);
    if (str_contains($decoded, '..') || !$root) return 'unsafe-local';
    return is_file($root . str_replace('/', DIRECTORY_SEPARATOR, $decoded)) ? 'local-present' : 'local-missing';
};
foreach (Db::name('article')->field('id,cover,content,links')->order('id asc')->select()->toArray() as $article) {
    $images = [];
    $html = (string)($article['content'] ?? '');
    if ($html !== '') {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $prior = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prior);
        foreach ($doc->getElementsByTagName('img') as $img) {
            $key = $classify((string)$img->getAttribute('src'));
            $images[$key] = ($images[$key] ?? 0) + 1;
        }
    }
    $links = [];
    foreach (json_decode((string)($article['links'] ?? ''), true) ?: [] as $link) {
        $key = $classify((string)($link['url'] ?? ''));
        $links[$key] = ($links[$key] ?? 0) + 1;
    }
    $result[] = ['id' => (int)$article['id'], 'cover' => $classify((string)($article['cover'] ?? '')),
        'images' => $images, 'download_links' => $links];
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
