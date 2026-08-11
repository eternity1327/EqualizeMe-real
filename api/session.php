<?php
/**
 * Shared secure session bootstrap. Call start_secure_session() instead of
 * session_start() directly everywhere in this project, so every page's
 * session cookie gets the same hardened settings instead of each file
 * configuring (or not configuring) its own.
 */

// How long a session can sit idle before it's treated as expired. Each
// request from the user pushes this back, so it only bites after a real
// period of inactivity — not while someone is actively using the site.
const SESSION_IDLE_TIMEOUT = 7200; // 2 hours

// Force a fresh session ID periodically even for active sessions, so a
// stolen ID has a limited useful lifetime.
const SESSION_REGENERATE_EVERY = 1800; // 30 minutes

function start_secure_session() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_set_cookie_params(session_cookie_options());
    session_start();

    _session_enforce_idle_timeout();
    _session_rotate_id_periodically();
}

/**
 * Cookie settings, shared by session start and logout so the logout
 * cookie deletion matches the cookie that was actually set (path and
 * flags have to line up or the browser keeps the old one).
 */
function session_cookie_options() {
    // Works whether Apache terminates HTTPS itself, or a tunnel/proxy in
    // front of it does and forwards the original protocol via header.
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    return [
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,   // JavaScript can't read the session cookie
        'samesite' => 'Lax',  // blocks most cross-site request forgery
        'secure' => $isHttps, // only sent over HTTPS once you're behind one
    ];
}

/**
 * Ends the session everywhere: server-side state, the in-memory copy,
 * and the browser's cookie. session_destroy() alone leaves the cookie
 * sitting in the browser, which is untidy and can confuse later logins.
 */
function end_secure_session() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        start_secure_session();
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_cookie_options();
        $params['expires'] = time() - 42000; // any past time deletes it
        setcookie(session_name(), '', $params);
    }

    session_destroy();
}

function _session_enforce_idle_timeout() {
    $now = time();
    $lastSeen = $_SESSION['_last_activity'] ?? null;

    if ($lastSeen !== null && ($now - $lastSeen) > SESSION_IDLE_TIMEOUT) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['_expired'] = true;  // lets pages show "your session expired"
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
