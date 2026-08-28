<?php

/**
 * Step one of enrolment: hand back a secret to put in an authenticator app.
 *
 * Serves two callers, which is why it accepts either a full session or a
 * half-finished login:
 *
 *   - someone already signed in, turning two-factor on from Settings
 *   - someone mid-login who has to enrol before they can continue, which
 *     is what happens when TWO_FACTOR_REQUIRED is on
 *
 * Nothing here confirms anything. The secret is written with
 * totp_confirmed_at left NULL, and only 2fa-activate.php can set that, and
 * only on proof of a working code. A user who loads this page and wanders
 * off is still unenrolled.
 */

require_once __DIR__ . "/../session.php";
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../csrf.php";
require_once __DIR__ . "/../totp.php";
start_secure_session();
header("Content-Type: application/json");

$body = json_decode(file_get_contents("php://input"), true);
csrf_verify_or_fail($body["csrf_token"] ?? null);

$userId = current_or_pending_user_id();
if ($userId === null) {
    http_response_code(401);
    echo json_encode(["error" => "Your sign-in timed out. Please log in again."]);
    exit;
}

try {
    $pdo = get_pdo();

    $stmt = $pdo->prepare(
        "SELECT email, totp_secret, totp_confirmed_at FROM users WHERE id = ?"
    );
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        clear_pending_login();
        http_response_code(401);
        echo json_encode(["error" => "Please log in again."]);
        exit;
    }

    // Already enrolled. Re-running setup here would silently replace a
    // working secret and lock the account out of its own authenticator, so
    // it refuses instead. Changing an existing secret belongs behind a full
    // session in settings, not here.
    if ($user["totp_confirmed_at"] !== null) {
        http_response_code(409);
        echo json_encode([
            "error" => "Two-factor is already set up on this account.",
            "next" => "verify",
        ]);
        exit;
    }

    // Reuse an unconfirmed secret rather than minting a new one. Someone
    // who scanned the QR, closed the tab and came back still has a working
    // entry in their app; issuing a fresh secret would invalidate it and
    // leave them staring at codes that never match.
    $secret = $user["totp_secret"];
    if (!$secret || base32_decode($secret) === "") {
        $secret = totp_new_secret();
        $pdo->prepare(
            "UPDATE users SET totp_secret = ?, totp_confirmed_at = NULL,
                              totp_last_step = NULL
             WHERE id = ?"
        )->execute([$secret, $userId]);
    }

    echo json_encode([
        "secret" => $secret,
        "formatted" => totp_format_secret($secret),
        "uri" => totp_provisioning_uri($secret, $user["email"]),
        "digits" => TOTP_DIGITS,
        "period" => TOTP_PERIOD,
    ]);
} catch (PDOException $e) {
    error_log("auth/2fa-setup.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["error" => "Something went wrong. Please try again."]);
}
