<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = (string)(getenv('CBT_MOCK_REQUEST_URI') ?: '/BEREVION/i/mock/api.php');
$_SERVER['HTTP_COOKIE'] = (string)getenv('CBT_MOCK_COOKIE');
$_SERVER['HTTP_X_CSRF_TOKEN'] = (string)getenv('CBT_MOCK_CSRF');
$_COOKIE = [];
foreach (explode(';', $_SERVER['HTTP_COOKIE']) as $cookie) {
    if (str_contains($cookie, '=')) {
        [$name, $value] = array_map('trim', explode('=', $cookie, 2));
        $_COOKIE[$name] = $value;
    }
}
$_GET['action'] = (string)getenv('CBT_MOCK_ACTION');
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'api.php';
