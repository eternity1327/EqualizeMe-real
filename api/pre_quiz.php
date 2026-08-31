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
                ["value" => "balanced", "label" => "Balanced and natural",
                 "impact" => []],
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
                ["value" => "natural", "label" => "Natural — part of the mix",
                 "impact" => []],
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
                ["value" => "never", "label" => "Not really",
                 "impact" => []],
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
                ["value" => "never", "label" => "No, there's plenty",
                 "impact" => []],
            ],
        ],
        [
            "id" => "environment",
            "question" => "Where do you usually listen?",
            "options" => [
                ["value" => "noisy", "label" => "Commuting or somewhere noisy",
                 "impact" => ["bassGain" => 1, "presenceGain" => 1]],
                ["value" => "quiet", "label" => "A quiet room",
                 "impact" => []],
                ["value" => "mixed", "label" => "A bit of both",
                 "impact" => []],
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
function quiz_score_answers($answers) {
    $answers = is_array($answers) ? $answers : [];

    $seed = [];
    foreach (QUIZ_BANDS as $band) {
        $seed[$band] = 0;
    }

    foreach (quiz_questions() as $question) {
        $submitted = $answers[$question["id"]] ?? null;
        if (!is_string($submitted)) {
            continue;
        }

        foreach ($question["options"] as $option) {
            if ($option["value"] !== $submitted) {
                continue;
            }
            foreach (($option["impact"] ?? []) as $band => $delta) {
                if (array_key_exists($band, $seed)) {
                    $seed[$band] += $delta;
                }
            }
            break;
        }
    }

    foreach ($seed as $band => $value) {
        $seed[$band] = quiz_clamp($value);
    }

    return $seed;
}
