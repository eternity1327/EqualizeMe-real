"""
adaptive_test.py
Python port of utils/adaptiveTest.js.

Binary-search "staircase" method: for each parameter (bassGain,
trebleGain, presenceGain) in turn, plays A at the low bound and B at
the high bound, narrows toward whichever side was preferred over that
parameter's allotted rounds, then locks in the midpoint as that
parameter's final value before moving to the next parameter.

TEST STRUCTURE
--------------
The test is exactly 10 questions long, and each question uses a
different clip — question 1 plays sample1.wav, question 2 sample2.wav,
and so on through sample10.wav. The 10 clips are 2 clips (two different
parts) from each of 5 songs, so every song is heard twice, from two
different sections.

Within a single question, A and B are the SAME clip with two different
EQ settings applied — the comparison is always EQ vs EQ, never song vs
song.

The 10 questions are split across the three EQ bands as 4 / 3 / 3:

    questions 1-4   -> bassGain
    questions 5-7   -> trebleGain
    questions 8-10  -> presenceGain

Bass gets the extra round because it's first; with only 10 questions
across 3 parameters the split can't be even, so treble and presence
converge on a slightly coarser final value than bass does.

If the listener took the written pre-quiz first (see pre_quiz.py), its
estimate is passed in as `seed` and each band starts its search in a
window around that estimate rather than the full range — so the same
number of questions lands on a more precise final value.

Sessions are keyed by user_id, so two different users' test progress
won't collide. NOTE: this does NOT make audio playback itself
multi-user — camilladsp.exe still only outputs to one machine's
speakers at a time, so only one person can actually be listening
to their test audio at once, even though their progress tracking
is now isolated.
"""

RANGE_LOW = -6
RANGE_HIGH = 6
NUM_SAMPLES = 10  # sample1.wav .. sample10.wav in data/audio/samples/

# How far either side of the pre-quiz's estimate the search starts.
# Smaller = trusts the quiz more and converges tighter, but a wrong
# estimate becomes harder to escape, since the true preference could sit
# outside the window entirely. +/-3 keeps half the original range in play.
SEED_WINDOW = 3

# (parameter, how many questions/rounds it gets). Must sum to NUM_SAMPLES,
# since every question consumes exactly one clip.
PARAM_ROUNDS = [
    ("bassGain", 4),
    ("trebleGain", 3),
    ("presenceGain", 3),
]

TOTAL_QUESTIONS = sum(rounds for _, rounds in PARAM_ROUNDS)

# Friendly display names for the 10 trimmed clips, in the order they were
# copied in from the source recordings — keeps sample1.wav from being a
# meaningless label in the UI. Ordered so each song's two clips sit next
# to each other, which is also the order the test plays them in.
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
    """All 10 clips in the order the test plays them.

    The UI no longer lets the user choose one — the test walks through
    every clip — but this stays useful for previewing the running order
    and for debugging which files the service can see.
    """
    return [
        {"file": f"sample{i}.wav", "label": SAMPLE_LABELS.get(f"sample{i}.wav", f"sample{i}.wav")}
        for i in range(1, NUM_SAMPLES + 1)
    ]


def _bounds_for(param, seed):
    """Starting search range for a parameter.

    With no pre-quiz seed this is the full -6..+6. With one, it's a
    window centred on the quiz's estimate — clamped so the window never
    runs past the usable range (an estimate of +6 gives 0..+6, not
    +3..+9).
    """
    if not seed or param not in seed:
        return {"low": RANGE_LOW, "high": RANGE_HIGH}

    estimate = max(RANGE_LOW, min(RANGE_HIGH, seed[param]))
    return {
        "low": max(RANGE_LOW, estimate - SEED_WINDOW),
        "high": min(RANGE_HIGH, estimate + SEED_WINDOW),
    }


def start_session(user_id, seed=None):
    """Begins a test. `seed` is the optional pre-quiz estimate, e.g.
    {"bassGain": 2, "trebleGain": -1, "presenceGain": 0}; when given,
    each band starts its search narrowed around that value."""
    first_param = PARAM_ROUNDS[0][0]

    _sessions[user_id] = {
        "questionIndex": 0,  # 0-based; also picks which clip to play
        "paramIndex": 0,
        "round": 0,
        "bounds": _bounds_for(first_param, seed),
        "finalized": {"bassGain": 0, "trebleGain": 0, "presenceGain": 0},
        "history": [],
        # Kept for the whole session so each band can be re-seeded as the
        # test moves on to it.
        "seed": seed,
    }
    return get_current_pair(user_id)


def _sample_for_question(question_index):
    """Question N plays clip N — the clips are walked in fixed order."""
    return f"sample{question_index + 1}.wav"


def get_current_pair(user_id):
    session = _sessions.get(user_id)
    if session is None:
        return None
    if session["paramIndex"] >= len(PARAM_ROUNDS):
        return None
    if session["questionIndex"] >= TOTAL_QUESTIONS:
        return None

    low = session["bounds"]["low"]
    high = session["bounds"]["high"]
    param, rounds_for_param = PARAM_ROUNDS[session["paramIndex"]]

    # A and B differ ONLY in the parameter currently being tuned; every
    # other band stays at whatever earlier questions locked in.
    a = dict(session["finalized"])
    a[param] = low
    b = dict(session["finalized"])
    b[param] = high

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
    session = _sessions.get(user_id)
    if session is None:
        return {"error": "No active session. Call start_session first."}
    if preferred not in ("A", "B"):
        return {"error": 'preferred must be "A" or "B"'}
    if session["paramIndex"] >= len(PARAM_ROUNDS):
        return {"error": "Test already complete", "done": True, "profile": session["finalized"]}

    low = session["bounds"]["low"]
    high = session["bounds"]["high"]
    mid = (low + high) / 2
    param, rounds_for_param = PARAM_ROUNDS[session["paramIndex"]]

    session["history"].append({
        "param": param,
        "round": session["round"] + 1,
        "question": session["questionIndex"] + 1,
        "sample": _sample_for_question(session["questionIndex"]),
        "low": low,
        "high": high,
        "preferred": preferred,
    })

    if preferred == "A":
        session["bounds"]["high"] = mid
    else:
        session["bounds"]["low"] = mid

    session["round"] += 1
    session["questionIndex"] += 1

    # Finished this parameter's allotted rounds — lock in its value and
    # move to the next band with a fresh full range.
    if session["round"] >= rounds_for_param:
        final_value = (session["bounds"]["low"] + session["bounds"]["high"]) / 2
        session["finalized"][param] = round(final_value, 1)
        session["paramIndex"] += 1
        session["round"] = 0
        if session["paramIndex"] < len(PARAM_ROUNDS):
            next_param = PARAM_ROUNDS[session["paramIndex"]][0]
            session["bounds"] = _bounds_for(next_param, session.get("seed"))

    if session["paramIndex"] >= len(PARAM_ROUNDS) or session["questionIndex"] >= TOTAL_QUESTIONS:
        return {"done": True, "profile": session["finalized"], "history": session["history"]}

    return {"done": False, "next": get_current_pair(user_id)}


def get_current_side_params(user_id, side):
    """Returns the A or B parameter set for whatever pair is currently active,
    plus the clip this question uses so camilla_dsp knows what to play."""
    pair = get_current_pair(user_id)
    if pair is None:
        return None
    params = dict(pair["A"] if side == "A" else pair["B"])
    params["sample"] = pair["sample"]
    return params
