<?php
require_once __DIR__ . "/session.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/csrf.php";
start_secure_session();
header("Content-Type: application/json");

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    echo json_encode(["error" => "Not logged in"]);
    exit;
}

$userId = $_SESSION["user_id"];

try {
    $pdo = get_pdo();

    if ($_SERVER["REQUEST_METHOD"] === "GET") {
        $stmt = $pdo->prepare("SELECT notifications, dark_mode, auto_play FROM settings WHERE user_id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if (!$row) {
            echo json_encode(["notifications" => true, "darkMode" => false, "autoPlay" => false]);
            exit;
        }

        echo json_encode([
            "notifications" => (bool)$row["notifications"],
            "darkMode" => (bool)$row["dark_mode"],
            "autoPlay" => (bool)$row["auto_play"],
        ]);
    } elseif ($_SERVER["REQUEST_METHOD"] === "PUT") {
        csrf_verify_or_fail();
        $data = json_decode(file_get_contents("php://input"), true);

        $columnMap = ["notifications" => "notifications", "darkMode" => "dark_mode", "autoPlay" => "auto_play"];
        $allowedColumns = ["notifications", "dark_mode", "auto_play"];

        $pdo->prepare("INSERT IGNORE INTO settings (user_id) VALUES (?)")->execute([$userId]);

        foreach ($columnMap as $key => $column) {
            if (!in_array($column, $allowedColumns, true)) {
                continue;
            }
            if (array_key_exists($key, $data)) {
                $value = $data[$key] ? 1 : 0;
                $pdo->prepare("UPDATE settings SET $column = ? WHERE user_id = ?")->execute([$value, $userId]);
            }
        }

        echo json_encode(["status" => "saved"]);
    } else {
        http_response_code(405);
        echo json_encode(["error" => "Method not allowed"]);
    }
} catch (PDOException $e) {
    error_log("settings.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["error" => "Something went wrong"]);
}
