<?php

declare(strict_types=1);

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $host = env_get('DB_HOST', '127.0.0.1');
    $port = env_get('DB_PORT', '3306');
    $name = env_get('DB_NAME', 'domain_watch');
    $user = env_get('DB_USER', 'domain_watch');
    $pass = env_get('DB_PASS', '');

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function db_one(string $sql, array $params=[]): ?array {
    $st = db()->prepare($sql);
    $st->execute($params);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

function db_all(string $sql, array $params=[]): array {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function db_exec(string $sql, array $params=[]): int {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}
