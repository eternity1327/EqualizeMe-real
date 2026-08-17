# EqualizeME in One Paragraph

Read this first. It is the whole system — every file, what its functions
do, and why it exists — in a single pass.

---

EqualizeME asks a listener which of two equalised versions of a song they
prefer, ten times, and uses the answers to recommend in-ear monitors whose
measured frequency response matches that preference. The front door is
`index.html` with `login.php` handling both signing in and signing up
(`register.php` is now only a redirect, because a second forgotten
registration page had none of the security the main one did), and the
whole front end is styled by `css/` and driven by two JavaScript files:
`js/script.js`, which every page loads, holds the shared plumbing —
`getCsrfToken`/`invalidateCsrfToken` fetch and refresh the anti-forgery
token, `formatPrice` shows pesos with the dollar figure beside it,
`buildIemCard`/`buildBandMatch`/`buildBuyLinks` render each recommendation
card, `loadRecommendations` fetches the ranked list, `buildPreferenceCurve`
turns the listener's three numbers into a drawable line using Web Audio's
own filter maths, and `normaliseToMidband`/`curveDatasets`/
`curveChartOptions`/`renderIemCurve` draw that line against the IEM's
measured curve — while `js/adaptiveTest.js` runs the test itself on
`test.html`, where `initPicker` and `getCurrentUserId` identify the
listener, `beginQuiz`/`buildQuizQuestion`/`wireQuizHighlighting`/
`submitQuiz` present the written pre-quiz, `startTest`/`renderPair` show
each A/B pair, `getAudioContext`/`loadSample`/`playThroughBrowser` apply
the equalisation **in the listener's own browser** (the single most
important architectural decision in the project — the server used to do
it, which meant only one person at a time could actually hear anything
while everyone else's answers were still recorded), `playSide` with its
helpers `playLocally` and `playOnServer` chooses browser playback and
falls back to the server, `updateChoiceAvailability` keeps both cards
locked until both sides have been heard so nobody can answer about audio
they skipped, and `chooseSide`/`showDoneScreen` submit the answer and
finish; behind the pages sit two back ends that split the work by what
each language is good at — PHP for identity, Python for numbers. On the
PHP side, `api/db.php`'s `get_pdo` opens one properly configured database
connection with prepared statements forced on, `api/config.php`'s
`app_config`/`app_base_url` read credentials from the gitignored
`config.local.php` so nothing secret is ever committed, `api/session.php`
(`start_secure_session`, `session_cookie_options`, `end_secure_session`,
`request_is_https`, `enforce_https_if_configured`,
`_session_enforce_idle_timeout`, `_session_rotate_id_periodically`) gives
every page the same hardened session cookie and expires or rotates the
session ID so a stolen one has a short useful life, `api/csrf.php`
(`csrf_token`, `csrf_submitted_token`, `csrf_is_valid`,
`csrf_verify_or_fail`) plus `api/csrf-token.php` stop another site
submitting forms as a logged-in visitor, `api/rate_limit.php` (`client_ip`,
`_first_valid_ip`, `rate_limit_key`, `rate_limit_check`,
`rate_limit_record`, `rate_limit_clear`, `_since`, and the file helpers)
throttles by IP **and** by account — because per-IP alone does nothing
against an attacker spread across many addresses grinding one account, and
`client_ip` exists because behind a Cloudflare tunnel every visitor would
otherwise share one bucket — `api/password_policy.php`
(`password_problems` built from `_length_problems`,
`_composition_problems`, `_guessability_problems`, `_contains_personal_term`,
and `password_error_message`) is the single definition of a valid password
so signup and reset can never disagree, `api/mailer.php` (`send_email` with
`_mailer_load_phpmailer`, `_mailer_write_to_log`) sends reset mail over
SMTP and falls back to a log file when mail isn't configured, and the four
routes in `api/auth/` do the actual work: `login.php` verifies the password
against a dummy hash even when the account doesn't exist so response
*timing* can't reveal which emails are registered, `register.php` creates
the user plus default profile and settings rows, `request-reset.php`
(`reset_email_body`) emails a one-time token while always replying
identically so nobody can enumerate addresses, and `reset-password.php`
(`reset_is_usable`) redeems that token once, with `me.php`, `logout.php`,
`profile.php`, `settings.php` and `upload-picture.php` covering the
everyday account pages. On the Python side, `backend/ai_service.py` is the
Flask API: `get_db`/`db_cursor` hand out database connections that always
close themselves (they used to leak on any exception until MySQL ran out),
`handle_db_error`/`handle_unexpected_error` guarantee every failure comes
back as JSON rather than an HTML page the front end would choke on,
`/recommendations` is `get_recommendations` built from `fetch_latest_profile`,
`fetch_iem_catalogue`, `has_complete_measurement`, `centre_on_catalogue`,
`agreement_score`, `score_iem`, `rank_recommendations` and `price_sort_key`
— where `centre_on_catalogue` carries **the fix that made the whole system
work**, subtracting the catalogue's median gain to cancel the ear-canal
resonance every in-ear measurement contains, which is why match scores went
from 11% to the low 90s, and `rank_recommendations` takes the fifteen best
matches then shows the cheapest five so the page doesn't open with
flagships nobody can afford — while `get_iem_curve` serves a stored
measurement to the chart, `quiz_questions` serves the pre-quiz,
`dsp_adaptive_start`/`dsp_adaptive_play`/`dsp_adaptive_answer` drive the
test and `_save_dsp_profile` stores the result; `backend/adaptive_test.py`
is the test algorithm itself, a binary-search staircase where
`start_session` and `_bounds_for` set the starting range (narrowed if the
pre-quiz gave a hint), `get_current_pair` builds each A/B comparison so the
two sides differ in exactly one frequency band, and `record_answer` with
`_validate_answer`, `_log_answer`, `_narrow_bounds`, `_midpoint`,
`_finalize_param` and `_is_complete` throws away the rejected half of the
range each round — four rounds on bass and three each on presence and
treble, ten questions on ten different clips so the result is a preference
across music rather than for one track; `backend/pre_quiz.py`
(`list_questions`, `score_answers`, `_find_option`, `_clamp`) is the six
written questions whose hidden dB weights seed that search, deliberately
stripped of their weights before being sent to the browser so people can't
answer strategically; and the data pipeline is four scripts that turn
public measurement files into database rows —
`backend/fetch_measurements.py` (`load_entries`, `select_entries`,
`download_one`, `fetch_entry`, `channel_url`, `preview`) politely downloads
REW files from squig.link with permission and a delay between requests,
`backend/catalog_parser.py` (`load_catalog`, `_parse_price`,
`_parse_review_score`, `_normalize_files`, `_parse_phone`) survives the
messy real catalogue where a price might be "$??" or "Priceless",
`backend/measurement_parser.py` (`parse_rew_file`, `compute_gains`,
`_band_average`, `_relative_to`, `serialize_curve`) reduces a several-
hundred-point curve to three numbers measured relative to the midrange so
that how loudly the rig was driven doesn't matter,
`backend/interpreter.py` (`describe_curve`, `_classify`, `_join_phrases`)
turns those three numbers into a plain-English sentence using fixed
thresholds rather than an AI call because a measurement instrument has to
be reproducible, `backend/calibrate_interpreter.py` (`collect_gains`,
`percentile`, `build_thresholds`, `preview_labels`, `apply_to_interpreter`)
retunes those thresholds to percentiles of the real catalogue after the
hand-picked ones gave twelve of the first fifteen IEMs the same
description, and `backend/import_to_db.py` (`build_rows`, `_build_row`,
`find_measurement_files`, `average_curves`, `render_sql`, `run_import`,
`_sql_literal`) writes everything into MySQL — finding files by squig.link's
`Name L.txt`/`Name R.txt` convention, averaging the channels the way the
site itself does, upserting so a second import doesn't duplicate the
catalogue, and printing reviewable SQL first with escaping that survives
adversarial input; `backend/camilla_dsp.py` (`start`, `apply_filters`,
`_build_config`, `_biquad`, `stop`) is the older server-side audio path,
kept as a fallback; and the supporting files are `sql/` (`password_resets.sql`
already run, `schema_cleanup.sql` and `create_app_user.sql` still waiting),
`requirements.txt` and `setup.cfg` for the Python environment and its style
check, `phone_book.json` as the source catalogue, `measurements/` for the
downloaded curves, `data/audio/samples/` for the ten test clips, and
`docs/` — `SRS.md`, `FILE-GUIDE.md`, `DEFENSE-BRIEF.md`,
`BACKEND-DEFENSE.md` and `CODING-STANDARDS.md` — which now carry all the
reasoning that used to live in code comments.

---

## The five things to remember if you remember nothing else

1. **Browser-side EQ.** The listener's own browser applies the
   equalisation. Server-side meant one listener at a time, silence for
   everyone else, and answers recorded about audio nobody heard.

2. **The catalogue median.** `distance = Σ |preference − (iem_gain −
   catalogue_median)|`. Subtracting the median cancels ear-canal
   resonance. Without it every match scored near zero.

3. **Binary search, not a questionnaire.** Each answer discards half the
   remaining range, so precision grows exponentially with question count.
   Ten questions get bass to about 0.75 dB.

4. **Best fifteen, then cheapest five.** Match quality decides who's
   eligible; price decides the order. Neither alone gives a useful page.

5. **One code path per sensitive action.** One file creates users, one
   checks passwords, one defines what a valid password is. The forgotten
   second registration page is why.
