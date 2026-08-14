<?php
require_once __DIR__ . "/../session.php";
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../rate_limit.php";
require_once __DIR__ . "/../csrf.php";
start_secure_session();
header("Content-Type: application/json");

// Per-IP limit: stops one machine hammering the form.
if (!rate_limit_check("login")) {
    http_response_code(429);
    echo json_encode(["error" => "Too many login attempts. Please wait a few minutes and try again."]);
    exit;
}

$body = json_decode(file_get_contents("php://input"), true);
csrf_verify_or_fail($body["csrf_token"] ?? null);

$email = trim($body["email"] ?? "");
$password = $body["password"] ?? "";

if (!$email || !$password) {
    http_response_code(400);
    echo json_encode(["error" => "email and password are required"]);
    exit;
}

// Per-account limit: stops an attacker spread across many IP addresses
// from grinding one account, where every individual address stays under
// the per-IP limit. Deliberately tighter, since a real person rarely
// fails their own password ten times in a quarter of an hour.
//
// This responds identically to the per-IP limit, so it can't be used to
// tell whether an address is registered — an unknown email throttles just
// the same as a real one.
$accountKey = rate_limit_key($email);

if (!rate_limit_check("login_account", 10, 900, $accountKey)) {
    http_response_code(429);
    echo json_encode(["error" => "Too many login attempts. Please wait a few minutes and try again."]);
    exit;
}

try {
    $pdo = get_pdo();
    $stmt = $pdo->prepare("SELECT id, name, email, password_hash FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // Always run a hash comparison, even when no such account exists.
    //
    // password_verify() is deliberately slow — that's what makes bcrypt
    // resistant to brute force. Skipping it for unknown emails made those
    // requests return measurably faster than ones for real accounts, so
    // timing the response revealed which addresses are registered. The
    // error message is identical either way, but the clock wasn't.
    //
    // Verifying against a dummy hash keeps the work (and therefore the
    // response time) roughly the same in both cases.
    $hashToCheck = $user
        ? $user["password_hash"]
        // bcrypt hash of a value nothing can match; cost matches PASSWORD_DEFAULT.
        : '$2y$10$usesomesillystringforeidsxa5PhSD0hzOXMH.HuAn9LSgLuRRuC';

    $passwordOk = password_verify($password, $hashToCheck);

    if (!$user || !$passwordOk) {
        rate_limit_record("login");
        rate_limit_record("login_account", $accountKey);
        http_response_code(401);
        echo json_encode(["error" => "Incorrect email or password"]);
        exit;
    }

    // Proving ownership of the account clears its failure count, so a few
    // mistyped passwords don't leave someone locked out afterwards.
    rate_limit_clear("login_account", $accountKey);

    // Regenerate the session ID on privilege change (session fixation defense) —
    // an attacker who fixed a visitor's session ID before login can't reuse it after.
    session_regenerate_id(true);
    $_SESSION["user_id"] = $user["id"];
    echo json_encode(["id" => (int)$user["id"], "name" => $user["name"], "email" => $user["email"]]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Something went wrong logging in"]);
}
