<?php
session_start();
// Assumes $_SESSION['user_id'] is set after login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$userId = $_SESSION['user_id'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>EqualizeME — Listening Assessment</title>
<link rel="stylesheet" href="css/assessment.css">
</head>
<body>

<main class="assessment-container">
    <h1>Listening Assessment</h1>
    <div id="question-area">
        <p id="loading-msg">Loading your first question...</p>
    </div>
</main>

<script>
const AI_SERVICE_URL = "http://127.0.0.1:5001"; // adjust for production
const userId = <?php echo json_encode($userId); ?>;

let assessmentId = null;
let sequenceOrder = 1;

async function startAssessment() {
    const res = await fetch(`${AI_SERVICE_URL}/start-assessment`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ user_id: userId })
    });
    const data = await res.json();
    assessmentId = data.assessment_id;
    renderQuestion(data.question);
}

function renderQuestion(question) {
    const area = document.getElementById("question-area");
    area.innerHTML = `
        <p>Listen to the clip, then choose your preference:</p>
        <audio controls src="assets/audio/${question.audio_stimulus_ref}"></audio>
        <div class="answer-buttons">
            <button onclick="submitAnswer(${question.question_id}, 'A')">Option A</button>
            <button onclick="submitAnswer(${question.question_id}, 'B')">Option B</button>
        </div>
    `;
}

async function submitAnswer(questionId, answerValue) {
    const res = await fetch(`${AI_SERVICE_URL}/next-question`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
            assessment_id: assessmentId,
            question_id: questionId,
            answer_value: answerValue,
            sequence_order: sequenceOrder++
        })
    });
    const data = await res.json();

    if (data.complete) {
        document.getElementById("question-area").innerHTML =
            "<p>Assessment complete! Generating your auditory profile...</p>";
        // TODO: trigger AI profiling endpoint, then redirect to profile viewer
    } else {
        renderQuestion(data.question);
    }
}

startAssessment();
</script>

</body>
</html>
