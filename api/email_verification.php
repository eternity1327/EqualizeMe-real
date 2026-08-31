<?php

/**
 * Issuing and sending email-verification links.
 *
 * Shared by register.php (first send) and resend-verification.php, so the
 * token format and the email wording only exist once.
 *
 * Same design as password_resets: a long random token goes in the email,
 * only its SHA-256 hash is stored, older unused tokens are cancelled when a
 * new one is issued, and each is single-use with an expiry.
 */

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/mailer.php";

const VERIFICATION_TOKEN_BYTES = 32;

// Longer than a password reset's hour. A reset is something you asked for
// thirty seconds ago; a signup confirmation often waits until someone gets
// back to their inbox.
const VERIFICATION_LIFETIME_HOURS = 48;


function verification_email_body($name, $url, $hours) {
    return "Hi {$name},\n\n"
        . "Thanks for signing up to EqualizeME. Confirm this email address\n"
        . "to finish setting up your account:\n\n"
        . $url . "\n\n"
        . "The link works once and expires in {$hours} hours.\n\n"
        . "If you didn't sign up, you can ignore this email — the account\n"
        . "cannot be used until someone opens the link above.\n\n"
        . "— EqualizeME\n";
}


/**
 * Issue a fresh token and email it.
 *
 * Returns the send result, or null if the account is already verified.
 * Never throws on a mail failure: a signup should not fail because SMTP is
 * down, and the caller decides what to tell the user.
 */
function send_verification_email($pdo, $userId, $name, $email) {
    // Cancel anything outstanding first, so an older link in an older email
    // stops working the moment a newer one is sent.
    $pdo->prepare(
        "UPDATE email_verifications SET used_at = NOW()
         WHERE user_id = ? AND used_at IS NULL"
    )->execute([$userId]);

    $token = bin2hex(random_bytes(VERIFICATION_TOKEN_BYTES));

    $pdo->prepare(
        "INSERT INTO email_verifications (user_id, token_hash, expires_at)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR))"
    )->execute([
        $userId,
        hash("sha256", $token),
        VERIFICATION_LIFETIME_HOURS,
    ]);

    $url = app_base_url() . "/verify-email.php?token=" . urlencode($token);

    return send_email(
        $email,
        "Confirm your EqualizeME email",
        verification_email_body($name, $url, VERIFICATION_LIFETIME_HOURS)
    );
}


/**
 * Spend a token. Returns the user id on success, or null.
 *
 * The three conditions are checked together in the lookup rather than one
 * at a time, so an expired token and a fake one are indistinguishable from
 * outside.
 */
function consume_verification_token($pdo, $token) {
    $token = trim((string)$token);
    if ($token === "") {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT id, user_id FROM email_verifications
         WHERE token_hash = ? AND used_at IS NULL AND expires_at >= NOW()"
    );
    $stmt->execute([hash("sha256", $token)]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    // Single use enforced in the UPDATE, not by having read it above. Two
    // clicks arriving together both see used_at IS NULL; only one of them
    // changes a row.
    $spend = $pdo->prepare(
        "UPDATE email_verifications SET used_at = NOW()
         WHERE id = ? AND used_at IS NULL"
    );
    $spend->execute([$row["id"]]);

    if ($spend->rowCount() !== 1) {
        return null;
    }

    $pdo->prepare(
        "UPDATE users SET email_verified_at = NOW()
         WHERE id = ? AND email_verified_at IS NULL"
    )->execute([$row["user_id"]]);

    return (int)$row["user_id"];
}
