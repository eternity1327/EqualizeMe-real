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

        $markers = songs_fetch_markers($pdo);

        $songs = [];
        foreach ($rows as $row) {
            $songs[] = [
                "id" => (int)$row["id"],
                "sampleKey" => $row["sample_key"],
                "filePath" => $row["file_path"],
                "title" => $row["title"],
                "section" => $row["section"],
                "isActive" => (bool)$row["is_active"],
                // One editable line per slot: "Intro 0:00, Chorus 1:12".
                // A grid of add/remove rows would be the obvious design and
                // a worse one — these are typed in while scrubbing through
                // a song, and typing beats clicking for that.
                "markers" => songs_markers_to_text($markers[(int)$row["id"]] ?? []),
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


/* ────────────────────────────── markers ───────────────────────────────── */

const MARKER_LABEL_MAX = 40;

// A song longer than this is not a song. The cap stops a typo like "Chorus
// 9999" producing a marker far past the end of the track, where it would be
// drawn off the edge of the waveform and be unreachable.
const MARKER_MAX_SECONDS = 3600;


function songs_fetch_markers($pdo) {
    try {
        $rows = $pdo->query(
            "SELECT audio_sample_id, label, start_seconds
             FROM audio_markers
             ORDER BY audio_sample_id, start_seconds"
        )->fetchAll();
    } catch (PDOException $e) {
        // The migration may not have been run yet. An admin page that
        // cannot show markers is still a working admin page.
        error_log("admin/songs.php: markers unavailable: " . $e->getMessage());
        return [];
    }

    $byId = [];
    foreach ($rows as $row) {
        $byId[(int)$row["audio_sample_id"]][] = $row;
    }
    return $byId;
}


function songs_markers_to_text($rows) {
    $parts = [];
    foreach ($rows as $row) {
        $seconds = (float)$row["start_seconds"];
        $minutes = (int)floor($seconds / 60);
        $rest = $seconds - $minutes * 60;

        // Whole seconds print as 1:12, fractions as 1:12.4. Showing .00 on
        // every marker would be noise on a field meant to be read at a
        // glance.
        $stamp = $rest == (int)$rest
            ? sprintf("%d:%02d", $minutes, (int)$rest)
            : sprintf("%d:%04.1f", $minutes, $rest);

        $parts[] = $row["label"] . " " . $stamp;
    }
    return implode(", ", $parts);
}


/**
 * Parse "Intro 0:00, Chorus 1:12.5" into rows.
 *
 * Returns [markers, errors]. A line that cannot be read is reported rather
 * than dropped: silently discarding a marker the admin typed would leave
 * them staring at a waveform wondering which of the four they got wrong.
 *
 * The label is whatever precedes the timestamp, so "Second chorus 2:40"
 * works without quoting anything.
 */
function songs_parse_markers($text) {
    $markers = [];
    $errors = [];

    foreach (explode(",", (string)$text) as $piece) {
        $piece = trim($piece);
        if ($piece === "") {
            continue;
        }

        if (!preg_match('/^(.*?)\s+(\d+):([0-5]?\d(?:\.\d+)?)$/u', $piece, $m)) {
            $errors[] = $piece;
            continue;
        }

        $label = songs_clean_text($m[1], MARKER_LABEL_MAX);
        if ($label === "") {
            $errors[] = $piece;
            continue;
        }

        $seconds = ((int)$m[2]) * 60 + (float)$m[3];
        if ($seconds > MARKER_MAX_SECONDS) {
            $errors[] = $piece;
            continue;
        }

        $markers[] = ["label" => $label, "start" => round($seconds, 2)];
    }

    return [$markers, $errors];
}


/**
 * Replace a slot's markers wholesale.
 *
 * Delete-then-insert rather than reconciling, because the field is a single
 * line of text with no stable identity per marker — there is no way to tell
 * an edited marker from a deleted one and a new one. Wrapped in the caller's
 * transaction so a failure half way cannot leave a slot with no markers at
 * all.
 */
function songs_replace_markers($pdo, $sampleId, $markers) {
    $pdo->prepare("DELETE FROM audio_markers WHERE audio_sample_id = ?")
        ->execute([$sampleId]);

    if (!$markers) {
        return;
    }

    $insert = $pdo->prepare(
        "INSERT INTO audio_markers (audio_sample_id, label, start_seconds)
         VALUES (?, ?, ?)"
    );
    foreach ($markers as $marker) {
        $insert->execute([$sampleId, $marker["label"], $marker["start"]]);
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

    // Rejected before anything is written. Saving the title and silently
    // dropping a mistyped marker would be the worst of both — the admin
    // sees "Saved" and the marker is simply gone.
    [$markers, $markerErrors] = songs_parse_markers($body["markers"] ?? "");
    if ($markerErrors) {
        http_response_code(400);
        echo json_encode([
            "error" => "Could not read " . implode("; ", $markerErrors)
                . ". Use a label then a time, like: Intro 0:00, Chorus 1:12",
        ]);
        return;
    }

    try {
        $pdo = get_pdo();

        $sampleId = songs_id_for_key($pdo, $key);
        if ($sampleId === null) {
            http_response_code(404);
            echo json_encode(["error" => "No slot called " . $key . "."]);
            return;
        }

        // The row and its markers move together. Without the transaction a
        // failure between them leaves a slot pointing at a new track with
        // the previous track's markers on it, which is worse than either
        // change failing outright.
        $pdo->beginTransaction();

        // WHERE on sample_key and nothing else. The slots are fixed, so
        // there is never a row to create here.
        $pdo->prepare(
            "UPDATE audio_samples
                SET file_path = ?, title = ?, section = ?, is_active = ?
              WHERE sample_key = ?"
        )->execute([$filePath, $title, $section, $isActive, $key]);

        songs_replace_markers($pdo, $sampleId, $markers);

        $pdo->commit();

        echo json_encode([
            "status" => "ok",
            "sampleKey" => $key,
            "title" => $title,
            "section" => $section,
            "filePath" => $filePath,
            "isActive" => (bool)$isActive,
            "markerCount" => count($markers),
        ]);
    } catch (PDOException $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fail_json(500, "Could not save the song.", $e, "admin/songs.php");
    }
}


/**
 * The row id for a slot key, or null if there is no such slot.
 *
 * Looked up before the update rather than inferred from rowCount()
 * afterwards. rowCount() returns zero both for "no such slot" and for
 * "saved, but nothing actually changed", and those deserve different
 * answers — the first is an error, the second is a successful no-op. The
 * id is needed for the markers anyway.
 */
function songs_id_for_key($pdo, $key) {
    $stmt = $pdo->prepare("SELECT id FROM audio_samples WHERE sample_key = ?");
    $stmt->execute([$key]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int)$id;
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
