<?php
session_start();

// Only allow redirecting to known pages in this project — never to an
// arbitrary URL, to avoid this being abused as an open redirect.
$allowedRedirects = [
    "assessment.php", "index.html", "test.html",
    "recommendations.html", "profile.html", "settings.html"
];
$redirectTarget = $_REQUEST["redirect"] ?? "";

if ($redirectTarget === "") {
    // No explicit ?redirect= was passed — this happens when someone clicks
    // the plain "Login" nav link (it doesn't set one) or gets bounced here
    // by a page that requires auth. Fall back to whatever page they were
    // actually on, via the Referer header, instead of always dumping them
    // on assessment.php.
    $referer = $_SERVER["HTTP_REFERER"] ?? "";
    $refPath = $referer !== "" ? parse_url($referer, PHP_URL_PATH) : false;
    $redirectTarget = ($refPath !== false && $refPath !== null) ? basename($refPath) : "";
}

if (!in_array($redirectTarget, $allowedRedirects, true)) {
    $redirectTarget = "assessment.php";
}

// Lets a link jump straight to the Sign Up tab, e.g. login.php?tab=register
$initialTab = ($_GET["tab"] ?? "") === "register" ? "register" : "login";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Log in or Sign up — EqualizeME</title>
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

    <div class="auth-tabs">
      <button type="button" class="auth-tab<?php echo $initialTab === 'login' ? ' active' : ''; ?>" id="tab-login" onclick="switchTab('login')">Log In</button>
      <button type="button" class="auth-tab<?php echo $initialTab === 'register' ? ' active' : ''; ?>" id="tab-register" onclick="switchTab('register')">Sign Up</button>
    </div>

    <form class="auth-form<?php echo $initialTab === 'login' ? ' active' : ''; ?>" id="login-form" onsubmit="return handleLogin(event)">
      <label for="login-email">Email</label>
      <input id="login-email" type="email" autocomplete="email" required>

      <label for="login-password">Password</label>
      <input id="login-password" type="password" autocomplete="current-password" required>

      <button class="auth-submit" type="submit" id="login-submit">Log In</button>
      <p class="auth-hint">New here? <a href="#" onclick="switchTab('register'); return false;">Create an account</a></p>
    </form>

    <form class="auth-form<?php echo $initialTab === 'register' ? ' active' : ''; ?>" id="register-form" onsubmit="return handleRegister(event)">
      <label for="reg-name">Name</label>
      <input id="reg-name" type="text" autocomplete="name" required>

      <label for="reg-email">Email</label>
      <input id="reg-email" type="email" autocomplete="email" required>

      <label for="reg-password">Password</label>
      <input id="reg-password" type="password" autocomplete="new-password" minlength="8" required>

      <button class="auth-submit" type="submit" id="register-submit">Create Account</button>
      <p class="auth-hint">Already have an account? <a href="#" onclick="switchTab('login'); return false;">Log in</a></p>
    </form>

    <p class="auth-status" id="auth-status"></p>

  </div>
</div>

<script src="js/script.js"></script>
<script>
// Where to send the user after they successfully log in or register —
// computed server-side above from ?redirect= or the page they came from.
const REDIRECT_TARGET = <?php echo json_encode($redirectTarget); ?>;

function switchTab(which) {
  document.getElementById('tab-login').classList.toggle('active', which === 'login');
  document.getElementById('tab-register').classList.toggle('active', which === 'register');
  document.getElementById('login-form').classList.toggle('active', which === 'login');
  document.getElementById('register-form').classList.toggle('active', which === 'register');
  setStatus('');
}

function setStatus(message, kind) {
  const el = document.getElementById('auth-status');
  el.textContent = message || '';
  el.className = 'auth-status' + (kind ? ' ' + kind : '');
}

async function handleLogin(event) {
  event.preventDefault();
  const email = document.getElementById('login-email').value.trim();
  const password = document.getElementById('login-password').value;
  const btn = document.getElementById('login-submit');

  btn.disabled = true;
  setStatus('Logging in...');

  try {
    const res = await fetch('api/auth/login.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email, password }),
    });
    const data = await res.json();

    if (!res.ok) {
      setStatus(data.error || 'Something went wrong.', 'error');
      btn.disabled = false;
      return false;
    }

    setStatus(`Welcome back, ${data.name}!`, 'success');
    window.location.href = REDIRECT_TARGET;
  } catch (err) {
    setStatus('Could not reach the server. Is it running?', 'error');
    btn.disabled = false;
  }

  return false;
}

async function handleRegister(event) {
  event.preventDefault();
  const name = document.getElementById('reg-name').value.trim();
  const email = document.getElementById('reg-email').value.trim();
  const password = document.getElementById('reg-password').value;
  const btn = document.getElementById('register-submit');

  btn.disabled = true;
  setStatus('Creating your account...');

  try {
    const res = await fetch('api/auth/register.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name, email, password }),
    });
    const data = await res.json();

    if (!res.ok) {
      setStatus(data.error || 'Something went wrong.', 'error');
      btn.disabled = false;
      return false;
    }

    setStatus(`Account created — welcome, ${data.name}!`, 'success');
    window.location.href = REDIRECT_TARGET;
  } catch (err) {
    setStatus('Could not reach the server. Is it running?', 'error');
    btn.disabled = false;
  }

  return false;
}
</script>
</body>
</html>
