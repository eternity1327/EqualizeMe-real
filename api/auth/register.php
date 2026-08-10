<?php
session_start();
header("Content-Type: application/json");
require __DIR__ . "/../db.php";

$body = json_decode(file_get_contents("php://input"), true);
$name = trim($body["name"] ?? "");
$email = trim($body["email"] ?? "");
$password = $body["password"] ?? "";

if (!$name || !$email || !$password) {
    http_response_code(400);
    echo json_encode(["error" => "name, email, and password are all required"]);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(["error" => "That email address doesn't look valid"]);
    exit;
}
if (strlen($password) < 8) {
    http_response_code(400);
    echo json_encode(["error" => "Password must be at least 8 characters"]);
    exit;
}

try {
    $pdo = get_pdo();

    $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $check->execute([$email]);
    if ($check->fetch()) {
        http_response_code(409);
        echo json_encode(["error" => "An account with that email already exists"]);
        exit;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)");
    $stmt->execute([$name, $email, $hash]);
    $userId = $pdo->lastInsertId();

    // Default rows so profile/settings lookups don't need special-casing later
    $pdo->prepare(
        "INSERT INTO auditory_profiles (user_id, bass_gain, treble_gain, presence_gain) VALUES (?, 0, 0, 0)"
    )->execute([$userId]);
    $pdo->prepare("INSERT INTO settings (user_id) VALUES (?)")->execute([$userId]);

    $_SESSION["user_id"] = $userId;

    http_response_code(201);
    echo json_encode(["id" => (int)$userId, "name" => $name, "email" => $email]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Something went wrong creating your account"]);
}
