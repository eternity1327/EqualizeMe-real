"""
pre_quiz.py
The written "pre-audiophile" quiz that runs BEFORE the listening test.

Purpose
-------
The A/B listening test in adaptive_test.py starts each EQ band with no
information at all, so its first couple of rounds are spent discovering
roughly where the listener sits in a -6..+6 dB range. This quiz asks a
handful of plain-language preference questions first and turns the
answers into a starting estimate for each band, so the listening test
can begin already narrowed around that estimate instead of the full
range. Same number of questions, tighter final result.

No audio is involved here — these are text questions only. (The older
audio-based branching quiz lives in ai_service.py's /start-assessment
and /next-question routes and is a separate, unlinked flow.)

How scoring works
-----------------
Every answer option carries an "impact": how many dB it nudges each
band. Impacts are summed across all answered questions, then clamped to
the usable range. Unanswered questions simply contribute nothing, so a
partially-filled quiz still produces a usable (if vaguer) seed.
"""

RANGE_LOW = -6
RANGE_HIGH = 6

BANDS = ("bassGain", "trebleGain", "presenceGain")

# Each question: an id, the prompt, and options carrying dB impacts.
# Impacts are deliberately modest — this is a starting hint for the
# listening test, not a replacement for it.
QUESTIONS = [
    {
        "id": "genre",
        "question": "What do you listen to most?",
        "options": [
            {"value": "hiphop", "label": "Hip-hop, R&B, or EDM",
             "impact": {"bassGain": 2}},
            {"value": "rock", "label": "Rock or metal",
             "impact": {"presenceGain": 1, "trebleGain": 1}},
            {"value": "classical", "label": "Classical, jazz, or acoustic",
             "impact": {"trebleGain": 1, "bassGain": -1}},
            {"value": "pop", "label": "Pop or a bit of everything",
             "impact": {"bassGain": 1}},
        ],
    },
    {
        "id": "signature",
        "question": "Which describes your ideal sound?",
        "options": [
            {"value": "warm", "label": "Warm and full — bass you can feel",
             "impact": {"bassGain": 3, "trebleGain": -1}},
            {"value": "balanced", "label": "Balanced and natural",
             "impact": {}},
            {"value": "bright", "label": "Bright and detailed — crisp highs",
             "impact": {"trebleGain": 3}},
            {"value": "vshape", "label": "Punchy bass AND sparkly highs",
             "impact": {"bassGain": 2, "trebleGain": 2, "presenceGain": -1}},
        ],
    },
    {
        "id": "vocals",
        "question": "How do you like vocals to sit in a mix?",
        "options": [
            {"value": "forward", "label": "Up front and clear",
             "impact": {"presenceGain": 2}},
            {"value": "natural", "label": "Natural — part of the mix",
             "impact": {}},
            {"value": "laidback", "label": "Laid back, behind the instruments",
             "impact": {"presenceGain": -2}},
        ],
    },
    {
        "id": "harshness",
        "question": "Do cymbals or 's' sounds ever feel sharp or painful?",
        "options": [
            {"value": "often", "label": "Yes, often — it makes me lower the volume",
             "impact": {"trebleGain": -2, "presenceGain": -1}},
            {"value": "sometimes", "label": "Occasionally, on some tracks",
             "impact": {"trebleGain": -1}},
            {"value": "never", "label": "Not really",
             "impact": {}},
        ],
    },
    {
        "id": "punch",
        "question": "Does your music ever feel thin or lacking punch?",
        "options": [
            {"value": "often", "label": "Yes — I want more weight behind it",
             "impact": {"bassGain": 2}},
            {"value": "sometimes", "label": "Sometimes",
             "impact": {"bassGain": 1}},
            {"value": "never", "label": "No, there's plenty",
             "impact": {}},
        ],
    },
    {
        "id": "environment",
        "question": "Where do you usually listen?",
        "options": [
            {"value": "noisy", "label": "Commuting or somewhere noisy",
             "impact": {"bassGain": 1, "presenceGain": 1}},
            {"value": "quiet", "label": "A quiet room",
             "impact": {}},
            {"value": "mixed", "label": "A bit of both",
             "impact": {}},
        ],
    },
]


def list_questions():
    """The quiz as the frontend needs it — impacts stripped out.

    Impacts are deliberately withheld so the UI can't hint at which
    answer 'adds bass', which would nudge people toward answering
    strategically instead of honestly.
    """
    return [
        {
            "id": q["id"],
            "question": q["question"],
            "options": [{"value": o["value"], "label": o["label"]} for o in q["options"]],
        }
        for q in QUESTIONS
    ]


def _clamp(value):
    return max(RANGE_LOW, min(RANGE_HIGH, value))


def score_answers(answers):
    """Turns {question_id: option_value} into a starting dB estimate per band.

    Unknown question ids and unknown option values are ignored rather
    than raising, so a stale or partly-filled submission still scores.
    """
    answers = answers or {}
    seed = {band: 0 for band in BANDS}

    for question in QUESTIONS:
        chosen_value = answers.get(question["id"])
        if chosen_value is None:
            continue

        option = next((o for o in question["options"] if o["value"] == chosen_value), None)
        if option is None:
            continue

        for band, delta in option.get("impact", {}).items():
            if band in seed:
                seed[band] += delta

    return {band: _clamp(value) for band, value in seed.items()}
