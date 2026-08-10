<?php
session_start();
header("Content-Type: application/json");
require __DIR__ . "/../db.php";

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
        $data = json_decode(file_get_contents("php://input"), true);
        $columnMap = ["notifications" => "notifications", "darkMode" => "dark_mode", "autoPlay" => "auto_play"];

        $pdo->prepare("INSERT IGNORE INTO settings (user_id) VALUES (?)")->execute([$userId]);

        foreach ($columnMap as $key => $column) {
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
    http_response_code(500);
    echo json_encode(["error" => "Something went wrong"]);
}
