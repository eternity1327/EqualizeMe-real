# Coding Standards

What this codebase follows, and how to verify it. Written so another
student, a professor, or a developer joining later can read the code
without needing to ask us anything.

---

## Verify it yourself

```bash
pip install flake8
python -m flake8 backend/

node --check js/script.js
node --check js/adaptiveTest.js
```

A clean run (no output) means the Python conforms to PEP 8 and the
JavaScript parses. Configuration is in `setup.cfg`.

We chose a checkable standard over a described one deliberately — a claim
in a document is worth less than a command anyone can run.

---

## The eight rules we hold the code to

These are the principles the project is written against. Each one below
says what it means here, and where to look for it in the source.

### 1. Clear, honest names

A name should tell the truth about what the thing does, so a reader never
has to open the function to find out.

```python
def has_complete_measurement(iem):     # a yes/no question — reads as one
def rank_recommendations(scored):      # says what it returns
def agreement_score(preference_db, iem_db):
```

The parameter names carry units where units matter: `preference_db` and
`iem_db` are decibels, and saying so stops anyone passing a percentage in.

### 2. One job per function

`get_recommendations()` used to be 132 lines and did six things: fetch the
profile, fetch the catalogue, filter unusable rows, score, rank, and shape
the response. It is now six functions, each named after its one job:

```python
profile   = fetch_latest_profile(cur, user_id)
catalogue = fetch_iem_catalogue(cur)
scorable  = [iem for iem in catalogue if has_complete_measurement(iem)]
scored    = [score_iem(iem, profile, baseline) for iem in scorable]
return    ... rank_recommendations(scored)
```

The route now reads like a description of the algorithm. Each piece can be
tested on its own, which is how the scoring was verified without a
database.

### 3. Short functions

**Every function in `backend/` is under 40 lines; the median is 12.**
Nothing in `js/` is over 38.

Measure it:

```bash
python - <<'PY'
import ast, io, os
for root, _, files in os.walk("backend"):
    for f in files:
        if f.endswith(".py"):
            src = io.open(os.path.join(root, f), encoding="utf-8").read()
            for n in ast.walk(ast.parse(src)):
                if isinstance(n, ast.FunctionDef):
                    length = n.end_lineno - n.lineno + 1
                    if length > 40:
                        print(length, f, n.name)
PY
```

No output means the rule holds.

### 4. DRY — don't repeat yourself

The three EQ filters are the clearest case. They exist in `camilla_dsp.py`
(server playback), in `adaptiveTest.js` (browser playback), and in
`script.js` (drawing the preference curve). Three copies of
*100 Hz / 3 kHz / 8 kHz* would drift, and the test would then measure one
equaliser while the chart drew another.

`js/script.js` defines them once:

```javascript
const EQ_BANDS = [
  { type: "lowshelf", frequency: 100,  Q: 0.7, band: "bass_gain",     gainKey: "bassGain" },
  { type: "peaking",  frequency: 3000, Q: 1.4, band: "presence_gain", gainKey: "presenceGain" },
  { type: "highshelf", frequency: 8000, Q: 0.7, band: "treble_gain",  gainKey: "trebleGain" },
];
```

and `adaptiveTest.js` uses that list rather than its own.

Same idea in PHP: `password_policy.php` is the only file that defines what
a valid password is, so signup and password reset cannot disagree.

### 5. KISS — keep it simple

`_median()` is nine lines of plain Python instead of a numpy dependency,
because the project needs a median of about 300 numbers once per request.

`interpreter.py` maps three numbers to a sentence with fixed thresholds
rather than calling a language model. Deterministic, free, inspectable,
and defensible as a measurement instrument.

Where a clever line was replaced with a boring one, the boring one won:

```python
# clever — works, but you have to trace it
rows = thresholds[f"{band.split('_')[0].upper()}_THRESHOLDS"]

# boring — you just read it
rows = thresholds[THRESHOLD_NAMES[band]]
```

### 6. Handle special cases early

Guard clauses first, real work unindented at the bottom. Nothing in the
project nests more than three levels deep.

```python
def _build_row(entry, measurements_dir):
    stem = entry["primary_file"]
    if not stem:
        return None, "no file field"

    files = find_measurement_files(measurements_dir, stem)
    if not files:
        return None, f"expected '{stem} L.txt' / '{stem} R.txt'"

    curves = [c for c in (parse_rew_file(f) for f in files) if c]
    if not curves:
        return None, "measurement file(s) found but empty/unparseable"
    ...
```

Each failure is answered where it's discovered, and each carries the
reason with it — which is what makes the importer's skipped list useful
instead of just a count.

### 7. Named constants, not magic numbers

Every number that means something has a name, and the name is where the
explanation lives.

| Was | Now |
|---|---|
| `100 - distance * 5` | `SCORE_SCALE` |
| `results[:15]`, `[:5]` | `CANDIDATE_POOL_SIZE`, `RESULTS_SHOWN` |
| `rate_limit_check("login_account", 10, 900, $k)` | `LOGIN_ACCOUNT_MAX_ATTEMPTS`, `LOGIN_ACCOUNT_WINDOW_SECONDS` |
| `time() - 42000` | `COOKIE_DELETE_OFFSET` |
| `20 * Math.log10(mag[i])` | `DB_PER_DECADE * Math.log10(mag[i])` |
| `if (len(body) < 200)` | `MIN_MEASUREMENT_BYTES` |

Every rate limit in the project now lives in one block at the top of
`api/rate_limit.php`, so the whole policy can be read at once instead of
being reconstructed from numbers scattered across five route files.

### 8. Consistent style

| | Python | PHP | JavaScript |
|---|---|---|---|
| Indent | 4 spaces | 4 spaces | 2 spaces |
| Functions | `snake_case` | `snake_case` | `camelCase` |
| Variables | `snake_case` | `camelCase` | `camelCase` |
| Constants | `UPPER_CASE` | `UPPER_CASE` | `UPPER_CASE` |
| Private | `_leading_underscore` | `_leading_underscore` | — |
| Enforced by | flake8 | convention | convention |

**The one deliberate inconsistency:** the JSON travelling between Python
and JavaScript uses `camelCase` (`bassGain`), because it is consumed by
JavaScript. Python's own variables stay `snake_case`. The wire format
follows the consumer's convention; each language follows its own
internally.

---

## Python — PEP 8

[PEP 8](https://peps.python.org/pep-0008/) is the official Python style
guide.

**Why 99 characters rather than 79:** PEP 8 permits a team to agree on up
to 99. This code has deliberately descriptive names (`presence_gain`,
`CANDIDATE_POOL_SIZE`) and embedded SQL that reads worse when chopped to
fit 79. Comments and docstrings stay near 72, as PEP 8 recommends.

## Docstrings — PEP 257

Every module and non-trivial function has a docstring, and ours answer
**why**, not just what:

```python
def _catalog_baseline(iems, bands):
    """
    The 'average IEM' for each band, used to centre measured gains before
    comparing them to a listener's preference.

    Median rather than mean: the catalogue contains genuine outliers
    (measurements past -13 dB and +13 dB) and a couple of extreme entries
    would drag a mean far enough to skew everyone's results.
    """
```

The signature already says it takes IEMs and bands. What a reader can't
recover from the code is *why median beats mean here* — so that's what the
docstring carries.

## PHP — PSR-12 conventions

No linter is configured for PHP, so these are followed by convention:
`<?php` never short tags, 4-space indent, braces on the same line, and a
`/** ... */` docblock at the top of every file explaining its role.

---

## Comment philosophy

The rule: **comment the decision, not the mechanism.** If the code already
says it, deleting the comment makes the file better.

Bad — restates the code:

```php
// Loop through the columns
foreach ($columnMap as $key => $column) {
```

Good — explains a choice a reader would otherwise question:

```php
// Column names can't be parameterised — placeholders only work for
// values — so the name is interpolated into the SQL. That's safe here
// because the loop iterates THIS map's hardcoded values, not anything
// from the request.
```

The second tells you something the code cannot. That matters most where
code looks wrong but isn't, or looks fine but is load-bearing.

**Where we comment heaviest:**

- Anywhere the obvious approach was wrong (the ear-gain correction)
- Anywhere a constraint isn't visible locally (bcrypt's 72-byte limit)
- Anywhere a future edit could silently break something (the settings
  column allowlist)
- Any deliberate non-obvious choice (logout having no CSRF token)

**What we removed:** the comments were originally written as running notes
to ourselves — long narratives about what we'd tried, second-person asides,
and reminders to check things later. Those told the story of writing the
code rather than explaining the code, and they went stale the moment
anything changed. Each was cut to the one or two sentences a reader
actually needs.

---

## Structure

```
equalizeme-ai/
├── backend/          Python: test algorithm, matching, data pipeline
├── api/              PHP: authentication, sessions, settings
│   └── auth/         Login, register, logout, password reset
├── js/  css/         Front end
├── sql/              Schema scripts, run manually
├── docs/             Specification and defence material
├── data/audio/       Test clips
└── measurements/     Downloaded measurement files
```

**One responsibility per file.** `password_policy.php` defines what a
valid password is — and it's the *only* place that does.

**Configuration is separated from code.** `config.local.php` holds
credentials and is gitignored; `config.example.php` is the committed
template.

---

## Error handling

**Fail loudly in logs, quietly to users.**

```python
log.exception("Database error")
return jsonify({"error": "The database is unavailable right now."}), 503
```

The full traceback goes to the server log where it's useful. The user gets
a generic message, because exception text can contain table names, queries
and file paths.

**Always return the type the caller expects.** Every caller of the API
does `await res.json()`. An HTML error page makes the front end fail a
second time on the parse, hiding the real fault behind a parse error.

**Release resources in `finally`.** The `db_cursor` context manager exists
because connections were previously closed only on the happy path — any
exception leaked one permanently.

---

## Security conventions

| Rule | Applied |
|---|---|
| Never build SQL from input | Prepared statements everywhere; every query audited |
| Never store plaintext passwords | bcrypt via `password_hash()` |
| Never trust client-side validation | Every rule re-checked server-side |
| Never leak internals in errors | Details to log, generic message to user |
| Never commit secrets | `config.local.php` gitignored, history verified clean |
| One code path per sensitive action | Exactly one file creates users, one checks passwords |

That last one is a lesson we learned the hard way: a forgotten second
registration page had none of the protections the main one did.

---

## Testing

**Honest position:** this project did not use test-driven development.
Code was written first and verified after.

Verification was done with targeted scripts checking behaviour that's easy
to get wrong:

- The staircase produces exactly 10 questions across 10 unique clips
- A/B options always differ in exactly one band
- SQL escaping survives adversarial input (`Foo\' ; DROP TABLE iems;--`)
- Rate limiting blocks at the right attempt count
- The choice gate stays locked until both sides are heard
- Scoring, ranking and price ordering, run against a stub catalogue with
  a NULL measurement and a missing price included

Those are real checks, and they caught real bugs. They are not a
substitute for a test suite, and we'd say so if asked.

---

## For someone reading this code for the first time

1. `docs/FILE-GUIDE.md` — what every file does
2. `backend/ai_service.py` — the API, and `get_recommendations()` for the
   core algorithm
3. `backend/adaptive_test.py` — the listening test
4. `api/auth/login.php` — the security conventions in one short file

The single most important line in the project:

```python
distance = Σ |preference − (iem_gain − catalogue_median)|
```

Subtracting the catalogue median cancels ear-canal resonance, which every
in-ear measurement contains. Without it, every match scored near zero. The
docstring on `centre_on_catalogue()` explains why in full.
