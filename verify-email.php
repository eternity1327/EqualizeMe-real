<?php
/**
 * The page the confirmation link opens.
 *
 * Does its work server-side and shows the result, rather than rendering a
 * page that then calls an API. The token is in the URL, and the fewer
 * places it travels the better.
 */
require_once __DIR__ . "/api/session.php";
require_once __DIR__ . "/api/csrf.php";
require_once __DIR__ . "/api/db.php";
require_once __DIR__ . "/api/email_verification.php";
start_secure_session();

$csrfToken = csrf_token();
$state = "invalid";

$token = $_GET["token"] ?? "";

if ($token !== "") {
    try {
        $pdo = get_pdo();
        $state = consume_verification_token($pdo, $token) !== null ? "ok" : "invalid";
    } catch (Throwable $e) {
        error_log("verify-email.php: " . $e->getMessage());
        $state = "error";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Confirm your email — EqualizeME</title>
<script>
  if (localStorage.getItem("theme") === "dark") {
    document.documentElement.setAttribute("data-theme", "dark");
  }
</script>
<link rel="stylesheet" href="css/style.css?v=20260905">
</head>
<body>

<nav class="navbar">
  <a href="index.html" class="logo">
    <img src="images/logo.png" alt="EqualizeME logo" class="logo-light">
    <img src="images/logo-dark.png" alt="EqualizeME logo" class="logo-dark">
  </a>
  <button id="themeToggle" class="theme-toggle" aria-label="Toggle dark mode">🌙</button>
</nav>

<div class="auth-wrap">
  <div class="auth-panel">

    <?php if ($state === "ok"): ?>

      <h2 style="margin-top:0;">Email confirmed</h2>
      <p class="auth-hint" style="text-align:left; margin-bottom:20px;">
        Thanks — your address is verified. You can log in now.
      </p>
      <p><a class="auth-submit" href="login.php"
            style="display:block; text-align:center; text-decoration:none;">Log In</a></p>

    <?php elseif ($state === "error"): ?>

      <h2 style="margin-top:0;">Something went wrong</h2>
      <p class="auth-hint" style="text-align:left;">
        We couldn't check that link just now. Try again in a moment.
      </p>
      <p class="auth-hint"><a href="login.php">Back to log in</a></p>

    <?php else: ?>

      <h2 style="margin-top:0;">That link didn't work</h2>
      <p class="auth-hint" style="text-align:left; margin-bottom:20px;">
        Confirmation links expire after 48 hours and can only be used once.
        If yours has run out, or you have already used it, put your email in
        below and we'll send a new one.
      </p>

      <form class="auth-form active" onsubmit="return handleResend(event)">
        <label for="resend-email">Email</label>
        <input id="resend-email" type="email" autocomplete="email" required>
        <button class="auth-submit" type="submit" id="resend-submit">Send a New Link</button>
      </form>

      <p class="auth-hint"><a href="login.php">Back to log in</a></p>

    <?php endif; ?>

    <p class="auth-status" id="auth-status"></p>

  </div>
</div>

<script src="js/script.js?v=20260905"></script>
<script>
const CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;

function setStatus(message, kind) {
  const el = document.getElementById('auth-status');
  el.textContent = message || '';
  el.className = 'auth-status' + (kind ? ' ' + kind : '');
}

async function handleResend(event) {
  event.preventDefault();
  const email = document.getElementById('resend-email').value.trim();
  const btn = document.getElementById('resend-submit');

  btn.disabled = true;
  setStatus('Sending...');

  try {
    const res = await fetch('api/auth/resend-verification.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email, csrf_token: CSRF_TOKEN }),
    });
    const data = await res.json();

    if (!res.ok) {
      setStatus(data.error || 'Something went wrong.', 'error');
      btn.disabled = false;
      return false;
    }

    setStatus(data.message, 'success');

    // Mail is not configured on this install, so the message went to a log
    // file. Only meaningful to whoever runs the server, and it does not
    // include the link itself.
    if (data.delivery === 'log') {
      setStatus(data.message + " (Email sending isn't set up on this server — "
        + "the message was written to logs/sent-mail.log instead.)", 'success');
    }
  } catch (err) {
    setStatus('Could not reach the server. Is it running?', 'error');
    btn.disabled = false;
  }

  return false;
}
</script>

</body>
</html>
