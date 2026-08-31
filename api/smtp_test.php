<?php

/**
 * Check the SMTP settings without going through the whole reset flow.
 *
 *     php api/smtp_test.php you@example.com
 *
 * Sends one short message and prints what actually happened. Iterating on
 * a wrong app password through the web form means a page load, a form, and
 * then digging through a log file; this is one command.
 *
 * CLI only. It reveals whether an address is configured and would be a
 * small gift to anyone poking at the site.
 */

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/mailer.php";

$to = $argv[1] ?? "";

if ($to === "" || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    echo "\nUsage: php api/smtp_test.php you@example.com\n\n";
    exit(1);
}

$smtp = app_config()["smtp"];

echo "\nConfigured\n";
printf("  enabled    %s\n", !empty($smtp["enabled"]) ? "yes" : "NO — nothing will be sent");
printf("  host       %s\n", $smtp["host"] ?: "(empty)");
printf("  port       %s\n", $smtp["port"]);
printf("  secure     %s\n", $smtp["secure"]);
printf("  username   %s\n", $smtp["username"] ?: "(empty)");
printf("  from       %s\n", $smtp["from_email"] ?: "(empty)");

// The password itself is never printed. Its shape is, because that is
// where the usual mistakes show: a Google app password is sixteen letters,
// and pasting the account password or leaving a placeholder in gives a
// length that is obviously wrong.
$password = (string)($smtp["password"] ?? "");
$stripped = preg_replace('/\s+/', "", $password);
printf("  password   %d characters (%d without spaces)\n",
    strlen($password), strlen($stripped));

// Say plainly what the mailer will actually send, so the cleaning is
// visible rather than something that happens behind your back.
$effective = _mailer_clean_password($password, $smtp["host"]);

if ($effective !== $password) {
    printf("             spaces removed before sending — %d characters go to Gmail\n",
        strlen($effective));
} elseif (strlen($stripped) === 16) {
    echo "             right shape for a Google app password\n";
}

if ($smtp["host"] === "smtp.gmail.com" && !preg_match('/^[a-z]{16}$/', $stripped)) {
    echo "\n  Note: a Google app password is exactly 16 lowercase letters, no\n";
    echo "  digits. This is not that shape, so it is probably the account\n";
    echo "  password or a leftover placeholder. Gmail SMTP has not accepted\n";
    echo "  account passwords since 2022 — it has to be an app password.\n";
}

if (empty($smtp["enabled"])) {
    echo "\nSMTP is disabled, so send_email() will write to the log instead.\n";
    echo "Set 'enabled' => true in api/config.local.php to actually send.\n\n";
    exit(1);
}

// Pass anything as a second argument to see the SMTP conversation itself.
// That is where a real answer lives when "could not authenticate" is not
// enough — the server's own rejection message is usually specific.
if (isset($argv[2])) {
    echo "\n--- SMTP conversation ---\n";
    $GLOBALS["MAILER_DEBUG"] = true;
}

echo "\nSending to {$to} ...\n";

$result = send_email(
    $to,
    "EqualizeME SMTP test",
    "If you are reading this, the SMTP settings work.\n\n"
        . "Sent by api/smtp_test.php at " . date("Y-m-d H:i:s") . ".\n"
);

echo "\n";

if (!empty($result["sent"])) {
    echo "SENT. Check the inbox — and the spam folder, first time round.\n\n";
    exit(0);
}

echo "FAILED: " . ($result["reason"] ?? "unknown") . "\n";

if (!empty($result["logged"])) {
    echo "Written to logs/sent-mail.log instead. The exact SMTP error is on\n";
    echo "the REASON line of the last entry there.\n";
}

echo "\nThe usual causes, in order of likelihood:\n";
echo "  - 'Could not authenticate': the app password is wrong, was revoked,\n";
echo "    or belongs to a different Google account than 'username'\n";
echo "  - the account password was used instead of an app password\n";
echo "  - 2-Step Verification is off, so app passwords do not exist yet\n";
echo "  - port/secure mismatched: 587 goes with tls, 465 with ssl\n";
echo "  - a firewall blocking outbound 587\n\n";

exit(1);
