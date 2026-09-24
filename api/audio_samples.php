<?php

/**
 * Where the listening test's audio actually lives.
 *
 * The files stay on disk; this reads the path to them out of the database.
 * That split is deliberate. Serving a file from disk is the web server's
 * job and it is good at it; streaming a multi-megabyte BLOB through PHP on
 * every play would be slower, would hold a database connection open for the
 * length of the download, and would bloat every backup. What belongs in the
 * database is the small, frequently-edited part: which file, and what to
 * call it.
 *
 * The identifier is unchanged. at_sample_for_question() still returns
 * "sampleN.wav", that string is still written into each test's history, and
 * tests/compare_with_python.php still checks it against the original Python.
 * It is a key now rather than a filename — the filename is file_path and can
 * be anything.
 */

require_once __DIR__ . "/db.php";
// For at_sample_for_question(), used to look one question ahead.
require_once __DIR__ . "/adaptive_test.php";

const AS_SAMPLE_ROOT = "data/audio/samples/";

// Where a row is pointed at something unusable. Not an error the listener
// should see: the test still runs, on the file the code would have picked
// before this table existed.
const AS_FALLBACK_EXTENSION = ".mp3";


/**
 * Is this path safe to hand to a browser?
 *
 * The value comes from our own database, which is not the same as it being
 * trustworthy — it is edited by hand, and a typo here becomes a URL the
 * browser fetches. Three things are checked:
 *
 *   it stays inside the samples folder, so a row cannot point at
 *   api/config.local.php and have the browser ask for it;
 *
 *   it contains no "..", which is the one way a string that starts with the
 *   right prefix can still escape the folder;
 *
 *   it is an audio file, so a row cannot turn into a link to a script.
 *
 * Note the check is on the string, before any filesystem call. A path that
 * fails here is never opened, so a malformed row cannot even be probed for.
 */
function as_path_is_safe($path) {
    if (!is_string($path) || $path === "") {
        return false;
    }
    if (strpos($path, "..") !== false) {
        return false;
    }
    if (strncmp($path, AS_SAMPLE_ROOT, strlen(AS_SAMPLE_ROOT)) !== 0) {
        return false;
    }
    return (bool)preg_match('/\.(mp3|ogg|m4a|wav)$/i', $path);
}


/**
 * The path the code would have used before this table existed.
 *
 * Reached when a row is missing, inactive, or fails the safety check. The
 * test carries on with the original file rather than failing, because a bad
 * row is an administrative mistake and the listener is not the one who
 * should be stopped by it.
 */
function as_fallback_path($sampleKey) {
    $base = preg_replace('/\.wav$/i', '', $sampleKey);
    return AS_SAMPLE_ROOT . $base . AS_FALLBACK_EXTENSION;
}


/**
 * Every active sample, keyed by identifier.
 *
 * One query rather than one per question. The table has ten rows and is
 * read twice per test; fetching it whole is cheaper than the round trips,
 * and means the caller cannot accidentally make this N+1.
 */
function as_fetch_catalogue($pdo) {
    $rows = $pdo->query(
        "SELECT id, sample_key, file_path, title, section
         FROM audio_samples
         WHERE is_active = 1"
    )->fetchAll();

    $catalogue = [];
    foreach ($rows as $row) {
        $row["markers"] = [];
        $catalogue[$row["sample_key"]] = $row;
    }

    return as_attach_markers($pdo, $catalogue);
}


/**
 * Hang each slot's named timestamps off its catalogue entry.
 *
 * One query for the lot, joined in PHP. The alternative -- a query per slot
 * -- would be ten round trips to decorate a response that already has all
 * the ids it needs.
 *
 * A failure here is not fatal. Markers are a convenience for finding the
 * chorus; a test with none still runs exactly as it did before they
 * existed, so a missing table (the migration not yet applied) leaves the
 * catalogue untouched rather than breaking the test.
 */
function as_attach_markers($pdo, $catalogue) {
    if (!$catalogue) {
        return $catalogue;
    }

    try {
        $rows = $pdo->query(
            "SELECT audio_sample_id, label, start_seconds
             FROM audio_markers
             ORDER BY audio_sample_id, start_seconds"
        )->fetchAll();
    } catch (PDOException $e) {
        error_log("audio_samples: markers unavailable: " . $e->getMessage());
        return $catalogue;
    }

    $byId = [];
    foreach ($rows as $row) {
        $byId[(int)$row["audio_sample_id"]][] = [
            "label" => $row["label"],
            "start" => (float)$row["start_seconds"],
        ];
    }

    foreach ($catalogue as $key => $entry) {
        $catalogue[$key]["markers"] = $byId[(int)$entry["id"]] ?? [];
    }

    return $catalogue;
}


/**
 * The label shown above the waveform.
 *
 * "Title — Section" when a section is named, otherwise just the title. The
 * browser splits on the dash, so the shape matters: a track with no section
 * must not carry a trailing separator or the page renders an empty pill.
 */
function as_label($row) {
    $title = trim((string)($row["title"] ?? ""));
    $section = trim((string)($row["section"] ?? ""));

    if ($title === "") {
        return "";
    }
    return $section === "" ? $title : "{$title} — {$section}";
}


/**
 * The path for one identifier, falling back when the row is unusable.
 */
function as_path_for($key, $catalogue) {
    $row = $catalogue[$key] ?? null;
    $path = $row !== null ? $row["file_path"] : null;

    if (as_path_is_safe($path)) {
        return $path;
    }

    if ($row !== null) {
        error_log("audio_samples: unusable file_path for {$key}: "
            . var_export($path, true));
    }
    return as_fallback_path($key);
}


/**
 * The path for the question after this one, or null on the last question.
 *
 * at_sample_for_question() takes a zero-based index, and $pair["question"]
 * is one-based — so the current question's own index is question - 1, and
 * the next one's is simply question.
 */
function as_next_path($pair, $catalogue) {
    $question = (int)($pair["question"] ?? 0);
    $total = (int)($pair["totalQuestions"] ?? 0);

    if ($question <= 0 || $question >= $total) {
        return null;
    }
    return as_path_for(at_sample_for_question($question), $catalogue);
}


/**
 * Attach the path and the label to a pair on its way to the browser.
 *
 * Called by the endpoints rather than by at_next_pair(), so the algorithm
 * file stays free of database access and keeps matching the Python it was
 * ported from. A finished pair — one with no "sample" — passes through
 * untouched.
 */
function as_decorate_pair($pair, $catalogue) {
    if (!is_array($pair) || !isset($pair["sample"])) {
        return $pair;
    }

    $key = $pair["sample"];
    $row = $catalogue[$key] ?? null;

    $pair["samplePath"] = as_path_for($key, $catalogue);

    // Named points inside the track — Intro, Chorus, Drop — drawn as flags
    // on the waveform and clickable to jump there. Always an array, so the
    // browser has nothing to check before iterating.
    $pair["markers"] = $row !== null ? ($row["markers"] ?? []) : [];

    // The path for the question after this one, so the browser can start
    // downloading it now. It used to work this out itself by incrementing a
    // number; with the filename in the database there is nothing to
    // increment, and prefetching matters more than it did — a full track is
    // megabytes where a ten-second clip was kilobytes.
    $next = as_next_path($pair, $catalogue);
    if ($next !== null) {
        $pair["nextSamplePath"] = $next;
    }

    // Only replace the built-in label when the row actually has one. A blank
    // title in the database should not wipe out a usable fallback.
    $label = $row !== null ? as_label($row) : "";
    if ($label !== "") {
        $pair["sampleLabel"] = $label;
    }

    return $pair;
}


/**
 * The path of the track the first question will use.
 *
 * Sent with the pre-quiz questions so the download can happen while they
 * are being answered. Returns the fallback path if anything goes wrong —
 * a wasted prefetch is a far smaller cost than a failed page.
 */
function as_first_sample_path() {
    $key = at_sample_for_question(0);
    try {
        return as_path_for($key, as_fetch_catalogue(get_pdo()));
    } catch (PDOException $e) {
        error_log("audio_samples: catalogue unavailable: " . $e->getMessage());
        return as_fallback_path($key);
    }
}


/**
 * The same, tolerant of the database being unreachable.
 *
 * A listening test that cannot start because the catalogue query failed
 * would be a worse outcome than one that plays the original files. The
 * failure is logged; the listener is not told, because there is nothing
 * they could do and nothing has gone wrong from where they sit.
 */
function as_decorate_pair_safely($pair) {
    try {
        return as_decorate_pair($pair, as_fetch_catalogue(get_pdo()));
    } catch (PDOException $e) {
        error_log("audio_samples: catalogue unavailable: " . $e->getMessage());
        return as_decorate_pair($pair, []);
    }
}
