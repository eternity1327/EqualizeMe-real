<?php
session_start();
header("Content-Type: application/json");
require __DIR__ . "/../db.php";

$body = json_decode(file_get_contents("php://input"), true);
$email = trim($body["email"] ?? "");
$password = $body["password"] ?? "";

if (!$email || !$password) {
    http_response_code(400);
    echo json_encode(["error" => "email and password are required"]);
    exit;
}

try {
    $pdo = get_pdo();
    $stmt = $pdo->prepare("SELECT id, name, email, password_hash FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user["password_hash"])) {
        http_response_code(401);
        echo json_encode(["error" => "Incorrect email or password"]);
        exit;
    }

    $_SESSION["user_id"] = $user["id"];
    echo json_encode(["id" => (int)$user["id"], "name" => $user["name"], "email" => $user["email"]]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Something went wrong logging in"]);
}
