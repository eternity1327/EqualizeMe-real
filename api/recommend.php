<?php

/**
 * Matching earphones to a preference target.
 *
 * Ported from the scoring half of backend/ai_service.py. Constants,
 * rounding and sort order are all carried across unchanged — this is the
 * function that decides what somebody is told to buy, so "close enough"
 * is not a standard it can be held to.
 *
 * The pipeline:
 *
 *   1. drop anything not fully measured
 *   2. find the catalogue median for each band
 *   3. re-express every earphone relative to that median
 *   4. score each one by distance from the target
 *   5. keep the closest fifteen, re-sort by price, show five
 */

require_once __DIR__ . "/numeric.php";

const REC_BANDS = ["bass_gain", "treble_gain", "presence_gain"];

// Each dB of error costs five points of match. Twenty dB out scores zero.
const REC_SCORE_SCALE = 5;

const REC_MEASUREMENT_DECIMALS = 2;

const REC_CANDIDATE_POOL_SIZE = 15;
const REC_RESULTS_SHOWN = 5;


/**
 * The middle value, not the mean.
 *
 * One strangely measured earphone drags an average around; it barely moves
 * a median. Since the whole point of the baseline is "what is typical",
 * robustness against outliers is the requirement.
 */
function rec_median($values) {
    if (!$values) {
        return 0.0;
    }
    $ordered = $values;
    sort($ordered, SORT_NUMERIC);
    $count = count($ordered);
    $mid = intdiv($count, 2);

    if ($count % 2) {
        return $ordered[$mid];
    }
    return ($ordered[$mid - 1] + $ordered[$mid]) / 2;
}


function rec_catalog_baseline($iems, $bands = REC_BANDS) {
    $baseline = [];
    foreach ($bands as $band) {
        $values = [];
        foreach ($iems as $iem) {
            $values[] = (float)$iem[$band];
        }
        $baseline[$band] = rec_median($values);
    }
    return $baseline;
}


/**
 * An earphone missing any band is dropped rather than scored on what it
 * has. Otherwise a partial measurement could win by having less to be
 * wrong about.
 */
function rec_has_complete_measurement($iem) {
    foreach (REC_BANDS as $band) {
        if (($iem[$band] ?? null) === null) {
            return false;
        }
    }
    return true;
}


/**
 * Re-express an earphone relative to the catalogue.
 *
 * Measurements are taken at different volumes, so raw numbers are not
 * comparable between earphones. Subtracting the median turns "this much
 * bass" into "this much more bass than typical", which is the only form in
 * which comparing them means anything.
 */
function rec_centre_on_catalogue($iem, $baseline) {
    $centred = [];
    foreach (REC_BANDS as $band) {
        $centred[$band] = py_round(
            (float)$iem[$band] - $baseline[$band],
            REC_MEASUREMENT_DECIMALS
        );
    }
    return $centred;
}


function rec_agreement_score($preferenceDb, $iemDb) {
    return max(0, py_round(100 - abs($preferenceDb - $iemDb) * REC_SCORE_SCALE, 1));
}


function rec_score_iem($iem, $profile, $baseline) {
    $centred = rec_centre_on_catalogue($iem, $baseline);

    // Manhattan distance: the errors add up rather than being averaged, so
    // being badly wrong in one band is not forgiven by being right in the
    // other two.
    $distance = 0.0;
    $bandMatch = [];
    foreach (REC_BANDS as $band) {
        $preference = (float)$profile[$band];
        $distance += abs($preference - $centred[$band]);
        $bandMatch[$band] = rec_agreement_score($preference, $centred[$band]);
    }

    return [
        "iem_id" => $iem["id"],
        "name" => $iem["name"],
        "brand" => $iem["brand"],
        "sound_signature" => $iem["sound_signature"],
        "price" => $iem["price"] !== null ? (float)$iem["price"] : null,
        "image_url" => $iem["image_url"] ?? null,
        "retailer_name" => $iem["retailer_name"],
        "product_url" => $iem["product_url"] ?: ($iem["shop_link"] ?? null),
        "gains" => $centred,
        "band_match" => $bandMatch,
        "match_score" => max(0, py_round(100 - $distance * REC_SCORE_SCALE, 1)),
    ];
}


/**
 * Cheapest first, with unpriced entries last.
 *
 * Mirrors Python's (price is None, price or 0) tuple: the boolean sorts
 * before the number, so everything with a price comes first.
 */
function rec_price_sort_key($recommendation) {
    $price = $recommendation["price"];
    return [$price === null ? 1 : 0, $price === null ? 0 : $price];
}


/**
 * Two stages, and the second is the point.
 *
 * Ranking on match alone would happily put the most expensive model in the
 * catalogue at the top for scoring two percent better than something a
 * fraction of the price. Narrowing to the closest fifteen first and then
 * sorting those by price gives something that suits the listener AND that
 * they might actually buy.
 */
function rec_rank_recommendations($scored) {
    // usort has been stable since PHP 8.0, which matters: Python's sorted()
    // is stable too, so earphones with identical scores keep their original
    // relative order in both. Without that guarantee the two versions could
    // return different sets from the same data.
    $byMatch = $scored;
    usort($byMatch, function ($a, $b) {
        return $b["match_score"] <=> $a["match_score"];
    });

    $candidates = array_slice($byMatch, 0, REC_CANDIDATE_POOL_SIZE);

    usort($candidates, function ($a, $b) {
        return rec_price_sort_key($a) <=> rec_price_sort_key($b);
    });

    return array_slice($candidates, 0, REC_RESULTS_SHOWN);
}


/**
 * The whole pipeline, from a raw catalogue and a target.
 */
function rec_recommend($catalogue, $profile) {
    $scorable = [];
    foreach ($catalogue as $iem) {
        if (rec_has_complete_measurement($iem)) {
            $scorable[] = $iem;
        }
    }

    $baseline = rec_catalog_baseline($scorable);

    $scored = [];
    foreach ($scorable as $iem) {
        $scored[] = rec_score_iem($iem, $profile, $baseline);
    }

    return [
        "baseline" => $baseline,
        "scored" => $scored,
        "recommendations" => rec_rank_recommendations($scored),
    ];
}
