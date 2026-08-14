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

// How long a session can sit idle before it's treated as expired. Each
// request from the user pushes this back, so it only bites after a real
// period of inactivity — not while someone is actively using the site.
const SESSION_IDLE_TIMEOUT = 7200; // 2 hours

// Force a fresh session ID periodically even for active sessions, so a
// stolen ID has a limited useful lifetime.
const SESSION_REGENERATE_EVERY = 1800; // 30 minutes

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
 * Redirects to HTTPS when the site is configured to require it.
 *
 * Off by default. Turning it on unconditionally would break local
 * development, where XAMPP serves plain HTTP on localhost and there's no
 * certificate to redirect to — the site would bounce forever.
 *
 * Enable by setting 'force_https' => true in api/config.local.php once
 * the site is served over TLS. Cloudflare already forces HTTPS at its
 * edge, so this is belt-and-braces there; it matters on hosting that
 * doesn't.
 *
 * Localhost is exempt regardless, so switching it on can't lock you out
 * of your own dev environment.
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
    // Works whether Apache terminates HTTPS itself, or a tunnel/proxy in
    // front of it does and forwards the original protocol via header.
    $isHttps = request_is_https();

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
