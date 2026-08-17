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
    $data = _rate_limit_read($bucket);

    $data[$key] = _since($data[$key] ?? [], RATE_LIMIT_RETENTION_SECONDS);
    $data[$key][] = time();

    _rate_limit_write($bucket, $data);
}

function rate_limit_clear($bucket, $key = null) {
    $key = $key ?? client_ip();
    $data = _rate_limit_read($bucket);

    if (!isset($data[$key])) {
        return;
    }

    unset($data[$key]);
    _rate_limit_write($bucket, $data);
}

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
