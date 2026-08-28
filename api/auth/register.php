<?php
require_once __DIR__ . "/../session.php";
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../rate_limit.php";
require_once __DIR__ . "/../password_policy.php";
require_once __DIR__ . "/../csrf.php";
require_once __DIR__ . "/../totp.php";
start_secure_session();
header("Content-Type: application/json");

if (!rate_limit_check("register", REGISTER_MAX_ATTEMPTS, REGISTER_WINDOW_SECONDS)) {
    http_response_code(429);
    echo json_encode([
        "error" => "Too many signup attempts. Please wait a few minutes and try again.",
    ]);
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

    $pdo->prepare(
        "INSERT INTO auditory_profiles (user_id, bass_gain, treble_gain, presence_gain)
         VALUES (?, 0, 0, 0)"
    )->execute([$userId]);
    $pdo->prepare("INSERT INTO settings (user_id) VALUES (?)")->execute([$userId]);

    if (TWO_FACTOR_REQUIRED) {
        // A new account is in exactly the position a returning user is in
        // after a correct password: identified, but not yet holding a
        // second factor. Granting a full session here would have made
        // signing up the way to skip two-factor entirely.
        begin_pending_login($userId);

        http_response_code(201);
        echo json_encode([
            "status" => "2fa_required",
            "name" => $name,
            "next" => "enrol",
            "redirect" => "two-factor.php",
        ]);
        exit;
    }

    complete_login($userId);

    http_response_code(201);
    echo json_encode(["id" => (int)$userId, "name" => $name, "email" => $email]);
} catch (PDOException $e) {
    error_log("auth/register.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["error" => "Something went wrong creating your account"]);
}
