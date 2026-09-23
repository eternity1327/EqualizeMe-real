<?php

/**
 * Who is allowed to administer the system.
 *
 * One file, included by every admin endpoint, because an authorisation
 * check that is written out separately in each place is an authorisation
 * check that will eventually be written out wrong in one of them.
 *
 * The rule throughout: the role is read from the database on every
 * request, never from the session. Copying it into the session at login
 * would be faster and would also mean that demoting someone leaves them an
 * administrator until they happen to log out — which is exactly the moment
 * you would least want the privilege to persist.
 */

require_once __DIR__ . "/db.php";
require_once __DIR__ . "/session.php";

const ROLE_USER = "user";
const ROLE_ADMIN = "admin";


/**
 * The role of the logged-in account, or null when nobody is logged in.
 *
 * An unrecognised value in the column is reported as it stands rather than
 * mapped to something sensible. is_admin() below compares against the
 * literal "admin", so anything unexpected — a typo, a half-finished
 * migration, a value from a future version — grants nothing.
 */
function current_role($pdo = null) {
    if (!isset($_SESSION["user_id"])) {
        return null;
    }

    try {
        $pdo = $pdo ?: get_pdo();
        $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$_SESSION["user_id"]]);
        $role = $stmt->fetchColumn();
    } catch (PDOException $e) {
        // A failed lookup is not a reason to assume anything. Logged, and
        // treated as "no role", which denies rather than allows.
        error_log("roles.php: could not read role: " . $e->getMessage());
        return null;
    }

    return $role === false ? null : (string)$role;
}


function is_admin($pdo = null) {
    return current_role($pdo) === ROLE_ADMIN;
}


/**
 * Stop here unless this is an administrator.
 *
 * Answers 404 rather than 403 on purpose. A 403 confirms that the endpoint
 * exists and that the caller simply lacks the rank for it, which tells
 * someone probing the site exactly where the administrative surface is.
 * A 404 tells them nothing they did not already have.
 *
 * The one exception is not being logged in at all, which gets a 401 — that
 * is a state the user can fix, and the browser needs to tell them how.
 */
function require_admin($pdo = null) {
    if (!isset($_SESSION["user_id"])) {
        http_response_code(401);
        echo json_encode(["error" => "Not logged in"]);
        exit;
    }

    if (!is_admin($pdo)) {
        error_log("roles.php: non-admin user {$_SESSION["user_id"]} tried "
            . ($_SERVER["REQUEST_METHOD"] ?? "?") . " "
            . ($_SERVER["REQUEST_URI"] ?? "?"));

        http_response_code(404);
        echo json_encode(["error" => "Not found"]);
        exit;
    }
}
