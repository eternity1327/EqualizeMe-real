<?php
/**
 * Step 2 of password reset: the user submits the emailed token and a new
 * password.
 *
 * A token must match a stored hash, be unexpired, and be unused — and is
 * marked used the moment it succeeds, so it can't be replayed.
 */
require_once __DIR__ . "/../session.php";
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../rate_limit.php";
require_once __DIR__ . "/../csrf.php";
require_once __DIR__ . "/../password_policy.php";
start_secure_session();
header("Content-Type: application/json");

// One message for every "this token won't work" case. Distinguishing
// expired from already-used from never-existed only helps someone
// probing token values.
const INVALID_TOKEN_MESSAGE =
    "This reset link is invalid or has expired. Please request a new one.";

// Guards against someone brute-forcing token values.
if (!rate_limit_check(
    "reset_submit",
    RESET_SUBMIT_MAX_ATTEMPTS,
    RESET_SUBMIT_WINDOW_SECONDS
)) {
    http_response_code(429);
    echo json_encode([
        "error" => "Too many attempts. Please wait a few minutes and try again.",
    ]);
    exit;
}

/**
 * True when this reset row can still be redeemed.
 */
function reset_is_usable($reset) {
    return $reset
        && $reset["used_at"] === null
        && strtotime($reset["expires_at"]) >= time();
}

$body = json_decode(file_get_contents("php://input"), true);
csrf_verify_or_fail($body["csrf_token"] ?? null);
rate_limit_record("reset_submit");

$token = trim($body["token"] ?? "");
$password = $body["password"] ?? "";

if ($token === "") {
    http_response_code(400);
    echo json_encode([
        "error" => "This reset link is missing its token. Please use the link from your email.",
    ]);
    exit;
}

$pdo = null;

try {
    $pdo = get_pdo();

    $tokenHash = hash("sha256", $token);

    $stmt = $pdo->prepare(
        "SELECT pr.id, pr.user_id, pr.expires_at, pr.used_at, u.email, u.name
         FROM password_resets pr
         JOIN users u ON u.id = pr.user_id
         WHERE pr.token_hash = ?"
    );
    $stmt->execute([$tokenHash]);
    $reset = $stmt->fetch();

    if (!reset_is_usable($reset)) {
        http_response_code(400);
        echo json_encode(["error" => INVALID_TOKEN_MESSAGE]);
        exit;
    }

    // Password rules are checked only after the token is known good, so a
    // stranger can't use this endpoint to probe the policy.
    $problems = password_problems($password, $reset["email"], $reset["name"]);
    if ($problems) {
        http_response_code(400);
        echo json_encode(["error" => password_error_message($problems)]);
        exit;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    $pdo->beginTransaction();

    $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
        ->execute([$hash, $reset["user_id"]]);

    // Mark this token used and clear any other outstanding ones for the
    // same user in the same breath.
    $pdo->prepare(
        "UPDATE password_resets SET used_at = NOW()
         WHERE user_id = ? AND used_at IS NULL"
    )->execute([$reset["user_id"]]);

    $pdo->commit();

    // Other devices already signed in as this user stay signed in.
    // Ending them needs a session store keyed by user, which PHP's
    // default file sessions can't provide.

    echo json_encode([
        "status" => "ok",
        "message" => "Your password has been updated. You can now log in.",
    ]);
} catch (PDOException $e) {
    if ($pdo !== null && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("reset-password.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["error" => "Something went wrong. Please try again."]);
}
