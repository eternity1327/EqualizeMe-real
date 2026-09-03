<?php
/**
 * The second step of logging in. One page, two modes:
 *
 *   enrol   — no authenticator set up yet. Shows the QR code, takes the
 *             first code as proof it works, then shows recovery codes once.
 *   verify  — already enrolled. Takes a six-digit code, or a recovery code.
 *
 * Which mode applies is decided here, from the database, not from anything
 * the browser sends. The endpoints behind it re-check the same thing, so a
 * user who edits the page cannot talk themselves into the wrong one.
 */
require_once __DIR__ . "/api/session.php";
require_once __DIR__ . "/api/csrf.php";
require_once __DIR__ . "/api/db.php";
start_secure_session();

// Two legitimate ways to be here:
//
//   mid-login   — the password was accepted and a code is owed
//   from Settings — already signed in, switching two-factor on
//
// Anything else (a bookmark, a timeout, someone poking at the URL) has
// neither and goes back to the start.
$userId = current_or_pending_user_id();
if ($userId === null) {
    header("Location: login.php");
    exit;
}

// Whether this is a full session matters: it decides where "cancel" goes
// and whether finishing enrolment needs to hand out a session or just
// confirm one that already exists.
$alreadySignedIn = isset($_SESSION["user_id"]);

$csrfToken = csrf_token();

// Same whitelist as login.php: never redirect to an arbitrary URL.
$allowedRedirects = [
    "index.html", "test.html",
    "recommendations.html", "profile.html", "settings.html"
];
$redirectTarget = $_REQUEST["redirect"] ?? "";
if (!in_array($redirectTarget, $allowedRedirects, true)) {
    $redirectTarget = "index.html";
}

$mode = "verify";
$userName = "";

try {
    $pdo = get_pdo();
    $stmt = $pdo->prepare("SELECT name, totp_confirmed_at FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();

    if (!$row) {
        clear_pending_login();
        header("Location: login.php");
        exit;
    }

    $userName = $row["name"];
    $enrolled = $row["totp_confirmed_at"] !== null;

    // Someone already signed in has nothing to prove — they hold a session.
    // The only reason for them to be here is to set two-factor up, and if
    // it is already on there is nothing to do.
    if ($alreadySignedIn) {
        if ($enrolled) {
            header("Location: settings.html");
            exit;
        }
        $mode = "enrol";
    } else {
        $mode = $enrolled ? "verify" : "enrol";
    }
} catch (PDOException $e) {
    error_log("two-factor.php: " . $e->getMessage());
    // Falling through in verify mode would ask for a code we cannot check.
    // Better to send them back than to present a form that cannot work.
    header("Location: login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Two-Factor Authentication — EqualizeME</title>
<script>
  if (localStorage.getItem("theme") === "dark") {
    document.documentElement.setAttribute("data-theme", "dark");
  }
</script>
<link rel="stylesheet" href="css/style.css?v=20260903">
<style>
  .tfa-step { display: none; }
  .tfa-step.active { display: block; }

  .tfa-lead {
    color: var(--text-muted);
    text-align: left;
    margin: 0 0 22px;
    line-height: 1.6;
  }

  .qr-holder {
    display: flex;
    justify-content: center;
    padding: 18px;
    background: #ffffff;
    border-radius: 18px;
    margin-bottom: 16px;
    min-height: 200px;
    align-items: center;
  }

  .qr-holder canvas, .qr-holder img { display: block; }

  .secret-box {
    background: var(--pill-bg);
    border-radius: 14px;
    padding: 14px 16px;
    margin-bottom: 20px;
    text-align: center;
  }

  .secret-box .label {
    display: block;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: .07em;
    color: var(--text-muted);
    margin-bottom: 6px;
  }

  .secret-value {
    font-family: "Courier New", monospace;
    font-size: 1.02rem;
    font-weight: 700;
    letter-spacing: .06em;
    word-break: break-all;
    user-select: all;
  }

  /* One box, six digits. inputmode brings up the number pad on a phone,
     which is where the code is being read from. */
  .code-input {
    font-family: "Courier New", monospace;
    font-size: 1.9rem;
    letter-spacing: .55em;
    text-align: center;
    text-indent: .55em;
  }

  .recovery-list {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 8px;
    background: var(--pill-bg);
    border-radius: 14px;
    padding: 16px;
    margin: 16px 0;
    font-family: "Courier New", monospace;
    font-size: 0.95rem;
    user-select: all;
  }

  .warn-box {
    background: #fdf3f3;
    border-left: 4px solid #c96a6a;
    border-radius: 10px;
    padding: 12px 14px;
    margin-bottom: 18px;
    font-size: 0.9rem;
    line-height: 1.55;
    text-align: left;
    color: #7a2c2c;
  }

  [data-theme="dark"] .warn-box { background: #3a2222; color: #f0c9c9; }

  .tfa-alt {
    background: none;
    border: none;
    color: var(--text-muted);
    text-decoration: underline;
    cursor: pointer;
    font-family: inherit;
    font-size: .9rem;
    padding: 0;
  }

  .tfa-alt:hover { color: var(--text); }

  @media (max-width: 520px) {
    .recovery-list { grid-template-columns: 1fr; }
  }
</style>
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

    <!-- ── ENROL: scan ────────────────────────────────────────────── -->
    <div class="tfa-step<?php echo $mode === 'enrol' ? ' active' : ''; ?>" id="step-scan">
      <h2 style="margin-top:0;">Set up two-factor</h2>
      <p class="tfa-lead">
        Hi <?php echo htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'); ?> —
        <?php echo $alreadySignedIn
            ? 'scan this with your authenticator app to add a code to your logins.'
            : 'your account needs an authenticator app before you can carry on. Scan this'; ?>
        <?php if (!$alreadySignedIn): ?>
        with Google Authenticator, Authy, 1Password, or any app that does
        six-digit codes.
        <?php endif; ?>
      </p>

      <div class="qr-holder" id="qr-holder">
        <span class="tfa-lead" style="margin:0;">Loading...</span>
      </div>

      <div class="secret-box">
        <span class="label">Or type this in by hand</span>
        <span class="secret-value" id="secret-text">&nbsp;</span>
      </div>

      <form class="auth-form active" id="activate-form" onsubmit="return handleActivate(event)">
        <label for="activate-code">Enter the 6-digit code it shows</label>
        <input id="activate-code" class="code-input" type="text" inputmode="numeric"
               autocomplete="one-time-code" maxlength="7" required autofocus>
        <button class="auth-submit" type="submit" id="activate-submit">Turn On</button>
      </form>

      <?php if ($alreadySignedIn): ?>
      <p class="auth-hint"><a href="settings.html">Cancel</a></p>
      <?php else: ?>
      <p class="auth-hint"><a href="logout.php">Cancel and log out</a></p>
      <?php endif; ?>
    </div>

    <!-- ── ENROL: recovery codes ──────────────────────────────────── -->
    <div class="tfa-step" id="step-recovery">
      <h2 style="margin-top:0;">Save your recovery codes</h2>

      <div class="warn-box">
        <strong>This is the only time these are shown.</strong> They are stored
        hashed, so nobody — including us — can read them back. Without your
        phone and without these, the account can only be unlocked by whoever
        administers the database.
      </div>

      <p class="tfa-lead">
        Each one works once, in place of a code from your app. Screenshot them,
        print them, or put them in a password manager.
      </p>

      <div class="recovery-list" id="recovery-list"></div>

      <form class="auth-form active" onsubmit="return handleRecoverySaved(event)">
        <label style="display:flex; gap:10px; align-items:flex-start; font-weight:normal;">
          <input type="checkbox" id="saved-check" style="width:auto; margin-top:4px;" required>
          <span>I have saved these somewhere safe</span>
        </label>
        <button class="auth-submit" type="submit">Continue</button>
      </form>
    </div>

    <!-- ── VERIFY ─────────────────────────────────────────────────── -->
    <div class="tfa-step<?php echo $mode === 'verify' ? ' active' : ''; ?>" id="step-verify">
      <h2 style="margin-top:0;">Two-factor</h2>
      <p class="tfa-lead">
        Hi <?php echo htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'); ?> — open
        your authenticator app and enter the current code.
      </p>

      <form class="auth-form active" id="verify-form" onsubmit="return handleVerify(event)">
        <label for="verify-code">6-digit code</label>
        <input id="verify-code" class="code-input" type="text" inputmode="numeric"
               autocomplete="one-time-code" maxlength="7" required
               <?php echo $mode === 'verify' ? 'autofocus' : ''; ?>>
        <button class="auth-submit" type="submit" id="verify-submit">Verify</button>
      </form>

      <p class="auth-hint">
        <button type="button" class="tfa-alt" onclick="showRecoveryEntry()">
          Lost your phone? Use a recovery code
        </button>
      </p>
      <p class="auth-hint"><a href="logout.php">Cancel and log out</a></p>
    </div>

    <!-- ── VERIFY: recovery fallback ──────────────────────────────── -->
    <div class="tfa-step" id="step-recovery-entry">
      <h2 style="margin-top:0;">Use a recovery code</h2>
      <p class="tfa-lead">
        One of the codes you saved when you set this up. Each works once.
      </p>

      <form class="auth-form active" onsubmit="return handleRecoveryLogin(event)">
        <label for="recovery-input">Recovery code</label>
        <input id="recovery-input" type="text" autocomplete="off"
               placeholder="XXXXXXXX-XXXXXXXX" required>
        <button class="auth-submit" type="submit" id="recovery-submit">Verify</button>
      </form>

      <p class="auth-hint">
        <button type="button" class="tfa-alt" onclick="showStep('step-verify')">
          Back to entering a code
        </button>
      </p>
    </div>

    <p class="auth-status" id="auth-status"></p>

  </div>
</div>

<!-- Renders the QR in the browser. The secret is already on this page, so
     using a local renderer rather than an image service means it is never
     sent to a third party. If the CDN is unreachable the typed secret above
     still works, which is why it is always shown rather than hidden behind
     a "can't scan?" link. -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
const CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;
const REDIRECT_TARGET = <?php echo json_encode($redirectTarget); ?>;
const MODE = <?php echo json_encode($mode); ?>;

function setStatus(message, kind) {
  const el = document.getElementById('auth-status');
  el.textContent = message || '';
  el.className = 'auth-status' + (kind ? ' ' + kind : '');
}

function showStep(id) {
  document.querySelectorAll('.tfa-step').forEach(s => s.classList.remove('active'));
  document.getElementById(id).classList.add('active');
  setStatus('');
  const focusable = document.querySelector('#' + id + ' input:not([type=checkbox])');
  if (focusable) focusable.focus();
}

function showRecoveryEntry() { showStep('step-recovery-entry'); }

async function post(url, payload) {
  const res = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ ...payload, csrf_token: CSRF_TOKEN }),
  });
  return { res, data: await res.json() };
}

// ── enrolment ───────────────────────────────────────────────────────

async function loadSetup() {
  try {
    const { res, data } = await post('api/auth/2fa-setup.php', {});

    if (!res.ok) {
      if (data.next === 'verify') { showStep('step-verify'); return; }
      setStatus(data.error || 'Could not start setup.', 'error');
      return;
    }

    document.getElementById('secret-text').textContent = data.formatted;

    const holder = document.getElementById('qr-holder');
    holder.innerHTML = '';

    if (typeof QRCode === 'undefined') {
      holder.innerHTML =
        '<span class="tfa-lead" style="margin:0;">Could not load the QR code. ' +
        'Type the key below into your app instead.</span>';
      return;
    }

    new QRCode(holder, {
      text: data.uri,
      width: 190,
      height: 190,
      correctLevel: QRCode.CorrectLevel.M,
    });
  } catch (err) {
    setStatus('Could not reach the server. Is it running?', 'error');
  }
}

async function handleActivate(event) {
  event.preventDefault();
  const btn = document.getElementById('activate-submit');
  const code = document.getElementById('activate-code').value;

  btn.disabled = true;
  setStatus('Checking...');

  try {
    const { res, data } = await post('api/auth/2fa-activate.php', { code });

    if (!res.ok) {
      setStatus(data.error || 'Something went wrong.', 'error');
      btn.disabled = false;
      return false;
    }

    const list = document.getElementById('recovery-list');
    list.replaceChildren(...data.recovery_codes.map(code => {
      const div = document.createElement('div');
      div.textContent = code;
      return div;
    }));

    showStep('step-recovery');
  } catch (err) {
    setStatus('Could not reach the server. Is it running?', 'error');
    btn.disabled = false;
  }

  return false;
}

function handleRecoverySaved(event) {
  event.preventDefault();
  // The session is already complete at this point — activation finished the
  // login. This step exists only so the codes are not skipped past.
  window.location.href = REDIRECT_TARGET;
  return false;
}

// ── verification ────────────────────────────────────────────────────

async function handleVerify(event) {
  event.preventDefault();
  const btn = document.getElementById('verify-submit');
  const code = document.getElementById('verify-code').value;

  btn.disabled = true;
  setStatus('Checking...');

  try {
    const { res, data } = await post('api/auth/2fa-verify.php', { code });

    if (!res.ok) {
      if (data.next === 'enrol') { window.location.reload(); return false; }
      setStatus(data.error || 'Something went wrong.', 'error');
      document.getElementById('verify-code').value = '';
      document.getElementById('verify-code').focus();
      btn.disabled = false;
      return false;
    }

    window.location.href = REDIRECT_TARGET;
  } catch (err) {
    setStatus('Could not reach the server. Is it running?', 'error');
    btn.disabled = false;
  }

  return false;
}

async function handleRecoveryLogin(event) {
  event.preventDefault();
  const btn = document.getElementById('recovery-submit');
  const recovery_code = document.getElementById('recovery-input').value;

  btn.disabled = true;
  setStatus('Checking...');

  try {
    const { res, data } = await post('api/auth/2fa-verify.php', { recovery_code });

    if (!res.ok) {
      setStatus(data.error || 'Something went wrong.', 'error');
      btn.disabled = false;
      return false;
    }

    // Worth saying out loud — recovery codes are finite and do not renew.
    if (data.recovery_codes_remaining === 0) {
      alert('That was your last recovery code. Set two-factor up again from '
        + 'Settings to get a new set.');
    }
    window.location.href = REDIRECT_TARGET;
  } catch (err) {
    setStatus('Could not reach the server. Is it running?', 'error');
    btn.disabled = false;
  }

  return false;
}

// Codes are read off a screen and typed in a hurry; spaces are inevitable.
['activate-code', 'verify-code'].forEach(id => {
  const input = document.getElementById(id);
  if (input) {
    input.addEventListener('input', () => {
      input.value = input.value.replace(/[^\d]/g, '').slice(0, 6);
    });
  }
});

if (MODE === 'enrol') loadSetup();
</script>

<script src="js/script.js?v=20260903"></script>
</body>
</html>
