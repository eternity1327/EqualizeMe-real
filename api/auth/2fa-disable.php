<?php

/**
 * Turn two-factor off for the signed-in account.
 *
 * Requires the account password, not a code from the app. Two reasons:
 *
 *   1. Someone who has stolen a session should not be able to quietly
 *      strip the second factor off it. A session alone must not be enough
 *      to weaken the account it belongs to.
 *
 *   2. Requiring a code instead would strand anyone whose phone is lost or
 *      wiped — exactly the person most likely to be here.
 *
 * Full session only. There is deliberately no pending-login path: turning
 * protection off is not something a half-finished login gets to do.
 */

require_once __DIR__ . "/../session.php";
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../rate_limit.php";
require_once __DIR__ . "/../csrf.php";
require_once __DIR__ . "/../totp.php";
start_secure_session();
header("Content-Type: application/json");

$body = json_decode(file_get_contents("php://input"), true);
csrf_verify_or_fail($body["csrf_token"] ?? null);

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    echo json_encode(["error" => "Not logged in"]);
    exit;
}

$userId = (int)$_SESSION["user_id"];

// Guessing a password through this endpoint has to cost the same as
// guessing it at the login form, or it becomes the cheaper way in.
$limitKey = rate_limit_key("user:{$userId}");

if (!rate_limit_check(
    "login_account",
    LOGIN_ACCOUNT_MAX_ATTEMPTS,
    LOGIN_ACCOUNT_WINDOW_SECONDS,
    $limitKey
)) {
    http_response_code(429);
    echo json_encode([
        "error" => "Too many attempts. Please wait a few minutes and try again.",
    ]);
    exit;
}

if (TWO_FACTOR_REQUIRED) {
    http_response_code(403);
    echo json_encode([
        "error" => "Two-factor is required on this site and can't be switched off.",
    ]);
    exit;
}

$pdo = null;

try {
    $pdo = get_pdo();

    $stmt = $pdo->prepare(
        "SELECT password_hash, totp_confirmed_at FROM users WHERE id = ?"
    );
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        http_response_code(401);
        echo json_encode(["error" => "Not logged in"]);
        exit;
    }

    if ($user["totp_confirmed_at"] === null) {
        // Already off. Reported as success rather than an error — the state
        // the caller asked for is the state they have got.
        echo json_encode(["status" => "ok", "enabled" => false]);
        exit;
    }

    if (!password_verify($body["password"] ?? "", $user["password_hash"])) {
        rate_limit_record("login_account", $limitKey);
        http_response_code(401);
        echo json_encode(["error" => "That password isn't right."]);
        exit;
    }

    $pdo->beginTransaction();

    // Clear the secret as well as the flag. Leaving it behind would mean a
    // later re-enrolment silently reused a secret that has already been on
    // a phone, and any authenticator entry still holding it would keep
    // producing codes that work.
    $pdo->prepare(
        "UPDATE users SET totp_secret = NULL, totp_confirmed_at = NULL,
                          totp_last_step = NULL
         WHERE id = ?"
    )->execute([$userId]);

    // The recovery codes were minted against that secret and are just as
    // capable of logging someone in. They go with it.
    $pdo->prepare("DELETE FROM recovery_codes WHERE user_id = ?")->execute([$userId]);

    $pdo->commit();

    rate_limit_clear("login_account", $limitKey);

    // Changing what it takes to log in is a privilege change, so the
    // session id rotates with it.
    session_regenerate_id(true);
    $_SESSION["_id_issued_at"] = time();

    echo json_encode(["status" => "ok", "enabled" => false]);
} catch (PDOException $e) {
    if ($pdo !== null && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("auth/2fa-disable.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["error" => "Something went wrong. Please try again."]);
}
