<?php
require_once __DIR__ . "/session.php";
require_once __DIR__ . "/db.php";
start_secure_session();
header("Content-Type: application/json");

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    echo json_encode(["error" => "Not logged in"]);
    exit;
}

try {
    $pdo = get_pdo();

    $pictureStmt = $pdo->prepare("SELECT profile_picture FROM users WHERE id = ?");
    $pictureStmt->execute([$_SESSION["user_id"]]);
    $profilePicture = $pictureStmt->fetchColumn();

    // ORDER BY / LIMIT are load-bearing here. This query was written when
    // auditory_profiles had a UNIQUE index on user_id and could only ever
    // hold one row per person. That index is gone — the table is now an
    // append-only history, one row per completed test — so without an
    // explicit order this returned whichever row MySQL felt like handing
    // back first, which in practice was the user's oldest test rather than
    // their newest.
    $stmt = $pdo->prepare(
        "SELECT bass_gain, treble_gain, presence_gain, confidence_score, updated_at
         FROM auditory_profiles
         WHERE user_id = ?
         ORDER BY created_at DESC
         LIMIT 1"
    );
    $stmt->execute([$_SESSION["user_id"]]);
    $profile = $stmt->fetch();

    if (!$profile) {
        http_response_code(404);
        echo json_encode([
            "error" => "No auditory profile found for this user",
            "profilePicture" => $profilePicture ?: null,
        ]);
        exit;
    }

    echo json_encode([
        "bassGain" => (float)$profile["bass_gain"],
        "trebleGain" => (float)$profile["treble_gain"],
        "presenceGain" => (float)$profile["presence_gain"],
        "confidenceScore" => $profile["confidence_score"] !== null ? (float)$profile["confidence_score"] : null,
        "updatedAt" => $profile["updated_at"],
        "profilePicture" => $profilePicture ?: null,
    ]);
} catch (PDOException $e) {
    error_log("profile.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["error" => "Something went wrong"]);
}
