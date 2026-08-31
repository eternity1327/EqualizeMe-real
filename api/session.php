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

    // Production implies HTTPS whether or not force_https was set. Session
    // cookies only get the Secure flag when the request is already
    // encrypted, so a production site served over plain HTTP hands its
    // cookies to anyone on the path.
    $force = !empty($config['force_https'])
        || (function_exists('is_production') && is_production());

    if (!$force) {
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

    // Before anything else, so an error thrown further down is already
    // subject to the production display rules rather than printing itself.
    if (function_exists('apply_environment')) {
        apply_environment();
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

/* ─────────────────────── the half-logged-in state ────────────────────── */

/**
 * Two-factor splits logging in across two requests, so there has to be a
 * way to remember "this person proved the password but not the second
 * factor" in between.
 *
 * The single most important property of that state is what it is NOT: it is
 * not $_SESSION['user_id']. Every authenticated endpoint in this codebase
 * gates on user_id, so as long as the pending key is a different key, a
 * half-finished login has exactly zero authority — no profile, no settings,
 * no recommendations, no uploads. Nothing had to be changed endpoint by
 * endpoint to make that true, and nothing can accidentally opt out of it.
 */
const PENDING_LOGIN_KEY = '_pending_login';

// A password that has been accepted but not yet completed is a standing
// invitation. Ten minutes is long enough to find your phone and short
// enough that walking away from a shared machine does not leave one open.
const PENDING_LOGIN_TTL = 600;

/**
 * Record that the password was right, without granting anything.
 */
function begin_pending_login($userId) {
    // Rotate here as well as on completion. The password step is where a
    // fixated session id would be planted, so the id the browser arrived
    // with must not survive it.
    session_regenerate_id(true);
    $_SESSION['_id_issued_at'] = time();

    unset($_SESSION['user_id']);

    $_SESSION[PENDING_LOGIN_KEY] = [
        'user_id' => (int)$userId,
        'started_at' => time(),
    ];
}

/**
 * Who is half-way through logging in, or null. Expired states clear
 * themselves rather than lingering.
 */
function pending_login_user_id() {
    $pending = $_SESSION[PENDING_LOGIN_KEY] ?? null;
    if (!is_array($pending) || !isset($pending['user_id'], $pending['started_at'])) {
        return null;
    }

    if ((time() - (int)$pending['started_at']) > PENDING_LOGIN_TTL) {
        clear_pending_login();
        return null;
    }

    return (int)$pending['user_id'];
}

function clear_pending_login() {
    unset($_SESSION[PENDING_LOGIN_KEY]);
}

/**
 * Whoever this request belongs to, fully logged in or half-way there.
 *
 * Only for the two-factor enrolment endpoints, which legitimately serve
 * both: someone mid-login who has to enrol before they can continue, and
 * someone already signed in who is switching it on from Settings.
 *
 * Nothing else should use this. For every other endpoint "logged in" means
 * $_SESSION['user_id'] and nothing less, and blurring that is exactly the
 * mistake this function looks like.
 */
function current_or_pending_user_id() {
    if (isset($_SESSION['user_id'])) {
        return (int)$_SESSION['user_id'];
    }
    return pending_login_user_id();
}

/**
 * Both factors are in. This is the only place in the codebase that grants
 * user_id, which makes it the only place worth auditing for that.
 */
function complete_login($userId) {
    clear_pending_login();

    session_regenerate_id(true);
    $_SESSION['_id_issued_at'] = time();
    $_SESSION['user_id'] = (int)$userId;
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
