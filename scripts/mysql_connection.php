<?php
declare(strict_types=1);

function mysqlMigrationEnv(string $root): array {
    $path = $root . DIRECTORY_SEPARATOR . '.env';
    if (!is_file($path)) throw new RuntimeException('.env was not found.');
    $values = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $values[trim($key)] = trim($value);
    }
    foreach (['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
        if (!array_key_exists($key, $values)) throw new RuntimeException("Missing {$key} in .env.");
    }
    return $values;
}

function mysqlMigrationPdo(string $root): PDO {
    $env = mysqlMigrationEnv($root);
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env['DB_HOST'], $env['DB_PORT'], $env['DB_DATABASE']);
    return new PDO($dsn, $env['DB_USERNAME'], $env['DB_PASSWORD'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
