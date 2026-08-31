<?php

/**
 * The ten-question listening test.
 *
 * Ported from backend/adaptive_test.py. Every constant, threshold and
 * rounding rule is carried across unchanged — a difference here would move
 * the profile, and the profile is what everything downstream is built on.
 *
 * The algorithm in one line: play two versions that differ in one band,
 * ask which is better, throw away the half of the range that lost. Ten
 * answers locate three bands to within a fraction of a decibel, where
 * testing every setting individually would take about seventy-five.
 *
 * ONE DELIBERATE CHANGE FROM THE PYTHON.
 *
 * The original kept in-progress tests in a module-level dict, so
 * restarting the service lost everyone's half-finished test and two
 * workers would not have seen each other's sessions. Here the state lives
 * in $_SESSION: it is already per-user, already isolated, already
 * persisted, and it survives a restart. This is the one place where the
 * port is better than the original rather than merely equal to it.
 */

require_once __DIR__ . "/pre_quiz.php";
require_once __DIR__ . "/numeric.php";

const AT_RANGE_LOW = -6;
const AT_RANGE_HIGH = 6;
const AT_NUM_SAMPLES = 10;
const AT_GAIN_DECIMALS = 1;

// How far either side of the seed to start looking. Halving the starting
// range is what lets the same ten questions land twice as precisely.
const AT_SEED_WINDOW = 3;

// Knowing nothing means the answer could be anywhere in the 12 dB range,
// i.e. +/- 6. Confidence is measured against that worst case.
const AT_MAX_UNCERTAINTY_DB = (AT_RANGE_HIGH - AT_RANGE_LOW) / 2;
const AT_CONFIDENCE_DECIMALS = 1;

// Bass gets the extra round because it is the most audible band and the
// one people disagree about most. Ten questions in total.
function at_param_rounds() {
    return [
        ["bassGain", 4],
        ["trebleGain", 3],
        ["presenceGain", 3],
    ];
}

function at_total_questions() {
    $total = 0;
    foreach (at_param_rounds() as $pair) {
        $total += $pair[1];
    }
    return $total;
}

function at_sample_labels() {
    return [
        "sample1.wav" => "Show — Chorus",
        "sample2.wav" => "Show — Intro",
        "sample3.wav" => "Everlasting Summer — Ref",
        "sample4.wav" => "Everlasting Summer — Chorus",
        "sample5.wav" => "Summertime Lime — Ambient",
        "sample6.wav" => "Summertime Lime — Intro",
        "sample7.wav" => "I Think They Call This Love — Chorus",
        "sample8.wav" => "I Think They Call This Love — Bridge",
        "sample9.wav" => "Original Me — Chorus",
        "sample10.wav" => "Original Me — Solo",
    ];
}

const AT_SESSION_KEY = "_adaptive_test";


function at_list_samples() {
    $labels = at_sample_labels();
    $out = [];
    for ($i = 1; $i <= AT_NUM_SAMPLES; $i++) {
        $name = "sample{$i}.wav";
        $out[] = ["file" => $name, "label" => $labels[$name] ?? $name];
    }
    return $out;
}


/**
 * Where to start hunting for one band.
 *
 * With no seed the whole range is in play. With one, the search starts in a
 * window around the guess — which is the entire payoff of the written
 * questions.
 */
function at_bounds_for($param, $seed) {
    if (!is_array($seed) || !array_key_exists($param, $seed)) {
        return ["low" => AT_RANGE_LOW, "high" => AT_RANGE_HIGH];
    }

    $estimate = max(AT_RANGE_LOW, min(AT_RANGE_HIGH, (float)$seed[$param]));

    return [
        "low" => max(AT_RANGE_LOW, $estimate - AT_SEED_WINDOW),
        "high" => min(AT_RANGE_HIGH, $estimate + AT_SEED_WINDOW),
    ];
}


function at_start_session($seed = null) {
    $rounds = at_param_rounds();
    $firstParam = $rounds[0][0];

    $finalized = [];
    foreach ($rounds as $pair) {
        $finalized[$pair[0]] = 0;
    }

    $_SESSION[AT_SESSION_KEY] = [
        "questionIndex" => 0,
        "paramIndex" => 0,
        "round" => 0,
        "bounds" => at_bounds_for($firstParam, $seed),
        "finalized" => $finalized,
        "uncertainty" => [],
        "history" => [],
        "seed" => $seed,
    ];

    return at_current_pair();
}


function at_session() {
    return $_SESSION[AT_SESSION_KEY] ?? null;
}

function at_clear_session() {
    unset($_SESSION[AT_SESSION_KEY]);
}


function at_sample_for_question($questionIndex) {
    return "sample" . ($questionIndex + 1) . ".wav";
}


function at_midpoint($bounds) {
    return ($bounds["low"] + $bounds["high"]) / 2;
}


function at_is_complete($session) {
    return $session["paramIndex"] >= count(at_param_rounds())
        || $session["questionIndex"] >= at_total_questions();
}


/**
 * The two versions to compare next.
 *
 * A is always the low end and B always the high end — a convention the
 * narrowing below depends on. The bands already settled are held fixed in
 * both, so exactly one thing differs and an answer is unambiguous.
 */
function at_current_pair() {
    $session = at_session();
    if ($session === null || at_is_complete($session)) {
        return null;
    }

    $rounds = at_param_rounds();
    [$param, $roundsForParam] = $rounds[$session["paramIndex"]];

    $a = $session["finalized"];
    $a[$param] = $session["bounds"]["low"];

    $b = $session["finalized"];
    $b[$param] = $session["bounds"]["high"];

    $sample = at_sample_for_question($session["questionIndex"]);
    $labels = at_sample_labels();

    return [
        "done" => false,
        "param" => $param,
        "round" => $session["round"] + 1,
        "totalRoundsForParam" => $roundsForParam,
        "paramNumber" => $session["paramIndex"] + 1,
        "totalParams" => count($rounds),
        "question" => $session["questionIndex"] + 1,
        "totalQuestions" => at_total_questions(),
        "A" => (object)$a,
        "B" => (object)$b,
        "sample" => $sample,
        "sampleLabel" => $labels[$sample] ?? $sample,
    ];
}


/**
 * The whole algorithm, in three lines.
 *
 * Picked A, the low end? The answer is not above the midpoint, so the top
 * half goes. Picked B? The bottom half goes. Either way half of what
 * remains is gone, which is why ten questions is enough.
 */
function at_narrow_bounds(&$session, $preferred) {
    $mid = at_midpoint($session["bounds"]);
    $edge = $preferred === "A" ? "high" : "low";
    $session["bounds"][$edge] = $mid;
}


function at_finalize_param(&$session, $param) {
    $bounds = $session["bounds"];

    // py_round, not round. Bounds halve from whole numbers, so a midpoint
    // of exactly -5.25 is routine here, and that is precisely where PHP
    // and Python disagree. See api/numeric.php.
    $session["finalized"][$param] = py_round(at_midpoint($bounds), AT_GAIN_DECIMALS);

    // The answer is the midpoint of what survived, so the most it can be
    // wrong by is half that range's width. This is what makes the
    // confidence figure a measurement rather than a claim.
    $session["uncertainty"][$param] = ($bounds["high"] - $bounds["low"]) / 2;

    $session["paramIndex"]++;
    $session["round"] = 0;

    $rounds = at_param_rounds();
    if ($session["paramIndex"] < count($rounds)) {
        $nextParam = $rounds[$session["paramIndex"]][0];
        $session["bounds"] = at_bounds_for($nextParam, $session["seed"] ?? null);
    }
}


function at_confidence_score($session) {
    $uncertainty = $session["uncertainty"] ?? [];
    if (!$uncertainty) {
        return 0.0;
    }

    $average = array_sum($uncertainty) / count($uncertainty);
    $score = 100.0 * (1 - $average / AT_MAX_UNCERTAINTY_DB);

    return py_round(max(0.0, min(100.0, $score)), AT_CONFIDENCE_DECIMALS);
}


function at_record_answer($preferred) {
    $session = at_session();

    if ($session === null) {
        return ["error" => "No active session. Start the test first."];
    }
    if ($preferred !== "A" && $preferred !== "B") {
        return ["error" => 'preferred must be "A" or "B"'];
    }
    if ($session["paramIndex"] >= count(at_param_rounds())) {
        return [
            "error" => "Test already complete",
            "done" => true,
            "profile" => (object)$session["finalized"],
        ];
    }

    $rounds = at_param_rounds();
    [$param, $roundsForParam] = $rounds[$session["paramIndex"]];

    $session["history"][] = [
        "param" => $param,
        "round" => $session["round"] + 1,
        "question" => $session["questionIndex"] + 1,
        "sample" => at_sample_for_question($session["questionIndex"]),
        "low" => $session["bounds"]["low"],
        "high" => $session["bounds"]["high"],
        "preferred" => $preferred,
    ];

    at_narrow_bounds($session, $preferred);

    $session["round"]++;
    $session["questionIndex"]++;

    if ($session["round"] >= $roundsForParam) {
        at_finalize_param($session, $param);
    }

    $_SESSION[AT_SESSION_KEY] = $session;

    if (at_is_complete($session)) {
        $precision = [];
        foreach ($session["uncertainty"] as $band => $value) {
            $precision[$band] = py_round($value, AT_GAIN_DECIMALS);
        }

        return [
            "done" => true,
            "profile" => (object)$session["finalized"],
            "confidence" => at_confidence_score($session),
            "precision" => (object)$precision,
            "history" => $session["history"],
        ];
    }

    // Not finished, so the next pair rides back in the same response
    // rather than costing another round trip.
    return ["done" => false, "next" => at_current_pair()];
}


function at_current_side_params($side) {
    $pair = at_current_pair();
    if ($pair === null) {
        return null;
    }
    $params = (array)($side === "A" ? $pair["A"] : $pair["B"]);
    $params["sample"] = $pair["sample"];
    return $params;
}
