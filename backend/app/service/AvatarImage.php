<?php

declare(strict_types=1);
namespace app\service;

final class AvatarImage
{
    public static function encode(string $bytes): string
    {
        if ($bytes === '' || strlen($bytes) > 5 * 1024 * 1024) throw new \RuntimeException('图片大小无效');
        $info = @getimagesizefromstring($bytes);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true) ||
            $info[0] < 1 || $info[1] < 1 || $info[0] > 4096 || $info[1] > 4096 || $info[0] * $info[1] > 4000000) {
            throw new \RuntimeException('请上传有效图片，像素总量不超过 400 万');
        }
        if (!function_exists('imagecreatefromstring')) throw new \RuntimeException('图片处理服务暂不可用');
        $source = @imagecreatefromstring($bytes);
        if (!$source) throw new \RuntimeException('图片无法解码');
        $scale = min(1, 512 / max($info[0], $info[1]));
        $target = imagecreatetruecolor(max(1, (int)round($info[0] * $scale)), max(1, (int)round($info[1] * $scale)));
        imagealphablending($target, false);
        imagesavealpha($target, true);
        try {
            imagecopyresampled($target, $source, 0, 0, 0, 0, imagesx($target), imagesy($target), $info[0], $info[1]);
            ob_start();
            try { imagepng($target); $png = ob_get_contents(); } finally { ob_end_clean(); }
            if (!is_string($png) || $png === '') throw new \RuntimeException('图片处理失败');
            return $png;
        } finally { imagedestroy($source); imagedestroy($target); }
    }
}
