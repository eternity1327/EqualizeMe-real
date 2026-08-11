<?php
require_once __DIR__ . "/../session.php";
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../rate_limit.php";
require_once __DIR__ . "/../password_policy.php";
require_once __DIR__ . "/../csrf.php";
start_secure_session();
header("Content-Type: application/json");

if (!rate_limit_check("register", 5, 600)) {
    http_response_code(429);
    echo json_encode(["error" => "Too many signup attempts. Please wait a few minutes and try again."]);
    exit;
}
rate_limit_record("register");

$body = json_decode(file_get_contents("php://input"), true);
csrf_verify_or_fail($body["csrf_token"] ?? null);

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
$passwordProblems = password_problems($password, $email, $name);
if ($passwordProblems) {
    http_response_code(400);
    echo json_encode(["error" => password_error_message($passwordProblems)]);
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

    // Same session-fixation defense as login — this is effectively a
    // fresh login too, since registering signs the user in immediately.
    session_regenerate_id(true);
    $_SESSION["user_id"] = $userId;

    http_response_code(201);
    echo json_encode(["id" => (int)$userId, "name" => $name, "email" => $email]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Something went wrong creating your account"]);
}
