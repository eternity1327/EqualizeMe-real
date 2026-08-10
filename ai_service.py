"""
EqualizeME AI Service (Flask)
Handles the adaptive listening assessment flow.
Run with: python ai_service.py  (defaults to http://127.0.0.1:5001)
"""

from flask import Flask, request, jsonify
from flask_cors import CORS
import mysql.connector
import os
import camilla_dsp
import adaptive_test

app = Flask(__name__)
CORS(app)  # allows requests from the PHP app on a different origin/port

DB_CONFIG = {
    "host": os.environ.get("DB_HOST", "127.0.0.1"),
    "user": os.environ.get("DB_USER", "root"),
    "password": os.environ.get("DB_PASSWORD", ""),
    "database": os.environ.get("DB_NAME", "equalizeme"),
}


def get_db():
    return mysql.connector.connect(**DB_CONFIG)


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


@app.route("/recommendations/<int:user_id>", methods=["GET"])
def get_recommendations(user_id):
    """
    Finds the user's most recent auditory profile, compares it against
    every IEM using absolute difference across bass/treble/presence,
    and returns the top 5 closest matches with retailer links.
    """
    conn = get_db()
    cur = conn.cursor(dictionary=True)

    # Get the most recent profile for this user
    cur.execute(
        "SELECT bass_gain, treble_gain, presence_gain FROM auditory_profiles "
        "WHERE user_id = %s ORDER BY updated_at DESC LIMIT 1",
        (user_id,),
    )
    profile = cur.fetchone()

    if not profile:
        cur.close()
        conn.close()
        return jsonify({"error": "No auditory profile found for this user"}), 404

    # Pull every IEM with its retailer info
    cur.execute(
        """
        SELECT i.id, i.name, i.brand, i.sound_signature, i.bass_gain,
               i.treble_gain, i.presence_gain, i.price,
               r.name AS retailer_name, r.product_url
        FROM iems i
        LEFT JOIN retailers r ON i.retailer_id = r.retailer_id
        """
    )
    all_iems = cur.fetchall()
    cur.close()
    conn.close()

    results = []
    for iem in all_iems:
        distance = (
            abs(float(profile["bass_gain"]) - float(iem["bass_gain"]))
            + abs(float(profile["treble_gain"]) - float(iem["treble_gain"]))
            + abs(float(profile["presence_gain"]) - float(iem["presence_gain"]))
        )
        # Convert distance to a 0-100 score; scale factor of 10 keeps
        # realistic gain differences (a few dB) from bottoming out at 0.
        match_score = max(0, round(100 - (distance * 10), 1))

        results.append({
            "iem_id": iem["id"],
            "name": iem["name"],
            "brand": iem["brand"],
            "sound_signature": iem["sound_signature"],
            "price": float(iem["price"]) if iem["price"] is not None else None,
            "retailer_name": iem["retailer_name"],
            "product_url": iem["product_url"],
            "match_score": match_score,
        })

    results.sort(key=lambda r: r["match_score"], reverse=True)
    top_results = results[:5]

    return jsonify({"user_id": user_id, "recommendations": top_results})


@app.route("/api/dsp/adaptive/start", methods=["POST"])
def dsp_adaptive_start():
    """Starts CamillaDSP (if not running) and begins a new staircase session for this user."""
    data = request.get_json() or {}
    user_id = data.get("user_id")
    if not user_id:
        return jsonify({"error": "user_id is required"}), 400

    camilla_dsp.start()
    pair = adaptive_test.start_session(user_id)
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
    user_id is UNIQUE on this table (one profile per user), so this upserts
    instead of always inserting."""
    conn = get_db()
    cur = conn.cursor()
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
    cur.close()
    conn.close()


@app.route("/start-assessment", methods=["POST"])
def start_assessment():
    """Creates a new assessment row and returns the first question."""
    data = request.get_json()
    user_id = data.get("user_id")
    if not user_id:
        return jsonify({"error": "user_id is required"}), 400

    conn = get_db()
    cur = conn.cursor(dictionary=True)

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

    cur.close()
    conn.close()

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

    conn = get_db()
    cur = conn.cursor(dictionary=True)

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

        cur.close()
        conn.close()
        return jsonify({"complete": True, "assessment_id": assessment_id, "profile": profile})

    # Fetch the next question's details
    cur.execute(
        "SELECT question_id, audio_stimulus_ref, question_type "
        "FROM questions WHERE question_id = %s",
        (rule["next_question_id"],),
    )
    next_q = cur.fetchone()

    cur.close()
    conn.close()

    return jsonify({
        "complete": False,
        "question": next_q,
    })


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=5001, debug=True)
