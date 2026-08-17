<?php
/**
 * Step 1 of password reset: the user submits an email, we send a link.
 *
 * Always responds with the same success message whether or not the email
 * is registered. "No account with that email" would let anyone enumerate
 * which addresses have accounts here.
 */
require_once __DIR__ . "/../session.php";
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../rate_limit.php";
require_once __DIR__ . "/../csrf.php";
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../mailer.php";
start_secure_session();
header("Content-Type: application/json");

// 32 bytes of randomness, hex-encoded: far beyond guessing range.
const RESET_TOKEN_BYTES = 32;
const DEFAULT_TOKEN_LIFETIME_MINUTES = 60;

// Tighter than login's limit — there's no legitimate reason to request
// many reset emails in a row, and each one mails a real person.
if (!rate_limit_check(
    "reset_request",
    RESET_REQUEST_MAX_ATTEMPTS,
    RESET_REQUEST_WINDOW_SECONDS
)) {
    http_response_code(429);
    echo json_encode([
        "error" => "Too many reset requests. Please wait a few minutes and try again.",
    ]);
    exit;
}

$body = json_decode(file_get_contents("php://input"), true);
csrf_verify_or_fail($body["csrf_token"] ?? null);
rate_limit_record("reset_request");

$email = trim($body["email"] ?? "");

if ($email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(["error" => "Please enter a valid email address."]);
    exit;
}

// The response every caller gets, whether or not the account exists.
$genericResponse = [
    "status" => "ok",
    "message" => "If an account exists for that email, a reset link is on its way.",
];

/**
 * The body of the reset email.
 */
function reset_email_body($name, $resetUrl, $lifetimeMinutes) {
    return "Hi {$name},\n\n"
        . "Someone asked to reset the password for your EqualizeME account.\n"
        . "If that was you, open this link to choose a new password:\n\n"
        . $resetUrl . "\n\n"
        . "The link expires in {$lifetimeMinutes} minutes and can only be used once.\n\n"
        . "If you didn't request this, you can ignore this email — your\n"
        . "password stays exactly as it is.\n\n"
        . "— EqualizeME\n";
}

try {
    $pdo = get_pdo();

    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        echo json_encode($genericResponse);
        exit;
    }

    // Invalidate any earlier unused tokens for this user, so an old email
    // sitting in their inbox can't be used after requesting a new one.
    $pdo->prepare(
        "UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL"
    )->execute([$user["id"]]);

    // The raw token goes in the email; only its hash is stored, so the
    // database alone is useless to an attacker.
    $token = bin2hex(random_bytes(RESET_TOKEN_BYTES));
    $tokenHash = hash("sha256", $token);

    $lifetimeMinutes = (int)(app_config()["reset_token_lifetime_minutes"]
        ?? DEFAULT_TOKEN_LIFETIME_MINUTES);

    $pdo->prepare(
        "INSERT INTO password_resets (user_id, token_hash, expires_at)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))"
    )->execute([$user["id"], $tokenHash, $lifetimeMinutes]);

    $resetUrl = app_base_url() . "/reset-password.php?token=" . urlencode($token);

    $result = send_email(
        $email,
        "Reset your EqualizeME password",
        reset_email_body($user["name"], $resetUrl, $lifetimeMinutes)
    );

    // Unconfigured mail is written to logs/sent-mail.log instead. Flagged
    // so the UI can point the developer at it — never including the link,
    // which would hand anyone a working reset for any address they type.
    if (!$result["sent"] && $result["logged"]) {
        $genericResponse["delivery"] = "log";
    }

    echo json_encode($genericResponse);
} catch (PDOException $e) {
    // A missing password_resets table is the most likely cause here, so
    // make that specific case obvious in the server log rather than
    // leaving a silent 500.
    error_log("request-reset.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["error" => "Something went wrong. Please try again."]);
}
