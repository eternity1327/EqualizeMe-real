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

# The short label shown on the card, above the sentence describe_curve()
# produces. It is derived from the SAME cutoffs, so the two cannot contradict
# each other -- a card cannot say "V-Shaped" over a sentence that reads
# "bass-light". Only the top and bottom tiers of each band count as "boosted"
# or "reduced"; the middle tiers are treated as neither.

_BASS_BOOSTED = 5.57      # "warm, full bass" and above
_BASS_REDUCED = 3.3       # below this is "bass-light"
_PRESENCE_FORWARD = 5.22  # "forward, present vocals/mids"
_PRESENCE_RECESSED = 3.5  # below this is "recessed, distant vocals/mids"
_TREBLE_BRIGHT = 0.68     # "bright, energetic treble"
_TREBLE_SMOOTH = -1.5     # below this is "smooth, rolled-off treble"


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


def signature_label(bass_gain, presence_gain, treble_gain):
    """The short signature name for the card, or None without measurements.

    These are the terms the audio community actually uses, so a reader who
    knows the hobby recognises them and a reader who does not still has the
    full sentence underneath. The order of the checks matters: "V-Shaped"
    means both ends lifted, so it has to be tested before either end alone.
    """
    if bass_gain is None or presence_gain is None or treble_gain is None:
        return None

    bass_up = bass_gain >= _BASS_BOOSTED
    bass_down = bass_gain < _BASS_REDUCED
    treble_up = treble_gain >= _TREBLE_BRIGHT
    treble_down = treble_gain < _TREBLE_SMOOTH
    mids_up = presence_gain >= _PRESENCE_FORWARD
    mids_down = presence_gain < _PRESENCE_RECESSED

    if bass_up and treble_up:
        return "V-Shaped"
    if bass_up and treble_down:
        return "Dark"
    if bass_up:
        return "Warm"
    if bass_down and treble_up:
        return "Bright"
    if treble_up:
        return "Bright"
    if treble_down:
        return "Smooth"
    if mids_up:
        return "Mid-Forward"
    if bass_down and mids_down:
        return "Neutral"
    return "Balanced"


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
