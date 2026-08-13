<?php
// NOTE: recommendations.html + js/script.js's loadRecommendations() is the
// version linked from the nav now. This standalone page is left working
// but isn't currently linked from anywhere in the UI.
require_once __DIR__ . "/api/session.php";
start_secure_session();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php?redirect=recommendations.html");
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
// Same host the page was loaded from, so this keeps working over the LAN
// or a public tunnel domain, not just on the machine running ai_service.py.
const AI_SERVICE_URL = `${window.location.protocol}//${window.location.hostname}:5001`;
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
                Price: ${iem.price !== null ? "$" + Number(iem.price).toFixed(2) : "N/A"}<br>
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
