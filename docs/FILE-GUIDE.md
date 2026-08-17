# EqualizeME — What Every File Does

A tour of the codebase for the team. Each entry: what it's for, and a
concrete example.

---

## The big picture

```
Browser  ──► PHP (Apache)   ──► MySQL     accounts, sessions, security
   │
   └──────► Python (Flask)  ──► MySQL     test logic, recommendations
   │
   └──────► Web Audio API                 plays the test audio + EQ locally
```

**PHP** handles *who you are*. **Python** handles *what you like*. The
**browser** plays the audio.

---

# 1. Pages you can visit

### `index.html`
Landing page. Nav bar, logo, links to the test.

### `login.php`
Combined Log In / Sign Up in one page with tabs. Generates the CSRF token
and embeds it so the forms can prove they came from us.
*Example:* `login.php?tab=register` opens straight on the Sign Up tab.

### `forgot-password.php`
Asks for your email and triggers a reset link.
*Example:* type an unregistered address — you still get "if an account
exists, a link is on its way", so nobody can discover who's registered.

### `reset-password.php`
Where the emailed link lands. Takes `?token=...` and lets you set a new
password.
*Example:* `reset-password.php?token=3777324f6373e3c...`

### `logout.php`
Destroys the session and the cookie, sends you home. Six lines.

### `register.php`
**Now just a redirect** to `login.php?tab=register`. It used to create
accounts itself with no CSRF, no rate limiting and leaked database errors
— a second unprotected way in. Kept only so old links don't break.

### `test.html`
The listening test. Intro screen → six written questions → 10 A/B rounds
→ your profile.

### `recommendations.html`
Your top 5 IEMs, each with a chart, description, price and buy links.

### `profile.html`
Your saved auditory profile and profile picture.

### `settings.html`
Dark mode, auto-play, notifications.

### `assessment.php`, `recommendations.php`
**Legacy.** An older question-branching quiz and a plain-text
recommendations page. Superseded, unlinked from the nav, kept working so
we can point at the earlier approach when explaining why we changed.

---

# 2. Front-end code

### `js/script.js` (585 lines)
Shared browser code: nav, dark mode, CSRF token fetching, settings,
profile, and the whole recommendations page.

*Example — the preference curve on each chart:* your three numbers are fed
through the same filters the test used (`BiquadFilterNode`), producing a
curve drawn against the IEM's measured one. The dashed line is literally
the EQ you chose.

*Example — prices:* `formatPrice(80)` → `₱4,896 ($80.00)`. Both are shown
because the peso figure is a conversion, not a real local price.

### `js/adaptiveTest.js` (477 lines)
Runs the listening test: loads the quiz, sends answers, renders each
round, and **plays the audio with EQ in your browser**.

*Example:* pressing Play fetches `sample3.mp3`, decodes it once, caches
it, chains three filters with that side's gains, and plays. Pressing B
stops A first so they never overlap.

### `css/style.css` (1097 lines)
All styling. Uses CSS variables so dark mode is one attribute flip.

*Example:* `--accent` is orange in light mode, and every button, chart
line and link that references it changes automatically.

### `css/style-backup.css`
An old copy. Not loaded by anything — safe to delete.

---

# 3. PHP back end (`api/`)

### `api/db.php`
Opens the database connection. Reads credentials from `config.local.php`,
falls back to XAMPP defaults.
*Example:* `PDO::ATTR_EMULATE_PREPARES => false` means values are sent to
MySQL separately from the SQL, never glued into the query string.

### `api/session.php`
Every page's session starts here, so all of them get the same hardened
cookie settings instead of each file deciding for itself.
*Example:* sessions expire after 2 hours idle; the session ID rotates
every 30 minutes so a stolen one has a short life.

### `api/csrf.php` + `api/csrf-token.php`
Proves a request came from our own pages.
*Example:* another website POSTs to `settings.php` using your logged-in
session — without the token it's rejected with 403.

### `api/rate_limit.php`
Slows down brute force. Counts attempts per IP **and** per account.
*Example:* an attacker using 50 different IPs against one account still
gets blocked at 10 tries in 15 minutes, because the account is counted
separately.

### `api/password_policy.php`
One place defining what a valid password is, used by both signup and
reset so they can't drift apart.
*Example:* rejects `password123`, rejects anything containing your own
name, and rejects over 72 bytes because bcrypt silently ignores the rest.

### `api/config.php` / `config.example.php` / `config.local.php`
Settings. `config.local.php` holds real secrets (Gmail app password, DB
credentials) and is **gitignored**. `config.example.php` is the template
that's safe to commit.

### `api/mailer.php`
Sends password reset emails via PHPMailer.
*Example:* with SMTP unconfigured it writes the email to
`logs/sent-mail.log` instead — so the reset flow still works end to end
for testing without a mail account.

### `api/auth/` — 6 files
| File | Job |
|---|---|
| `register.php` | Create account (CSRF, rate limit, password policy) |
| `login.php` | Authenticate |
| `logout.php` | End session |
| `me.php` | "Who am I?" — used by every page |
| `request-reset.php` | Email a reset link |
| `reset-password.php` | Consume the token, set new password |

*Example — login:* even when the email doesn't exist, it still runs a
password check against a dummy hash. Otherwise unknown emails would
respond faster, and timing the response would reveal who's registered.

### `api/settings.php`, `api/profile.php`, `api/upload-picture.php`
Read/write settings, fetch the auditory profile, handle avatar uploads
(type and size checked, old file deleted).

---

# 4. Python back end

### `ai_service.py` (599 lines) — the Flask API
The brain. Serves the quiz, runs the test, scores recommendations.

*Example — the most important line in the project:*
```python
distance = Σ |preference − (iem_gain − catalogue_median)|
```
Subtracting the median cancels ear-canal resonance, which every
measurement contains. Without it every match scored near 0%.

*Example — `db_cursor`:* a context manager so connections close even when
something throws. Before it, one error leaked a connection permanently.

### `adaptive_test.py` (237 lines) — the test algorithm
Runs the 10-question staircase.

*Example:* for bass it plays −6 dB vs +6 dB; you pick; the range halves to
−6…0; then −3…0. Four rounds narrow 12 dB down to under 1 dB.

### `pre_quiz.py` (158 lines) — the written questionnaire
Six questions, each answer carrying hidden dB weights.

*Example:* "Hip-hop / EDM" adds +2 bass. The weights are never sent to
the browser, so nobody can reverse-engineer which answer "adds bass".

### `camilla_dsp.py` (115 lines) — server-side audio (fallback)
Drives CamillaDSP for playback on the server machine. Only used if the
browser can't do it. This was the original design — and the reason only
one person could take the test.

---

# 5. The measurement pipeline

Turns squig.link data into rows in the `iems` table.

### `fetch_measurements.py` (253 lines) — downloader
Fetches L/R measurement files.
*Example:* `--preset demo` grabs ten varied IEMs; 1-second delay between
requests, skips files you already have.

### `catalog_parser.py` (106 lines) — reads `phone_book.json`
Handles messy real data.
*Example:* `"$1,299.50"` → `1299.5`; `"$??"` and `"Free"` → `NULL`;
`"Tuned with Squiglink"` in a numeric field → `None` instead of crashing.

### `measurement_parser.py` (101 lines) — reads REW files
Turns ~300 frequency/SPL points into three numbers.
*Example:* `bass_gain = avg(20–250 Hz) − avg(500–2000 Hz)`. It's measured
*relative* to the midrange because absolute loudness depends on how loud
the rig was driven, not on the IEM's tuning.

### `interpreter.py` (89 lines) — the "AI" description
Turns three numbers into a sentence.
*Example:* `(6.5, 4.2, −1.8)` → *"This IEM has bass-boosted, balanced
presence, and smooth, rolled-off treble."* Rule-based on purpose:
deterministic, free, and defensible to a panel.

### `import_to_db.py` (370 lines) — the importer
Ties it together and writes to MySQL.
*Example:* run it with no flags and it **prints** the SQL; add `--live`
to execute. Re-running updates rows instead of duplicating them.

### `calibrate_interpreter.py` (219 lines) — threshold tuner
Sets the description cut-points from the real data.
*Example:* the original thresholds called every IEM "forward presence" —
12 of the first 15 got the same sentence. Percentile-based thresholds now
spread them evenly.

---

# 6. Data and assets

| Path | What |
|---|---|
| `phone_book.json` | squig.link catalogue — 588 IEMs, 152 brands |
| `measurements/` | 554 REW `.txt` files (downloaded, not authored) |
| `data/audio/samples/` | 10 clips as `.wav` (server) + `.mp3` (browser) |
| `images/` | Logo (light/dark) and the IEM placeholder icon |
| `uploads/` | User profile pictures |
| `lib/PHPMailer/` | Third-party email library |
| `camilladsp.exe` | Third-party DSP binary |

*Why both WAV and MP3:* the source WAVs are 24-bit, which browsers can't
decode. MP3 also drops the set from 28 MB to 3.1 MB — 45 classmates
downloading 28 MB over one home connection would be painful.

---

# 7. SQL scripts (`sql/`)

### `password_resets.sql`
Creates the reset-token table. **Already run.**

### `schema_cleanup.sql` — **not run yet**
Adds constraints the code already assumes.
*Example, and why it matters:* without `UNIQUE(user_id)` on
`auditory_profiles`, retaking the test **adds a row instead of replacing
one**. Nothing looks broken because we always read the newest — the table
just quietly grows.

### `create_app_user.sql` — **not run yet**
Creates a limited database account.
*Example:* the app currently connects as `root` with no password. The new
account can only SELECT/INSERT/UPDATE/DELETE on our tables — it can't
drop the database or create users.

---

# 8. Documentation

| File | Contents |
|---|---|
| `docs/SRS.md` | Software Requirements Specification — 60+ numbered requirements, use cases |
| `docs/FILE-GUIDE.md` | This file |
| `METHODOLOGY.md` | Why each design decision was made, and its limitations |
| `HOSTING-OPTIONS.md` | Comparison of hosting paths |
| `DEPLOYMENT.md` | Cloudflare Tunnel setup |
| `AUTH-SETUP.md` | Email/reset configuration |
| `requirements.txt` | Python dependencies |
| `.gitignore` | Keeps secrets and logs out of git |

---

# Quick answers

**"Where do I change how the test works?"** → `adaptive_test.py`

**"Where do I change how matching works?"** → `ai_service.py`,
`get_recommendations()`

**"Where do I change how it looks?"** → `css/style.css`

**"Where are the passwords?"** → nowhere readable — bcrypt hashes in the
`users` table. The SMTP password is in `config.local.php`, which is
gitignored.

**"What do I run to start it?"**
1. XAMPP: Apache + MySQL
2. `python ai_service.py`
3. Open `localhost/equalizeme-ai/`

**"What's safe to delete?"** → `css/style-backup.css`. Everything else is
used, legacy-but-referenced, or third-party.
