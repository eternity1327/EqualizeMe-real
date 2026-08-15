<?php
/**
 * Database connection.
 *
 * Credentials come from api/config.local.php (gitignored) when present,
 * falling back to XAMPP's defaults so a fresh clone still runs.
 *
 * WHY THIS MATTERS
 *
 * The fallback is MySQL's `root` account with an empty password — XAMPP's
 * out-of-the-box setup. That's tolerable while the database only listens
 * on localhost, and dangerous the moment it doesn't:
 *
 *   - Anyone who reaches port 3306 connects as root with no password.
 *   - MySQL's root can usually read and write files on the server, so it
 *     isn't only the data at risk.
 *   - phpMyAdmin, if it ever becomes reachable, is the same door with a
 *     friendlier handle.
 *
 * It also breaks least privilege: this application reads and writes a
 * handful of tables, and has no business holding rights to drop the
 * schema or create users. A SQL injection bug that slipped past the
 * prepared statements would inherit whatever the connection can do.
 *
 * See sql/create_app_user.sql for a limited account, then put its
 * credentials in config.local.php.
 */
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
            // Use real prepared statements rather than PDO's emulation, so
            // the values never reach the server as part of the SQL string.
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    return $pdo;
}
