<?php
session_start();
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
<title>EqualizeME — Your Recommendations</title>
</head>
<body>

<h1>Your IEM Recommendations</h1>
<div id="results">
    <p>Loading recommendations...</p>
</div>

<script>
const AI_SERVICE_URL = "http://127.0.0.1:5001";
const userId = <?php echo json_encode($userId); ?>;

async function loadRecommendations() {
    const res = await fetch(`${AI_SERVICE_URL}/recommendations/${userId}`);
    const data = await res.json();

    const container = document.getElementById("results");

    if (data.error) {
        container.innerHTML = `<p>${data.error}. Take the <a href="assessment.php">listening assessment</a> first.</p>`;
        return;
    }

    if (data.recommendations.length === 0) {
        container.innerHTML = "<p>No IEMs found in the database yet.</p>";
        return;
    }

    let html = "<ol>";
    data.recommendations.forEach(iem => {
        html += `
            <li>
                <strong>${iem.brand} ${iem.name}</strong> — ${iem.sound_signature ?? "N/A"}<br>
                Match score: ${iem.match_score}%<br>
                Price: ${iem.price !== null ? "₱" + iem.price : "N/A"}<br>
                ${iem.product_url ? `<a href="${iem.product_url}" target="_blank">Buy at ${iem.retailer_name ?? "retailer"}</a>` : "No retailer link available"}
            </li>
            <br>
        `;
    });
    html += "</ol>";
    container.innerHTML = html;
}

loadRecommendations();
</script>

</body>
</html>
