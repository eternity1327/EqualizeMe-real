<?php
/**
 * Step 2 of password reset: user submits the token from their email plus
 * a new password.
 *
 * Token rules enforced here:
 *   - must match a stored hash
 *   - must not be expired
 *   - must not have been used already
 *   - is marked used the moment it succeeds, so it can't be replayed
 *
 * All existing sessions for that user are left alone deliberately — see
 * the note at the bottom.
 */
require_once __DIR__ . "/../session.php";
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../rate_limit.php";
require_once __DIR__ . "/../csrf.php";
require_once __DIR__ . "/../password_policy.php";
start_secure_session();
header("Content-Type: application/json");

// Guards against someone brute-forcing token values.
if (!rate_limit_check("reset_submit", 10, 900)) {
    http_response_code(429);
    echo json_encode(["error" => "Too many attempts. Please wait a few minutes and try again."]);
    exit;
}

$body = json_decode(file_get_contents("php://input"), true);
csrf_verify_or_fail($body["csrf_token"] ?? null);
rate_limit_record("reset_submit");

$token = trim($body["token"] ?? "");
$password = $body["password"] ?? "";

if ($token === "") {
    http_response_code(400);
    echo json_encode(["error" => "This reset link is missing its token. Please use the link from your email."]);
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

    // One shared message for every "this token won't work" case — telling
    // the difference between expired, already-used and never-existed only
    // helps someone probing tokens.
    $invalidMessage = "This reset link is invalid or has expired. Please request a new one.";

    if (!$reset || $reset["used_at"] !== null || strtotime($reset["expires_at"]) < time()) {
        http_response_code(400);
        echo json_encode(["error" => $invalidMessage]);
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

    // Mark this token used, and clear any other outstanding ones for the
    // same user in the same breath.
    $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")
        ->execute([$reset["user_id"]]);

    $pdo->commit();

    // NOTE: other devices already logged in as this user stay logged in.
    // Killing them would need a server-side session store keyed by user,
    // which this project doesn't have (PHP's default file sessions can't
    // be looked up by user id). Worth adding if account takeover ever
    // becomes a real concern here.

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
