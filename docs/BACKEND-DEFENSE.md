# Backend & Functionality — Defense Notes

Your section. What the back end does, how it works, and **why** each
decision was made — including the ones that started out wrong.

---

## Your one-paragraph opening

> The back end has two halves. PHP handles identity — accounts, sessions,
> security. Python handles intelligence — the adaptive test algorithm and
> the matching engine. They're split because they're good at different
> things: PHP is built for web sessions and runs natively under XAMPP,
> while Python is better for the numeric work of parsing measurement files
> and scoring matches. Both talk to the same MySQL database.

---

# 1. The adaptive test algorithm

**File:** `backend/adaptive_test.py`

## What it does

Finds a listener's preferred gain in three frequency bands using **ten**
forced-choice comparisons.

## How — binary search

For bass, the test starts at the extremes: **−6 dB versus +6 dB**.

```
Round 1:  −6 ......................... +6     listener picks the +6 side
Round 2:            0 ................ +6     picks 0
Round 3:            0 ...... +3               picks +3
Round 4:               +1.5 . +3              → settles ~+2.25 dB
```

Each answer discards half the remaining range. Four rounds take a 12 dB
span down to under 1 dB.

**Why binary search rather than a fixed questionnaire:** precision grows
*exponentially* with question count. A fixed list of settings would need
far more questions for the same resolution — and a test people won't
finish measures nothing.

## The 4 / 3 / 3 split

Ten questions across three bands doesn't divide evenly:

| Questions | Band | Final precision |
|---|---|---|
| 1–4 | Bass | ~0.75 dB |
| 5–7 | Presence | ~1.5 dB |
| 8–10 | Treble | ~1.5 dB |

**Why bass gets the extra one:** it's first, and bass preference varies
most between listeners.

**Admit the trade-off:** treble and presence *are* measured more coarsely.
Twelve questions (4/4/4) would equalise it; we chose ten to keep the test
short enough that people finish it.

## A different clip every question

Question 1 uses clip 1, question 2 uses clip 2 — ten clips, two excerpts
from each of five songs.

**Why:** judging everything on one track measures preference *for that
track*. Someone might want more bass on a bass-light recording without
wanting it generally.

**The cost, if asked:** consecutive rounds of the same search happen on
different audio, so the result is a preference averaged across material
rather than a precise per-track optimum. That's the intended trade-off.

---

# 2. The pre-quiz that seeds the test

**File:** `backend/pre_quiz.py`

Six written questions → a starting estimate → the search begins in a
**±3 dB window** around it instead of the full −6…+6.

**Why seed at all:** same ten questions, finer final answer.

**Why the window is ±3 and not tighter:** a tighter window trusts the
quiz more and converges faster, but a wrong guess becomes impossible to
escape — the true preference may lie outside it entirely. ±3 keeps half
the original range reachable, so the listening test can still overrule the
questionnaire. **The quiz is a hint, not a verdict.**

**Why the scoring weights aren't sent to the browser:** if users could see
that "Hip-hop / EDM" adds +2 bass, they could answer strategically instead
of honestly.

---

# 3. The matching engine — your strongest material

**File:** `backend/ai_service.py`, `get_recommendations()`

## The problem

First run: **the best match scored 11%.** Everything looked broken.

## The diagnosis

We were comparing two things that aren't the same quantity.

| | What it measures | Typical value |
|---|---|---|
| Listener profile | dB of EQ boost preferred, relative to flat | centred on **0** |
| IEM `bass_gain` | how much louder 20–250 Hz is than the midrange | median **+5.2** |

Every in-ear measurement contains **ear-canal resonance** — a lift near
3 kHz caused by the shape of ears, not by the earphone. Across our 123
IEMs the medians are bass **+5.2 dB**, presence **+4.4 dB**.

So a listener who preferred perfectly neutral EQ (0, 0, 0) scored a
distance of ~10 against *every* IEM. The ranking still ordered things, but
the percentage was meaningless and the "closest" matches were whichever
IEMs happened to have unusual measurements.

## The fix

Subtract the catalogue median before comparing:

```python
centred_gain = iem_gain − catalogue_median(band)
distance     = Σ |preference − centred_gain|
score        = max(0, 100 − distance × 5)
```

Zero now means "average IEM" on the measurement side, which is what "no
EQ change wanted" means on the listener side.

**Median, not mean** — the catalogue has genuine outliers past ±13 dB, and
a couple of extremes would drag a mean and skew everyone's results.

## The result

| Profile | Top match |
|---|---|
| Neutral | Truthear Hexa ($80) |
| Bass-lover | 7Hz Salnotes Zero 2 ($23) |
| Treble-lover | 7Hz Dioko ($100) |

Scores moved from 11% to the low 90s. More importantly, **three different
profiles now produce three genuinely different lists**, and those models
match their reputation among reviewers — an independent sanity check we
didn't design for.

---

## Ranking: cheapest good match first

Take the **15 best matches**, sort *those* by price, show the cheapest 5.

**Why not sort by match alone:** the best fits skewed expensive. The page
opened with $650–$7,500 flagships and read as "you can't afford our
advice".

**Why not sort by price alone:** that recommends cheap IEMs that don't
suit the listener, which defeats the point of running a test.

The compromise shows only genuinely good matches, affordable ones first.
The trade-off is visible — match percentages are on every card, so someone
can see they'd pay $170 instead of $35 for a few more points of fit.

---

# 4. The measurement pipeline

Four scripts turn public measurement data into database rows.

```
phone_book.json  →  catalog_parser.py     → names, prices, shop links
   REW .txt      →  measurement_parser.py → 3 numbers per IEM
   3 numbers     →  interpreter.py        → a plain-language sentence
   everything    →  import_to_db.py       → MySQL
```

## Curve → three numbers

```
bass_gain = avg(20–250 Hz) − avg(500–2000 Hz)
```

**Why relative to the midrange, not absolute loudness:** absolute SPL
depends on how loud the measurement rig was driven, not on the IEM's
tuning. Two measurements of the same earphone at different volumes would
give different absolute numbers but identical tonal balance. Subtracting
the midrange removes that.

## Why the descriptions are rule-based, not an LLM

`interpreter.py` maps three numbers to a sentence with fixed thresholds.

**Why:** deterministic, reproducible, free, and inspectable. An LLM would
produce different wording every run and couldn't be defended as a
measurement instrument. The interface is the same either way, so one could
be swapped in later — that was a choice, not a limitation.

## Why the thresholds are percentile-based

The original hand-picked thresholds produced **two sentences for the first
fifteen IEMs** — every one cleared the "forward presence" cutoff and
almost none reached either treble cutoff, because the thresholds assumed
gains scattered around zero.

We recalibrated them to percentiles of the real distribution, so labels
land on actual slices of the catalogue.

**Be upfront:** this makes labels *relative*. "Bass-boosted" means bassier
than ~80% of our 123 IEMs, not above a fixed dB figure.

---

# 5. Reliability work

Three bugs worth describing, because each is a *class* of problem.

## Connection leaks

Every database block closed its connection on the last line of the happy
path. Any exception in between leaked one permanently. MySQL allows 151 by
default — a recurring error would slowly exhaust the pool until the
service stopped answering and needed a restart.

**Nasty because** it looks like "the server randomly broke" rather than
pointing at the error that caused it.

**Fix:** a context manager releasing the connection in a `finally`, so it
comes back regardless of how the block exits.

## Duplicate data from missing constraints

Re-running the importer created a second copy of every IEM, because
nothing told MySQL those rows were the same ones.

**Fix:** upsert instead of insert, plus a `UNIQUE(brand, name)` constraint.

The same class of bug is still latent on `auditory_profiles`: without
`UNIQUE(user_id)`, retaking the test **appends** a profile instead of
replacing it. Nothing looks broken because we always read the newest — the
table just quietly grows.

**The lesson to state:** the code assumed constraints the schema didn't
enforce. Those are exactly the insertion and update anomalies normalization
theory warns about, found in our own database rather than a textbook.

## Errors returned HTML, not JSON

Every caller does `await res.json()`. Flask's default 500 is an HTML page,
so an exception made the front end fail a *second* time on the parse — and
the browser reported a parse error instead of the real fault.

**Fix:** handlers guaranteeing JSON. Database outage → 503 "is MySQL
running?"; anything else → 500, with the full traceback to the server log
rather than the browser.

---

# 6. Security — your section

| Threat | Defence | Why that way |
|---|---|---|
| Password theft | bcrypt | Deliberately slow, so brute force is impractical |
| SQL injection | Prepared statements everywhere | Values never become part of the SQL string |
| CSRF | Per-session tokens | `SameSite=Lax` covers most cases but isn't airtight |
| Brute force | Rate limit per IP **and** per account | Per-IP alone does nothing against an attacker spread over many addresses |
| Session hijacking | ID regenerated on login, rotates every 30 min | Limits how long a stolen ID stays useful |
| Account discovery | Identical responses for unknown emails | Including response *time* — see below |

## Two findings worth telling

**A forgotten registration page.** `register.php` was unlinked from the
nav but still fully working — no CSRF, no rate limiting, no password
policy, and it echoed raw database error messages to the browser, exposing
table and column names. `api/auth/register.php` had all four protections.
**Hardening lands on the path everyone remembers, not the one nobody
links to.** It's now a redirect, so there's one registration path to keep
secure instead of two.

**Timing revealed which emails were registered.** The check was:

```php
if (!$user || !password_verify($password, $user["password_hash"]))
```

PHP short-circuits, so for an unknown email `password_verify` never ran.
That function is *deliberately slow* — so unregistered addresses came back
measurably faster. Same error message, different response time. Timing it
produces a list of real accounts, which is what gets fed into
credential-stuffing attacks. Now a dummy hash comparison runs either way.

---

# 7. Questions you'll get

**"Why two back-end languages? Isn't that over-engineered?"**
Each is used where it's strongest. PHP handles sessions and auth natively
under XAMPP; Python handles the numeric work — parsing measurement files,
the search algorithm, scoring. Forcing either to do both would have been
more work, not less.

**"What makes the algorithm adaptive?"**
Each question depends on the previous answer. If you prefer more bass, the
next comparison narrows to the upper half of the range. It's not a fixed
script — two listeners answering differently get different questions.

**"How do you know the matching is correct?"**
We can't validate against ground truth without a listening panel. What we
can show: different profiles produce different, internally consistent
results, and the models surfaced match their reputation among reviewers.
We also caught and fixed a case where it *wasn't* correct — the ear-gain
offset — which is why we're confident about the current behaviour rather
than merely hopeful.

**"Why not store the full curve for matching instead of three numbers?"**
Three numbers is what the listening test can produce in ten questions. A
full-curve match would need a far longer test to be meaningful. We do
store the full curve — it's what the chart draws.

**"What happens if two people take the test at once?"**
Fine now. Test progress is tracked per user, and audio plays in each
person's own browser. It wasn't fine originally — server-side audio meant
one listener at a time — which is why we moved equalisation into the
browser.

---

# 8. Numbers to have ready

| | |
|---|---|
| Questions in the test | 10 (4 bass / 3 presence / 3 treble) |
| EQ range searched | −6 to +6 dB |
| Precision after 4 rounds | ~0.75 dB |
| Audio clips | 10 (2 excerpts × 5 songs) |
| IEMs in the database | 123 |
| Catalogue available | 588 IEMs, 152 brands |
| Match score improvement | 11% → low 90s |
| Filters | 100 Hz shelf, 3 kHz peak, 8 kHz shelf |
| Audio size reduction | 28 MB → 3.1 MB |
