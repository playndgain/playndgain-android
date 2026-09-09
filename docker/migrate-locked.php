<?php

/**
 * Run `artisan migrate --force` while holding a MySQL advisory lock on one
 * connection. GET_LOCK is connection-scoped — a separate PHP process that only
 * acquires then exits releases the lock immediately, which is useless against
 * two app replicas booting together (docs/13 rolling deploy).
 */

declare(strict_types=1);

$lockName = getenv('MIGRATE_LOCK_NAME') ?: 'playndgain_migrate';
$lockTimeout = (int) (getenv('MIGRATE_LOCK_TIMEOUT') ?: 300);

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s',
    getenv('DB_HOST') ?: 'mysql',
    getenv('DB_PORT') ?: '3306',
    getenv('DB_DATABASE') ?: 'playndgain',
);

$pdo = new PDO($dsn, getenv('DB_USERNAME') ?: 'root', getenv('DB_PASSWORD') ?: '', [
    PDO::ATTR_TIMEOUT => 5,
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$get = $pdo->prepare('SELECT GET_LOCK(?, ?)');
$get->execute([$lockName, $lockTimeout]);
$ok = (int) $get->fetchColumn();

if ($ok !== 1) {
    fwrite(STDERR, "GET_LOCK({$lockName}) returned {$ok}\n");
    exit(1);
}

$exit = 0;
$appRoot = getenv('APP_BASE_PATH') ?: '/var/www/html';

try {
    passthru('php '.escapeshellarg($appRoot.'/artisan').' migrate --force', $exit);
} finally {
    $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
    $release->execute([$lockName]);
}

exit($exit);
