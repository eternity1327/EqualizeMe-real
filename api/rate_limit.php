<?php
/**
 * Lightweight brute-force guard for login/register — file-based, no DB
 * schema change needed. Blocks an IP after too many attempts within a
 * short window. This is a basic deterrent for a small group of users on
 * a home network / simple public link, not a substitute for a real rate
 * limiter if this project ever needs to handle serious abuse traffic.
 */
function rate_limit_check($bucket, $maxAttempts = 8, $windowSeconds = 300) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $data = _rate_limit_read($bucket);
    $now = time();

    $recent = array_filter($data[$ip] ?? [], function ($t) use ($now, $windowSeconds) {
        return $t > $now - $windowSeconds;
    });

    return count($recent) < $maxAttempts;
}

function rate_limit_record($bucket) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
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
