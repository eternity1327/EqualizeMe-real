RANGE_LOW = -6
RANGE_HIGH = 6
NUM_SAMPLES = 10
GAIN_DECIMALS = 1

SEED_WINDOW = 3

PARAM_ROUNDS = [
    ("bassGain", 4),
    ("trebleGain", 3),
    ("presenceGain", 3),
]

TOTAL_QUESTIONS = sum(rounds for _, rounds in PARAM_ROUNDS)

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

_sessions = {}


def list_samples():
    return [
        {"file": name, "label": SAMPLE_LABELS.get(name, name)}
        for name in (f"sample{i}.wav" for i in range(1, NUM_SAMPLES + 1))
    ]


def _bounds_for(param, seed):
    if not seed or param not in seed:
        return {"low": RANGE_LOW, "high": RANGE_HIGH}

    estimate = max(RANGE_LOW, min(RANGE_HIGH, seed[param]))
    return {
        "low": max(RANGE_LOW, estimate - SEED_WINDOW),
        "high": min(RANGE_HIGH, estimate + SEED_WINDOW),
    }


def start_session(user_id, seed=None):
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
    return f"sample{question_index + 1}.wav"


def get_current_pair(user_id):
    session = _sessions.get(user_id)
    if session is None or _is_complete(session):
        return None

    param, rounds_for_param = PARAM_ROUNDS[session["paramIndex"]]

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
    mid = _midpoint(session["bounds"])
    edge = "high" if preferred == "A" else "low"
    session["bounds"][edge] = mid


def _midpoint(bounds):
    return (bounds["low"] + bounds["high"]) / 2


def _finalize_param(session, param):
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
    pair = get_current_pair(user_id)
    if pair is None:
        return None
    params = dict(pair["A"] if side == "A" else pair["B"])
    params["sample"] = pair["sample"]
    return params
