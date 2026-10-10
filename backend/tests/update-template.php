<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$template = new think\Template([
    'view_path' => dirname(__DIR__) . '/app/admin/view/',
    'tpl_cache' => false,
]);
$content = (string)file_get_contents(dirname(__DIR__) . '/app/admin/view/update/index.html');
$template->parse($content);
if (!preg_match('~<script>(.*?)</script>~s', $content, $match)) {
    throw new RuntimeException('Update page script is missing');
}
$script = preg_replace('~<\?php.*?\?>~s', '"token"', $match[1]);
if (substr_count($script, '$.ajax(') !== 4 || str_contains($script, '<?php') ||
    !str_contains($content, "data-token-url=\"<?php echo url('token'); ?>\"") ||
    !str_contains($script, "_token_: response.data.token")) {
    throw new RuntimeException('Template parser damaged update page requests');
}
$process = proc_open(['node', '--check'], [
    0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
], $pipes);
if (!is_resource($process)) throw new RuntimeException('Node.js is required for this test');
fwrite($pipes[0], $script);
fclose($pipes[0]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
if (proc_close($process) !== 0) throw new RuntimeException('Compiled update page JavaScript is invalid: ' . $stderr);
echo "Compiled update page JavaScript passed\n";
