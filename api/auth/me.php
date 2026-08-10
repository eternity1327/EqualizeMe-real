<?php
session_start();
header("Content-Type: application/json");
require __DIR__ . "/../db.php";

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    echo json_encode(["error" => "Not logged in"]);
    exit;
}

try {
    $pdo = get_pdo();
    $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE id = ?");
    $stmt->execute([$_SESSION["user_id"]]);
    $user = $stmt->fetch();

    if (!$user) {
        http_response_code(401);
        echo json_encode(["error" => "Not logged in"]);
        exit;
    }

    echo json_encode(["id" => (int)$user["id"], "name" => $user["name"], "email" => $user["email"]]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Something went wrong"]);
}
