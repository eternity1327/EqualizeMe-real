<?php
require_once __DIR__ . "/../session.php";
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../rate_limit.php";
require_once __DIR__ . "/../csrf.php";
require_once __DIR__ . "/../totp.php";
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../errors.php";
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
    $stmt = $pdo->prepare(
        "SELECT id, name, email, password_hash, totp_confirmed_at,
                email_verified_at
         FROM users WHERE email = ?"
    );
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

    // Checked after the password, never before. Answering "confirm your
    // email" to a wrong password would confirm the address exists — the
    // exact thing the identical error message above is there to prevent.
    if (require_email_verification() && $user["email_verified_at"] === null) {
        http_response_code(403);
        echo json_encode([
            "status" => "verify_email",
            "error" => "Confirm your email address before logging in. "
                . "Check your inbox for the link we sent when you signed up.",
            "canResend" => true,
        ]);
        exit;
    }

    $enrolled = $user["totp_confirmed_at"] !== null;

    // Being enrolled always means being asked, whatever the policy says.
    // Someone who turned two-factor on for themselves does not lose it
    // because the app-wide default is opt-in.
    if (!$enrolled && !TWO_FACTOR_REQUIRED) {
        complete_login($user["id"]);

        echo json_encode([
            "id" => (int)$user["id"],
            "name" => $user["name"],
            "email" => $user["email"],
        ]);
        exit;
    }

    // Second factor still to come. begin_pending_login() records who got
    // this far WITHOUT setting user_id, so every authenticated endpoint
    // turns this person away until the code lands.
    begin_pending_login($user["id"]);

    // Only the name is echoed, so the next page can greet them. No id, no
    // email: nothing that a half-finished login should be handing out.
    echo json_encode([
        "status" => "2fa_required",
        "name" => $user["name"],
        // Which of the two things happens next. Not a permission — the
        // server re-derives this from the database on every 2FA request
        // rather than trusting whatever the client does with it.
        "next" => $enrolled ? "verify" : "enrol",
        "redirect" => "two-factor.php",
    ]);
} catch (PDOException $e) {
    fail_json(500, "Something went wrong logging in", $e, "auth/login.php");
}
