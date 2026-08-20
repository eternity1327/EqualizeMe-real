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
import db_config

app = Flask(__name__)

DEBUG_MODE = os.environ.get("FLASK_DEBUG", "0") == "1"

# This service performs no authentication of its own — it takes the user id
# it is given. That is safe only because it is not reachable from a browser:
# every request arrives from api/dsp.php, which checks the session first and
# supplies the user id itself.
#
# Two things enforce that. The service binds to loopback (see DEFAULT_HOST
# below), and CORS defaults to refusing browser origins outright. Setting
# ALLOWED_ORIGIN to "*" re-opens direct access and should only be done for
# local debugging on a machine nothing else can reach.
ALLOWED_ORIGIN = os.environ.get("ALLOWED_ORIGIN", "null")
CORS(app, origins=ALLOWED_ORIGIN)

DB_CONFIG = db_config.db_config()

DEFAULT_PORT = 5001

# Loopback only. Previously 0.0.0.0, which exposed every route — including
# ones that read and overwrite user profiles — to anyone on the same network.
# api/dsp.php runs on the same machine, so it can still reach this.
DEFAULT_HOST = os.environ.get("DSP_BIND_HOST", "127.0.0.1")

BANDS = ("bass_gain", "treble_gain", "presence_gain")

SCORE_SCALE = 5

MEASUREMENT_DECIMALS = 2

# Fallback only. The adaptive test reports a real figure derived from how
# far it actually narrowed each band (see adaptive_test.confidence_score);
# this is used only if a caller somehow omits it.
FULL_CONFIDENCE = 100.0

CANDIDATE_POOL_SIZE = 15
RESULTS_SHOWN = 5


logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)s %(message)s",
)
log = logging.getLogger("equalizeme")


@app.errorhandler(mysql.connector.Error)
def handle_db_error(err):
    log.exception("Database error")
    return jsonify({
        "error": "The database is unavailable right now. Is MySQL running?"
    }), 503


@app.errorhandler(Exception)
def handle_unexpected_error(err):
    code = getattr(err, "code", None)
    if isinstance(code, int):
        return jsonify({"error": getattr(err, "description", "Request failed")}), code

    log.exception("Unhandled error")
    return jsonify({"error": "Something went wrong on the server."}), 500


def get_db():
    return mysql.connector.connect(**DB_CONFIG)


@contextmanager
def db_cursor(dictionary=False):
    conn = get_db()
    cur = conn.cursor(dictionary=dictionary)
    try:
        yield conn, cur
    finally:
        try:
            cur.close()
        finally:
            conn.close()


def _median(values):
    if not values:
        return 0.0
    ordered = sorted(values)
    mid = len(ordered) // 2
    if len(ordered) % 2:
        return ordered[mid]
    return (ordered[mid - 1] + ordered[mid]) / 2


def _catalog_baseline(iems, bands):
    return {band: _median([float(iem[band]) for iem in iems]) for band in bands}


@app.route("/recommendations/<int:user_id>", methods=["GET"])
def get_recommendations(user_id):
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
    cur.execute(
        "SELECT bass_gain, treble_gain, presence_gain FROM auditory_profiles "
        "WHERE user_id = %s ORDER BY created_at DESC LIMIT 1",
        (user_id,),
    )
    return cur.fetchone()


def fetch_iem_catalogue(cur):
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
    return all(iem[band] is not None for band in BANDS)


def centre_on_catalogue(iem, baseline):
    return {
        band: round(float(iem[band]) - baseline[band], MEASUREMENT_DECIMALS)
        for band in BANDS
    }


def agreement_score(preference_db, iem_db):
    return max(0, round(100 - abs(preference_db - iem_db) * SCORE_SCALE, 1))


def score_iem(iem, profile, baseline):
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
    best_matches = sorted(scored, key=lambda r: r["match_score"], reverse=True)
    candidates = best_matches[:CANDIDATE_POOL_SIZE]

    candidates.sort(key=price_sort_key)
    return candidates[:RESULTS_SHOWN]


def price_sort_key(recommendation):
    price = recommendation["price"]
    return (price is None, price if price is not None else 0)


@app.route("/api/iems/<int:iem_id>/curve", methods=["GET"])
def get_iem_curve(iem_id):
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

    try:
        curve = json.loads(row["fr_curve_json"])
    except (TypeError, ValueError):
        return jsonify({"error": "Stored curve data is not valid JSON"}), 500

    return jsonify({"curve": curve, "description": row["description"]})


@app.route("/api/quiz/questions", methods=["GET"])
def quiz_questions():
    return jsonify({"questions": pre_quiz.list_questions()})


@app.route("/api/dsp/adaptive/samples", methods=["GET"])
def dsp_adaptive_samples():
    return jsonify({"samples": adaptive_test.list_samples()})


def _missing_user_id():
    return jsonify({"error": "user_id is required"}), 400


@app.route("/api/dsp/adaptive/start", methods=["POST"])
def dsp_adaptive_start():
    data = request.get_json() or {}
    user_id = data.get("user_id")
    if not user_id:
        return _missing_user_id()

    camilla_dsp.start()

    seed = pre_quiz.score_answers(data.get("quiz")) if data.get("quiz") else None

    pair = adaptive_test.start_session(user_id, seed=seed)
    if seed:
        pair["seed"] = seed
    return jsonify(pair)


@app.route("/api/dsp/adaptive/play", methods=["POST"])
def dsp_adaptive_play():
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
    data = request.get_json()
    user_id = data.get("user_id")
    if not user_id:
        return _missing_user_id()

    result = adaptive_test.record_answer(user_id, data.get("preferred"))

    if "error" in result:
        return jsonify(result), 400

    if result.get("done"):
        _save_dsp_profile(user_id, result["profile"], result.get("confidence"))

    return jsonify(result)


def _save_dsp_profile(user_id, profile, confidence=None):
    with db_cursor() as (conn, cur):
        # A plain insert: every completed test appends a row, which is what
        # api/profile/history.php and api/profile/compare.php need in order
        # to show how a listener's preferences change over time.
        #
        # This previously carried ON DUPLICATE KEY UPDATE, which was doing
        # real work — auditory_profiles had a UNIQUE index on user_id named
        # `unique_user_profile`, so each retake overwrote the previous row
        # and history never accumulated. That index has since been dropped
        # (see sql/add_profile_history.sql), leaving only PRIMARY on id, so
        # there is nothing left for a retake to collide with.
        #
        # If a duplicate-key error ever appears here, a UNIQUE index on
        # user_id has been reintroduced; drop it rather than restoring the
        # clause, or history silently breaks again.
        cur.execute(
            """
            INSERT INTO auditory_profiles
                (user_id, bass_gain, treble_gain, presence_gain, confidence_score)
            VALUES (%s, %s, %s, %s, %s)
            """,
            (
                user_id,
                profile["bassGain"],
                profile["trebleGain"],
                profile["presenceGain"],
                FULL_CONFIDENCE if confidence is None else confidence,
            ),
        )
        conn.commit()


if __name__ == "__main__":
    if DEBUG_MODE:
        print("WARNING: FLASK_DEBUG=1 — the interactive debugger is ON. "
              "Only run this if the service is NOT reachable from outside "
              "your own machine.")

    print(db_config.describe_source())
    print(f"binding to {DEFAULT_HOST}:{DEFAULT_PORT} "
          f"(requests arrive via api/dsp.php)")

    app.run(host=DEFAULT_HOST, port=DEFAULT_PORT, debug=DEBUG_MODE)
