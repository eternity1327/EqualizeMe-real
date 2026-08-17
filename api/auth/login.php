<?php
require_once __DIR__ . "/../session.php";
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../rate_limit.php";
require_once __DIR__ . "/../csrf.php";
start_secure_session();
header("Content-Type: application/json");

const TOO_MANY_ATTEMPTS_MESSAGE =
    "Too many login attempts. Please wait a few minutes and try again.";

const BAD_CREDENTIALS_MESSAGE = "Incorrect email or password";

const UNMATCHABLE_HASH = '$2y$10$usesomesillystringforeidsxa5PhSD0hzOXMH.HuAn9LSgLuRRuC';

if (!rate_limit_check("login")) {
    http_response_code(429);
    echo json_encode(["error" => TOO_MANY_ATTEMPTS_MESSAGE]);
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

$accountKey = rate_limit_key($email);

if (!rate_limit_check(
    "login_account",
    LOGIN_ACCOUNT_MAX_ATTEMPTS,
    LOGIN_ACCOUNT_WINDOW_SECONDS,
    $accountKey
)) {
    http_response_code(429);
    echo json_encode(["error" => TOO_MANY_ATTEMPTS_MESSAGE]);
    exit;
}

try {
    $pdo = get_pdo();
    $stmt = $pdo->prepare("SELECT id, name, email, password_hash FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    $hashToCheck = $user ? $user["password_hash"] : UNMATCHABLE_HASH;
    $passwordOk = password_verify($password, $hashToCheck);

    if (!$user || !$passwordOk) {
        rate_limit_record("login");
        rate_limit_record("login_account", $accountKey);
        http_response_code(401);
        echo json_encode(["error" => BAD_CREDENTIALS_MESSAGE]);
        exit;
    }

    rate_limit_clear("login_account", $accountKey);

    session_regenerate_id(true);
    $_SESSION["user_id"] = $user["id"];

    echo json_encode([
        "id" => (int)$user["id"],
        "name" => $user["name"],
        "email" => $user["email"],
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Something went wrong logging in"]);
}
