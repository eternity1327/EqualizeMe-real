<?php
/**
 * Ends the session.
 *
 * No CSRF token required, deliberately. Logout is the one state change
 * where being forced into it costs the user nothing worse than having to
 * log in again — there's no data loss and nothing an attacker gains.
 * Requiring a token here would mean a logged-out or expired session
 * couldn't clean itself up, which is the opposite of useful.
 *
 * Every other state-changing endpoint does require one.
 */
require_once __DIR__ . "/../session.php";
start_secure_session();
header("Content-Type: application/json");

end_secure_session();

echo json_encode(["status" => "logged out"]);
