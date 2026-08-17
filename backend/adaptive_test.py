"""
The adaptive listening test.

A binary-search staircase: for each band in turn, A plays at the low
bound and B at the high bound, the range narrows toward whichever side
was preferred, and the midpoint becomes that band's final value.

Ten questions, one clip each - two excerpts from each of five songs.
Within a question A and B are the same clip at two EQ settings, so the
comparison is always EQ against EQ, never song against song.

The pre-quiz's estimate, when present, arrives as `seed` and narrows
where each band's search begins.

Sessions are keyed by user_id, so progress doesn't collide between
users. Audio itself plays in each listener's own browser.
"""

RANGE_LOW = -6
RANGE_HIGH = 6
NUM_SAMPLES = 10  # sample1.wav .. sample10.wav in data/audio/samples/
GAIN_DECIMALS = 1

# How far either side of the pre-quiz's estimate the search starts.
# Smaller trusts the quiz more and converges tighter, but a wrong estimate
# becomes harder to escape. +/-3 keeps half the original range in play.
SEED_WINDOW = 3

# (band, rounds it gets). Must sum to NUM_SAMPLES - one clip per question.
# Bass takes the spare round because preference varies most there.
PARAM_ROUNDS = [
    ("bassGain", 4),
    ("trebleGain", 3),
    ("presenceGain", 3),
]

TOTAL_QUESTIONS = sum(rounds for _, rounds in PARAM_ROUNDS)

# Display names, ordered so each song's two clips sit together - which is
# also the order the test plays them in.
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
    """All 10 clips in the order the test plays them."""
    return [
        {"file": name, "label": SAMPLE_LABELS.get(name, name)}
        for name in (f"sample{i}.wav" for i in range(1, NUM_SAMPLES + 1))
    ]


def _bounds_for(param, seed):
    """
    Starting search range for one band.

    Without a seed this is the full -6..+6. With one it's a window around
    the quiz's estimate, clamped so it never runs past the usable range.
    """
    if not seed or param not in seed:
        return {"low": RANGE_LOW, "high": RANGE_HIGH}

    estimate = max(RANGE_LOW, min(RANGE_HIGH, seed[param]))
    return {
        "low": max(RANGE_LOW, estimate - SEED_WINDOW),
        "high": min(RANGE_HIGH, estimate + SEED_WINDOW),
    }


def start_session(user_id, seed=None):
    """
    Begin a test for this user.

    `seed` is the optional pre-quiz estimate, e.g.
    {"bassGain": 2, "trebleGain": -1, "presenceGain": 0}. It is kept for
    the whole session so each band is re-seeded as the test reaches it.
    """
    first_param = PARAM_ROUNDS[0][0]

    _sessions[user_id] = {
        "questionIndex": 0,
        "paramIndex": 0,
        "round": 0,
        "bounds": _bounds_for(first_param, seed),
        "finalized": {band: 0 for band, _ in PARAM_ROUNDS},
        "history": [],
        "seed": seed,
    }
    return get_current_pair(user_id)


def _sample_for_question(question_index):
    """Question N plays clip N."""
    return f"sample{question_index + 1}.wav"


def get_current_pair(user_id):
    """The A/B comparison this user should hear next, or None when done."""
    session = _sessions.get(user_id)
    if session is None or _is_complete(session):
        return None

    param, rounds_for_param = PARAM_ROUNDS[session["paramIndex"]]

    # A and B differ only in the band being tuned; the rest stay at
    # whatever earlier questions locked in.
    a = dict(session["finalized"])
    a[param] = session["bounds"]["low"]
    b = dict(session["finalized"])
    b[param] = session["bounds"]["high"]

    sample = _sample_for_question(session["questionIndex"])

    return {
        "done": False,
        "param": param,
        "round": session["round"] + 1,
        "totalRoundsForParam": rounds_for_param,
        "paramNumber": session["paramIndex"] + 1,
        "totalParams": len(PARAM_ROUNDS),
        "question": session["questionIndex"] + 1,
        "totalQuestions": TOTAL_QUESTIONS,
        "A": a,
        "B": b,
        "sample": sample,
        "sampleLabel": SAMPLE_LABELS.get(sample, sample),
    }


def record_answer(user_id, preferred):
    """Narrow the search toward the preferred side and hand back what's next."""
    session = _sessions.get(user_id)
    error = _validate_answer(session, preferred)
    if error:
        return error

    param, rounds_for_param = PARAM_ROUNDS[session["paramIndex"]]

    _log_answer(session, param, preferred)
    _narrow_bounds(session, preferred)

    session["round"] += 1
    session["questionIndex"] += 1

    if session["round"] >= rounds_for_param:
        _finalize_param(session, param)

    if _is_complete(session):
        return {
            "done": True,
            "profile": session["finalized"],
            "history": session["history"],
        }

    return {"done": False, "next": get_current_pair(user_id)}


def _validate_answer(session, preferred):
    """The reason this answer can't be accepted, or None if it can."""
    if session is None:
        return {"error": "No active session. Call start_session first."}
    if preferred not in ("A", "B"):
        return {"error": 'preferred must be "A" or "B"'}
    if session["paramIndex"] >= len(PARAM_ROUNDS):
        return {
            "error": "Test already complete",
            "done": True,
            "profile": session["finalized"],
        }
    return None


def _log_answer(session, param, preferred):
    """Append this round to the session's history."""
    session["history"].append({
        "param": param,
        "round": session["round"] + 1,
        "question": session["questionIndex"] + 1,
        "sample": _sample_for_question(session["questionIndex"]),
        "low": session["bounds"]["low"],
        "high": session["bounds"]["high"],
        "preferred": preferred,
    })


def _narrow_bounds(session, preferred):
    """Discard the half of the range the listener rejected."""
    mid = _midpoint(session["bounds"])
    edge = "high" if preferred == "A" else "low"
    session["bounds"][edge] = mid


def _midpoint(bounds):
    return (bounds["low"] + bounds["high"]) / 2


def _finalize_param(session, param):
    """Lock in this band's value and start the search for the next one."""
    session["finalized"][param] = round(_midpoint(session["bounds"]), GAIN_DECIMALS)
    session["paramIndex"] += 1
    session["round"] = 0

    if session["paramIndex"] < len(PARAM_ROUNDS):
        next_param = PARAM_ROUNDS[session["paramIndex"]][0]
        session["bounds"] = _bounds_for(next_param, session.get("seed"))


def _is_complete(session):
    return (session["paramIndex"] >= len(PARAM_ROUNDS)
            or session["questionIndex"] >= TOTAL_QUESTIONS)


def get_current_side_params(user_id, side):
    """One side's filter settings plus the clip they apply to."""
    pair = get_current_pair(user_id)
    if pair is None:
        return None
    params = dict(pair["A"] if side == "A" else pair["B"])
    params["sample"] = pair["sample"]
    return params
