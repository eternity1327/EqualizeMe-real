<?php

/**
 * The six written questions, without their scoring weights.
 *
 * Replaces the /api/quiz/questions route on the Flask service. Read-only,
 * so no CSRF token — there is nothing here to trick anyone into doing.
 */

require_once __DIR__ . "/session.php";
require_once __DIR__ . "/pre_quiz.php";
require_once __DIR__ . "/audio_samples.php";
start_secure_session();
header("Content-Type: application/json");

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    echo json_encode(["error" => "Not logged in"]);
    exit;
}

// Sent with the questions so the browser can start downloading the first
// track while they are being answered. That head start is the whole fix for
// the first-play delay testers reported, and it matters more now that the
// files are full songs rather than ten-second clips.
//
// Worked out here rather than guessed in JavaScript, because the filename
// lives in the database and there is no longer a formula to guess it with.
echo json_encode([
    "questions" => quiz_list_questions(),
    "firstSamplePath" => as_first_sample_path(),
]);
