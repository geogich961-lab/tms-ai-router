<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$api = (string)file_get_contents($root . '/public/router-api.php');
$js = (string)file_get_contents($root . '/public/assets/app.js');
$version = trim((string)file_get_contents($root . '/VERSION'));
$config = (string)file_get_contents($root . '/config/app.php');

$checks = [
    $version === '1.2.1' => 'VERSION phải là 1.2.1',
    str_contains($config, "'version' => '1.2.1'") => 'config version phải là 1.2.1',
    is_file($root . '/public/router-api.php') => 'Thiếu router-api.php',
    str_contains($api, "\$data['_action']") => 'router-api phải đọc _action từ JSON body',
    str_contains($api, "\$data['_csrf']") => 'router-api phải đọc _csrf từ JSON body',
    str_contains($api, "case 'update-check'") => 'Thiếu update-check',
    str_contains($api, "case 'update-apply'") => 'Thiếu update-apply',
    str_contains($js, "'/router-api.php'") => 'Dashboard phải gọi direct PHP endpoint',
    str_contains($js, '_action:action') => 'Dashboard phải gửi action trong JSON body',
    str_contains($js, '_csrf:csrf') => 'Dashboard phải gửi CSRF trong JSON body',
    !str_contains($js, 'X-TMS-Action') => 'Không được phụ thuộc X-TMS-Action',
];

foreach ($checks as $ok => $message) {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

echo "PASS: v1.2.1 direct admin transport surface.\n";
