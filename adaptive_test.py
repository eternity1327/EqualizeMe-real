"""
adaptive_test.py
Python port of utils/adaptiveTest.js.

Binary-search "staircase" method: for each parameter (bassGain,
trebleGain, presenceGain) in turn, plays A at the low bound and B at
the high bound, narrows toward whichever side was preferred, over
STEP_ROUNDS rounds, then locks in the midpoint as that parameter's
final value before moving to the next parameter.

Sessions are keyed by user_id, so two different users' test progress
won't collide. NOTE: this does NOT make audio playback itself
multi-user — camilladsp.exe still only outputs to one machine's
speakers at a time, so only one person can actually be listening
to their test audio at once, even though their progress tracking
is now isolated.
"""

import random

STEP_ROUNDS = 4
PARAMS = ["bassGain", "trebleGain", "presenceGain"]
RANGE_LOW = -6
RANGE_HIGH = 6
NUM_SAMPLES = 10  # sample1.wav .. sample10.wav in data/audio/samples/

# Friendly display names for the 10 trimmed clips, in the order they were
# copied in from the source recordings — keeps sample1.wav from being a
# meaningless label in the UI.
SAMPLE_LABELS = {
    "sample1.wav": "Show — Chorus",
    "sample2.wav": "Show — Intro",
    "sample3.wav": "Everlasting Summer — Ref",
    "sample4.wav": "Everlasting Summer — Chorus",
    "sample5.wav": "Summertime Lime — Ambient",
    "sample6.wav": "Summertime Lime — Intro",
    "sample7.wav": "I Think They Call This Love — Chorus",
    "sample8.wav": "I Think They Call This Love — Bridge",
    "sample9.wav": "Original Me — Chorus",
    "sample10.wav": "Original Me — Solo",
}

_sessions = {}  # user_id -> session dict


def list_samples():
    """All selectable tracks, in order, for populating a picker UI."""
    return [
        {"file": f"sample{i}.wav", "label": SAMPLE_LABELS.get(f"sample{i}.wav", f"sample{i}.wav")}
        for i in range(1, NUM_SAMPLES + 1)
    ]


def start_session(user_id, sample=None):
    # Use the requested track if it's one of the real sample files;
    # otherwise fall back to a random pick (e.g. if the picker UI is
    # skipped, or an invalid value sneaks through).
    valid_samples = {f"sample{i}.wav" for i in range(1, NUM_SAMPLES + 1)}
    if sample not in valid_samples:
        sample = f"sample{random.randint(1, NUM_SAMPLES)}.wav"

    _sessions[user_id] = {
        "paramIndex": 0,
        "round": 0,
        "bounds": {"low": RANGE_LOW, "high": RANGE_HIGH},
        "finalized": {"bassGain": 0, "trebleGain": 0, "presenceGain": 0},
        "history": [],
        # Fixed for the whole session so A/B stays the same song for a fair
        # comparison within one person's test.
        "sample": sample,
    }
    return get_current_pair(user_id)


def _current_param(user_id):
    return PARAMS[_sessions[user_id]["paramIndex"]]


def get_current_pair(user_id):
    session = _sessions.get(user_id)
    if session is None:
        return None
    if session["paramIndex"] >= len(PARAMS):
        return None

    low = session["bounds"]["low"]
    high = session["bounds"]["high"]
    param = _current_param(user_id)

    a = dict(session["finalized"])
    a[param] = low
    b = dict(session["finalized"])
    b[param] = high

    return {
        "done": False,
        "param": param,
        "round": session["round"] + 1,
        "totalRoundsForParam": STEP_ROUNDS,
        "paramNumber": session["paramIndex"] + 1,
        "totalParams": len(PARAMS),
        "A": a,
        "B": b,
        "sample": session["sample"],
        "sampleLabel": SAMPLE_LABELS.get(session["sample"], session["sample"]),
    }


def record_answer(user_id, preferred):
    session = _sessions.get(user_id)
    if session is None:
        return {"error": "No active session. Call start_session first."}
    if preferred not in ("A", "B"):
        return {"error": 'preferred must be "A" or "B"'}
    if session["paramIndex"] >= len(PARAMS):
        return {"error": "Test already complete", "done": True, "profile": session["finalized"]}

    low = session["bounds"]["low"]
    high = session["bounds"]["high"]
    mid = (low + high) / 2
    param = _current_param(user_id)

    session["history"].append(
        {"param": param, "round": session["round"] + 1, "low": low, "high": high, "preferred": preferred}
    )

    if preferred == "A":
        session["bounds"]["high"] = mid
    else:
        session["bounds"]["low"] = mid

    session["round"] += 1

    if session["round"] >= STEP_ROUNDS:
        final_value = (session["bounds"]["low"] + session["bounds"]["high"]) / 2
        session["finalized"][param] = round(final_value, 1)
        session["paramIndex"] += 1
        session["round"] = 0
        session["bounds"] = {"low": RANGE_LOW, "high": RANGE_HIGH}

    if session["paramIndex"] >= len(PARAMS):
        return {"done": True, "profile": session["finalized"], "history": session["history"]}

    return {"done": False, "next": get_current_pair(user_id)}


def get_current_side_params(user_id, side):
    """Returns the A or B parameter set for whatever pair is currently active,
    plus this session's fixed sample file so camilla_dsp knows what to play."""
    pair = get_current_pair(user_id)
    if pair is None:
        return None
    params = dict(pair["A"] if side == "A" else pair["B"])
    params["sample"] = _sessions[user_id]["sample"]
    return params
