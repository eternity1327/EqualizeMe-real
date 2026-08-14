<?php
/**
 * Lightweight brute-force guard for login/register — file-based, no DB
 * schema change needed. Blocks an IP after too many attempts within a
 * short window. This is a basic deterrent for a small group of users on
 * a home network / simple public link, not a substitute for a real rate
 * limiter if this project ever needs to handle serious abuse traffic.
 */
/**
 * The visitor's real IP address.
 *
 * Behind a tunnel or reverse proxy, REMOTE_ADDR is the proxy itself
 * (127.0.0.1 for cloudflared), so every visitor would otherwise share a
 * single rate-limit bucket — one person's failed logins would lock out
 * the whole group.
 *
 * The forwarded-IP headers are only trusted when the request genuinely
 * arrived from the local proxy. Trusting them unconditionally would let
 * anyone bypass rate limiting entirely just by sending a made-up
 * CF-Connecting-IP header with each attempt.
 */
function client_ip() {
    $remote = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    $localProxies = ['127.0.0.1', '::1'];
    if (!in_array($remote, $localProxies, true)) {
        return $remote;
    }

    // Cloudflare's header is a single IP and is the more reliable of the two.
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $candidate = trim($_SERVER['HTTP_CF_CONNECTING_IP']);
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $candidate;
        }
    }

    // X-Forwarded-For is a comma-separated chain; the original client is first.
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        foreach (explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']) as $part) {
            $candidate = trim($part);
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }
    }

    return $remote;
}

/**
 * A stable, non-reversible key for throttling something other than an IP.
 *
 * Used to limit attempts per ACCOUNT. Rate limiting by IP alone stops one
 * machine hammering the login form, but does nothing against an attacker
 * spread across many addresses all targeting the same account — each IP
 * stays under its own limit while the account is ground down. Counting
 * failures per account closes that.
 *
 * Hashed rather than stored raw so the throttle file doesn't become a
 * plaintext list of everyone's email addresses sitting in the temp
 * directory.
 */
function rate_limit_key($value) {
    return substr(hash('sha256', strtolower(trim($value))), 0, 32);
}

/**
 * $key defaults to the caller's IP. Pass rate_limit_key($email) to
 * throttle by account instead.
 */
function rate_limit_check($bucket, $maxAttempts = 8, $windowSeconds = 300, $key = null) {
    $key = $key ?? client_ip();
    $data = _rate_limit_read($bucket);
    $now = time();

    $recent = array_filter($data[$key] ?? [], function ($t) use ($now, $windowSeconds) {
        return $t > $now - $windowSeconds;
    });

    return count($recent) < $maxAttempts;
}

function rate_limit_record($bucket, $key = null) {
    $key = $key ?? client_ip();
    $data = _rate_limit_read($bucket);
    $now = time();

    $data[$key] = array_filter($data[$key] ?? [], function ($t) use ($now) {
        return $t > $now - 3600; // don't let the file grow forever
    });
    $data[$key][] = $now;

    file_put_contents(_rate_limit_path($bucket), json_encode($data), LOCK_EX);
}

/**
 * Clears a key's recorded attempts. Called after a successful login so a
 * few mistyped passwords don't leave someone throttled once they've
 * proven they own the account.
 */
function rate_limit_clear($bucket, $key = null) {
    $key = $key ?? client_ip();
    $data = _rate_limit_read($bucket);

    if (isset($data[$key])) {
        unset($data[$key]);
        file_put_contents(_rate_limit_path($bucket), json_encode($data), LOCK_EX);
    }
}

function _rate_limit_path($bucket) {
    return sys_get_temp_dir() . "/equalizeme_ratelimit_" . preg_replace('/[^a-z0-9_-]/i', '', $bucket) . ".json";
}

function _rate_limit_read($bucket) {
    $path = _rate_limit_path($bucket);
    if (!file_exists($path)) {
        return [];
    }
    $decoded = json_decode(file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}
