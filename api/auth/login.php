<?php
require_once __DIR__ . "/../session.php";
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../rate_limit.php";
require_once __DIR__ . "/../csrf.php";
start_secure_session();
header("Content-Type: application/json");

if (!rate_limit_check("login")) {
    http_response_code(429);
    echo json_encode(["error" => "Too many login attempts. Please wait a few minutes and try again."]);
    exit;
}

$body = json_decode(file_get_contents("php://input"), true);
csrf_verify_or_fail($body["csrf_token"] ?? null);

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
        rate_limit_record("login");
        http_response_code(401);
        echo json_encode(["error" => "Incorrect email or password"]);
        exit;
    }

    // Regenerate the session ID on privilege change (session fixation defense) —
    // an attacker who fixed a visitor's session ID before login can't reuse it after.
    session_regenerate_id(true);
    $_SESSION["user_id"] = $user["id"];
    echo json_encode(["id" => (int)$user["id"], "name" => $user["name"], "email" => $user["email"]]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Something went wrong logging in"]);
}
