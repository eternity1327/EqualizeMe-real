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

function rate_limit_check($bucket, $maxAttempts = 8, $windowSeconds = 300) {
    $ip = client_ip();
    $data = _rate_limit_read($bucket);
    $now = time();

    $recent = array_filter($data[$ip] ?? [], function ($t) use ($now, $windowSeconds) {
        return $t > $now - $windowSeconds;
    });

    return count($recent) < $maxAttempts;
}

function rate_limit_record($bucket) {
    $ip = client_ip();
    $data = _rate_limit_read($bucket);
    $now = time();

    $data[$ip] = array_filter($data[$ip] ?? [], function ($t) use ($now) {
        return $t > $now - 3600; // don't let the file grow forever
    });
    $data[$ip][] = $now;

    file_put_contents(_rate_limit_path($bucket), json_encode($data), LOCK_EX);
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
