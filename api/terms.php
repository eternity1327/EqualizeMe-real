<?php

/**
 * Record that this account has accepted the terms.
 *
 * GET  — whether they have, and when.
 * POST — record that they just did.
 *
 * A timestamp rather than a flag, so a consent given before the terms were
 * revised can be told apart from one given after. See
 * sql/add_terms_and_apparatus.sql.
 *
 * Acceptance is per account, not per test. Asking again before every
 * listening test would train people to click past it, which is the opposite
 * of consent meaning anything.
 */

require_once __DIR__ . "/session.php";
require_once __DIR__ . "/csrf.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/errors.php";
start_secure_session();
header("Content-Type: application/json");

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    echo json_encode(["error" => "Not logged in"]);
    exit;
}

$userId = (int)$_SESSION["user_id"];
$method = $_SERVER["REQUEST_METHOD"] ?? "GET";

try {
    $pdo = get_pdo();

    if ($method === "GET") {
        $stmt = $pdo->prepare("SELECT terms_accepted_at FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $acceptedAt = $stmt->fetchColumn();

        echo json_encode([
            "accepted" => $acceptedAt !== null && $acceptedAt !== false,
            "acceptedAt" => $acceptedAt ?: null,
        ]);
        exit;
    }

    if ($method !== "POST") {
        http_response_code(405);
        echo json_encode(["error" => "Use GET or POST"]);
        exit;
    }

    $body = json_decode(file_get_contents("php://input"), true);
    csrf_verify_or_fail($body["csrf_token"] ?? null);

    // The browser sends this, but it is not what is trusted — the record is
    // written from the server's clock. A client-supplied timestamp on a
    // consent record is worth nothing.
    if (empty($body["accept"])) {
        http_response_code(400);
        echo json_encode(["error" => "Acceptance was not confirmed."]);
        exit;
    }

    // Only sets it if it is still null, so re-accepting does not move the
    // date. The first acceptance is the one that happened.
    $pdo->prepare(
        "UPDATE users SET terms_accepted_at = NOW()
         WHERE id = ? AND terms_accepted_at IS NULL"
    )->execute([$userId]);

    $stmt = $pdo->prepare("SELECT terms_accepted_at FROM users WHERE id = ?");
    $stmt->execute([$userId]);

    echo json_encode([
        "accepted" => true,
        "acceptedAt" => $stmt->fetchColumn() ?: null,
    ]);
} catch (PDOException $e) {
    fail_json(500, "Could not record your acceptance.", $e, "terms.php");
}
