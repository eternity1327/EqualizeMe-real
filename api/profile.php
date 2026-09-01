<?php

/**
 * The signed-in user's current preference profile.
 *
 * Returns the same aggregate the results page and the recommendation
 * engine use, not the most recent single test.
 *
 * This used to return one row. That was correct when auditory_profiles
 * held one row per person, and it stayed after the table became an
 * append-only history — so this page showed the newest sitting while the
 * results page showed the weighted target, and the two disagreed on
 * screen with no explanation.
 *
 * The aggregate is authoritative, so this reports the aggregate. The
 * latest sitting is still returned alongside it, because "what did I
 * answer last time" is a fair question — it is just not the profile.
 *
 * pp_build() is the only implementation of the weighting. Nothing is
 * duplicated here; if the two ever disagree again it will be because the
 * shared function changed, which is the point.
 */

require_once __DIR__ . "/session.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/preference_profile.php";
require_once __DIR__ . "/errors.php";
start_secure_session();
header("Content-Type: application/json");

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    echo json_encode(["error" => "Not logged in"]);
    exit;
}

$userId = (int)$_SESSION["user_id"];

try {
    $pdo = get_pdo();

    $pictureStmt = $pdo->prepare("SELECT profile_picture FROM users WHERE id = ?");
    $pictureStmt->execute([$userId]);
    $profilePicture = $pictureStmt->fetchColumn();

    $assessments = pp_fetch_assessments($pdo, $userId);

    if (!$assessments) {
        http_response_code(404);
        echo json_encode([
            "error" => "No auditory profile found for this user",
            "profilePicture" => $profilePicture ?: null,
        ]);
        exit;
    }

    $aggregated = pp_build($assessments);
    $target = $aggregated["target"];

    // Newest first, so this is the most recent sitting.
    $latest = $assessments[0];

    echo json_encode([
        // The keys the interface has always read. They now carry the
        // aggregate rather than one row, which is the whole change.
        "bassGain" => (float)$target["bass_gain"],
        "trebleGain" => (float)$target["treble_gain"],
        "presenceGain" => (float)$target["presence_gain"],

        // How much evidence is behind it, so the page can say "based on
        // 5 listening tests" instead of implying a single measurement.
        "assessmentCount" => $aggregated["assessment_count"],

        "updatedAt" => $latest["created_at"],

        // The most recent sitting on its own. Useful for "what did I
        // answer last time", and deliberately not called the profile.
        "latest" => [
            "bassGain" => (float)$latest["bass_gain"],
            "trebleGain" => (float)$latest["treble_gain"],
            "presenceGain" => (float)$latest["presence_gain"],
            "confidenceScore" => $latest["confidence_score"] !== null
                ? (float)$latest["confidence_score"] : null,
            "takenAt" => $latest["created_at"],
        ],

        "profilePicture" => $profilePicture ?: null,
    ]);
} catch (PDOException $e) {
    fail_json(500, "Something went wrong", $e, "profile.php");
}
