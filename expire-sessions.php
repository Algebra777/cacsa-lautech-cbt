<?php

declare(strict_types=1);

// Run this file from Windows Task Scheduler (or another server scheduler),
// not through the browser. Loading the health action executes the same
// server-side expiry pass used by every API request.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['action'] = 'health';

require __DIR__ . DIRECTORY_SEPARATOR . 'api.php';
