<?php

require_once __DIR__ . '/config.php';

const SESSION_IDLE_TIMEOUT = 7200;

const SESSION_REGENERATE_EVERY = 1800;

const COOKIE_DELETE_OFFSET = 42000;

function request_is_https() {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function enforce_https_if_configured() {
    $config = function_exists('app_config') ? app_config() : [];
    if (empty($config['force_https'])) {
        return;
    }

    $host = $_SERVER['HTTP_HOST'] ?? '';
    $isLocal = preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/i', $host);

    if (request_is_https() || $isLocal) {
        return;
    }

    $target = 'https://' . $host . ($_SERVER['REQUEST_URI'] ?? '/');
    header('Location: ' . $target, true, 301);
    exit;
}

function start_secure_session() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    enforce_https_if_configured();

    session_set_cookie_params(session_cookie_options());
    session_start();

    _session_enforce_idle_timeout();
    _session_rotate_id_periodically();
}

/**
 * Options for session_set_cookie_params(), which is the only caller.
 *
 * Note the key is 'lifetime' here. setcookie() takes a similar-looking
 * array but names that key 'expires' and rejects 'lifetime' outright, so
 * the two are NOT interchangeable — see expired_cookie_options() below.
 */
function session_cookie_options() {
    return [
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => request_is_https(),
    ];
}

/**
 * The same cookie, described the way setcookie() expects, with an expiry
 * already in the past so the browser discards it.
 *
 * Passing session_cookie_options() straight to setcookie() throws
 * "ValueError: option \"lifetime\" is invalid" on PHP 8 — it was a silent
 * warning on PHP 7, which is why this went unnoticed. 'lifetime' is
 * dropped and 'expires' supplied in its place.
 *
 * Everything else must match how the cookie was originally set. Browsers
 * identify a cookie by name + path + domain, so a mismatch means the
 * deletion quietly targets a different cookie and the real one survives.
 */
function expired_cookie_options() {
    $params = session_cookie_options();
    unset($params['lifetime']);

    $params['expires'] = time() - COOKIE_DELETE_OFFSET;

    return $params;
}

function end_secure_session() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        start_secure_session();
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        setcookie(session_name(), '', expired_cookie_options());
    }

    session_destroy();
}

function _session_enforce_idle_timeout() {
    $now = time();
    $lastSeen = $_SESSION['_last_activity'] ?? null;

    if ($lastSeen !== null && ($now - $lastSeen) > SESSION_IDLE_TIMEOUT) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['_expired'] = true;
        $_SESSION['_last_activity'] = $now;
        return;
    }

    $_SESSION['_last_activity'] = $now;
}

function _session_rotate_id_periodically() {
    $now = time();
    $lastRotated = $_SESSION['_id_issued_at'] ?? null;

    if ($lastRotated === null) {
        $_SESSION['_id_issued_at'] = $now;
        return;
    }

    if (($now - $lastRotated) > SESSION_REGENERATE_EVERY) {
        session_regenerate_id(true);
        $_SESSION['_id_issued_at'] = $now;
    }
}
