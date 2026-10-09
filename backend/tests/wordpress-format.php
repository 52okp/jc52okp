<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$sync = new \app\service\WordpressSync();
$clean = new ReflectionMethod($sync, 'cleanHtml');
$html = '<!-- wp:image -->'
    . '<figure class="wp-block-image"><img src="https://52okp.com/uploads/中文封面.webp" alt="流程图" onerror="alert(1)">'
    . '<figcaption>图片说明</figcaption></figure>'
    . '<div class="wp-block-columns"><div class="wp-block-column"><h2>章节</h2><p class="has-text-align-center" style="color:#336699;position:fixed">正文 <a href="https://52okp.com/p/中文">查看原文</a></p></div></div>'
    . '<table><tr><th>名称</th><td>内容</td></tr></table><pre><code>const x = 1;</code></pre>'
    . '<img src="data:image/gif;base64,AA==" data-src="https://52okp.com/uploads/延迟图片.jpg" alt="延迟图片">'
    . '<script>alert(2)</script><iframe src="https://evil.example/"></iframe>';
$out = $clean->invoke($sync, $html);

foreach (['%E4%B8%AD%E6%96%87', '%E5%BB%B6%E8%BF%9F', 'alt="流程图"', '图片说明', '<table', '<pre', 'max-width:100%', 'text-align:center', 'color:#336699', 'wp-block-columns'] as $needle) {
    if ($needle === 'wp-block-columns') {
        if (str_contains($out, $needle)) throw new RuntimeException('Untrusted editor class survived');
        continue;
    }
    if (!str_contains($out, $needle)) throw new RuntimeException("Missing WordPress format: $needle");
}
foreach (['onerror', '<script', '<iframe', 'evil.example', 'position:fixed'] as $needle) {
    if (str_contains($out, $needle)) throw new RuntimeException("Unsafe markup survived: $needle");
}
echo "WordPress format cleanup passed\n";
