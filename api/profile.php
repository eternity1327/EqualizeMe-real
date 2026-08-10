<?php
session_start();
header("Content-Type: application/json");
require __DIR__ . "/../db.php";

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    echo json_encode(["error" => "Not logged in"]);
    exit;
}

try {
    $pdo = get_pdo();
    $stmt = $pdo->prepare(
        "SELECT bass_gain, treble_gain, presence_gain, confidence_score, updated_at " .
        "FROM auditory_profiles WHERE user_id = ?"
    );
    $stmt->execute([$_SESSION["user_id"]]);
    $profile = $stmt->fetch();

    if (!$profile) {
        http_response_code(404);
        echo json_encode(["error" => "No auditory profile found for this user"]);
        exit;
    }

    echo json_encode([
        "bassGain" => (float)$profile["bass_gain"],
        "trebleGain" => (float)$profile["treble_gain"],
        "presenceGain" => (float)$profile["presence_gain"],
        "confidenceScore" => $profile["confidence_score"] !== null ? (float)$profile["confidence_score"] : null,
        "updatedAt" => $profile["updated_at"],
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Something went wrong"]);
}
