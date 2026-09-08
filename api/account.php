<?php

/**
 * Change the signed-in account's display name.
 *
 * PUT with {"name": "..."} and a CSRF token in the X-CSRF-Token header.
 *
 * ONE NAME, NOT TWO. The design this came from asks for a "username" and a
 * "display name" as separate things. The schema has only users.name, which
 * is what is shown on the profile and in emails; sign-in is by email
 * address, so nothing here is used as a login identifier. Adding a second
 * name would mean a new column, a uniqueness rule, and a decision about
 * what happens to accounts that already exist -- none of which is needed
 * for the thing actually being asked for, which is "let me change what the
 * site calls me".
 *
 * Because it is not a login identifier, it is deliberately NOT unique.
 * Two people called Gian are two people called Gian.
 */

require_once __DIR__ . "/session.php";
require_once __DIR__ . "/csrf.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/errors.php";
start_secure_session();
header("Content-Type: application/json");

// Matches the users.name column, which is varchar(100). Checked in
// characters rather than bytes -- an accented or non-Latin name would
// otherwise be rejected for being "too long" at well under 100 letters.
const NAME_MAX_LENGTH = 100;
const NAME_MIN_LENGTH = 1;

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    echo json_encode(["error" => "Not logged in"]);
    exit;
}

if (($_SERVER["REQUEST_METHOD"] ?? "") !== "PUT") {
    http_response_code(405);
    echo json_encode(["error" => "Use PUT"]);
    exit;
}

$body = json_decode(file_get_contents("php://input"), true);
csrf_verify_or_fail($_SERVER["HTTP_X_CSRF_TOKEN"] ?? null);

$name = is_string($body["name"] ?? null) ? trim($body["name"]) : "";

// Control characters stripped rather than rejected. A name pasted from a
// document can carry a stray newline or tab, and refusing it teaches
// nothing; removing it does what the person meant.
$name = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name);
$name = trim(preg_replace('/\s{2,}/u', ' ', $name));

if (mb_strlen($name) < NAME_MIN_LENGTH) {
    http_response_code(400);
    echo json_encode(["error" => "Your name cannot be empty."]);
    exit;
}

if (mb_strlen($name) > NAME_MAX_LENGTH) {
    http_response_code(400);
    echo json_encode([
        "error" => "That name is too long — " . NAME_MAX_LENGTH . " characters at most.",
    ]);
    exit;
}

try {
    $pdo = get_pdo();
    $pdo->prepare("UPDATE users SET name = ? WHERE id = ?")
        ->execute([$name, (int)$_SESSION["user_id"]]);

    // Echoed back so the page displays what was actually stored, not what
    // was typed. They differ whenever the tidying above did anything.
    echo json_encode(["status" => "ok", "name" => $name]);
} catch (PDOException $e) {
    fail_json(500, "Could not save your name.", $e, "account.php");
}
