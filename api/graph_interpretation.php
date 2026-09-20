<?php

/**
 * Explaining a recommendation's curve against the listener's target.
 *
 * This is the interpretation layer the project needs its "AI" to have, and
 * it is deliberately rule-based rather than generative. The numbers it
 * describes are already decided by recommend.php; the only job here is to
 * say what they mean in words. A language model asked to do the same thing
 * would be free to describe a 1.4 dB difference as "significantly more
 * bass" on one page load and "essentially identical" on the next, and the
 * text would stop being evidence of anything.
 *
 * It reads. It never scores, ranks or filters. rec_rank_recommendations()
 * has already chosen the five by the time anything here runs, so nothing
 * written below can change what a listener is shown -- only how it is
 * explained.
 *
 * Every threshold is in decibels of difference between the earphone's
 * centred measurement and the listener's target for that band.
 */

require_once __DIR__ . "/numeric.php";
require_once __DIR__ . "/preference_profile.php";

const GI_BANDS = ["bass_gain", "presence_gain", "treble_gain"];

// Below this, the difference is not worth mentioning as a difference.
//
// Chosen to match PP_NEUTRAL_DB, which is the figure the profile analysis
// already uses for "near reference". Two parts of the same results page
// disagreeing about what counts as a meaningful decibel would be worse
// than either threshold being slightly wrong.
const GI_ON_TARGET_DB = 0.75;

// A difference a careful listener would notice on familiar material.
const GI_CLOSE_DB = 2.0;

// A difference that changes the character of the earphone.
const GI_OFF_DB = 4.0;

const GI_DECIMALS = 1;

// How many bands have to sit inside GI_ON_TARGET_DB before the summary is
// allowed to call the whole match close. Two of three is not "closely" --
// the third band is the one the listener would hear.
const GI_ALL_BANDS = 3;


function gi_closeness_key($difference) {
    $size = abs($difference);

    if ($size < GI_ON_TARGET_DB) {
        return "on_target";
    }
    if ($size < GI_CLOSE_DB) {
        return "close";
    }
    if ($size < GI_OFF_DB) {
        return "off";
    }
    return "far";
}


/**
 * The phrase for one band, given how far out it is and in which direction.
 *
 * Kept as a table rather than assembled from fragments so that every
 * sentence the page can produce is visible in one place and can be read
 * for tone. Built sentences are how you end up shipping "slightly much
 * less bass".
 */
function gi_phrases() {
    return [
        "on_target" => [
            "more" => "almost exactly what you asked for",
            "less" => "almost exactly what you asked for",
        ],
        "close" => [
            "more" => "a little more than you asked for",
            "less" => "a little less than you asked for",
        ],
        "off" => [
            "more" => "noticeably more than you asked for",
            "less" => "noticeably less than you asked for",
        ],
        "far" => [
            "more" => "well beyond what you asked for",
            "less" => "well short of what you asked for",
        ],
    ];
}


/**
 * One band, compared.
 *
 * `difference` is the earphone minus the target, so a positive number
 * always means "more of this than the listener wants" regardless of
 * whether either figure is itself positive. That sign convention is the
 * whole reason this reads sensibly: a listener who wants -3 dB of bass and
 * is offered -1 dB is being offered more bass, even though both numbers
 * are negative.
 */
function gi_compare_band($band, $targetDb, $iemDb) {
    $difference = py_round((float)$iemDb - (float)$targetDb, GI_DECIMALS);
    $closeness = gi_closeness_key($difference);
    $direction = $difference >= 0 ? "more" : "less";

    $labels = pp_band_labels();
    $ranges = pp_band_ranges();
    $phrases = gi_phrases();

    return [
        "band" => $band,
        "label" => $labels[$band],
        "range" => $ranges[$band],
        "target_db" => py_round((float)$targetDb, GI_DECIMALS),
        "iem_db" => py_round((float)$iemDb, GI_DECIMALS),
        "difference_db" => $difference,
        "closeness" => $closeness,
        // Neither more nor less in any way worth saying, so the direction
        // is suppressed rather than reported as a coin toss. At 0.2 dB out,
        // calling it "more" would be technically true and actively
        // misleading.
        "direction" => $closeness === "on_target" ? "match" : $direction,
        "sentence" => gi_band_sentence(
            $labels[$band],
            $ranges[$band],
            $difference,
            $phrases[$closeness][$direction]
        ),
    ];
}


function gi_band_sentence($label, $range, $difference, $phrase) {
    if (abs($difference) < GI_ON_TARGET_DB) {
        return "{$label} ({$range}) is {$phrase}.";
    }

    // The signed figure is kept in the sentence on purpose. The phrase says
    // how much it matters; the number lets somebody who knows what a
    // decibel is check the phrase.
    $signed = ($difference > 0 ? "+" : "") . number_format($difference, GI_DECIMALS);
    return "{$label} ({$range}) is {$phrase} ({$signed} dB).";
}


/**
 * The band furthest from the target, or null if they are all close.
 *
 * Returns the single worst rather than a list. A summary that names all
 * three deviations is a table, and the table is already on the page.
 */
function gi_largest_deviation($bands) {
    $worst = null;
    foreach ($bands as $band) {
        if ($band["closeness"] === "on_target") {
            continue;
        }
        if ($worst === null
            || abs($band["difference_db"]) > abs($worst["difference_db"])) {
            $worst = $band;
        }
    }
    return $worst;
}


/**
 * One sentence for the top of the card.
 *
 * Says the thing a listener actually wants to know -- is this close, and
 * if not, where does it differ -- before any of the per-band detail.
 */
function gi_summary($bands) {
    $onTarget = 0;
    foreach ($bands as $band) {
        if ($band["closeness"] === "on_target") {
            $onTarget++;
        }
    }

    if ($onTarget === GI_ALL_BANDS) {
        return "This one tracks your target closely across all three bands.";
    }

    $worst = gi_largest_deviation($bands);
    $label = strtolower($worst["label"]);

    // "Apart from the upper midrange" over a 0.9 dB difference reads as a
    // warning, and the line beneath it calls the same figure "a little
    // more". A deviation only earns a caveat in the summary once it is big
    // enough to change the earphone's character -- which is what the step
    // from "close" to "off" means.
    if ($worst["closeness"] === "close") {
        return "Close to your target, with only small differences.";
    }

    if ($onTarget === GI_ALL_BANDS - 1) {
        return "Close to your target, apart from the {$label}.";
    }

    return "Its {$label} is the furthest from your target; "
        . "the other bands are nearer.";
}


/**
 * The full interpretation for one recommendation.
 *
 * Takes the recommendation as rec_score_iem() built it -- its `gains` are
 * already centred on the catalogue median, which is the only form in which
 * comparing them to a target means anything.
 */
function gi_interpret($recommendation, $target) {
    $gains = $recommendation["gains"] ?? [];

    $bands = [];
    foreach (GI_BANDS as $band) {
        // A band the catalogue never measured is skipped rather than
        // treated as zero. rec_has_complete_measurement() should have
        // dropped such an earphone long before this, so reaching here means
        // something upstream changed -- and silently describing an unknown
        // measurement as "exactly on target" would hide it.
        if (!array_key_exists($band, $gains) || !array_key_exists($band, $target)) {
            continue;
        }
        $bands[] = gi_compare_band($band, $target[$band], $gains[$band]);
    }

    if (!$bands) {
        return ["summary" => "", "bands" => []];
    }

    return [
        "summary" => gi_summary($bands),
        "bands" => $bands,
    ];
}


/**
 * Annotate a whole list of recommendations in place.
 *
 * Added as a key on each recommendation rather than returned separately so
 * that the card rendering cannot pair the wrong explanation with the wrong
 * earphone -- there is no index to get out of step.
 */
function gi_annotate($recommendations, $target) {
    $out = [];
    foreach ($recommendations as $recommendation) {
        $recommendation["interpretation"] = gi_interpret($recommendation, $target);
        $out[] = $recommendation;
    }
    return $out;
}
