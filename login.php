<?php
require_once __DIR__ . "/api/session.php";
require_once __DIR__ . "/api/csrf.php";
start_secure_session();

// Generated here and embedded in the page below, so the login/signup
// forms don't need a separate round-trip to fetch it.
$csrfToken = csrf_token();

// Only allow redirecting to known pages in this project — never to an
// arbitrary URL, to avoid this being abused as an open redirect.
// index.html is the fallback default; test.html is the sound test.
$allowedRedirects = [
    "index.html", "test.html",
    "recommendations.html", "profile.html", "settings.html"
];
$redirectTarget = $_REQUEST["redirect"] ?? "";

if ($redirectTarget === "") {
    // No explicit ?redirect= was passed — this happens when someone clicks
    // the plain "Login" nav link (it doesn't set one) or gets bounced here
    // by a page that requires auth. Fall back to whatever page they were
    // actually on, via the Referer header, instead of always dumping them
    // on a hardcoded page.
    $referer = $_SERVER["HTTP_REFERER"] ?? "";
    $refPath = $referer !== "" ? parse_url($referer, PHP_URL_PATH) : false;
    $redirectTarget = ($refPath !== false && $refPath !== null) ? basename($refPath) : "";
}

if (!in_array($redirectTarget, $allowedRedirects, true)) {
    $redirectTarget = "index.html";
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
      <p class="auth-hint"><a href="forgot-password.php">Forgot your password?</a></p>
      <p class="auth-hint">New here? <a href="#" onclick="switchTab('register'); return false;">Create an account</a></p>
    </form>

    <form class="auth-form<?php echo $initialTab === 'register' ? ' active' : ''; ?>" id="register-form" onsubmit="return handleRegister(event)">
      <label for="reg-name">Name</label>
      <input id="reg-name" type="text" autocomplete="name" required>

      <label for="reg-email">Email</label>
      <input id="reg-email" type="email" autocomplete="email" required>

      <label for="reg-password">Password</label>
      <!-- maxlength matches bcrypt's 72-byte limit; see api/password_policy.php -->
      <input id="reg-password" type="password" autocomplete="new-password" minlength="8" maxlength="72" required>

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

// Session-bound token proving these requests came from this page, not
// from another site posting to our API on your behalf.
const CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;

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

// Shown only after a correct password on an unconfirmed account, so it
// never appears in response to a guess.
function showResendLink(email) {
  if (document.getElementById('resend-inline')) return;

  const wrap = document.createElement('p');
  wrap.className = 'auth-hint';
  wrap.id = 'resend-inline';

  const link = document.createElement('a');
  link.href = '#';
  link.textContent = 'Send me the confirmation link again';
  link.onclick = async (e) => {
    e.preventDefault();
    link.textContent = 'Sending...';
    try {
      const res = await fetch('api/auth/resend-verification.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email, csrf_token: CSRF_TOKEN }),
      });
      const data = await res.json();
      setStatus(data.message || data.error || 'Done.',
                res.ok ? 'success' : 'error');
    } catch (err) {
      setStatus('Could not reach the server.', 'error');
    }
    link.textContent = 'Send it again';
    return false;
  };

  wrap.appendChild(link);
  document.getElementById('auth-status').insertAdjacentElement('beforebegin', wrap);
}

// The second-factor page needs to know where the user was originally
// headed, so the detour does not lose the destination.
function goToSecondFactor(redirect) {
  const next = new URL(redirect || 'two-factor.php', window.location.href);
  next.searchParams.set('redirect', REDIRECT_TARGET);
  window.location.href = next.toString();
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
      body: JSON.stringify({ email, password, csrf_token: CSRF_TOKEN }),
    });
    const data = await res.json();

    if (!res.ok) {
      setStatus(data.error || 'Something went wrong.', 'error');
      // Password was right but the address is unconfirmed. Offer the way
      // out rather than leaving them stuck on an error.
      if (data.status === 'verify_email' && data.canResend) {
        showResendLink(email);
      }
      btn.disabled = false;
      return false;
    }

    // Two possible outcomes. Either the password was enough and a session
    // already exists, or this account has two-factor on and the server is
    // waiting for a code. The server decides; this just follows.
    if (data.status === '2fa_required') {
      setStatus(`Welcome back, ${data.name}. One more step...`, 'success');
      goToSecondFactor(data.redirect);
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
      body: JSON.stringify({ name, email, password, csrf_token: CSRF_TOKEN }),
    });
    const data = await res.json();

    if (!res.ok) {
      setStatus(data.error || 'Something went wrong.', 'error');
      btn.disabled = false;
      return false;
    }

    // Account exists but is not usable until the address is confirmed. No
    // session was granted, so there is nowhere to redirect to.
    if (data.status === 'verify_email') {
      setStatus(data.message, data.sent ? 'success' : 'error');
      return false;
    }

    if (data.status === '2fa_required') {
      setStatus(`Account created — welcome, ${data.name}. One more step...`, 'success');
      goToSecondFactor(data.redirect);
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
