<?php

/**
 * One earphone's measured frequency response, for the chart on its card.
 *
 * Replaces /api/iems/<id>/curve on the Flask service.
 */

require_once __DIR__ . "/session.php";
require_once __DIR__ . "/db.php";
start_secure_session();
header("Content-Type: application/json");

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    echo json_encode(["error" => "Not logged in"]);
    exit;
}

// Cast to int before it reaches the query. The prepared statement makes
// that unnecessary for safety, but it also means a non-numeric id becomes
// a clean 404 rather than a confusing empty result.
$iemId = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if ($iemId === false || $iemId === null) {
    http_response_code(400);
    echo json_encode(["error" => "A numeric IEM id is required"]);
    exit;
}

try {
    $pdo = get_pdo();

    $stmt = $pdo->prepare("SELECT fr_curve_json, description FROM iems WHERE id = ?");
    $stmt->execute([$iemId]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(404);
        echo json_encode(["error" => "IEM not found"]);
        exit;
    }

    if (empty($row["fr_curve_json"])) {
        http_response_code(404);
        echo json_encode(["error" => "No measurement curve stored for this IEM"]);
        exit;
    }

    $curve = json_decode($row["fr_curve_json"], true);

    if ($curve === null && json_last_error() !== JSON_ERROR_NONE) {
        error_log("iem-curve.php: bad JSON stored for IEM {$iemId}");
        http_response_code(500);
        echo json_encode(["error" => "Stored curve data is not valid JSON"]);
        exit;
    }

    echo json_encode([
        "curve" => $curve,
        "description" => $row["description"],
    ]);
} catch (PDOException $e) {
    error_log("iem-curve.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["error" => "Something went wrong"]);
}
