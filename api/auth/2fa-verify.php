<?php

/**
 * The second factor at login. Accepts either a code from the authenticator
 * app or one of the recovery codes issued at enrolment.
 *
 * This is the only route from "password was right" to an actual session,
 * other than finishing enrolment.
 */

require_once __DIR__ . "/../session.php";
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../rate_limit.php";
require_once __DIR__ . "/../csrf.php";
require_once __DIR__ . "/../totp.php";
start_secure_session();
header("Content-Type: application/json");

// Deliberately the same wording whichever way it fails — wrong code, reused
// code, wrong recovery code. Distinguishing them would tell an attacker
// which of the two credentials they are closer to.
const BAD_CODE_MESSAGE =
    "That code didn't match. Check your authenticator app and try the current code.";

$body = json_decode(file_get_contents("php://input"), true);
csrf_verify_or_fail($body["csrf_token"] ?? null);

$userId = pending_login_user_id();
if ($userId === null) {
    http_response_code(401);
    echo json_encode(["error" => "Your sign-in timed out. Please log in again."]);
    exit;
}

$usingRecovery = !empty($body["recovery_code"]);
$limitKey = rate_limit_key("user:{$userId}");

$bucket = $usingRecovery ? "recovery_verify" : "totp_verify";
$maxAttempts = $usingRecovery ? RECOVERY_VERIFY_MAX_ATTEMPTS : TOTP_VERIFY_MAX_ATTEMPTS;
$window = $usingRecovery ? RECOVERY_VERIFY_WINDOW_SECONDS : TOTP_VERIFY_WINDOW_SECONDS;

if (!rate_limit_check($bucket, $maxAttempts, $window, $limitKey)) {
    http_response_code(429);
    echo json_encode([
        "error" => "Too many attempts. Please wait a few minutes and try again.",
    ]);
    exit;
}
rate_limit_record($bucket, $limitKey);

$pdo = null;

try {
    $pdo = get_pdo();

    $stmt = $pdo->prepare(
        "SELECT totp_secret, totp_confirmed_at, totp_last_step
         FROM users WHERE id = ?"
    );
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user || $user["totp_confirmed_at"] === null) {
        // Not enrolled, so there is nothing to verify against. Send them
        // back to enrolment rather than failing obscurely.
        http_response_code(409);
        echo json_encode([
            "error" => "Two-factor isn't set up on this account yet.",
            "next" => "enrol",
        ]);
        exit;
    }

    if ($usingRecovery) {
        if (!consume_recovery_code($pdo, $userId, $body["recovery_code"])) {
            http_response_code(400);
            echo json_encode(["error" => BAD_CODE_MESSAGE]);
            exit;
        }

        $remaining = count_unused_recovery_codes($pdo, $userId);

        rate_limit_clear($bucket, $limitKey);
        complete_login($userId);

        echo json_encode([
            "status" => "ok",
            "used_recovery_code" => true,
            "recovery_codes_remaining" => $remaining,
            "redirect" => "index.html",
        ]);
        exit;
    }

    $step = totp_verify($user["totp_secret"], $body["code"] ?? null);

    if ($step === null) {
        http_response_code(400);
        echo json_encode(["error" => BAD_CODE_MESSAGE]);
        exit;
    }

    // Replay guard. A code stays valid for its whole 30-second step and the
    // window accepts three steps, so without this a code seen over a
    // shoulder or captured by a phishing page could be spent twice.
    // Refusing anything at or below the last accepted step also blocks the
    // drift window being walked backwards.
    $lastStep = $user["totp_last_step"];
    if ($lastStep !== null && $step <= (int)$lastStep) {
        http_response_code(400);
        echo json_encode(["error" => BAD_CODE_MESSAGE]);
        exit;
    }

    $pdo->prepare("UPDATE users SET totp_last_step = ? WHERE id = ?")
        ->execute([$step, $userId]);

    rate_limit_clear($bucket, $limitKey);
    complete_login($userId);

    echo json_encode(["status" => "ok", "redirect" => "index.html"]);
} catch (PDOException $e) {
    if ($pdo !== null && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("auth/2fa-verify.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["error" => "Something went wrong. Please try again."]);
}


/**
 * Spend one recovery code, if it matches an unused one.
 *
 * Every unused hash has to be tried, because the codes are hashed with a
 * random salt each — there is no way to look one up directly. Ten hashes is
 * the cost, and the rate limit above keeps that from being a lever.
 */
function consume_recovery_code($pdo, $userId, $submitted) {
    $normalised = recovery_code_normalise($submitted);
    if ($normalised === "") {
        return false;
    }

    $stmt = $pdo->prepare(
        "SELECT id, code_hash FROM recovery_codes
         WHERE user_id = ? AND used_at IS NULL"
    );
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();

    foreach ($rows as $row) {
        if (!password_verify($normalised, $row["code_hash"])) {
            continue;
        }

        // Single use, enforced in the UPDATE rather than by reading first.
        // Two requests arriving together both see used_at IS NULL, but only
        // one of them changes a row — the other gets rowCount() 0 and is
        // treated as a miss.
        $spend = $pdo->prepare(
            "UPDATE recovery_codes SET used_at = NOW()
             WHERE id = ? AND used_at IS NULL"
        );
        $spend->execute([$row["id"]]);

        return $spend->rowCount() === 1;
    }

    return false;
}


function count_unused_recovery_codes($pdo, $userId) {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM recovery_codes WHERE user_id = ? AND used_at IS NULL"
    );
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}
