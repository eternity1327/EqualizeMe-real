<?php
/**
 * Brute-force guard for login, registration and password reset.
 *
 * File-based so it needs no schema change. A deterrent for a small group
 * of users on a simple public link, not a substitute for a real rate
 * limiter under serious abuse traffic.
 */

// Defaults for rate_limit_check(). Eight attempts in five minutes is
// generous for someone mistyping a password and useless for a script.
const RATE_LIMIT_MAX_ATTEMPTS = 8;
const RATE_LIMIT_WINDOW_SECONDS = 300;

// Per-endpoint policy, kept here so every limit in the project is
// visible in one place rather than scattered as numbers across the
// route files. Sensitive actions get tighter limits than ordinary ones.
//
// The per-account login limit is stricter than the per-IP one: a real
// person rarely fails their own password ten times in a quarter hour.
const LOGIN_ACCOUNT_MAX_ATTEMPTS = 10;
const LOGIN_ACCOUNT_WINDOW_SECONDS = 900;

const REGISTER_MAX_ATTEMPTS = 5;
const REGISTER_WINDOW_SECONDS = 600;

// Reset requests send email, so they're throttled hardest.
const RESET_REQUEST_MAX_ATTEMPTS = 4;
const RESET_REQUEST_WINDOW_SECONDS = 900;

const RESET_SUBMIT_MAX_ATTEMPTS = 10;
const RESET_SUBMIT_WINDOW_SECONDS = 900;

// Attempts older than this are dropped so the file can't grow forever.
const RATE_LIMIT_RETENTION_SECONDS = 3600;

// Enough of the hash to make collisions irrelevant, short enough to keep
// the file readable.
const RATE_LIMIT_KEY_LENGTH = 32;

// Requests arriving from these are the local tunnel or proxy, not a real
// visitor, so their forwarded headers are the only trustworthy source.
const LOCAL_PROXIES = ['127.0.0.1', '::1'];

/**
 * The visitor's real IP address.
 *
 * Behind a tunnel, REMOTE_ADDR is the proxy itself, so every visitor
 * would share one bucket and a single person's failed logins would lock
 * out the whole group. Forwarded headers are trusted only when the
 * request really came from the local proxy — trusting them always would
 * let anyone bypass the limit with a made-up header.
 */
function client_ip() {
    $remote = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    if (!in_array($remote, LOCAL_PROXIES, true)) {
        return $remote;
    }

    // Cloudflare's header holds a single IP and is the more reliable.
    $forwarded = _first_valid_ip($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '')
        ?? _first_valid_ip($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');

    return $forwarded ?? $remote;
}

/**
 * The first valid IP in a header value, or null.
 *
 * X-Forwarded-For is a comma-separated chain with the original client
 * first; CF-Connecting-IP is a single address, which this also handles.
 */
function _first_valid_ip($header) {
    foreach (explode(',', $header) as $part) {
        $candidate = trim($part);
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $candidate;
        }
    }
    return null;
}

/**
 * A stable, non-reversible key for throttling by account.
 *
 * Limiting by IP alone stops one machine hammering the login form but
 * does nothing against an attacker spread across many addresses against
 * one account — each IP stays under its own limit while the account is
 * ground down. Hashed so the throttle file doesn't become a plaintext
 * list of everyone's email addresses.
 */
function rate_limit_key($value) {
    return substr(hash('sha256', strtolower(trim($value))), 0, RATE_LIMIT_KEY_LENGTH);
}

/**
 * Whether another attempt is allowed. $key defaults to the caller's IP;
 * pass rate_limit_key($email) to throttle by account instead.
 */
function rate_limit_check(
    $bucket,
    $maxAttempts = RATE_LIMIT_MAX_ATTEMPTS,
    $windowSeconds = RATE_LIMIT_WINDOW_SECONDS,
    $key = null
) {
    $key = $key ?? client_ip();
    $attempts = _rate_limit_read($bucket)[$key] ?? [];

    return count(_since($attempts, $windowSeconds)) < $maxAttempts;
}

/**
 * Records one attempt against this key.
 */
function rate_limit_record($bucket, $key = null) {
    $key = $key ?? client_ip();
    $data = _rate_limit_read($bucket);

    $data[$key] = _since($data[$key] ?? [], RATE_LIMIT_RETENTION_SECONDS);
    $data[$key][] = time();

    _rate_limit_write($bucket, $data);
}

/**
 * Clears a key's attempts, so a few mistyped passwords don't leave
 * someone throttled once they've proven they own the account.
 */
function rate_limit_clear($bucket, $key = null) {
    $key = $key ?? client_ip();
    $data = _rate_limit_read($bucket);

    if (!isset($data[$key])) {
        return;
    }

    unset($data[$key]);
    _rate_limit_write($bucket, $data);
}

/**
 * Only the timestamps within the last $seconds.
 */
function _since($timestamps, $seconds) {
    $cutoff = time() - $seconds;

    return array_values(array_filter($timestamps, function ($at) use ($cutoff) {
        return $at > $cutoff;
    }));
}

function _rate_limit_path($bucket) {
    $safeName = preg_replace('/[^a-z0-9_-]/i', '', $bucket);

    return sys_get_temp_dir() . "/equalizeme_ratelimit_{$safeName}.json";
}

function _rate_limit_read($bucket) {
    $path = _rate_limit_path($bucket);
    if (!file_exists($path)) {
        return [];
    }

    $decoded = json_decode(file_get_contents($path), true);

    return is_array($decoded) ? $decoded : [];
}

function _rate_limit_write($bucket, $data) {
    file_put_contents(_rate_limit_path($bucket), json_encode($data), LOCK_EX);
}
