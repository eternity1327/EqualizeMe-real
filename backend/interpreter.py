"""
interpreter.py
---------------
Turns the 3 gain numbers (already computed per IEM) into a plain-
language description -- the "interpreter module" piece from your
Chapter I brief. Deliberately rule-based (thresholds on numbers you
already have), not an LLM call: deterministic, free, and easy to
defend/tune for a thesis panel. Swap in an LLM call later in
describe_curve() if the group decides that's what "AI-assisted"
should mean -- the interface (gains in, sentence out) stays the same
either way.

Thresholds are a starting point, not gospel -- tune BASS_THRESHOLDS /
PRESENCE_THRESHOLDS / TREBLE_THRESHOLDS below once you've looked at
the actual gain spread across your imported catalog.
"""

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


def _classify(gain_db, thresholds):
    if gain_db is None:
        return None
    for cutoff, label in thresholds:
        if gain_db >= cutoff:
            return label
    return thresholds[-1][1]


def describe_curve(bass_gain, presence_gain, treble_gain):
    """
    Returns a plain-language sentence describing an IEM's tonal
    balance from its 3 gain values. Any missing value is skipped
    rather than guessed at.
    """
    parts = []
    bass_label = _classify(bass_gain, BASS_THRESHOLDS)
    presence_label = _classify(presence_gain, PRESENCE_THRESHOLDS)
    treble_label = _classify(treble_gain, TREBLE_THRESHOLDS)

    if bass_label:
        parts.append(bass_label)
    if presence_label:
        parts.append(presence_label)
    if treble_label:
        parts.append(treble_label)

    if not parts:
        return "No measurement data available for this IEM."

    if len(parts) == 1:
        joined = parts[0]
    elif len(parts) == 2:
        joined = f"{parts[0]} and {parts[1]}"
    else:
        joined = f"{parts[0]}, {parts[1]}, and {parts[2]}"

    return f"This IEM has {joined}."


if __name__ == "__main__":
    # Sanity check against gains already computed earlier in this project
    examples = [
        ("Hifiman Bolt (sample_measurement.txt)", 6.46, 1.00, -1.45),
        ("Unlabeled measurement2.txt", 2.42, 4.69, -1.50),
        ("Hypothetical neutral IEM", 0.2, -0.5, 0.8),
        ("Missing treble data", 5.0, 2.0, None),
    ]
    for label, b, p, t in examples:
        print(f"{label}:")
        print(f"  {describe_curve(b, p, t)}\n")
