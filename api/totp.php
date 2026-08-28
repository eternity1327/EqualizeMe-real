<?php

/**
 * Time-based one-time passwords, RFC 6238.
 *
 * People call this "Google 2FA" because Google Authenticator popularised
 * it, but nothing here talks to Google. It is an open standard: the server
 * and the phone share a secret, both compute HMAC-SHA1 over the current
 * 30-second time step, and both truncate the result to six digits. Any
 * authenticator app works — Google Authenticator, Authy, 1Password,
 * Microsoft Authenticator, KeePassXC. No network, no account, no vendor.
 *
 * Everything here uses functions PHP ships with. There is no dependency to
 * vendor and nothing to keep updated.
 */

/**
 * Whether every account must have two-factor before it can be used.
 *
 * false — opt-in. Password alone logs you in, unless you have turned
 *         two-factor on for yourself, in which case you are still asked
 *         for a code. This is the setting the app ships with.
 *
 * true  — mandatory. Anyone without it is sent through enrolment on their
 *         next login and cannot skip it.
 *
 * Flipping this to true is the only change needed to make it compulsory.
 * Nothing else reads the policy, and nobody who has already enrolled is
 * affected either way — being enrolled always means being asked.
 */
const TWO_FACTOR_REQUIRED = false;

// RFC 6238 defaults, which is what every authenticator app assumes when the
// provisioning URI does not say otherwise. Changing any of these means the
// app and the server disagree and every code looks wrong.
const TOTP_DIGITS = 6;
const TOTP_PERIOD = 30;
const TOTP_ALGORITHM = "sha1";

// 160 bits, the size RFC 4226 recommends for the shared secret.
const TOTP_SECRET_BYTES = 20;

// How many 30-second steps either side of now to accept. One step covers
// roughly a minute and a half of total tolerance, which absorbs ordinary
// phone-vs-server clock drift and a user who starts typing as the code is
// about to roll over. Raising it widens the window an attacker can guess
// into, so it stays at one.
const TOTP_DRIFT_STEPS = 1;

const BASE32_ALPHABET = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";


/**
 * A fresh secret, base32-encoded because that is what authenticator apps
 * and the otpauth:// URI format expect.
 */
function totp_new_secret() {
    return base32_encode(random_bytes(TOTP_SECRET_BYTES));
}


function base32_encode($binary) {
    if ($binary === "") {
        return "";
    }

    $bits = "";
    foreach (str_split($binary) as $byte) {
        $bits .= str_pad(decbin(ord($byte)), 8, "0", STR_PAD_LEFT);
    }

    // Pad up to a multiple of five so the final group is a whole symbol.
    $bits = str_pad($bits, (int)(ceil(strlen($bits) / 5) * 5), "0", STR_PAD_RIGHT);

    $out = "";
    foreach (str_split($bits, 5) as $chunk) {
        $out .= BASE32_ALPHABET[bindec($chunk)];
    }
    return $out;
}


function base32_decode($base32) {
    // Users retyping a secret by hand produce spaces, lowercase and the
    // occasional "=" carried over from somewhere. Normalise before the
    // strict check below, so a well-meaning typo is not an error.
    $clean = strtoupper(preg_replace('/[^A-Za-z2-7]/', "", (string)$base32));
    if ($clean === "") {
        return "";
    }

    $bits = "";
    for ($i = 0; $i < strlen($clean); $i++) {
        $index = strpos(BASE32_ALPHABET, $clean[$i]);
        if ($index === false) {
            return "";
        }
        $bits .= str_pad(decbin($index), 5, "0", STR_PAD_LEFT);
    }

    $out = "";
    foreach (str_split($bits, 8) as $chunk) {
        // The last group is padding left over from the 5-to-8 bit
        // regrouping, not data. Dropping it is what the standard expects.
        if (strlen($chunk) === 8) {
            $out .= chr(bindec($chunk));
        }
    }
    return $out;
}


/**
 * Which 30-second step a timestamp falls in. This is the number both sides
 * must agree on; everything else is arithmetic.
 */
function totp_time_step($timestamp = null) {
    return (int)floor(($timestamp ?? time()) / TOTP_PERIOD);
}


/**
 * The six digits for one step. Straight RFC 4226 dynamic truncation.
 */
function totp_code($base32Secret, $step) {
    $key = base32_decode($base32Secret);
    if ($key === "") {
        return null;
    }

    // The counter is a 64-bit big-endian integer. "J" would be machine byte
    // order, so the high and low words are packed separately to force big
    // endian on any platform.
    $counter = pack("N2", ($step >> 32) & 0xFFFFFFFF, $step & 0xFFFFFFFF);

    $hash = hash_hmac(TOTP_ALGORITHM, $counter, $key, true);

    // The low nibble of the last byte picks where in the hash to read from,
    // so the same secret does not always expose the same four bytes.
    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;

    $binary = ((ord($hash[$offset]) & 0x7F) << 24)
        | ((ord($hash[$offset + 1]) & 0xFF) << 16)
        | ((ord($hash[$offset + 2]) & 0xFF) << 8)
        | (ord($hash[$offset + 3]) & 0xFF);

    return str_pad(
        (string)($binary % (10 ** TOTP_DIGITS)),
        TOTP_DIGITS,
        "0",
        STR_PAD_LEFT
    );
}


/**
 * Check a submitted code against the accepted window.
 *
 * Returns the step the code matched, or null. The step matters to the
 * caller: storing it is what stops the same code being replayed while it is
 * still technically valid. Returning a bare true would throw that away.
 */
function totp_verify($base32Secret, $submitted, $timestamp = null) {
    $digits = preg_replace('/\D/', "", (string)$submitted);
    if (strlen($digits) !== TOTP_DIGITS) {
        return null;
    }

    $current = totp_time_step($timestamp);

    for ($offset = -TOTP_DRIFT_STEPS; $offset <= TOTP_DRIFT_STEPS; $offset++) {
        $expected = totp_code($base32Secret, $current + $offset);
        if ($expected === null) {
            return null;
        }
        // Constant time, for the same reason login.php uses it: a plain
        // === leaks how many leading digits were right.
        if (hash_equals($expected, $digits)) {
            return $current + $offset;
        }
    }

    return null;
}


/**
 * The otpauth:// URI that goes into the QR code.
 *
 * The label is "Issuer:account" and the issuer is repeated as a parameter —
 * that duplication is in the spec, and apps rely on it to group entries.
 */
function totp_provisioning_uri($base32Secret, $account, $issuer = "EqualizeME") {
    $label = rawurlencode($issuer) . ":" . rawurlencode($account);

    $params = http_build_query([
        "secret" => $base32Secret,
        "issuer" => $issuer,
        "algorithm" => strtoupper(TOTP_ALGORITHM),
        "digits" => TOTP_DIGITS,
        "period" => TOTP_PERIOD,
    ], "", "&", PHP_QUERY_RFC3986);

    return "otpauth://totp/{$label}?{$params}";
}


/**
 * The secret in readable groups, for anyone typing it in by hand because
 * their camera will not focus or the QR will not scan.
 */
function totp_format_secret($base32Secret) {
    return trim(chunk_split($base32Secret, 4, " "));
}


/* ───────────────────────────── recovery codes ─────────────────────────── */

// Ten codes is the usual number: enough to keep in a wallet or a notes app
// without feeling endless, few enough that people actually save them.
const RECOVERY_CODE_COUNT = 10;

// 5 bytes -> 8 base32 characters, shown as two groups of four. Two of these
// per code gives 80 bits, which is far past guessable and still short
// enough to read off paper.
const RECOVERY_CODE_BYTES = 5;


function recovery_code_generate() {
    $left = base32_encode(random_bytes(RECOVERY_CODE_BYTES));
    $right = base32_encode(random_bytes(RECOVERY_CODE_BYTES));
    return substr($left, 0, 4) . substr($left, 4, 4)
        . "-" . substr($right, 0, 4) . substr($right, 4, 4);
}


function recovery_codes_generate($count = RECOVERY_CODE_COUNT) {
    $codes = [];
    for ($i = 0; $i < $count; $i++) {
        $codes[] = recovery_code_generate();
    }
    return $codes;
}


/**
 * Strip formatting so "abcd efgh-ijkl mnop" and "ABCDEFGH-IJKLMNOP" are the
 * same code. Hyphens and case are presentation, not data.
 */
function recovery_code_normalise($code) {
    return strtoupper(preg_replace('/[^A-Za-z2-7]/', "", (string)$code));
}


/**
 * Recovery codes are stored hashed, exactly like passwords, because that is
 * what they are: a credential that logs someone in without the phone.
 */
function recovery_code_hash($code) {
    return password_hash(recovery_code_normalise($code), PASSWORD_DEFAULT);
}
