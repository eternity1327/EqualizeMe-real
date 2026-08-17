<?php

require_once __DIR__ . '/config.php';

function get_pdo() {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $config = app_config()['database'] ?? [];

    $host = $config['host'] ?? '127.0.0.1';
    $db   = $config['name'] ?? 'equalizeme';
    $user = $config['user'] ?? 'root';
    $pass = $config['password'] ?? '';

    $pdo = new PDO(
        "mysql:host=$host;dbname=$db;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    return $pdo;
}
