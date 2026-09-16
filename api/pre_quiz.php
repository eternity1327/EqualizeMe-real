<?php

/**
 * The six written questions asked before any audio plays.
 *
 * Ported from backend/pre_quiz.py. The question text, the option values and
 * every impact weight are identical — a seed that came out differently
 * would move the listening test's starting range and change the profile,
 * so this file is a transcription rather than a rewrite.
 *
 * Answers are worth a rough guess, never a conclusion. Its only job is to
 * narrow where the adaptive test starts looking.
 */

const QUIZ_RANGE_LOW = -6;
const QUIZ_RANGE_HIGH = 6;

const QUIZ_BANDS = ["bassGain", "trebleGain", "presenceGain"];

function quiz_questions() {
    return [
        [
            "id" => "genre",
            "question" => "What do you listen to most?",
            "options" => [
                ["value" => "hiphop", "label" => "Hip-hop, R&B, or EDM",
                 "impact" => ["bassGain" => 2]],
                ["value" => "rock", "label" => "Rock or metal",
                 "impact" => ["presenceGain" => 1, "trebleGain" => 1]],
                ["value" => "classical", "label" => "Classical, jazz, or acoustic",
                 "impact" => ["trebleGain" => 1, "bassGain" => -1]],
                ["value" => "pop", "label" => "Pop or a bit of everything",
                 "impact" => ["bassGain" => 1]],
            ],
        ],
        [
            "id" => "signature",
            "question" => "Which describes your ideal sound?",
            "options" => [
                ["value" => "warm", "label" => "Warm and full — bass you can feel",
                 "impact" => ["bassGain" => 3, "trebleGain" => -1]],
                // "Natural" is not the same as "scooped". Someone asking for
                // a natural presentation wants the midrange present rather
                // than pushed back, which is what separates this answer from
                // the V-shape below.
                ["value" => "balanced", "label" => "Balanced and natural",
                 "impact" => ["presenceGain" => 1]],
                ["value" => "bright", "label" => "Bright and detailed — crisp highs",
                 "impact" => ["trebleGain" => 3]],
                ["value" => "vshape", "label" => "Punchy bass AND sparkly highs",
                 "impact" => ["bassGain" => 2, "trebleGain" => 2, "presenceGain" => -1]],
            ],
        ],
        [
            "id" => "vocals",
            "question" => "How do you like vocals to sit in a mix?",
            "options" => [
                ["value" => "forward", "label" => "Up front and clear",
                 "impact" => ["presenceGain" => 2]],
                // Deliberately zero, and written out rather than left empty.
                // This question is a symmetric scale -- forward is +2, laid
                // back is -2 -- so "natural" is its midpoint by definition.
                // Giving it a value would push every listener who chose the
                // middle option in a direction nothing in their answer
                // supports.
                ["value" => "natural", "label" => "Natural — part of the mix",
                 "impact" => ["presenceGain" => 0]],
                ["value" => "laidback", "label" => "Laid back, behind the instruments",
                 "impact" => ["presenceGain" => -2]],
            ],
        ],
        [
            "id" => "harshness",
            "question" => "Do cymbals or 's' sounds ever feel sharp or painful?",
            "options" => [
                ["value" => "often", "label" => "Yes, often — it makes me lower the volume",
                 "impact" => ["trebleGain" => -2, "presenceGain" => -1]],
                ["value" => "sometimes", "label" => "Occasionally, on some tracks",
                 "impact" => ["trebleGain" => -1]],
                // Completes the scale rather than sitting at its end. The
                // other two answers subtract treble; someone who never finds
                // cymbals painful tolerates more of it than average, so the
                // seed starts a little higher.
                ["value" => "never", "label" => "Not really",
                 "impact" => ["trebleGain" => 1]],
            ],
        ],
        [
            "id" => "punch",
            "question" => "Does your music ever feel thin or lacking punch?",
            "options" => [
                ["value" => "often", "label" => "Yes — I want more weight behind it",
                 "impact" => ["bassGain" => 2]],
                ["value" => "sometimes", "label" => "Sometimes",
                 "impact" => ["bassGain" => 1]],
                // "There's plenty already" is a statement about having
                // enough weight, so it belongs below the midpoint rather
                // than at it -- the other answers ask for more.
                ["value" => "never", "label" => "No, there's plenty",
                 "impact" => ["bassGain" => -1]],
            ],
        ],
        [
            "id" => "environment",
            "question" => "Where do you usually listen?",
            "options" => [
                ["value" => "noisy", "label" => "Commuting or somewhere noisy",
                 "impact" => ["bassGain" => 1, "presenceGain" => 1]],
                // The mirror of the noisy answer. Background noise masks low
                // frequencies first, which is why people turn bass up on a
                // commute; a quiet room removes that need, so the seed
                // starts slightly lower rather than merely not higher.
                ["value" => "quiet", "label" => "A quiet room",
                 "impact" => ["bassGain" => -1, "presenceGain" => -1]],

                // Zero on purpose, and written out rather than left empty.
                // This answer is literally "both of the above", and the two
                // above are +1 and -1 -- their midpoint is nought. Anything
                // else here would be invented.
                ["value" => "mixed", "label" => "A bit of both",
                 "impact" => ["bassGain" => 0, "presenceGain" => 0]],
            ],
        ],
    ];
}


/**
 * The questions as the browser sees them — without the impact weights.
 *
 * That omission is the point. If a user could see that "Warm and full" is
 * worth +3 bass, they could answer strategically rather than honestly, and
 * the seed would be describing the answers they thought would help rather
 * than what they actually like.
 */
function quiz_list_questions() {
    $out = [];
    foreach (quiz_questions() as $question) {
        $options = [];
        foreach ($question["options"] as $option) {
            $options[] = [
                "value" => $option["value"],
                "label" => $option["label"],
            ];
        }
        $out[] = [
            "id" => $question["id"],
            "question" => $question["question"],
            "options" => $options,
        ];
    }
    return $out;
}


function quiz_clamp($value) {
    return max(QUIZ_RANGE_LOW, min(QUIZ_RANGE_HIGH, $value));
}


/**
 * Turn a set of answers into a starting guess.
 *
 * Note the direction of the loop: over the known questions, never over the
 * submitted keys. Whatever the client sends is only ever used as a value to
 * look up, so an unrecognised question id or option value finds nothing and
 * is skipped. There is no path from submitted data into anything but a
 * string comparison.
 */
/**
 * The option this question defines for the submitted value, or null.
 *
 * The lookup direction is the security property, so it lives in its own
 * function where it cannot be lost in a later edit: the submitted string is
 * only ever compared against values this file declares. An unrecognised one
 * matches nothing and returns null.
 */
function quiz_find_option($question, $submitted) {
    foreach ($question["options"] as $option) {
        if ($option["value"] === $submitted) {
            return $option;
        }
    }
    return null;
}


/**
 * Add one option's effect to the running seed.
 *
 * Bands the seed does not already know about are ignored rather than added,
 * so a typo in a question definition cannot invent a fourth band downstream.
 */
function quiz_apply_impact(array $seed, $option) {
    foreach (($option["impact"] ?? []) as $band => $delta) {
        if (array_key_exists($band, $seed)) {
            $seed[$band] += $delta;
        }
    }
    return $seed;
}


function quiz_score_answers($answers) {
    $answers = is_array($answers) ? $answers : [];

    $seed = array_fill_keys(QUIZ_BANDS, 0);

    foreach (quiz_questions() as $question) {
        $submitted = $answers[$question["id"]] ?? null;
        if (!is_string($submitted)) {
            continue;
        }

        $option = quiz_find_option($question, $submitted);
        if ($option === null) {
            continue;
        }

        $seed = quiz_apply_impact($seed, $option);
    }

    return array_map("quiz_clamp", $seed);
}
