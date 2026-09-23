<?php

/**
 * Put an audio file into the samples folder.
 *
 * POST multipart with a "song" file part. Returns the path to store in
 * audio_samples.file_path — it does not touch the table itself, because
 * uploading a file and assigning it to a slot are separate decisions and
 * an administrator may well upload several before wiring any of them up.
 *
 * THE FILENAME IS NOT THE ONE THAT WAS SENT
 * A browser-supplied filename is attacker-controlled text that would
 * otherwise become a path. Rather than sanitising it — which is a game of
 * guessing every dangerous form — the name is rebuilt here from a slug of
 * the title and a timestamp. Nothing the caller sends reaches the
 * filesystem verbatim.
 */

require_once __DIR__ . "/../session.php";
require_once __DIR__ . "/../csrf.php";
require_once __DIR__ . "/../rate_limit.php";
require_once __DIR__ . "/../roles.php";
require_once __DIR__ . "/../audio_samples.php";
start_secure_session();
header("Content-Type: application/json");

require_admin();
csrf_verify_or_fail($_POST["csrf_token"] ?? null);

// Roughly eight minutes at 192 kbps. Large enough for any song, small
// enough that a mistaken upload of something that is not music fails
// before it costs the hosting quota.
const SONG_MAX_BYTES = 12 * 1024 * 1024;

// Kept to what browsers decode reliably through the Web Audio API. WAV is
// allowed because it is what the group works in, but it is a poor choice
// for the server — a four-minute WAV is ten times the size of the MP3 —
// so the response says so rather than silently accepting it.
const SONG_TYPES = [
    "audio/mpeg" => "mp3",
    "audio/mp3" => "mp3",
    "audio/wav" => "wav",
    "audio/x-wav" => "wav",
    "audio/ogg" => "ogg",
    "audio/mp4" => "m4a",
    "audio/x-m4a" => "m4a",
];

$uploadKey = rate_limit_key("user:" . $_SESSION["user_id"]);

if (!rate_limit_check(
    "upload_song",
    UPLOAD_SONG_MAX_ATTEMPTS,
    UPLOAD_SONG_WINDOW_SECONDS,
    $uploadKey
)) {
    http_response_code(429);
    echo json_encode([
        "error" => "That is a lot of uploads in a short time. Wait a few "
            . "minutes before adding more.",
    ]);
    exit;
}

// Recorded before the file is examined, so sending deliberate rubbish
// costs an attempt just as a real upload does.
rate_limit_record("upload_song", $uploadKey);

if (!isset($_FILES["song"]) || $_FILES["song"]["error"] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(["error" => song_upload_error($_FILES["song"]["error"] ?? null)]);
    exit;
}

// Read from the file's own bytes, not from the Content-Type the browser
// claimed. The claim is caller-controlled; the bytes are the file.
$mime = mime_content_type($_FILES["song"]["tmp_name"]);

if (!isset(SONG_TYPES[$mime])) {
    http_response_code(400);
    echo json_encode([
        "error" => "That is not an audio file the browser can play "
            . "(detected " . $mime . "). Use MP3.",
    ]);
    exit;
}

if ($_FILES["song"]["size"] > SONG_MAX_BYTES) {
    http_response_code(400);
    echo json_encode([
        "error" => "That file is "
            . round($_FILES["song"]["size"] / 1048576, 1) . " MB. The limit is "
            . (SONG_MAX_BYTES / 1048576) . " MB — export it as a 192 kbps MP3.",
    ]);
    exit;
}

$extension = SONG_TYPES[$mime];
$slug = song_slug($_POST["title"] ?? "", $_FILES["song"]["name"] ?? "");
$filename = $slug . "-" . time() . "." . $extension;

$relativePath = AS_SAMPLE_ROOT . $filename;
$destination = __DIR__ . "/../../" . $relativePath;

// Belt and braces. The name was built here from a slug that cannot contain
// a slash or a dot, so this should never fail — and if a future edit makes
// it possible, it fails here rather than writing outside the folder.
if (!as_path_is_safe($relativePath)) {
    http_response_code(500);
    echo json_encode(["error" => "Could not build a safe filename."]);
    exit;
}

if (!is_dir(dirname($destination))) {
    http_response_code(500);
    echo json_encode(["error" => "The samples folder is missing on the server."]);
    exit;
}

if (!move_uploaded_file($_FILES["song"]["tmp_name"], $destination)) {
    error_log("admin/upload-song.php: could not write " . $destination);
    http_response_code(500);
    echo json_encode([
        "error" => "The file could not be saved. The samples folder may not "
            . "be writable.",
    ]);
    exit;
}

echo json_encode([
    "status" => "ok",
    "filePath" => $relativePath,
    "bytes" => $_FILES["song"]["size"],
    // A nudge rather than a refusal. An uncompressed upload works, it is
    // just wasteful, and the person doing it is the person who can fix it.
    "note" => $extension === "wav"
        ? "Saved, but this is a WAV — several times larger than an MP3 of "
            . "the same music, and every listener downloads it. Consider "
            . "re-uploading as a 192 kbps MP3."
        : null,
]);


/**
 * A filesystem-safe stem, from the title if there is one and the original
 * filename if there is not.
 *
 * Everything outside a-z, 0-9 and the hyphen is discarded rather than
 * escaped. The result never contains a dot, a slash, or a leading hyphen,
 * so it cannot become a path fragment, a hidden file, or a second
 * extension.
 */
function song_slug($title, $originalName) {
    $source = trim((string)$title);
    if ($source === "") {
        $source = pathinfo((string)$originalName, PATHINFO_FILENAME);
    }

    $slug = strtolower($source);
    // Transliterate what can be transliterated, so an accented title keeps
    // its letters instead of losing them to the filter below.
    $converted = @iconv("UTF-8", "ASCII//TRANSLIT", $slug);
    if ($converted !== false) {
        $slug = $converted;
    }

    $slug = preg_replace('/[^a-z0-9]+/', "-", $slug);
    $slug = trim($slug, "-");
    $slug = substr($slug, 0, 60);

    // A title made entirely of characters the filter removes — an
    // all-Japanese song name, say — would leave nothing. "track" plus the
    // timestamp appended by the caller is still unique.
    return $slug === "" ? "track" : $slug;
}


/**
 * PHP's upload error codes, in words the person can act on.
 *
 * UPLOAD_ERR_INI_SIZE and UPLOAD_ERR_FORM_SIZE are the ones that actually
 * happen: shared hosting often caps uploads well below what this endpoint
 * would allow, and the resulting failure is otherwise mystifying.
 */
function song_upload_error($code) {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return "The server rejected that file for being too large. This "
                . "is the host's own limit, below ours — check "
                . "upload_max_filesize and post_max_size.";
        case UPLOAD_ERR_PARTIAL:
            return "The upload was cut off part-way. Try again.";
        case UPLOAD_ERR_NO_FILE:
            return "No file was chosen.";
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
            return "The server could not write the file to disk.";
        default:
            return "The upload failed.";
    }
}
