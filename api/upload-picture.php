<?php
require_once __DIR__ . "/session.php";
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/csrf.php";
start_secure_session();
header("Content-Type: application/json");

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    echo json_encode(["error" => "Not logged in"]);
    exit;
}

csrf_verify_or_fail();

if (!isset($_FILES["photo"]) || $_FILES["photo"]["error"] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(["error" => "No photo uploaded"]);
    exit;
}

$allowed = ["image/jpeg" => "jpg", "image/png" => "png", "image/webp" => "webp"];
$mime = mime_content_type($_FILES["photo"]["tmp_name"]);

if (!isset($allowed[$mime])) {
    http_response_code(400);
    echo json_encode(["error" => "Only JPG, PNG, or WEBP images are allowed"]);
    exit;
}

$maxBytes = 5 * 1024 * 1024;
if ($_FILES["photo"]["size"] > $maxBytes) {
    http_response_code(400);
    echo json_encode(["error" => "Image must be under 5MB"]);
    exit;
}

$userId = $_SESSION["user_id"];
$ext = $allowed[$mime];
$filename = "user_{$userId}_" . time() . ".{$ext}";
$uploadDir = __DIR__ . "/../uploads/";
$destPath = $uploadDir . $filename;
$relativePath = "uploads/" . $filename;

try {
    $pdo = get_pdo();

    // Remember the old picture, but don't delete it yet — if the new file
    // fails to save we'd be left with no picture at all and a users row
    // pointing at a file that no longer exists. Delete only once the new
    // one is safely written and the row has been updated.
    $stmt = $pdo->prepare("SELECT profile_picture FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $old = $stmt->fetchColumn();

    if (!move_uploaded_file($_FILES["photo"]["tmp_name"], $destPath)) {
        http_response_code(500);
        echo json_encode(["error" => "Failed to save uploaded file"]);
        exit;
    }

    $update = $pdo->prepare("UPDATE users SET profile_picture = ? WHERE id = ?");
    $update->execute([$relativePath, $userId]);

    // Safe to clean up now. Guard against deleting the file we just wrote,
    // in case the generated name somehow matched the previous one.
    if ($old && $old !== $relativePath && file_exists(__DIR__ . "/../" . $old)) {
        unlink(__DIR__ . "/../" . $old);
    }

    echo json_encode(["profilePicture" => $relativePath]);
} catch (PDOException $e) {
    error_log("upload-picture.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["error" => "Something went wrong"]);
}
