<?php
/**
 * Hands the current session's CSRF token to the frontend.
 *
 * Safe to expose: the token is tied to the caller's own session cookie,
 * and a malicious site can't read this response cross-origin (no CORS
 * headers are sent, so the browser blocks it). The value is only useful
 * to the session that already owns it.
 */
require_once __DIR__ . "/session.php";
require_once __DIR__ . "/csrf.php";
start_secure_session();

header("Content-Type: application/json");
header("Cache-Control: no-store");

echo json_encode(["token" => csrf_token()]);
