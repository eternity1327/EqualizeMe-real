<?php
/**
 * Shared secure session bootstrap. Call start_secure_session() instead of
 * session_start() directly everywhere in this project, so every page's
 * session cookie gets the same hardened settings instead of each file
 * configuring (or not configuring) its own.
 */

// app_config() supplies the optional force_https flag. Loaded with
// require_once and guarded below, so pages that don't need config still
// work if this file is ever used standalone.
require_once __DIR__ . '/config.php';

// How long a session may sit idle before it expires. Each request pushes
// this back, so it only bites after real inactivity.
const SESSION_IDLE_TIMEOUT = 7200;      // 2 hours

// Fresh session ID even for active sessions, so a stolen one has a
// limited useful lifetime.
const SESSION_REGENERATE_EVERY = 1800;  // 30 minutes

// Any past expiry deletes a cookie; this is comfortably in the past.
const COOKIE_DELETE_OFFSET = 42000;

/**
 * True when the request reached us over HTTPS.
 *
 * Checks the forwarded header as well as PHP's own flag, because behind
 * Cloudflare (or any reverse proxy) TLS terminates at the proxy and the
 * request arrives here as plain HTTP. Looking only at $_SERVER['HTTPS']
 * would report "insecure" for connections that were encrypted the whole
 * way to the user.
 */
function request_is_https() {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

/**
 * Redirects to HTTPS when 'force_https' is set in config.local.php.
 *
 * Off by default: XAMPP serves plain HTTP locally with no certificate to
 * redirect to, so forcing it unconditionally would bounce forever.
 * Localhost stays exempt even when enabled.
 */
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

    // 301 rather than 302: browsers remember it, so later visits go
    // straight to HTTPS without an insecure first hop.
    $target = 'https://' . $host . ($_SERVER['REQUEST_URI'] ?? '/');
    header('Location: ' . $target, true, 301);
    exit;
}

/**
 * Start a session with this project's hardened cookie settings.
 */
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
 * Cookie settings, shared by session start and logout so the logout
 * cookie deletion matches the cookie that was actually set (path and
 * flags have to line up or the browser keeps the old one).
 */
function session_cookie_options() {
    return [
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,             // JavaScript can't read the cookie
        'samesite' => 'Lax',            // blocks most cross-site forgery
        'secure' => request_is_https(), // only sent over HTTPS
    ];
}

/**
 * Ends the session everywhere: server state, memory, and the browser's
 * cookie. session_destroy() alone leaves the cookie in the browser.
 */
function end_secure_session() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        start_secure_session();
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_cookie_options();
        $params['expires'] = time() - COOKIE_DELETE_OFFSET;
        setcookie(session_name(), '', $params);
    }

    session_destroy();
}

/**
 * Clears a session that has been idle past the timeout.
 */
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

/**
 * Issues a new session ID once the current one is old enough.
 */
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
