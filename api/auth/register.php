<?php
require_once __DIR__ . "/../session.php";
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../rate_limit.php";
require_once __DIR__ . "/../password_policy.php";
require_once __DIR__ . "/../csrf.php";
require_once __DIR__ . "/../totp.php";
require_once __DIR__ . "/../email_verification.php";
require_once __DIR__ . "/../errors.php";
start_secure_session();
header("Content-Type: application/json");

if (!rate_limit_check("register", REGISTER_MAX_ATTEMPTS, REGISTER_WINDOW_SECONDS)) {
    http_response_code(429);
    echo json_encode([
        "error" => "Too many signup attempts. Please wait a few minutes and try again.",
    ]);
    exit;
}
rate_limit_record("register");

$body = json_decode(file_get_contents("php://input"), true);
csrf_verify_or_fail($body["csrf_token"] ?? null);

$name = trim($body["name"] ?? "");
$email = trim($body["email"] ?? "");
$password = $body["password"] ?? "";

if (!$name || !$email || !$password) {
    http_response_code(400);
    echo json_encode(["error" => "name, email, and password are all required"]);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(["error" => "That email address doesn't look valid"]);
    exit;
}
$passwordProblems = password_problems($password, $email, $name);
if ($passwordProblems) {
    http_response_code(400);
    echo json_encode(["error" => password_error_message($passwordProblems)]);
    exit;
}

try {
    $pdo = get_pdo();

    $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $check->execute([$email]);
    if ($check->fetch()) {
        http_response_code(409);
        echo json_encode(["error" => "An account with that email already exists"]);
        exit;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)");
    $stmt->execute([$name, $email, $hash]);
    $userId = $pdo->lastInsertId();

    // No placeholder row in auditory_profiles. There used to be one -- zeros
    // across all three bands, written here at registration -- from when that
    // table held exactly one row per person and the application assumed it
    // existed. The table became an append-only history of completed tests,
    // and the placeholder became actively harmful:
    //
    //   pp_fetch_assessments() selects every row for a user, and a NULL
    //   confidence_score is weighted 1.0. So a row standing for no test at
    //   all was folded into the aggregate at roughly the weight of a real
    //   one, pulling every target toward zero. With eight real tests it was
    //   about a ninth of the total weight.
    //
    //   It also made "No profile yet -- take the sound test" unreachable,
    //   because there was always at least one row to find.
    //
    // sql/remove_placeholder_profiles.sql clears the ones already written.
    $pdo->prepare("INSERT INTO settings (user_id) VALUES (?)")->execute([$userId]);

    // Sent outside the account-creation path deliberately. A mail failure
    // must not undo a signup that already succeeded — the user can ask for
    // another link, but they cannot ask for their account back.
    $verificationSent = false;
    if (require_email_verification()) {
        try {
            $result = send_verification_email($pdo, $userId, $name, $email);
            $verificationSent = $result["sent"] ?? false;
        } catch (Throwable $e) {
            error_log("auth/register.php: verification email failed: " . $e->getMessage());
        }

        // The account exists but cannot be used yet, so no session is
        // granted — not even a pending one.
        http_response_code(201);
        echo json_encode([
            "status" => "verify_email",
            "name" => $name,
            "email" => $email,
            "sent" => $verificationSent,
            "message" => $verificationSent
                ? "Check your email for a link to confirm your address."
                : "Your account was created, but the confirmation email could "
                    . "not be sent. Try requesting another one in a moment.",
        ]);
        exit;
    }

    if (TWO_FACTOR_REQUIRED) {
        // A new account is in exactly the position a returning user is in
        // after a correct password: identified, but not yet holding a
        // second factor. Granting a full session here would have made
        // signing up the way to skip two-factor entirely.
        begin_pending_login($userId);

        http_response_code(201);
        echo json_encode([
            "status" => "2fa_required",
            "name" => $name,
            "next" => "enrol",
            "redirect" => "two-factor.php",
        ]);
        exit;
    }

    complete_login($userId);

    http_response_code(201);
    echo json_encode(["id" => (int)$userId, "name" => $name, "email" => $email]);
} catch (PDOException $e) {
    fail_json(500, "Something went wrong creating your account", $e, "auth/register.php");
}
