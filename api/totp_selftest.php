<?php

/**
 * Self-test for api/totp.php, checked against the RFC 6238 test vectors.
 *
 * Run it before trusting the login flow to it:
 *
 *     php api/totp_selftest.php
 *
 * If every line says PASS, this machine's PHP produces exactly the codes
 * the standard specifies, which means any authenticator app will agree
 * with it. If anything fails, do not enable two-factor — a mismatch here
 * locks people out rather than letting them in.
 */

// CLI only. This file has no business being reachable over HTTP, and
// refusing outright is simpler than remembering to delete it later.
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require_once __DIR__ . "/totp.php";

$failures = 0;

function check($label, $got, $want) {
    global $failures;
    $ok = $got === $want;
    if (!$ok) {
        $failures++;
    }
    printf("  %-4s %-46s got %-14s want %s\n",
        $ok ? "PASS" : "FAIL", $label, var_export($got, true), var_export($want, true));
}

echo "\nRFC 6238 test vectors (SHA1)\n";

// The secret the RFC uses for its SHA1 row, as a base32 string.
$rfcSecret = base32_encode("12345678901234567890");
check("secret encodes to the published value", $rfcSecret,
    "GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ");
check("and decodes back", base32_decode($rfcSecret), "12345678901234567890");

// The RFC publishes 8-digit codes; this implementation emits 6, which are
// the last six digits of each.
$vectors = [
    59 => "287082",
    1111111109 => "081804",
    1111111111 => "050471",
    1234567890 => "005924",
    2000000000 => "279037",
    20000000000 => "353130",
];

foreach ($vectors as $time => $expected) {
    check("T={$time}", totp_code($rfcSecret, totp_time_step($time)), $expected);
}

echo "\nVerification window\n";

$now = time();
$step = totp_time_step($now);

check("current code is accepted",
    totp_verify($rfcSecret, totp_code($rfcSecret, $step), $now), $step);
check("previous step accepted (clock drift)",
    totp_verify($rfcSecret, totp_code($rfcSecret, $step - 1), $now), $step - 1);
check("next step accepted (clock drift)",
    totp_verify($rfcSecret, totp_code($rfcSecret, $step + 1), $now), $step + 1);
check("two steps back rejected",
    totp_verify($rfcSecret, totp_code($rfcSecret, $step - 2), $now), null);
check("two steps forward rejected",
    totp_verify($rfcSecret, totp_code($rfcSecret, $step + 2), $now), null);

echo "\nMalformed input is rejected rather than throwing\n";

check("empty", totp_verify($rfcSecret, "", $now), null);
check("too short", totp_verify($rfcSecret, "12345", $now), null);
check("too long", totp_verify($rfcSecret, "1234567", $now), null);
check("letters", totp_verify($rfcSecret, "abcdef", $now), null);
check("null", totp_verify($rfcSecret, null, $now), null);
// Spaces are stripped before length checking, so "123 456" is six digits.
check("spaced code still works",
    totp_verify($rfcSecret, chunk_split(totp_code($rfcSecret, $step), 3, " "), $now), $step);

echo "\nSecrets and recovery codes\n";

$secret = totp_new_secret();
check("new secret is 32 base32 chars", strlen($secret), 32);
check("new secret decodes to 20 bytes", strlen(base32_decode($secret)), 20);
check("two secrets differ", $secret === totp_new_secret(), false);

$codes = recovery_codes_generate();
check("ten recovery codes", count($codes), RECOVERY_CODE_COUNT);
check("all distinct", count(array_unique($codes)), RECOVERY_CODE_COUNT);
check("formatting is ignored when matching",
    recovery_code_normalise(strtolower($codes[0])),
    recovery_code_normalise($codes[0]));
check("a hashed code verifies",
    password_verify(recovery_code_normalise($codes[0]), recovery_code_hash($codes[0])),
    true);
check("a different code does not",
    password_verify(recovery_code_normalise($codes[1]), recovery_code_hash($codes[0])),
    false);

echo "\nProvisioning URI\n";

$uri = totp_provisioning_uri($secret, "someone@example.com");
check("scheme and label", str_starts_with($uri, "otpauth://totp/EqualizeME:"), true);
check("carries the secret", str_contains($uri, "secret={$secret}"), true);
check("names the issuer", str_contains($uri, "issuer=EqualizeME"), true);
check("declares SHA1", str_contains($uri, "algorithm=SHA1"), true);
check("declares 6 digits", str_contains($uri, "digits=6"), true);
check("declares a 30s period", str_contains($uri, "period=30"), true);

echo "\n";
if ($failures === 0) {
    echo "All checks passed. This PHP agrees with the standard, so any\n";
    echo "authenticator app will agree with it too.\n\n";
    exit(0);
}

echo "{$failures} check(s) FAILED. Do not enable two-factor until this is\n";
echo "resolved — a mismatch here locks users out rather than letting them in.\n\n";
exit(1);
