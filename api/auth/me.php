<?php
require_once __DIR__ . "/../session.php";
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../roles.php";
start_secure_session();
header("Content-Type: application/json");

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    echo json_encode(["error" => "Not logged in"]);
    exit;
}

try {
    $pdo = get_pdo();
    $stmt = $pdo->prepare(
        "SELECT id, name, email, role, terms_accepted_at FROM users WHERE id = ?"
    );
    $stmt->execute([$_SESSION["user_id"]]);
    $user = $stmt->fetch();

    if (!$user) {
        http_response_code(401);
        echo json_encode(["error" => "Not logged in"]);
        exit;
    }

    echo json_encode([
        "id" => (int)$user["id"],
        "name" => $user["name"],
        "email" => $user["email"],
        // Returned here rather than from an endpoint of its own because
        // every protected page already calls this one on load. The consent
        // gate needs the answer before the test can start, and a second
        // request would only add a round trip to say the same thing.
        "termsAcceptedAt" => $user["terms_accepted_at"],

        // A hint for the interface, not a permission. It decides whether
        // the Admin link is drawn; it decides nothing about what the
        // account may do. Every admin endpoint calls require_admin() for
        // itself and would refuse an ordinary account even if this flag
        // were forged in the browser.
        "role" => $user["role"],
        "isAdmin" => $user["role"] === ROLE_ADMIN,
    ]);
} catch (PDOException $e) {
    error_log("auth/me.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["error" => "Something went wrong"]);
}
