<?php

/**
 * Whether two-factor is on for the signed-in account, and how many
 * recovery codes are left.
 *
 * Kept out of api/settings.php on purpose. That endpoint reads and writes
 * the `settings` table, and two-factor state does not live there — it lives
 * on `users`, next to the password hash, because it is a credential rather
 * than a preference. Putting it behind the same PUT that flips checkboxes
 * would eventually let someone turn it off by sending
 * {"twoFactor": false}, with no password and no confirmation.
 *
 * Read-only. Turning it on goes through 2fa-setup + 2fa-activate; turning
 * it off goes through 2fa-disable and needs the password.
 */

require_once __DIR__ . "/../session.php";
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../totp.php";
start_secure_session();
header("Content-Type: application/json");

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    echo json_encode(["error" => "Not logged in"]);
    exit;
}

try {
    $pdo = get_pdo();

    $stmt = $pdo->prepare("SELECT totp_confirmed_at FROM users WHERE id = ?");
    $stmt->execute([$_SESSION["user_id"]]);
    $confirmedAt = $stmt->fetchColumn();

    $enabled = $confirmedAt !== false && $confirmedAt !== null;

    $remaining = 0;
    if ($enabled) {
        $count = $pdo->prepare(
            "SELECT COUNT(*) FROM recovery_codes WHERE user_id = ? AND used_at IS NULL"
        );
        $count->execute([$_SESSION["user_id"]]);
        $remaining = (int)$count->fetchColumn();
    }

    echo json_encode([
        "enabled" => $enabled,
        "enabledAt" => $enabled ? $confirmedAt : null,
        "recoveryCodesRemaining" => $remaining,
        // Lets the interface hide the "turn off" control entirely when the
        // policy would refuse it anyway.
        "required" => TWO_FACTOR_REQUIRED,
    ]);
} catch (PDOException $e) {
    error_log("auth/2fa-status.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["error" => "Something went wrong"]);
}
