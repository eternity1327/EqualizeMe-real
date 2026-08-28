<?php

/**
 * Step two of enrolment: the first correct code turns two-factor on.
 *
 * The point of asking for a code before enabling anything is that it proves
 * the app and the server agree. Enabling on trust would let a mistyped
 * secret, a wrong algorithm or a badly skewed clock lock the account out
 * permanently, and the user would only discover it at the next login with
 * no way back in.
 *
 * Recovery codes are issued here and shown exactly once.
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

// Same two callers as 2fa-setup.php: mid-login enrolment, or someone
// already signed in switching it on from Settings.
$userId = current_or_pending_user_id();
if ($userId === null) {
    http_response_code(401);
    echo json_encode(["error" => "Your sign-in timed out. Please log in again."]);
    exit;
}

// Keyed by account rather than by IP. Two people enrolling from the same
// network should not exhaust each other's attempts.
$limitKey = rate_limit_key("user:{$userId}");

if (!rate_limit_check(
    "totp_verify",
    TOTP_VERIFY_MAX_ATTEMPTS,
    TOTP_VERIFY_WINDOW_SECONDS,
    $limitKey
)) {
    http_response_code(429);
    echo json_encode([
        "error" => "Too many attempts. Please wait a few minutes and try again.",
    ]);
    exit;
}
rate_limit_record("totp_verify", $limitKey);

$pdo = null;

try {
    $pdo = get_pdo();

    $stmt = $pdo->prepare(
        "SELECT totp_secret, totp_confirmed_at FROM users WHERE id = ?"
    );
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user || !$user["totp_secret"]) {
        http_response_code(400);
        echo json_encode(["error" => "Start the setup again from the beginning."]);
        exit;
    }

    if ($user["totp_confirmed_at"] !== null) {
        http_response_code(409);
        echo json_encode(["error" => "Two-factor is already set up on this account."]);
        exit;
    }

    $step = totp_verify($user["totp_secret"], $body["code"] ?? null);

    if ($step === null) {
        http_response_code(400);
        echo json_encode([
            "error" => "That code didn't match. Check your authenticator app and "
                . "try the current code.",
        ]);
        exit;
    }

    $codes = recovery_codes_generate();

    $pdo->beginTransaction();

    // totp_last_step is set here as well as on login. The code just used to
    // enrol is still valid for the rest of its 30-second window, and
    // without this it would work again as a login code.
    $pdo->prepare(
        "UPDATE users SET totp_confirmed_at = NOW(), totp_last_step = ? WHERE id = ?"
    )->execute([$step, $userId]);

    // Replace rather than append, so re-enrolling never leaves an old
    // recovery code alive against a new secret.
    $pdo->prepare("DELETE FROM recovery_codes WHERE user_id = ?")->execute([$userId]);

    $insert = $pdo->prepare(
        "INSERT INTO recovery_codes (user_id, code_hash) VALUES (?, ?)"
    );
    foreach ($codes as $code) {
        $insert->execute([$userId, recovery_code_hash($code)]);
    }

    $pdo->commit();

    rate_limit_clear("totp_verify", $limitKey);

    // Enrolment counts as the second factor — they have just proved they
    // hold the phone. Making them immediately type another code would be
    // ceremony, not security.
    //
    // For someone already signed in this is a no-op in effect: it clears
    // the (absent) pending state, rotates the session id, and re-grants the
    // same user_id they already had. Rotating on a privilege change is
    // right anyway.
    complete_login($userId);

    echo json_encode([
        "status" => "ok",
        // The only time these are ever readable. They are hashed in the
        // database, so this response cannot be reproduced later.
        "recovery_codes" => $codes,
        "redirect" => "index.html",
    ]);
} catch (PDOException $e) {
    if ($pdo !== null && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("auth/2fa-activate.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["error" => "Something went wrong. Please try again."]);
}
