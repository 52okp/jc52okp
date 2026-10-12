<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$normalize = new ReflectionMethod(app\admin\controller\Setting::class, 'normalizeSettingPost');
$normalize->setAccessible(true);
$policy = file_get_contents(dirname(__DIR__) . '/PRIVACY_POLICY_ZH.txt');
if (!is_string($policy) || strlen($policy) > 20000) {
    throw new RuntimeException('Privacy policy exceeds the setting field limit');
}

$nested = $normalize->invoke(null, [
    'wxapp' => ['follow_image' => '', 'follow_text' => '', 'privacy_content' => $policy],
    '_token_' => 'csrf',
]);
if (($nested['wxapp.privacy_content'] ?? null) !== $policy ||
    ($nested['wxapp.follow_text'] ?? null) !== '' ||
    isset($nested['wxapp']) || ($nested['_token_'] ?? null) !== 'csrf') {
    throw new RuntimeException('Nested small-program settings were not normalized');
}

$flat = $normalize->invoke(null, ['wxapp.privacy_content' => $policy]);
if (($flat['wxapp.privacy_content'] ?? null) !== $policy) {
    throw new RuntimeException('Flat small-program settings were changed');
}

foreach ([
    ['wxapp' => ['privacy_content' => 'new'], 'wxapp.privacy_content' => 'old'],
    ['wxapp' => 'invalid'],
    ['wxapp' => ['privacy_content' => []]],
    ['wxapp' => ['unexpected' => 'value']],
] as $invalid) {
    try {
        $normalize->invoke(null, $invalid);
        throw new RuntimeException('Invalid small-program settings were accepted');
    } catch (InvalidArgumentException $expected) {
    }
}

echo "Small-program setting form normalization passed\n";

