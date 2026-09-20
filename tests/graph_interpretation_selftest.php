<?php

/**
 * Checks for api/graph_interpretation.php.
 *
 * Run from the project root:
 *
 *   C:\xampp\php\php.exe tests\graph_interpretation_selftest.php
 *
 * CLI only. It prints text, has no session and touches no database, but
 * there is no reason for it to be reachable over the web either.
 */

if (PHP_SAPI !== "cli") {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once __DIR__ . "/../api/graph_interpretation.php";

$checks = 0;
$failures = 0;

function check($label, $actual, $expected) {
    global $checks, $failures;
    $checks++;

    if ($actual === $expected) {
        return;
    }

    $failures++;
    echo "FAIL  {$label}\n";
    echo "      expected: " . var_export($expected, true) . "\n";
    echo "      actual:   " . var_export($actual, true) . "\n";
}

function target($bass, $presence, $treble) {
    return [
        "bass_gain" => $bass,
        "presence_gain" => $presence,
        "treble_gain" => $treble,
    ];
}

function iem($bass, $presence, $treble) {
    return ["gains" => target($bass, $presence, $treble)];
}


/* ── the closeness bands ──────────────────────────────────────────────── */

check("0.0 dB is on target", gi_closeness_key(0.0), "on_target");
check("0.74 dB is on target", gi_closeness_key(0.74), "on_target");
check("0.75 dB is close", gi_closeness_key(0.75), "close");
check("-0.75 dB is close", gi_closeness_key(-0.75), "close");
check("1.99 dB is close", gi_closeness_key(1.99), "close");
check("2.0 dB is off", gi_closeness_key(2.0), "off");
check("3.9 dB is off", gi_closeness_key(3.9), "off");
check("4.0 dB is far", gi_closeness_key(4.0), "far");
check("-9.0 dB is far", gi_closeness_key(-9.0), "far");


/* ── the sign convention ──────────────────────────────────────────────── */
//
// The case that matters: both figures negative, but the earphone is still
// offering more bass than the listener asked for.

$band = gi_compare_band("bass_gain", -3.0, -1.0);
check("negative target, less negative iem => more",
    $band["direction"], "more");
check("difference is iem minus target",
    $band["difference_db"], 2.0);

$band = gi_compare_band("bass_gain", 1.0, -1.5);
check("iem below target => less", $band["direction"], "less");
check("difference is signed", $band["difference_db"], -2.5);

$band = gi_compare_band("treble_gain", 2.0, 2.2);
check("a 0.2 dB gap has no direction", $band["direction"], "match");


/* ── the sentences ────────────────────────────────────────────────────── */

$band = gi_compare_band("bass_gain", 0.0, 0.3);
check("on-target sentence omits the figure",
    $band["sentence"],
    "Bass (below ~250 Hz) is almost exactly what you asked for.");

$band = gi_compare_band("bass_gain", 0.0, 1.4);
check("a small excess reads as a little more",
    $band["sentence"],
    "Bass (below ~250 Hz) is a little more than you asked for (+1.4 dB).");

$band = gi_compare_band("treble_gain", 0.0, -3.0);
check("a shortfall keeps its minus sign",
    $band["sentence"],
    "Treble (above ~6 kHz) is noticeably less than you asked for (-3.0 dB).");

$band = gi_compare_band("presence_gain", 0.0, 6.5);
check("a large excess reads as well beyond",
    $band["sentence"],
    "Upper midrange (~2-5 kHz) is well beyond what you asked for (+6.5 dB).");


/* ── the summary ──────────────────────────────────────────────────────── */

$result = gi_interpret(iem(2.1, -0.9, 3.0), target(2.0, -1.0, 3.2));
check("all three close => tracks closely",
    $result["summary"],
    "This one tracks your target closely across all three bands.");

$result = gi_interpret(iem(5.0, -1.0, 3.2), target(2.0, -1.0, 3.2));
check("one band out is named",
    $result["summary"],
    "Close to your target, apart from the bass.");

// A deviation just over the on-target line is not a caveat. The summary
// must not contradict the band line beneath it, which calls the same
// figure "a little more".
$result = gi_interpret(iem(2.9, -1.0, 3.2), target(2.0, -1.0, 3.2));
check("a 0.9 dB deviation does not earn an 'apart from'",
    $result["summary"],
    "Close to your target, with only small differences.");

$result = gi_interpret(iem(2.9, -0.2, 3.2), target(2.0, -1.0, 3.2));
check("two small deviations still read as small",
    $result["summary"],
    "Close to your target, with only small differences.");

$result = gi_interpret(iem(5.0, 3.0, 3.2), target(2.0, -1.0, 3.2));
check("two bands out => the worst is named",
    $result["summary"],
    "Its upper midrange is the furthest from your target; "
        . "the other bands are nearer.");

// Ties go to the first band checked rather than being undefined. The order
// is GI_BANDS, so bass wins a tie with treble.
$result = gi_interpret(iem(5.0, -1.0, 6.2), target(2.0, -1.0, 3.2));
check("an exact tie resolves to the first band",
    $result["summary"],
    "Its bass is the furthest from your target; "
        . "the other bands are nearer.");


/* ── shape of the output ──────────────────────────────────────────────── */

$result = gi_interpret(iem(0.0, 0.0, 0.0), target(0.0, 0.0, 0.0));
check("three bands come back", count($result["bands"]), 3);
check("bands are in GI_BANDS order",
    array_column($result["bands"], "band"),
    ["bass_gain", "presence_gain", "treble_gain"]);

// An earphone missing a measurement should be dropped upstream. If one
// reaches here, the band is skipped rather than invented.
$partial = gi_interpret(["gains" => ["bass_gain" => 1.0]], target(0.0, 0.0, 0.0));
check("an unmeasured band is skipped", count($partial["bands"]), 1);

$empty = gi_interpret(["gains" => []], target(0.0, 0.0, 0.0));
check("no measurements gives no summary", $empty["summary"], "");


/* ── annotation ───────────────────────────────────────────────────────── */

$annotated = gi_annotate([
    ["iem_id" => 1, "gains" => target(2.0, -1.0, 3.2)],
    ["iem_id" => 2, "gains" => target(9.0, -1.0, 3.2)],
], target(2.0, -1.0, 3.2));

check("annotation keeps the list length", count($annotated), 2);
check("annotation keeps each id",
    array_column($annotated, "iem_id"), [1, 2]);
check("the close one is described as close",
    $annotated[0]["interpretation"]["summary"],
    "This one tracks your target closely across all three bands.");
check("the distant one has its bass called out",
    $annotated[1]["interpretation"]["summary"],
    "Close to your target, apart from the bass.");


/* ── the thresholds agree with the profile analysis ───────────────────── */
//
// Two parts of the same page calling different figures "meaningful" would
// contradict each other in front of the reader.

check("on-target threshold matches PP_NEUTRAL_DB",
    GI_ON_TARGET_DB, PP_NEUTRAL_DB);


echo "\n{$checks} checks, {$failures} failures.\n";
exit($failures === 0 ? 0 : 1);
