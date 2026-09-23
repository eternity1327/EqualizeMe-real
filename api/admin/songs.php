<?php

/**
 * The listening test's song catalogue, for administrators.
 *
 * GET    — every slot, with the track currently assigned to it
 * PUT    — change a slot's title, section, file or active flag
 *
 * Uploading the audio itself is api/admin/upload-song.php. Kept separate
 * because a multipart file upload and a JSON edit have almost nothing in
 * common: different parsing, different limits, different failure modes.
 *
 * WHAT AN ADMINISTRATOR CANNOT DO HERE
 * Create or delete slots. There are exactly ten, because the adaptive test
 * asks exactly ten questions, and that count lives in at_param_rounds() in
 * the algorithm rather than in this table. Letting the catalogue grow an
 * eleventh row would produce a row nothing ever plays; letting it lose one
 * would produce a question with no audio. So the slots are fixed and only
 * their contents are editable.
 */

require_once __DIR__ . "/../session.php";
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../csrf.php";
require_once __DIR__ . "/../roles.php";
require_once __DIR__ . "/../audio_samples.php";
require_once __DIR__ . "/../errors.php";
start_secure_session();
header("Content-Type: application/json");

require_admin();

const SONG_TITLE_MAX = 120;
const SONG_SECTION_MAX = 60;

$method = $_SERVER["REQUEST_METHOD"] ?? "GET";

if ($method === "GET") {
    songs_list();
    exit;
}

if ($method === "PUT") {
    songs_update();
    exit;
}

http_response_code(405);
echo json_encode(["error" => "Use GET or PUT"]);
exit;


/**
 * Every slot in question order, whether or not it has a usable track.
 *
 * Ordered by the number inside the key rather than by the key itself,
 * because sorting "sample10.wav" as text puts it between 1 and 2.
 */
function songs_list() {
    try {
        $pdo = get_pdo();
        $rows = $pdo->query(
            "SELECT id, sample_key, file_path, title, section, is_active
             FROM audio_samples
             ORDER BY CAST(
                 REPLACE(REPLACE(sample_key, 'sample', ''), '.wav', '')
                 AS UNSIGNED)"
        )->fetchAll();

        $songs = [];
        foreach ($rows as $row) {
            $songs[] = [
                "id" => (int)$row["id"],
                "sampleKey" => $row["sample_key"],
                "filePath" => $row["file_path"],
                "title" => $row["title"],
                "section" => $row["section"],
                "isActive" => (bool)$row["is_active"],
                // Reported rather than corrected. An administrator who
                // typed a bad path should see that it is bad here, on the
                // page where they can fix it, instead of discovering it as
                // silence during a listening test.
                "pathIsValid" => as_path_is_safe($row["file_path"]),
                "fileExists" => songs_file_exists($row["file_path"]),
            ];
        }

        echo json_encode(["songs" => $songs]);
    } catch (PDOException $e) {
        fail_json(500, "Could not load the song list.", $e, "admin/songs.php");
    }
}


/**
 * Does the file actually sit on disk where the row says it does?
 *
 * Only asked for paths that already passed the safety check, so this
 * cannot be used to probe for files outside the samples folder.
 */
function songs_file_exists($path) {
    if (!as_path_is_safe($path)) {
        return false;
    }
    return file_exists(__DIR__ . "/../../" . $path);
}


function songs_update() {
    $body = json_decode(file_get_contents("php://input"), true);
    csrf_verify_or_fail($_SERVER["HTTP_X_CSRF_TOKEN"] ?? null);

    $key = is_string($body["sampleKey"] ?? null) ? $body["sampleKey"] : "";
    if ($key === "") {
        http_response_code(400);
        echo json_encode(["error" => "Which slot? sampleKey is required."]);
        return;
    }

    $title = songs_clean_text($body["title"] ?? "", SONG_TITLE_MAX);
    if ($title === "") {
        http_response_code(400);
        echo json_encode(["error" => "A song needs a title."]);
        return;
    }

    // Empty section means "the whole track", which is the normal case now
    // that the files are full songs. Stored as NULL rather than an empty
    // string so the label builder has one thing to check instead of two.
    $section = songs_clean_text($body["section"] ?? "", SONG_SECTION_MAX);
    $section = $section === "" ? null : $section;

    $filePath = is_string($body["filePath"] ?? null) ? trim($body["filePath"]) : "";
    if (!as_path_is_safe($filePath)) {
        http_response_code(400);
        echo json_encode([
            "error" => "That file path is not allowed. It must sit inside "
                . AS_SAMPLE_ROOT . " and be an audio file.",
        ]);
        return;
    }

    // Checked because the alternative is a silent listening test. The row
    // would save happily and the failure would appear later, to a user, as
    // audio that never starts.
    if (!songs_file_exists($filePath)) {
        http_response_code(400);
        echo json_encode([
            "error" => "No file at " . $filePath . ". Upload it first, or "
                . "check the name — it is case-sensitive on the server.",
        ]);
        return;
    }

    $isActive = !empty($body["isActive"]) ? 1 : 0;

    try {
        $pdo = get_pdo();

        // WHERE on sample_key and nothing else. The slots are fixed, so an
        // UPDATE that matches no row means the caller invented a key —
        // which is a bad request, not a row to create.
        $stmt = $pdo->prepare(
            "UPDATE audio_samples
                SET file_path = ?, title = ?, section = ?, is_active = ?
              WHERE sample_key = ?"
        );
        $stmt->execute([$filePath, $title, $section, $isActive, $key]);

        if ($stmt->rowCount() === 0 && !songs_key_exists($pdo, $key)) {
            http_response_code(404);
            echo json_encode(["error" => "No slot called " . $key . "."]);
            return;
        }

        echo json_encode([
            "status" => "ok",
            "sampleKey" => $key,
            "title" => $title,
            "section" => $section,
            "filePath" => $filePath,
            "isActive" => (bool)$isActive,
        ]);
    } catch (PDOException $e) {
        fail_json(500, "Could not save the song.", $e, "admin/songs.php");
    }
}


/**
 * Separates "no such slot" from "saved, but nothing changed".
 *
 * rowCount() returns zero for both, and they deserve different answers:
 * the first is an error, the second is a successful no-op.
 */
function songs_key_exists($pdo, $key) {
    $stmt = $pdo->prepare("SELECT 1 FROM audio_samples WHERE sample_key = ?");
    $stmt->execute([$key]);
    return (bool)$stmt->fetchColumn();
}


/**
 * Trim, flatten whitespace, drop control characters, enforce a length.
 *
 * Stripped rather than rejected: a title pasted from a document often
 * carries a stray newline, and refusing it teaches nothing while removing
 * it does what the person meant. Same reasoning as api/account.php.
 */
function songs_clean_text($value, $max) {
    if (!is_string($value)) {
        return "";
    }
    $value = preg_replace('/[\x00-\x1F\x7F]+/u', " ", $value);
    $value = trim(preg_replace('/\s{2,}/u', " ", $value));
    return mb_substr($value, 0, $max);
}
