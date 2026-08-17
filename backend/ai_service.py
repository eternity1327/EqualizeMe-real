"""
EqualizeME AI Service (Flask)
Handles the adaptive listening assessment flow.
Run with: python backend/ai_service.py  (from the project root)
Defaults to http://127.0.0.1:5001

Environment variables:
  FLASK_DEBUG    "1" to enable the Werkzeug debugger/reloader (dev only —
                 NEVER set this if the service is reachable from outside
                 your own machine; the interactive debugger allows remote
                 code execution). Defaults to off.
  ALLOWED_ORIGIN Origin allowed to call this API (e.g. your tunnel's
                 https://yourname.trycloudflare.com). Defaults to "*"
                 for local/LAN use — set this once you have a real
                 public domain, so random sites can't call your API
                 using a logged-in visitor's session.
"""

from flask import Flask, request, jsonify
from flask_cors import CORS
from contextlib import contextmanager
import mysql.connector
import json
import logging
import os
import camilla_dsp
import adaptive_test
import pre_quiz

app = Flask(__name__)

DEBUG_MODE = os.environ.get("FLASK_DEBUG", "0") == "1"
ALLOWED_ORIGIN = os.environ.get("ALLOWED_ORIGIN", "*")
CORS(app, origins=ALLOWED_ORIGIN)  # allows requests from the PHP app on a different origin/port

DB_CONFIG = {
    "host": os.environ.get("DB_HOST", "127.0.0.1"),
    "user": os.environ.get("DB_USER", "root"),
    "password": os.environ.get("DB_PASSWORD", ""),
    "database": os.environ.get("DB_NAME", "equalizeme"),
}

DEFAULT_PORT = 5001
DEFAULT_HOST = "0.0.0.0"

# The three frequency bands the listening test measures, named as they
# appear in both the auditory_profiles and iems tables.
BANDS = ("bass_gain", "treble_gain", "presence_gain")

# Decibels of mismatch cost this many percentage points of match score.
# At the original 10 the post-centring spread bottomed out at 0 for
# everything and lost all ranking information.
SCORE_SCALE = 5

MEASUREMENT_DECIMALS = 2
GAIN_DECIMALS = 1

# A fully answered assessment, used when the adaptive test writes a profile.
FULL_CONFIDENCE = 100.0

DEFAULT_SEQUENCE_ORDER = 1

# Best matches considered before sorting by price, and how many finally
# reach the page. A wider pool surfaces cheaper options but dilutes match
# quality; 15 into 5 keeps everything shown genuinely suitable while still
# giving the price sort something to work with.
CANDIDATE_POOL_SIZE = 15
RESULTS_SHOWN = 5


logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)s %(message)s",
)
log = logging.getLogger("equalizeme")


# Error handlers guarantee JSON. Every caller does `await res.json()`, so
# Flask's default HTML error page made the frontend fail a second time on
# the parse and hid the real fault. Messages stay generic — exception text
# can carry table names and file paths — while the traceback goes to the log.

@app.errorhandler(mysql.connector.Error)
def handle_db_error(err):
    log.exception("Database error")
    return jsonify({
        "error": "The database is unavailable right now. Is MySQL running?"
    }), 503


@app.errorhandler(Exception)
def handle_unexpected_error(err):
    # Flask routes its own HTTP errors (404 etc.) through here too; those
    # already carry a sensible code and shouldn't be relabelled as crashes.
    code = getattr(err, "code", None)
    if isinstance(code, int):
        return jsonify({"error": getattr(err, "description", "Request failed")}), code

    log.exception("Unhandled error")
    return jsonify({"error": "Something went wrong on the server."}), 500


def get_db():
    return mysql.connector.connect(**DB_CONFIG)


@contextmanager
def db_cursor(dictionary=False):
    """
    A database cursor that always cleans up after itself.

    Closing on the last line of the happy path leaked a connection on any
    exception. MySQL allows 151 by default, so a recurring error would
    exhaust the pool until the service needed a restart. try/finally
    returns the connection however the block exits.
    """
    conn = get_db()
    cur = conn.cursor(dictionary=dictionary)
    try:
        yield conn, cur
    finally:
        try:
            cur.close()
        finally:
            conn.close()


def _generate_profile(cur, conn, assessment_id):
    """Turn this assessment's answers into a stored auditory profile."""
    user_id = fetch_assessment_user(cur, assessment_id)
    gains = sum_band_deltas(cur, assessment_id)
    confidence = completion_confidence(cur, assessment_id)

    profile = {**gains, "confidence_score": confidence}
    save_profile(cur, conn, user_id, assessment_id, profile)
    return profile


def fetch_assessment_user(cur, assessment_id):
    """Which user this assessment belongs to."""
    cur.execute(
        "SELECT user_id FROM assessments WHERE assessment_id = %s",
        (assessment_id,),
    )
    return cur.fetchone()["user_id"]


def sum_band_deltas(cur, assessment_id):
    """Total the dB impact of every answer, band by band."""
    cur.execute(
        """
        SELECT qsi.band, SUM(qsi.delta) AS total
        FROM responses r
        JOIN question_score_impact qsi
            ON qsi.question_id = r.question_id
            AND qsi.answer_value = r.answer_value
        WHERE r.assessment_id = %s
        GROUP BY qsi.band
        """,
        (assessment_id,),
    )
    totals = {row["band"]: float(row["total"]) for row in cur.fetchall()}
    return {
        f"{band}_gain": round(totals.get(band, 0.0), GAIN_DECIMALS)
        for band in ("bass", "treble", "presence")
    }


def completion_confidence(cur, assessment_id):
    """How much of the questionnaire was answered, as a percentage."""
    cur.execute("SELECT COUNT(*) AS total FROM questions")
    total_questions = cur.fetchone()["total"]
    if not total_questions:
        return 0.0

    cur.execute(
        "SELECT COUNT(DISTINCT question_id) AS answered FROM responses "
        "WHERE assessment_id = %s",
        (assessment_id,),
    )
    answered = cur.fetchone()["answered"]
    return round((answered / total_questions) * 100, GAIN_DECIMALS)


def save_profile(cur, conn, user_id, assessment_id, profile):
    """Write the finished profile to auditory_profiles."""
    cur.execute(
        """
        INSERT INTO auditory_profiles
            (user_id, assessment_id, bass_gain, treble_gain, presence_gain,
             confidence_score)
        VALUES (%s, %s, %s, %s, %s, %s)
        """,
        (
            user_id,
            assessment_id,
            profile["bass_gain"],
            profile["treble_gain"],
            profile["presence_gain"],
            profile["confidence_score"],
        ),
    )
    conn.commit()


def _median(values):
    """Median without pulling in numpy for one calculation."""
    if not values:
        return 0.0
    ordered = sorted(values)
    mid = len(ordered) // 2
    if len(ordered) % 2:
        return ordered[mid]
    return (ordered[mid - 1] + ordered[mid]) / 2


def _catalog_baseline(iems, bands):
    """
    The 'average IEM' for each band, used to centre measured gains before
    comparing them to a listener's preference.

    Median rather than mean: the catalogue contains genuine outliers
    (measurements ranging past -13 dB and +13 dB) and a couple of extreme
    entries would drag a mean far enough to skew everyone's results.

    Recomputed per request. That's a handful of arithmetic over ~130 rows,
    and it means the baseline stays correct as IEMs are added without any
    cache to invalidate.
    """
    return {band: _median([float(iem[band]) for iem in iems]) for band in bands}


@app.route("/recommendations/<int:user_id>", methods=["GET"])
def get_recommendations(user_id):
    """Return the IEMs that best suit this listener, cheapest first."""
    with db_cursor(dictionary=True) as (conn, cur):
        profile = fetch_latest_profile(cur, user_id)
        if not profile:
            return jsonify({"error": "No auditory profile found for this user"}), 404

        catalogue = fetch_iem_catalogue(cur)

    scorable = [iem for iem in catalogue if has_complete_measurement(iem)]
    baseline = _catalog_baseline(scorable, BANDS)

    scored = [score_iem(iem, profile, baseline) for iem in scorable]

    return jsonify({
        "user_id": user_id,
        "profile": {band: float(profile[band]) for band in BANDS},
        "recommendations": rank_recommendations(scored),
    })


def fetch_latest_profile(cur, user_id):
    """The listener's most recent auditory profile, or None."""
    cur.execute(
        "SELECT bass_gain, treble_gain, presence_gain FROM auditory_profiles "
        "WHERE user_id = %s ORDER BY updated_at DESC LIMIT 1",
        (user_id,),
    )
    return cur.fetchone()


def fetch_iem_catalogue(cur):
    """Every IEM with its retailer details."""
    # shop_link is selected alongside the retailer join because imported
    # IEMs have no retailer_id — without it those rows show no buy link.
    cur.execute(
        """
        SELECT i.id, i.name, i.brand, i.sound_signature, i.bass_gain,
               i.treble_gain, i.presence_gain, i.price, i.image_url,
               i.shop_link,
               r.name AS retailer_name, r.product_url
        FROM iems i
        LEFT JOIN retailers r ON i.retailer_id = r.retailer_id
        """
    )
    return cur.fetchall()


def has_complete_measurement(iem):
    """True when every band has a gain, so the IEM can be scored."""
    return all(iem[band] is not None for band in BANDS)


def centre_on_catalogue(iem, baseline):
    """
    Express an IEM's measured gains relative to the average IEM.

    A listener's profile is an EQ setting centred on zero. A measurement is
    a deviation from its own midrange, which includes the ear-canal
    resonance present in every in-ear measurement — a median of roughly
    +5 dB bass and +4.4 dB presence across this catalogue. Comparing the
    two raw made even a neutral listener score near zero against
    everything. Subtracting the catalogue median puts both on the same
    footing.
    """
    return {
        band: round(float(iem[band]) - baseline[band], MEASUREMENT_DECIMALS)
        for band in BANDS
    }


def agreement_score(preference_db, iem_db):
    """Convert a gap in decibels to a 0-100 agreement percentage."""
    return max(0, round(100 - abs(preference_db - iem_db) * SCORE_SCALE, 1))


def score_iem(iem, profile, baseline):
    """Build the scored recommendation entry for one IEM."""
    centred = centre_on_catalogue(iem, baseline)

    distance = sum(abs(float(profile[band]) - centred[band]) for band in BANDS)
    band_match = {
        band: agreement_score(float(profile[band]), centred[band])
        for band in BANDS
    }

    return {
        "iem_id": iem["id"],
        "name": iem["name"],
        "brand": iem["brand"],
        "sound_signature": iem["sound_signature"],
        "price": float(iem["price"]) if iem["price"] is not None else None,
        "image_url": iem.get("image_url"),
        "retailer_name": iem["retailer_name"],
        "product_url": iem["product_url"] or iem.get("shop_link"),
        "gains": centred,
        "band_match": band_match,
        "match_score": max(0, round(100 - distance * SCORE_SCALE, 1)),
    }


def rank_recommendations(scored):
    """
    Pick the best matches, then lead with the most affordable of them.

    Ranking by match alone surfaced flagship IEMs costing thousands.
    Ranking by price alone recommends cheap IEMs that don't suit the
    listener, which defeats the point of the test. Narrowing to the best
    candidates first means everything shown is a genuine fit.
    """
    best_matches = sorted(scored, key=lambda r: r["match_score"], reverse=True)
    candidates = best_matches[:CANDIDATE_POOL_SIZE]

    candidates.sort(key=price_sort_key)
    return candidates[:RESULTS_SHOWN]


def price_sort_key(recommendation):
    """Sort ascending by price, placing unknown prices last."""
    price = recommendation["price"]
    return (price is None, price if price is not None else 0)


@app.route("/api/iems/<int:iem_id>/curve", methods=["GET"])
def get_iem_curve(iem_id):
    """
    One IEM's measured curve and its plain-language description.

    An IEM with no measurement imported yet is a normal state, not an
    error — the recommendations page skips the chart when this 404s.
    """
    with db_cursor(dictionary=True) as (conn, cur):
        cur.execute(
            "SELECT fr_curve_json, description FROM iems WHERE id = %s",
            (iem_id,),
        )
        row = cur.fetchone()

    if row is None:
        return jsonify({"error": "IEM not found"}), 404
    if not row["fr_curve_json"]:
        return jsonify({"error": "No measurement curve stored for this IEM"}), 404

    # Stored as a JSON string, decoded here so the frontend gets an array
    # rather than a string it has to parse again.
    try:
        curve = json.loads(row["fr_curve_json"])
    except (TypeError, ValueError):
        return jsonify({"error": "Stored curve data is not valid JSON"}), 500

    return jsonify({"curve": curve, "description": row["description"]})


# ---------------------------------------------------------------------------
# Adaptive A/B listening test (current flow) — powers test.html's track
# picker + staircase test via js/adaptiveTest.js.
# ---------------------------------------------------------------------------

@app.route("/api/quiz/questions", methods=["GET"])
def quiz_questions():
    """The written pre-quiz shown before the listening test starts."""
    return jsonify({"questions": pre_quiz.list_questions()})


@app.route("/api/dsp/adaptive/samples", methods=["GET"])
def dsp_adaptive_samples():
    """Lists the 10 clips in the order the test plays them."""
    return jsonify({"samples": adaptive_test.list_samples()})


def _missing_user_id():
    return jsonify({"error": "user_id is required"}), 400


@app.route("/api/dsp/adaptive/start", methods=["POST"])
def dsp_adaptive_start():
    """Begin a new staircase session for this user."""
    data = request.get_json() or {}
    user_id = data.get("user_id")
    if not user_id:
        return _missing_user_id()

    camilla_dsp.start()

    # Pre-quiz answers narrow where each band's search begins. Skipping
    # the quiz is fine — the seed stays None and the test runs the full
    # range, just with less to go on.
    seed = pre_quiz.score_answers(data.get("quiz")) if data.get("quiz") else None

    pair = adaptive_test.start_session(user_id, seed=seed)
    if seed:
        pair["seed"] = seed
    return jsonify(pair)


@app.route("/api/dsp/adaptive/play", methods=["POST"])
def dsp_adaptive_play():
    """Play one side of the current comparison through CamillaDSP."""
    data = request.get_json()
    user_id = data.get("user_id")
    side = data.get("side")

    if not user_id:
        return _missing_user_id()
    if side not in ("A", "B"):
        return jsonify({"error": 'side must be "A" or "B"'}), 400

    params = adaptive_test.get_current_side_params(user_id, side)
    if params is None:
        return jsonify({"error": "No active test session"}), 400

    if not camilla_dsp.apply_filters(**params)["ok"]:
        return jsonify({"error": "Could not reach CamillaDSP"}), 500

    return jsonify({"ok": True, "params": params})


@app.route("/api/dsp/adaptive/answer", methods=["POST"])
def dsp_adaptive_answer():
    """Record the preferred side, and save the profile once the test ends."""
    data = request.get_json()
    user_id = data.get("user_id")
    if not user_id:
        return _missing_user_id()

    result = adaptive_test.record_answer(user_id, data.get("preferred"))

    if "error" in result:
        return jsonify(result), 400

    if result.get("done"):
        _save_dsp_profile(user_id, result["profile"])

    return jsonify(result)


def _save_dsp_profile(user_id, profile):
    """
    Store the finished listening-test profile.

    Relies on user_id being UNIQUE on auditory_profiles so the upsert
    replaces the previous profile. Without that constraint the ON
    DUPLICATE KEY clause never fires and each retake silently adds a row
    — see sql/schema_cleanup.sql.
    """
    with db_cursor() as (conn, cur):
        cur.execute(
            """
            INSERT INTO auditory_profiles
                (user_id, bass_gain, treble_gain, presence_gain, confidence_score)
            VALUES (%s, %s, %s, %s, %s)
            ON DUPLICATE KEY UPDATE
                bass_gain = VALUES(bass_gain),
                treble_gain = VALUES(treble_gain),
                presence_gain = VALUES(presence_gain),
                confidence_score = VALUES(confidence_score)
            """,
            (
                user_id,
                profile["bassGain"],
                profile["trebleGain"],
                profile["presenceGain"],
                FULL_CONFIDENCE,
            ),
        )
        conn.commit()

# ---------------------------------------------------------------------------
# Legacy branching assessment flow — question-by-question quiz used by
# assessment.php, which is no longer linked from the site nav (superseded
# by the adaptive test above). Left working, not extended further.
# ---------------------------------------------------------------------------


@app.route("/start-assessment", methods=["POST"])
def start_assessment():
    """Creates a new assessment row and returns the first question."""
    data = request.get_json()
    user_id = data.get("user_id")
    if not user_id:
        return jsonify({"error": "user_id is required"}), 400

    with db_cursor(dictionary=True) as (conn, cur):
        cur.execute(
            "INSERT INTO assessments (user_id, status) VALUES (%s, 'in_progress')",
            (user_id,),
        )
        assessment_id = cur.lastrowid
        conn.commit()

        cur.execute(
            "SELECT question_id, audio_stimulus_ref, question_type "
            "FROM questions WHERE is_starting_question = TRUE LIMIT 1"
        )
        question = cur.fetchone()

    if not question:
        return jsonify({"error": "No starting question configured"}), 500

    return jsonify({
        "assessment_id": assessment_id,
        "question": question,
    })


@app.route("/next-question", methods=["POST"])
def next_question():
    """Record an answer and return whatever the branching rules ask next."""
    data = request.get_json()
    assessment_id = data.get("assessment_id")
    question_id = data.get("question_id")
    answer_value = data.get("answer_value")
    sequence_order = data.get("sequence_order", DEFAULT_SEQUENCE_ORDER)

    if not all([assessment_id, question_id, answer_value]):
        return jsonify({
            "error": "assessment_id, question_id, and answer_value are required"
        }), 400

    with db_cursor(dictionary=True) as (conn, cur):
        record_response(cur, conn, assessment_id, question_id,
                        answer_value, sequence_order)

        next_id = find_next_question_id(cur, question_id, answer_value)
        if next_id is None:
            profile = complete_assessment(cur, conn, assessment_id)
            return jsonify({
                "complete": True,
                "assessment_id": assessment_id,
                "profile": profile,
            })

        question = fetch_question(cur, next_id)

    return jsonify({"complete": False, "question": question})


def record_response(cur, conn, assessment_id, question_id, answer_value,
                    sequence_order):
    """Log one answer against its assessment."""
    cur.execute(
        "INSERT INTO responses "
        "(assessment_id, question_id, answer_value, sequence_order) "
        "VALUES (%s, %s, %s, %s)",
        (assessment_id, question_id, answer_value, sequence_order),
    )
    conn.commit()


def find_next_question_id(cur, question_id, answer_value):
    """The question this answer branches to, or None when the quiz ends."""
    cur.execute(
        "SELECT next_question_id FROM question_rules "
        "WHERE from_question_id = %s AND condition_answer_value = %s "
        "ORDER BY priority ASC LIMIT 1",
        (question_id, answer_value),
    )
    rule = cur.fetchone()
    return rule["next_question_id"] if rule else None


def fetch_question(cur, question_id):
    """The details the frontend needs to display one question."""
    cur.execute(
        "SELECT question_id, audio_stimulus_ref, question_type "
        "FROM questions WHERE question_id = %s",
        (question_id,),
    )
    return cur.fetchone()


def complete_assessment(cur, conn, assessment_id):
    """Mark the assessment finished and build the resulting profile."""
    cur.execute(
        "UPDATE assessments SET status = 'completed', completed_at = NOW() "
        "WHERE assessment_id = %s",
        (assessment_id,),
    )
    conn.commit()
    return _generate_profile(cur, conn, assessment_id)


if __name__ == "__main__":
    if DEBUG_MODE:
        print("WARNING: FLASK_DEBUG=1 — the interactive debugger is ON. "
              "Only run this if the service is NOT reachable from outside "
              "your own machine.")
    app.run(host=DEFAULT_HOST, port=DEFAULT_PORT, debug=DEBUG_MODE)
