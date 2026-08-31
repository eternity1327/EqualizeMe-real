<?php

/**
 * Record one A/B answer, and either hand back the next pair or finish.
 *
 * Replaces /api/dsp/adaptive/answer on the Flask service.
 *
 * On the last answer this also writes the completed profile into
 * auditory_profiles — appended as history, never overwriting what came
 * before, because every sitting is evidence towards one evolving target.
 */

require_once __DIR__ . "/session.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/csrf.php";
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

$result = at_record_answer($body["preferred"] ?? null);

if (isset($result["error"]) && empty($result["done"])) {
    http_response_code(400);
    echo json_encode($result);
    exit;
}

if (!empty($result["done"]) && isset($result["profile"])) {
    try {
        $profile = (array)$result["profile"];
        $pdo = get_pdo();

        // A plain INSERT, deliberately. The unique index on user_id was
        // dropped so this table could become an append-only history; with
        // it still in place this would throw on anyone's second test.
        $pdo->prepare(
            "INSERT INTO auditory_profiles
                 (user_id, bass_gain, treble_gain, presence_gain, confidence_score)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([
            $_SESSION["user_id"],
            $profile["bassGain"],
            $profile["trebleGain"],
            $profile["presenceGain"],
            $result["confidence"] ?? null,
        ]);

        // The test is over; leaving its state behind would let a refresh
        // save the same result twice.
        at_clear_session();
    } catch (PDOException $e) {
        error_log("test-answer.php: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            "error" => "Your answers were fine, but the profile could not be "
                . "saved. Please try the test again.",
        ]);
        exit;
    }
}

echo json_encode($result);
