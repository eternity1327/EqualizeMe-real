<?php

/**
 * The results page, in one response.
 *
 * Replaces /recommendations/<user_id> on the Flask service — note that the
 * user id is gone from the address entirely. It comes from the session now,
 * so there is nothing in the request for anyone to change.
 *
 * The layer separation is kept exactly as it was in Python:
 *
 *   assessments -> aggregation -> the target
 *                                   |
 *                                   +-> analysis    (interpretation)
 *                                   +-> ranking     (application)
 *                                   +-> hearing     (read-only consumer)
 */

require_once __DIR__ . "/session.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/preference_profile.php";
require_once __DIR__ . "/hearing_preservation.php";
require_once __DIR__ . "/recommend.php";
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

    // Every assessment on record, with its age measured against the
    // database clock — the same clock that wrote created_at, rather than
    // whatever the web server thinks the time is.
    $stmt = $pdo->prepare(
        "SELECT bass_gain, treble_gain, presence_gain, confidence_score,
                created_at,
                TIMESTAMPDIFF(SECOND, created_at, NOW()) / 86400 AS age_days
         FROM auditory_profiles
         WHERE user_id = ?
         ORDER BY created_at DESC"
    );
    $stmt->execute([$userId]);
    $assessments = $stmt->fetchAll();

    if (!$assessments) {
        http_response_code(404);
        echo json_encode(["error" => "No auditory profile found for this user"]);
        exit;
    }

    $catalogue = $pdo->query(
        "SELECT i.id, i.name, i.brand, i.sound_signature, i.bass_gain,
                i.treble_gain, i.presence_gain, i.price, i.image_url,
                i.shop_link,
                r.name AS retailer_name, r.product_url
         FROM iems i
         LEFT JOIN retailers r ON i.retailer_id = r.retailer_id"
    )->fetchAll();

    // Aggregation. Every sitting folds into one target; the rows are left
    // alone. This replaced "most recent row wins", which discarded every
    // assessment but the last.
    $aggregated = pp_build($assessments);
    $target = $aggregated["target"];

    $result = rec_recommend($catalogue, $target);

    echo json_encode([
        "user_id" => $userId,
        // Kept under this key because the curve drawing and the IEM cards
        // already read it. It is the aggregate now rather than the last
        // row, which is the only thing that changed for them.
        "profile" => [
            "bass_gain" => (float)$target["bass_gain"],
            "treble_gain" => (float)$target["treble_gain"],
            "presence_gain" => (float)$target["presence_gain"],
        ],
        "preference" => [
            "target" => $target,
            "spread" => $aggregated["spread"],
            "regions" => $aggregated["regions"],
            "analysis" => $aggregated["analysis"],
            "assessment_count" => $aggregated["assessment_count"],
            "dominant_share" => $aggregated["dominant_share"],
        ],
        "recommendations" => $result["recommendations"],
        // Assembled after the ranking, so it cannot influence it even by
        // accident. Reads the target; changes nothing.
        "hearing_preservation" => hp_build($aggregated),
    ]);
} catch (PDOException $e) {
    error_log("recommendations.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["error" => "Something went wrong loading recommendations."]);
}
