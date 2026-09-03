<?php
/**
 * "Forgot your password?" — asks for an email and triggers the reset link.
 * Styled to match login.php, reusing the same .auth-* classes.
 */
require_once __DIR__ . "/api/session.php";
require_once __DIR__ . "/api/csrf.php";
start_secure_session();
$csrfToken = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Forgot Password — EqualizeME</title>
<script>
  if (localStorage.getItem("theme") === "dark") {
    document.documentElement.setAttribute("data-theme", "dark");
  }
</script>
<link rel="stylesheet" href="css/style.css?v=20260903">
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

    <h2 style="margin-top:0;">Forgot your password?</h2>
    <p class="auth-hint" style="text-align:left; margin-bottom:20px;">
      Enter the email you signed up with and we'll send you a link to choose
      a new password.
    </p>

    <form class="auth-form active" id="forgot-form" onsubmit="return handleForgot(event)">
      <label for="forgot-email">Email</label>
      <input id="forgot-email" type="email" autocomplete="email" required>

      <button class="auth-submit" type="submit" id="forgot-submit">Send Reset Link</button>
      <p class="auth-hint"><a href="login.php">Back to log in</a></p>
    </form>

    <p class="auth-status" id="auth-status"></p>

  </div>
</div>

<script src="js/script.js?v=20260903"></script>
<script>
const CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;

function setStatus(message, kind) {
  const el = document.getElementById('auth-status');
  el.textContent = message || '';
  el.className = 'auth-status' + (kind ? ' ' + kind : '');
}

async function handleForgot(event) {
  event.preventDefault();
  const email = document.getElementById('forgot-email').value.trim();
  const btn = document.getElementById('forgot-submit');

  btn.disabled = true;
  setStatus('Sending...');

  try {
    const res = await fetch('api/auth/request-reset.php', {
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

    // Mail isn't configured on this install, so the message went to a log
    // file instead of an inbox. Only useful to whoever is running the
    // server, and it deliberately doesn't reveal the link itself.
    if (data.delivery === 'log') {
      setStatus(
        data.message + ' (Email sending isn\'t set up on this server — ' +
        'the message was written to logs/sent-mail.log instead.)',
        'success'
      );
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
