<?php

const RATE_LIMIT_MAX_ATTEMPTS = 8;
const RATE_LIMIT_WINDOW_SECONDS = 300;

const LOGIN_ACCOUNT_MAX_ATTEMPTS = 10;
const LOGIN_ACCOUNT_WINDOW_SECONDS = 900;

const REGISTER_MAX_ATTEMPTS = 5;
const REGISTER_WINDOW_SECONDS = 600;

const RESET_REQUEST_MAX_ATTEMPTS = 4;
const RESET_REQUEST_WINDOW_SECONDS = 900;

const RESET_SUBMIT_MAX_ATTEMPTS = 10;
const RESET_SUBMIT_WINDOW_SECONDS = 900;

// A six-digit code is one in a million, but the accepted window is three
// steps wide, so a blind guess is really about 3 in a million per attempt.
// That is only safe while the number of attempts stays small: at 10 tries
// per 15 minutes an attacker needs centuries, at 10 per second they need an
// afternoon. This limit is the entire security margin of the second factor,
// which is why it is tighter than the password limit rather than looser.
const TOTP_VERIFY_MAX_ATTEMPTS = 10;
const TOTP_VERIFY_WINDOW_SECONDS = 900;

// Recovery codes are 80 bits and unguessable, so the limit here is about
// stopping someone hammering the endpoint rather than about the odds.
const RECOVERY_VERIFY_MAX_ATTEMPTS = 5;
const RECOVERY_VERIFY_WINDOW_SECONDS = 900;

// Avatar uploads. Generous enough that nobody picking a photo will notice,
// tight enough that a loop cannot fill the disk: 10 uploads at the 5 MB
// ceiling is 50 MB per 10 minutes, and each new file replaces the last.
const UPLOAD_PICTURE_MAX_ATTEMPTS = 10;
const UPLOAD_PICTURE_WINDOW_SECONDS = 600;

const RATE_LIMIT_RETENTION_SECONDS = 3600;

const RATE_LIMIT_KEY_LENGTH = 32;

const LOCAL_PROXIES = ['127.0.0.1', '::1'];

function client_ip() {
    $remote = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    if (!in_array($remote, LOCAL_PROXIES, true)) {
        return $remote;
    }

    $forwarded = _first_valid_ip($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '')
        ?? _first_valid_ip($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');

    return $forwarded ?? $remote;
}

function _first_valid_ip($header) {
    foreach (explode(',', $header) as $part) {
        $candidate = trim($part);
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $candidate;
        }
    }
    return null;
}

function rate_limit_key($value) {
    return substr(hash('sha256', strtolower(trim($value))), 0, RATE_LIMIT_KEY_LENGTH);
}

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

function rate_limit_record($bucket, $key = null) {
    $key = $key ?? client_ip();

    _rate_limit_update($bucket, function ($data) use ($key) {
        $data[$key] = _since($data[$key] ?? [], RATE_LIMIT_RETENTION_SECONDS);
        $data[$key][] = time();
        return $data;
    });
}

function rate_limit_clear($bucket, $key = null) {
    $key = $key ?? client_ip();

    _rate_limit_update($bucket, function ($data) use ($key) {
        unset($data[$key]);
        return $data;
    });
}

/**
 * Read, change and write the counter file without letting go of the lock.
 *
 * The previous version read with file_get_contents and wrote with LOCK_EX,
 * which locks the wrong half. Two requests arriving together both read the
 * same count, both add one, and the second write overwrites the first — so
 * an attacker firing requests in parallel got more attempts than the limit
 * claims. The lock has to span the read as well, which means one handle
 * held open across both.
 */
function _rate_limit_update($bucket, callable $mutate) {
    $path = _rate_limit_path($bucket);

    // 'c+' opens for read and write, creates if missing, and does NOT
    // truncate — truncating before the lock would be the same bug again.
    $handle = @fopen($path, "c+");
    if ($handle === false) {
        return;
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            return;
        }

        $size = filesize($path);
        $raw = $size > 0 ? fread($handle, $size) : "";
        $decoded = json_decode($raw, true);

        $data = $mutate(is_array($decoded) ? $decoded : []);

        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($data));
        fflush($handle);
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }
}

function _since($timestamps, $seconds) {
    $cutoff = time() - $seconds;

    return array_values(array_filter($timestamps, function ($at) use ($cutoff) {
        return $at > $cutoff;
    }));
}

/**
 * Where the counters live.
 *
 * The system temp directory is the right default on a machine you control,
 * but shared hosting does not always give PHP a writable one — and if this
 * silently fails, every limit in the app stops working while the site
 * carries on looking healthy. That is the worst kind of breakage: invisible
 * and security-relevant.
 *
 * So the location is configurable, and the fallback is a directory inside
 * the project. logs/ already exists and is already written to.
 *
 * A .htaccess in that directory denies web access, and the filenames are
 * unguessable anyway, but the counters are not secret — knowing how many
 * times an IP has tried to log in helps nobody.
 */
function _rate_limit_dir() {
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }

    $configured = function_exists('app_config')
        ? trim(app_config()['rate_limit_dir'] ?? '')
        : '';

    if ($configured !== '' && is_dir($configured) && is_writable($configured)) {
        return $dir = rtrim($configured, '/\\');
    }

    $temp = sys_get_temp_dir();
    if ($temp && is_dir($temp) && is_writable($temp)) {
        return $dir = $temp;
    }

    // Last resort, and the one shared hosting usually lands on.
    $local = __DIR__ . '/../logs';
    if (!is_dir($local)) {
        @mkdir($local, 0775, true);
    }

    return $dir = $local;
}


function _rate_limit_path($bucket) {
    $safeName = preg_replace('/[^a-z0-9_-]/i', '', $bucket);

    return _rate_limit_dir() . "/equalizeme_ratelimit_{$safeName}.json";
}

function _rate_limit_read($bucket) {
    $path = _rate_limit_path($bucket);
    if (!file_exists($path)) {
        return [];
    }

    $decoded = json_decode(file_get_contents($path), true);

    return is_array($decoded) ? $decoded : [];
}

// Kept for rate_limit_check(), which only reads. A stale read there is
// harmless: the worst case is one extra attempt getting through, and the
// recording side is what actually has to be exact.
//
// _rate_limit_write() is gone deliberately. Writing outside
// _rate_limit_update() is how the race got in, so there is no longer a
// function that makes it easy to do.
