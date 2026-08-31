<?php

/**
 * Send another confirmation link.
 *
 * Takes an email address and no password, because the person asking is by
 * definition someone who cannot log in yet. That makes it the same shape of
 * endpoint as request-reset.php, with the same two problems to handle:
 *
 *   - it must not reveal whether an address has an account
 *   - it sends real mail to a real inbox, so it needs a tight limit or it
 *     becomes a way to use this server to bother somebody
 */

require_once __DIR__ . "/../session.php";
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../rate_limit.php";
require_once __DIR__ . "/../csrf.php";
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../email_verification.php";
start_secure_session();
header("Content-Type: application/json");

// Same ceiling as password reset requests, for the same reason: every
// success puts an email in somebody's inbox.
if (!rate_limit_check(
    "resend_verification",
    RESET_REQUEST_MAX_ATTEMPTS,
    RESET_REQUEST_WINDOW_SECONDS
)) {
    http_response_code(429);
    echo json_encode([
        "error" => "Too many requests. Please wait a few minutes and try again.",
    ]);
    exit;
}

$body = json_decode(file_get_contents("php://input"), true);
csrf_verify_or_fail($body["csrf_token"] ?? null);
rate_limit_record("resend_verification");

$email = trim($body["email"] ?? "");

if ($email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(["error" => "Please enter a valid email address."]);
    exit;
}

// The same answer whatever the truth is: address unknown, address known and
// already confirmed, address known and a link just went out.
$genericResponse = [
    "status" => "ok",
    "message" => "If that address needs confirming, a new link is on its way.",
];

try {
    $pdo = get_pdo();

    $stmt = $pdo->prepare(
        "SELECT id, name, email_verified_at FROM users WHERE email = ?"
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // Nothing to do in either of these cases, and neither says so.
    if (!$user || $user["email_verified_at"] !== null) {
        echo json_encode($genericResponse);
        exit;
    }

    $result = send_verification_email($pdo, $user["id"], $user["name"], $email);

    // Only useful to whoever runs the server: mail is not configured, so
    // the message went to logs/sent-mail.log. It deliberately does not
    // include the link.
    if (!($result["sent"] ?? false) && ($result["logged"] ?? false)) {
        $genericResponse["delivery"] = "log";
    }

    echo json_encode($genericResponse);
} catch (PDOException $e) {
    error_log("auth/resend-verification.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["error" => "Something went wrong. Please try again."]);
}
