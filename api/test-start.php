<?php

/**
 * Begin a listening test.
 *
 * Replaces /api/dsp/adaptive/start on the Flask service.
 *
 * There is no user_id in the request and no way to put one there. The old
 * service took the id it was given, which is why api/dsp.php had to exist
 * to overwrite it. Running in PHP, the session already knows who this is —
 * the class of bug the proxy was built to prevent is now unreachable
 * rather than merely guarded against.
 */

require_once __DIR__ . "/session.php";
require_once __DIR__ . "/csrf.php";
require_once __DIR__ . "/pre_quiz.php";
require_once __DIR__ . "/adaptive_test.php";
start_secure_session();
header("Content-Type: application/json");

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    echo json_encode(["error" => "Not logged in"]);
    exit;
}

$body = json_decode(file_get_contents("php://input"), true);
csrf_verify_or_fail($body["csrf_token"] ?? null);

// The answers are worth a starting guess, never a conclusion. Anything
// unrecognised is skipped rather than rejected — a partial quiz gives a
// weaker seed, which is the correct consequence, not an error.
$seed = null;
if (!empty($body["quiz"]) && is_array($body["quiz"])) {
    $seed = quiz_score_answers($body["quiz"]);
}

$pair = at_start_session($seed);

if ($pair === null) {
    http_response_code(500);
    echo json_encode(["error" => "Could not start the test."]);
    exit;
}

echo json_encode($pair);
