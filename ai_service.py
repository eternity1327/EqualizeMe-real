"""
EqualizeME AI Service (Flask)
Handles the adaptive listening assessment flow.
Run with: python ai_service.py  (defaults to http://127.0.0.1:5001)

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


logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)s %(message)s",
)
log = logging.getLogger("equalizeme")


# ---------------------------------------------------------------------------
# Error handling
#
# Every caller of this API is JavaScript doing `await res.json()`. Flask's
# default 500 is an HTML page, so an unexpected exception made the frontend
# fail a second time on the JSON parse, and the browser reported a parse
# error rather than the actual fault. These handlers guarantee the response
# is always JSON, so the real problem reaches the console.
#
# The message sent to the browser stays generic on purpose — exception text
# can carry table names, queries and file paths. The full traceback goes to
# the server log where it's useful and not exposed.
# ---------------------------------------------------------------------------

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

    Every query block here used to open a connection and close it on the
    last line of the happy path. Any exception in between — a malformed
    row, a missing column, a bug in the scoring loop — skipped the close
    and leaked the connection for good. MySQL allows 151 by default, so a
    recurring error would slowly exhaust the pool until the service
    stopped answering at all and needed a restart. That failure is
    especially nasty because it looks like "the server randomly broke"
    rather than pointing at the error that caused it.

    Wrapping acquisition and release in try/finally means the connection
    comes back regardless of how the block exits.
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
    """
    Reads all responses for this assessment, sums their score deltas
    per band (bass/treble/presence), and writes the result into
    auditory_profiles. Also computes a simple confidence score based
    on how many questions were answered vs. how many exist total.
    """
    # Get user_id for this assessment
    cur.execute("SELECT user_id FROM assessments WHERE assessment_id = %s", (assessment_id,))
    row = cur.fetchone()
    user_id = row["user_id"]

    # Sum deltas per band by joining responses -> question_score_impact
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
    band_totals = {row["band"]: float(row["total"]) for row in cur.fetchall()}

    bass_gain = round(band_totals.get("bass", 0.0), 1)
    treble_gain = round(band_totals.get("treble", 0.0), 1)
    presence_gain = round(band_totals.get("presence", 0.0), 1)

    # Confidence: % of total available questions actually answered
    cur.execute("SELECT COUNT(*) AS total FROM questions")
    total_questions = cur.fetchone()["total"]

    cur.execute(
        "SELECT COUNT(DISTINCT question_id) AS answered FROM responses WHERE assessment_id = %s",
        (assessment_id,),
    )
    answered = cur.fetchone()["answered"]

    confidence_score = round((answered / total_questions) * 100, 1) if total_questions else 0.0

    # Insert the profile
    cur.execute(
        """
        INSERT INTO auditory_profiles
            (user_id, assessment_id, bass_gain, treble_gain, presence_gain, confidence_score)
        VALUES (%s, %s, %s, %s, %s, %s)
        """,
        (user_id, assessment_id, bass_gain, treble_gain, presence_gain, confidence_score),
    )
    conn.commit()

    return {
        "bass_gain": bass_gain,
        "treble_gain": treble_gain,
        "presence_gain": presence_gain,
        "confidence_score": confidence_score,
    }


# How many of the best-matching IEMs to consider before sorting by price,
# and how many finally reach the page. A wider pool surfaces cheaper
# options but dilutes match quality; 15 into 5 keeps everything shown
# comfortably above the "genuinely suits you" line while still giving the
# price sort something to work with.
CANDIDATE_POOL_SIZE = 15
RESULTS_SHOWN = 5


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
    """
    Finds the user's most recent auditory profile, compares it against
    every IEM using absolute difference across bass/treble/presence,
    and returns the top 5 closest matches with retailer links.
    """
    with db_cursor(dictionary=True) as (conn, cur):
        # Get the most recent profile for this user
        cur.execute(
            "SELECT bass_gain, treble_gain, presence_gain FROM auditory_profiles "
            "WHERE user_id = %s ORDER BY updated_at DESC LIMIT 1",
            (user_id,),
        )
        profile = cur.fetchone()

        if not profile:
            return jsonify({"error": "No auditory profile found for this user"}), 404

        # Pull every IEM with its retailer info.
        #
        # shop_link is the direct URL used by IEMs imported via
        # import_to_db.py, which don't get a retailer_id — without selecting
        # it here, those rows would show no buy link at all despite having a
        # perfectly good URL.
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
        all_iems = cur.fetchall()

    # An imported measurement that didn't cover a whole band leaves that
    # gain NULL. Scoring it would raise on float(None) and take the entire
    # endpoint down, so those rows are dropped here — they simply can't be
    # matched until a full measurement is imported.
    bands = ("bass_gain", "treble_gain", "presence_gain")
    scorable = [iem for iem in all_iems if not any(iem[b] is None for b in bands)]

    baseline = _catalog_baseline(scorable, bands)

    results = []
    for iem in scorable:
        # Centre the IEM's measured gains on the catalogue before comparing.
        #
        # The two sides of this comparison are not the same quantity. The
        # listening test's numbers are EQ settings: how many dB of boost the
        # listener preferred relative to flat, so they sit around 0. The
        # IEM's numbers come from a measured curve relative to its own
        # mid-band, which carries the ear-canal resonance every in-ear
        # measurement has — across this catalogue the median bass gain is
        # about +5 dB and presence about +4.4 dB, on every IEM, regardless
        # of tuning.
        #
        # Comparing them raw meant even a listener who preferred perfectly
        # neutral EQ scored a distance of ~10 against everything, capping
        # match scores near zero and effectively ranking IEMs by how
        # unusual their measurement was rather than how well they fit.
        #
        # Subtracting the catalogue median puts both sides on the same
        # footing: 0 now means "average IEM" on the measurement side, which
        # is what "no EQ change needed" means on the listener side.
        distance = sum(
            abs(float(profile[band]) - (float(iem[band]) - baseline[band]))
            for band in bands
        )

        # Convert distance to a 0-100 score. The scale factor of 5 (rather
        # than the original 10) suits the post-centring spread: differences
        # of a few dB per band are normal even between good matches, and at
        # x10 those bottomed out at 0 and lost all ranking information.
        match_score = max(0, round(100 - (distance * 5), 1))

        # Per-band agreement, so the UI can show WHERE an IEM fits or
        # misses rather than only an overall figure. Same 5x scale as the
        # total, applied to that band's gap alone.
        centred = {band: round(float(iem[band]) - baseline[band], 2) for band in bands}
        band_match = {
            band: max(0, round(100 - abs(float(profile[band]) - centred[band]) * 5, 1))
            for band in bands
        }

        results.append({
            "iem_id": iem["id"],
            "name": iem["name"],
            "brand": iem["brand"],
            "sound_signature": iem["sound_signature"],
            "price": float(iem["price"]) if iem["price"] is not None else None,
            "image_url": iem.get("image_url"),
            "retailer_name": iem["retailer_name"],
            # Centred gains and per-band scores let the frontend draw the
            # listener's preference against this IEM's measurement.
            "gains": centred,
            "band_match": band_match,
            # Retailer link when there's a retailer row, otherwise the
            # direct shop_link stored by the importer.
            "product_url": iem["product_url"] or iem.get("shop_link"),
            "match_score": match_score,
        })

    # Ordering: quality first, then affordability.
    #
    # Sorting the whole catalogue by price would put a cheap IEM that
    # doesn't suit the listener above an excellent one, which defeats the
    # point of running a listening test. Sorting purely by match went the
    # other way — the best fits skew expensive, so the page opened with
    # $1000+ IEMs and read as "you can't afford our advice".
    #
    # So: narrow to the best-matching candidates, then show the cheapest
    # of those. Everything displayed is still a genuinely good fit; the
    # affordable ones just come first.
    results.sort(key=lambda r: r["match_score"], reverse=True)
    candidates = results[:CANDIDATE_POOL_SIZE]

    # Unknown prices sort last — an IEM with no price shouldn't lead the
    # list when the whole point is showing what's affordable.
    candidates.sort(
        key=lambda r: (r["price"] is None, r["price"] if r["price"] is not None else 0)
    )
    top_results = candidates[:RESULTS_SHOWN]

    return jsonify({
        "user_id": user_id,
        # The listener's own preference, echoed back so the frontend can
        # plot it against each IEM's measured curve instead of only
        # showing a number.
        "profile": {band: float(profile[band]) for band in bands},
        "recommendations": top_results,
    })


@app.route("/api/iems/<int:iem_id>/curve", methods=["GET"])
def get_iem_curve(iem_id):
    """
    The measured frequency-response curve for one IEM, plus the
    plain-language description generated from it by interpreter.py.

    Populated by import_to_db.py from REW measurement files — an IEM
    that hasn't had a measurement imported yet has no curve, which is
    a normal state rather than an error condition. recommendations.html
    fetches this per card and quietly skips the chart when it 404s.
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

    # Stored as a JSON string by import_to_db.py; decoded here so the
    # frontend gets a real array rather than a string it has to parse again.
    try:
        curve = json.loads(row["fr_curve_json"])
    except (TypeError, ValueError):
        return jsonify({"error": "Stored curve data is not valid JSON"}), 500

    return jsonify({
        "curve": curve,
        "description": row["description"],
    })


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


@app.route("/api/dsp/adaptive/start", methods=["POST"])
def dsp_adaptive_start():
    """Starts CamillaDSP (if not running) and begins a new staircase session for this user."""
    data = request.get_json() or {}
    user_id = data.get("user_id")
    if not user_id:
        return jsonify({"error": "user_id is required"}), 400

    camilla_dsp.start()

    # Pre-quiz answers, if the user took it, become a starting estimate so
    # each EQ band's search begins narrowed instead of at the full range.
    # Skipping the quiz is fine — seed stays None and the test runs as
    # before, just with less to go on.
    seed = pre_quiz.score_answers(data.get("quiz")) if data.get("quiz") else None

    # No sample argument — the test walks through all 10 clips in fixed
    # order, one per question, rather than using a single chosen track.
    pair = adaptive_test.start_session(user_id, seed=seed)
    if seed:
        pair["seed"] = seed
    return jsonify(pair)


@app.route("/api/dsp/adaptive/play", methods=["POST"])
def dsp_adaptive_play():
    """Applies the filter values for the requested side (A or B) and plays them."""
    data = request.get_json()
    side = data.get("side")
    user_id = data.get("user_id")
    if not user_id:
        return jsonify({"error": "user_id is required"}), 400
    if side not in ("A", "B"):
        return jsonify({"error": 'side must be "A" or "B"'}), 400

    params = adaptive_test.get_current_side_params(user_id, side)
    if params is None:
        return jsonify({"error": "No active test session"}), 400

    result = camilla_dsp.apply_filters(**params)
    if not result["ok"]:
        return jsonify({"error": "Could not reach CamillaDSP"}), 500

    return jsonify({"ok": True, "params": params})


@app.route("/api/dsp/adaptive/answer", methods=["POST"])
def dsp_adaptive_answer():
    """Records the preferred side, advances the staircase, and saves the profile on completion."""
    data = request.get_json()
    preferred = data.get("preferred")
    user_id = data.get("user_id")
    if not user_id:
        return jsonify({"error": "user_id is required"}), 400

    result = adaptive_test.record_answer(user_id, preferred)

    if "error" in result:
        return jsonify(result), 400

    if result.get("done"):
        _save_dsp_profile(user_id, result["profile"])
        return jsonify(result)

    return jsonify(result)


def _save_dsp_profile(user_id, profile):
    """Writes the finalized bassGain/trebleGain/presenceGain into auditory_profiles.

    Relies on user_id being UNIQUE on that table so the upsert replaces the
    previous profile instead of stacking a new row on every retake. If that
    constraint is missing the ON DUPLICATE KEY clause never fires and each
    retake silently adds a row — see sql/schema_cleanup.sql.
    """
    with db_cursor() as (conn, cur):
        cur.execute(
            """
            INSERT INTO auditory_profiles (user_id, bass_gain, treble_gain, presence_gain, confidence_score)
            VALUES (%s, %s, %s, %s, %s)
            ON DUPLICATE KEY UPDATE
                bass_gain = VALUES(bass_gain),
                treble_gain = VALUES(treble_gain),
                presence_gain = VALUES(presence_gain),
                confidence_score = VALUES(confidence_score)
            """,
            (user_id, profile["bassGain"], profile["trebleGain"], profile["presenceGain"], 100.0),
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
    """
    Takes the current assessment_id, question_id, and the user's answer.
    Logs the response, then looks up question_rules to find what's next.
    If no rule matches, the assessment is marked complete.
    """
    data = request.get_json()
    assessment_id = data.get("assessment_id")
    question_id = data.get("question_id")
    answer_value = data.get("answer_value")
    sequence_order = data.get("sequence_order", 1)

    if not all([assessment_id, question_id, answer_value]):
        return jsonify({"error": "assessment_id, question_id, and answer_value are required"}), 400

    with db_cursor(dictionary=True) as (conn, cur):
        # Log the response
        cur.execute(
            "INSERT INTO responses (assessment_id, question_id, answer_value, sequence_order) "
            "VALUES (%s, %s, %s, %s)",
            (assessment_id, question_id, answer_value, sequence_order),
        )
        conn.commit()

        # Look up the branching rule
        cur.execute(
            "SELECT next_question_id FROM question_rules "
            "WHERE from_question_id = %s AND condition_answer_value = %s "
            "ORDER BY priority ASC LIMIT 1",
            (question_id, answer_value),
        )
        rule = cur.fetchone()

        if not rule:
            # No further question -> assessment complete
            cur.execute(
                "UPDATE assessments SET status = 'completed', completed_at = NOW() "
                "WHERE assessment_id = %s",
                (assessment_id,),
            )
            conn.commit()

            profile = _generate_profile(cur, conn, assessment_id)
            return jsonify({
                "complete": True,
                "assessment_id": assessment_id,
                "profile": profile,
            })

        # Fetch the next question's details
        cur.execute(
            "SELECT question_id, audio_stimulus_ref, question_type "
            "FROM questions WHERE question_id = %s",
            (rule["next_question_id"],),
        )
        next_q = cur.fetchone()

    return jsonify({
        "complete": False,
        "question": next_q,
    })


if __name__ == "__main__":
    if DEBUG_MODE:
        print("WARNING: FLASK_DEBUG=1 — the interactive debugger is ON. "
              "Only run this if the service is NOT reachable from outside your own machine.")
    app.run(host="0.0.0.0", port=5001, debug=DEBUG_MODE)
