<?php
/**
 * Shared secure session bootstrap. Call start_secure_session() instead of
 * session_start() directly everywhere in this project, so every page's
 * session cookie gets the same hardened settings instead of each file
 * configuring (or not configuring) its own.
 */
function start_secure_session() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // Works whether Apache terminates HTTPS itself, or a tunnel/proxy in
    // front of it does and forwards the original protocol via header.
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,   // JavaScript can't read the session cookie
        'samesite' => 'Lax',  // blocks most cross-site request forgery
        'secure' => $isHttps, // only sent over HTTPS once you're behind one
    ]);

    session_start();
}
