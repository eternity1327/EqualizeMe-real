BASS_THRESHOLDS = [
    (6.88, "bass-boosted"),
    (5.57, "warm, full bass"),
    (4.65, "balanced bass"),
    (3.3, "light bass"),
    (float("-inf"), "bass-light"),
]

PRESENCE_THRESHOLDS = [
    (5.22, "forward, present vocals/mids"),
    (3.5, "balanced presence"),
    (float("-inf"), "recessed, distant vocals/mids"),
]

TREBLE_THRESHOLDS = [
    (0.68, "bright, energetic treble"),
    (-1.5, "balanced treble"),
    (float("-inf"), "smooth, rolled-off treble"),
]


NO_DATA_MESSAGE = "No measurement data available for this IEM."


def _classify(gain_db, thresholds):
    if gain_db is None:
        return None
    for cutoff, label in thresholds:
        if gain_db >= cutoff:
            return label
    return thresholds[-1][1]


def _join_phrases(phrases):
    if len(phrases) == 1:
        return phrases[0]
    if len(phrases) == 2:
        return f"{phrases[0]} and {phrases[1]}"
    return ", ".join(phrases[:-1]) + f", and {phrases[-1]}"


def describe_curve(bass_gain, presence_gain, treble_gain):
    labels = [
        _classify(bass_gain, BASS_THRESHOLDS),
        _classify(presence_gain, PRESENCE_THRESHOLDS),
        _classify(treble_gain, TREBLE_THRESHOLDS),
    ]
    described = [label for label in labels if label]

    if not described:
        return NO_DATA_MESSAGE

    return f"This IEM has {_join_phrases(described)}."


if __name__ == "__main__":
    examples = [
        ("Hifiman Bolt", 6.46, 1.00, -1.45),
        ("Unlabeled measurement2.txt", 2.42, 4.69, -1.50),
        ("Hypothetical neutral IEM", 0.2, -0.5, 0.8),
        ("Missing treble data", 5.0, 2.0, None),
        ("No data at all", None, None, None),
    ]
    for label, bass, presence, treble in examples:
        print(f"{label}:")
        print(f"  {describe_curve(bass, presence, treble)}\n")
