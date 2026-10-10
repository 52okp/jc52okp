<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$parse = new ReflectionMethod(app\admin\Img2Storage::class, 'parseTokenResponse');
$parse->setAccessible(true);
$valid = json_encode(['status' => true, 'data' => ['token' => '1|validToken']], JSON_THROW_ON_ERROR);
if ($parse->invoke(null, 200, $valid) !== '1|validToken') {
    throw new RuntimeException('Yutu token response was not accepted');
}
foreach ([[401, '{"status":false,"message":"bad credentials"}'],
          [200, '{"status":true,"data":{}}'],
          [200, '{"status":true,"data":{"token":"bad\\ntoken"}}']] as [$status, $body]) {
    try {
        $parse->invoke(null, $status, $body);
        throw new RuntimeException('Invalid Yutu token response was accepted');
    } catch (think\admin\Exception $expected) {
        if (str_contains($expected->getMessage(), 'bad credentials')) {
            throw new RuntimeException('Remote login details leaked into the error message');
        }
    }
}

echo "Yutu token response checks passed\n";
