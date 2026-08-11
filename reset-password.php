<?php
/**
 * Landing page for the link in the reset email. Takes ?token=... and lets
 * the user set a new password.
 *
 * The token is only validated when the form is submitted — this page
 * doesn't check it up front on purpose, so that simply loading the page
 * can't be used to burn through tokens or probe which ones are real.
 */
require_once __DIR__ . "/api/session.php";
require_once __DIR__ . "/api/csrf.php";
start_secure_session();
$csrfToken = csrf_token();

$token = $_GET["token"] ?? "";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Choose a New Password — EqualizeME</title>
<script>
  if (localStorage.getItem("theme") === "dark") {
    document.documentElement.setAttribute("data-theme", "dark");
  }
</script>
<link rel="stylesheet" href="css/style.css">
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

    <h2 style="margin-top:0;">Choose a new password</h2>

    <?php if ($token === ""): ?>
      <p class="auth-hint" style="text-align:left;">
        This page needs a reset link to work. Open the link from your email,
        or <a href="forgot-password.php">request a new one</a>.
      </p>
    <?php else: ?>
      <p class="auth-hint" style="text-align:left; margin-bottom:20px;">
        Pick something at least 8 characters long, with upper and lowercase
        letters and a number.
      </p>

      <form class="auth-form active" id="reset-form" onsubmit="return handleReset(event)">
        <label for="new-password">New password</label>
        <input id="new-password" type="password" autocomplete="new-password" minlength="8" required>

        <label for="confirm-password">Confirm new password</label>
        <input id="confirm-password" type="password" autocomplete="new-password" minlength="8" required>

        <button class="auth-submit" type="submit" id="reset-submit">Update Password</button>
        <p class="auth-hint"><a href="login.php">Back to log in</a></p>
      </form>
    <?php endif; ?>

    <p class="auth-status" id="auth-status"></p>

  </div>
</div>

<script src="js/script.js"></script>
<script>
const CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;
const RESET_TOKEN = <?php echo json_encode($token); ?>;

function setStatus(message, kind) {
  const el = document.getElementById('auth-status');
  el.textContent = message || '';
  el.className = 'auth-status' + (kind ? ' ' + kind : '');
}

async function handleReset(event) {
  event.preventDefault();
  const password = document.getElementById('new-password').value;
  const confirm = document.getElementById('confirm-password').value;
  const btn = document.getElementById('reset-submit');

  // Checked here rather than server-side because it's purely about the
  // user typing consistently, not about trusting the client.
  if (password !== confirm) {
    setStatus('The two passwords don\'t match.', 'error');
    return false;
  }

  btn.disabled = true;
  setStatus('Updating your password...');

  try {
    const res = await fetch('api/auth/reset-password.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token: RESET_TOKEN, password, csrf_token: CSRF_TOKEN }),
    });
    const data = await res.json();

    if (!res.ok) {
      setStatus(data.error || 'Something went wrong.', 'error');
      btn.disabled = false;
      return false;
    }

    setStatus(data.message + ' Redirecting you to log in...', 'success');
    document.getElementById('reset-form').style.display = 'none';
    setTimeout(() => { window.location.href = 'login.php'; }, 2500);
  } catch (err) {
    setStatus('Could not reach the server. Is it running?', 'error');
    btn.disabled = false;
  }

  return false;
}
</script>

</body>
</html>
