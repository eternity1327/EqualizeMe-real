# Coding Standards

What this codebase follows, and how to verify it. Written so another
student, a professor, or a developer joining later can read the code
without needing to ask us anything.

---

## Verify it yourself

```bash
pip install flake8
python -m flake8 backend/
```

A clean run (no output) means the Python conforms to PEP 8. Configuration
is in `setup.cfg`.

We chose a checkable standard over a described one deliberately — a claim
in a document is worth less than a command anyone can run.

---

## Python — PEP 8

[PEP 8](https://peps.python.org/pep-0008/) is the official Python style
guide.

| Rule | What we do |
|---|---|
| Indentation | 4 spaces, never tabs |
| Line length | 99 characters (PEP 8's team-agreement option; comments near 72) |
| Naming | `snake_case` for functions and variables, `UPPER_CASE` for constants |
| Private helpers | Prefixed with `_` — `_median`, `_catalog_baseline` |
| Imports | Standard library, then third-party, then local |
| Blank lines | Two between top-level definitions, one between methods |

**Why 99 rather than 79:** PEP 8 permits a team to agree on up to 99. This
code has deliberately descriptive names (`presence_gain`,
`CANDIDATE_POOL_SIZE`, `catalogue_baseline`) and embedded SQL that reads
worse when chopped to fit 79. Comments and docstrings stay near 72, as
PEP 8 recommends.

## Docstrings — PEP 257

Every module and non-trivial function has a docstring. Ours answer
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

There's no linter configured for PHP, so these are followed by convention:

| Rule | What we do |
|---|---|
| Opening tag | `<?php`, never short tags |
| Indentation | 4 spaces |
| Naming | `snake_case` functions, `camelCase` variables, `UPPER_CASE` constants |
| Braces | Same line for control structures |
| File docblock | Every file opens with `/** ... */` explaining its role |

---

## Comment philosophy

The rule we apply: **comment the decision, not the mechanism.**

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
valid password is — and it's the *only* place that does, so signup and
password reset can't drift apart.

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
comment above that line in the source explains why in full.
