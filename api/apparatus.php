<?php

/**
 * What the listener was wearing during a test.
 *
 * Recorded, not acted upon. Nothing in the scoring, the aggregation or the
 * recommendation reads this value -- a test taken through wireless earbuds
 * is scored exactly like one taken through wired IEMs. It is stored because
 * per-device calibration is planned, and that work needs history: gathering
 * this from now on costs nothing, gathering it for tests already taken is
 * impossible.
 *
 * Saying so plainly matters. A column that looks like it feeds the model
 * but does not is the kind of thing that gets described wrongly in a paper.
 */

// Kept out of the adaptive test's own session array on purpose.
// api/adaptive_test.php is covered by tests/compare_with_python.php, which
// compares its session structure against the retired Python implementation.
// Adding a field there to carry something the algorithm never reads would
// mean changing what that suite verifies, for no benefit.
const APPARATUS_SESSION_KEY = "_test_apparatus";

/**
 * The device kinds offered, and how each is described to the listener.
 *
 * A fixed list rather than free text: this is the only thing keeping the
 * column analysable. The database column is VARCHAR so the list can grow
 * without an ALTER on a table that will hold every assessment ever taken.
 */
function apparatus_options() {
    return [
        "iem" => "In-ear monitors (wired)",
        "earbuds" => "Wireless earbuds",
        "headphones" => "Over-ear or on-ear headphones",
        "other" => "Something else, or not sure",
    ];
}

/**
 * A submitted value, or null if it is not one we offer.
 *
 * Null rather than a default. An unrecognised value means we do not know
 * what they used, and recording a guess as though it were an answer would
 * quietly corrupt the only reason this column exists.
 */
function apparatus_normalise($value) {
    if (!is_string($value)) {
        return null;
    }
    $value = strtolower(trim($value));
    return array_key_exists($value, apparatus_options()) ? $value : null;
}

function apparatus_remember($value) {
    $_SESSION[APPARATUS_SESSION_KEY] = apparatus_normalise($value);
}

function apparatus_for_this_test() {
    return $_SESSION[APPARATUS_SESSION_KEY] ?? null;
}

function apparatus_forget() {
    unset($_SESSION[APPARATUS_SESSION_KEY]);
}
