<?php
function get_pdo() {
    $host = "127.0.0.1";
    $db   = "equalizeme";
    $user = "root";
    $pass = ""; // XAMPP default

    return new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}
