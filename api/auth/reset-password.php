<?php

require_once __DIR__ . "/../session.php";
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../rate_limit.php";
require_once __DIR__ . "/../csrf.php";
require_once __DIR__ . "/../password_policy.php";
start_secure_session();
header("Content-Type: application/json");

const INVALID_TOKEN_MESSAGE =
    "This reset link is invalid or has expired. Please request a new one.";

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
 * Is this token still good?
 *
 * Expiry is decided by the database, not here. expires_at is written by
 * MySQL's NOW(); comparing it with PHP's time() asks two different clocks
 * in two possibly different timezones whether the same instant has passed.
 * Where they disagree -- which shared hosting makes easy, since nothing in
 * this project sets date.timezone -- a link is "expired" the moment it is
 * created, and no amount of requesting a new one helps.
 *
 * So the query returns is_expired, evaluated in SQL against the same clock
 * that set the column, and this function only reads the answer.
 */
function reset_is_usable($reset) {
    return $reset
        && $reset["used_at"] === null
        && (int)$reset["is_expired"] === 0;
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
        "SELECT pr.id, pr.user_id, pr.expires_at, pr.used_at,
                (pr.expires_at < NOW()) AS is_expired,
                u.email, u.name
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

    $pdo->prepare(
        "UPDATE password_resets SET used_at = NOW()
         WHERE user_id = ? AND used_at IS NULL"
    )->execute([$reset["user_id"]]);

    $pdo->commit();

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
