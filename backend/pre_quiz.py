RANGE_LOW = -6
RANGE_HIGH = 6

BANDS = ("bassGain", "trebleGain", "presenceGain")

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
    return [
        {
            "id": question["id"],
            "question": question["question"],
            "options": [
                {"value": option["value"], "label": option["label"]}
                for option in question["options"]
            ],
        }
        for question in QUESTIONS
    ]


def _clamp(value):
    return max(RANGE_LOW, min(RANGE_HIGH, value))


def _find_option(question, value):
    return next(
        (option for option in question["options"] if option["value"] == value),
        None,
    )


def score_answers(answers):
    answers = answers or {}
    seed = {band: 0 for band in BANDS}

    for question in QUESTIONS:
        option = _find_option(question, answers.get(question["id"]))
        if option is None:
            continue
        for band, delta in option.get("impact", {}).items():
            if band in seed:
                seed[band] += delta

    return {band: _clamp(value) for band, value in seed.items()}
