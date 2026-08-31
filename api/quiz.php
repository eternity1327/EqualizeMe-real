<?php

/**
 * The six written questions, without their scoring weights.
 *
 * Replaces the /api/quiz/questions route on the Flask service. Read-only,
 * so no CSRF token — there is nothing here to trick anyone into doing.
 */

require_once __DIR__ . "/session.php";
require_once __DIR__ . "/pre_quiz.php";
start_secure_session();
header("Content-Type: application/json");

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    echo json_encode(["error" => "Not logged in"]);
    exit;
}

echo json_encode(["questions" => quiz_list_questions()]);
