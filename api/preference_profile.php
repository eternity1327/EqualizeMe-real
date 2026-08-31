<?php

/**
 * Aggregation and analysis for the longitudinal preference profile.
 *
 * Ported from backend/preference_profile.py, constant for constant.
 *
 * Individual assessments are evidence, not answers. This folds the whole
 * history into one current Preference Target, then reads that target back
 * out in words.
 *
 *     assessments -> pp_aggregate() -> the target
 *                                        |
 *                                        +-> pp_analyse_regions()  verdicts
 *                                        +-> pp_describe()         sentences
 *
 * Two rules the rest of the system depends on:
 *
 *   There is exactly one target per user. A new assessment refines it and
 *   never creates a second. The rows in auditory_profiles stay as history
 *   and are never rewritten.
 *
 *   The target is authoritative. The verdicts are an interpretation of it
 *   and the recommendations an application of it. Nothing downstream edits
 *   it.
 *
 * Only the three bands the listening test measures are reported. Midrange
 * is the reference the others are expressed against, and the 8 kHz shelf
 * covers the whole top end, so treble and air are not separable from this
 * data. Reporting them as two findings would invent a distinction the
 * measurement cannot make.
 */

require_once __DIR__ . "/numeric.php";

const PP_BANDS = ["bass_gain", "presence_gain", "treble_gain"];

function pp_band_labels() {
    return [
        "bass_gain" => "Bass",
        "presence_gain" => "Upper midrange",
        "treble_gain" => "Treble",
    ];
}

// What each band covers, following from the filters the test uses: a
// 100 Hz low shelf, a 3 kHz peak at Q 1.4, an 8 kHz high shelf.
function pp_band_ranges() {
    return [
        "bass_gain" => "below ~250 Hz",
        "presence_gain" => "~2-5 kHz",
        "treble_gain" => "above ~6 kHz",
    ];
}

/* ───────────────────────────────── weighting ──────────────────────────── */

// weight = confidence x recency
//
// Confidence comes from the test itself, so a run that ended up vague has
// less say than a decisive one. Recency is a half-life rather than a
// cutoff: ears, gear and taste drift, and a test from a year ago on laptop
// speakers should not carry the authority of one taken yesterday on the
// headphones being matched. Fading is gentler than discarding — an old
// assessment keeps contributing, just less.
const PP_RECENCY_HALF_LIFE_DAYS = 180.0;

// Floor on the confidence part, so one poor run cannot round to zero
// influence and vanish from an otherwise short history.
const PP_MIN_CONFIDENCE_WEIGHT = 0.1;

const PP_GAIN_DECIMALS = 2;


function pp_confidence_weight($confidence) {
    if ($confidence === null) {
        return 1.0;
    }
    if (!is_numeric($confidence)) {
        return 1.0;
    }
    $value = (float)$confidence / 100.0;
    return max(PP_MIN_CONFIDENCE_WEIGHT, min(1.0, $value));
}


function pp_recency_weight($ageDays) {
    // MySQL hands this back as a string-formatted decimal. Coerce rather
    // than assume the caller normalised it.
    if ($ageDays === null || !is_numeric($ageDays)) {
        return 1.0;
    }
    $days = (float)$ageDays;
    if ($days <= 0) {
        return 1.0;
    }
    return pow(0.5, $days / PP_RECENCY_HALF_LIFE_DAYS);
}


function pp_assessment_weight($confidence, $ageDays) {
    return pp_confidence_weight($confidence) * pp_recency_weight($ageDays);
}


function pp_weighted_mean($pairs) {
    $totalWeight = 0.0;
    foreach ($pairs as $pair) {
        $totalWeight += $pair[1];
    }
    if ($totalWeight <= 0) {
        return 0.0;
    }
    $sum = 0.0;
    foreach ($pairs as $pair) {
        $sum += $pair[0] * $pair[1];
    }
    return $sum / $totalWeight;
}


/**
 * Spread of the observations around the target, same weights.
 *
 * This is what separates "you consistently want more bass" from "your bass
 * answers have been all over the place" — two histories that can average
 * to exactly the same number.
 */
function pp_weighted_std($pairs, $mean) {
    $totalWeight = 0.0;
    foreach ($pairs as $pair) {
        $totalWeight += $pair[1];
    }
    if ($totalWeight <= 0 || count($pairs) < 2) {
        return 0.0;
    }
    $variance = 0.0;
    foreach ($pairs as $pair) {
        $variance += $pair[1] * pow($pair[0] - $mean, 2);
    }
    $variance /= $totalWeight;
    return sqrt(max(0.0, $variance));
}


/* ────────────────────────────── aggregation ───────────────────────────── */

/**
 * Fold every assessment into one target.
 *
 * Each row needs the band keys, and optionally confidence_score and
 * age_days. Order does not matter.
 */
function pp_aggregate($assessments) {
    $usable = [];
    foreach ($assessments as $row) {
        if ($row !== null) {
            $usable[] = $row;
        }
    }
    if (!$usable) {
        return null;
    }

    $weights = [];
    foreach ($usable as $row) {
        $weights[] = pp_assessment_weight(
            $row["confidence_score"] ?? null,
            $row["age_days"] ?? null
        );
    }

    $target = [];
    $spread = [];
    foreach (PP_BANDS as $band) {
        $pairs = [];
        foreach ($usable as $i => $row) {
            if (($row[$band] ?? null) === null) {
                continue;
            }
            $pairs[] = [(float)$row[$band], $weights[$i]];
        }
        $mean = pp_weighted_mean($pairs);
        $target[$band] = py_round($mean, PP_GAIN_DECIMALS);
        $spread[$band] = py_round(pp_weighted_std($pairs, $mean), PP_GAIN_DECIMALS);
    }

    $totalWeight = array_sum($weights);

    return [
        "target" => $target,
        "spread" => $spread,
        "assessment_count" => count($usable),
        "total_weight" => py_round($totalWeight, 3),
        // How much of the target comes from the single heaviest
        // assessment. 1.0 means one sitting is doing all the work, which
        // the interface uses to avoid claiming consistency from one point.
        "dominant_share" => $totalWeight > 0
            ? py_round(max($weights) / $totalWeight, 3)
            : 1.0,
    ];
}


/* ─────────────────────────────── analysis ─────────────────────────────── */

// Below this, a deviation is not distinguishable from the test's own
// precision and is reported as sitting at the reference. Not an arbitrary
// round number: a full run locates each band to roughly +/-0.4 to +/-0.8 dB,
// so anything under about three quarters of a decibel is noise wearing the
// costume of a preference.
const PP_NEUTRAL_DB = 0.75;
const PP_SLIGHT_DB = 2.0;
const PP_STRONG_DB = 4.0;

// Spread across a history, in dB, below which the answers count as
// agreeing rather than merely averaging to something.
const PP_CONSISTENT_DB = 0.75;
const PP_FAIRLY_CONSISTENT_DB = 1.5;

// Above this share, one assessment is effectively the whole profile and no
// claim about consistency over time can honestly be made.
const PP_SINGLE_SOURCE_SHARE = 0.8;


function pp_levels() {
    return [
        "strong_up" => "Strongly elevated",
        "up" => "Elevated",
        "slight_up" => "Slightly elevated",
        "neutral" => "Near reference",
        "slight_down" => "Slightly relaxed",
        "down" => "Reduced",
        "strong_down" => "Strongly reduced",
    ];
}


function pp_level_key($value) {
    $magnitude = abs($value);
    if ($magnitude < PP_NEUTRAL_DB) {
        return "neutral";
    }
    if ($magnitude < PP_SLIGHT_DB) {
        $tier = "slight_";
    } elseif ($magnitude < PP_STRONG_DB) {
        $tier = "";
    } else {
        $tier = "strong_";
    }
    return $tier . ($value > 0 ? "up" : "down");
}


function pp_consistency($spread, $assessmentCount, $dominantShare) {
    if ($assessmentCount < 2) {
        return "single";
    }
    // More than one sitting on record, but the weighting has landed almost
    // entirely on one — usually an old test that has faded next to a fresh
    // one. Plural wording, but no claim of consistency over time.
    if ($dominantShare > PP_SINGLE_SOURCE_SHARE) {
        return "dominated";
    }
    if ($spread <= PP_CONSISTENT_DB) {
        return "consistent";
    }
    if ($spread <= PP_FAIRLY_CONSISTENT_DB) {
        return "fairly_consistent";
    }
    return "varied";
}


/**
 * Read the target back out as one verdict per measured band. Everything is
 * derived from the numbers — no band has a pre-written conclusion.
 */
function pp_analyse_regions($profile) {
    if (!$profile) {
        return [];
    }

    $labels = pp_band_labels();
    $ranges = pp_band_ranges();
    $levels = pp_levels();

    $regions = [];
    foreach (PP_BANDS as $band) {
        $value = $profile["target"][$band];
        $level = pp_level_key($value);

        $regions[] = [
            "band" => $band,
            "label" => $labels[$band],
            "range" => $ranges[$band],
            "value" => $value,
            "spread" => $profile["spread"][$band],
            "level" => $level,
            "level_label" => $levels[$level],
            "direction" => $level === "neutral" ? "neutral" : ($value > 0 ? "up" : "down"),
            "meaningful" => $level !== "neutral",
            "consistency" => pp_consistency(
                $profile["spread"][$band],
                $profile["assessment_count"],
                $profile["dominant_share"]
            ),
        ];
    }
    return $regions;
}


/* ───────────────────────── wording, from the data ─────────────────────── */

// Only ever describes preference. Never health, never ability, never a
// suggestion that any of these answers is the wrong one to have given.
function pp_openers() {
    return [
        "consistent" => "Your assessments consistently",
        "fairly_consistent" => "Your assessments generally",
        "varied" => "Averaged across your assessments, your answers",
        "dominated" => "Weighted towards your most recent test, your answers",
        "single" => "Your assessment so far",
    ];
}

function pp_verbs() {
    return [
        "up" => [
            "strong_up" => "favour clearly more energy",
            "up" => "favour more energy",
            "slight_up" => "lean towards a little more energy",
        ],
        "down" => [
            "strong_down" => "favour clearly less energy",
            "down" => "favour less energy",
            "slight_down" => "lean towards a little less energy",
        ],
    ];
}


/**
 * "favour more energy" -> "favours more energy". Needed only for the
 * one-assessment opener, which is grammatically singular.
 */
function pp_singular($verb) {
    $parts = explode(" ", $verb, 2);
    $head = $parts[0];
    $rest = $parts[1] ?? "";
    return rtrim("{$head}s {$rest}");
}


function pp_sentence($region) {
    $single = $region["consistency"] === "single";
    $openers = pp_openers();
    $opener = $openers[$region["consistency"]];

    if (!$region["meaningful"]) {
        $verb = $single ? "sits" : "sit";
        return "{$opener} {$verb} close to the reference {$region['range']}, "
            . "with no clear lean either way.";
    }

    $verbs = pp_verbs();
    $verb = $verbs[$region["direction"]][$region["level"]];

    if ($single) {
        return "{$opener} " . pp_singular($verb) . " {$region['range']}.";
    }

    $tail = "";
    if ($region["consistency"] === "varied") {
        $tail = " Your answers here have moved around between sittings, "
            . "so this one is worth re-checking.";
    }

    return "{$opener} {$verb} {$region['range']}.{$tail}";
}


function pp_join($items) {
    if (count($items) === 1) {
        return $items[0];
    }
    if (count($items) === 2) {
        return "{$items[0]} and {$items[1]}";
    }
    $last = array_pop($items);
    return implode(", ", $items) . " and {$last}";
}


function pp_describe($profile, $regions = null) {
    if (!$profile) {
        return ["summary" => "", "regions" => []];
    }

    $regions = $regions ?? pp_analyse_regions($profile);
    $count = $profile["assessment_count"];

    if ($count === 1) {
        $summary = "This profile is based on one listening test. It will get "
            . "sharper each time you take another.";
    } else {
        $moving = [];
        foreach ($regions as $region) {
            if ($region["consistency"] === "varied") {
                $moving[] = strtolower($region["label"]);
            }
        }
        $summary = "This profile combines {$count} listening tests, "
            . "weighted towards your more recent and more decisive ones.";
        if ($moving) {
            $summary .= " Your answers for " . pp_join($moving)
                . " have varied between sittings.";
        }
    }

    $described = [];
    foreach ($regions as $region) {
        $region["sentence"] = pp_sentence($region);
        $described[] = $region;
    }

    return ["summary" => $summary, "regions" => $described];
}


/**
 * Everything the results page needs, from raw assessment rows.
 */
function pp_build($assessments) {
    $profile = pp_aggregate($assessments);
    if (!$profile) {
        return null;
    }
    $regions = pp_analyse_regions($profile);
    $profile["analysis"] = pp_describe($profile, $regions);
    $profile["regions"] = $regions;
    return $profile;
}
