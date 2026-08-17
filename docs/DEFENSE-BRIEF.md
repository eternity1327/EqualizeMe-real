# EqualizeME — Defense Brief

A short guide to what the system does, how it's built, and why each choice
was made. Written to be spoken aloud, not read from.

---

## The one-sentence pitch

> People choose earphones by reading subjective reviews — "warm",
> "V-shaped", "musical". EqualizeME measures what a listener actually
> prefers through a blind listening test, then matches that against real
> laboratory measurements of 123 in-ear monitors.

---

## How it works — four steps

**1. A short questionnaire.** Six plain-language questions: what you
listen to, whether music ever sounds harsh, whether it feels thin. No
audio yet.

**2. A blind listening test.** Ten questions. Each plays the same music
clip twice with different equalisation, and you pick the one that sounds
better. You're never told which is which.

**3. A profile.** The answers become three numbers — how much bass, mid
presence and treble you prefer, in decibels.

**4. Recommendations.** Those numbers are compared against measured
frequency-response curves for 123 IEMs, and the best matches are shown
with charts, prices and buy links.

---

## Architecture — and why three parts

```
   Browser          PHP (Apache)         Python (Flask)        MySQL
   ────────         ────────────         ──────────────        ─────
   plays audio  →   who you are      →   what you like     →   storage
   applies EQ       accounts             test algorithm
   draws charts     sessions             matching engine
                    security
```

**Why split PHP and Python?** They're good at different things. PHP is
built for web sessions, authentication and form handling, and runs
natively under XAMPP. Python is better suited to the numeric work —
parsing measurement files, running the search algorithm, scoring matches.
Using each where it's strongest was simpler than forcing one to do both.

**Why the browser does the audio.** This is the most important
architectural decision, and it started as a mistake worth admitting.

---

## The decision we'd highlight

Originally the equalisation was applied **on the server**, using CamillaDSP.
It worked — for exactly one person, sitting at the server machine.

Everyone else heard **silence**. Worse, the test still recorded their
answers. Forty-five classmates could have "completed" it and produced
forty-five confident-looking profiles describing audio nobody heard.

We moved the equalisation into the browser using the **Web Audio API**, so
each listener hears it on their own headphones. Two gains:

- The test works for everyone, simultaneously
- It's *methodologically* correct — if everyone heard the same speakers,
  we'd be measuring those speakers as much as anyone's preference

---

## Front-end choices

| Choice | Why |
|---|---|
| **Plain HTML/CSS/JS**, no framework | The whole front end is two JS files. React or Vue would add a build step and dependencies for no benefit at this size. |
| **Web Audio API** | Applies EQ in the browser using the same filter definitions as the server: 100 Hz low shelf, 3 kHz peak, 8 kHz high shelf. |
| **Chart.js** | Draws the frequency-response comparison. Loaded from a CDN; if it fails, the written description still shows. |
| **CSS variables** | Dark mode is one attribute change rather than a second stylesheet. |
| **MP3 audio** | The source WAVs are 24-bit, which browsers can't decode. MP3 also cut the set from 28 MB to 3.1 MB — significant when 45 people download it. |

---

## Back-end choices

| Choice | Why |
|---|---|
| **Binary search (staircase)** | Halving the range each round reaches ~0.75 dB precision in 4 questions. A fixed questionnaire would need far more questions for the same resolution. |
| **A different clip per question** | Judging everything on one track measures preference *for that track*. Ten clips from five songs measures preference across material. |
| **Questionnaire seeds the test** | The written answers narrow the starting range, so the same 10 questions land on a finer result. |
| **Rule-based descriptions, not an LLM** | Deterministic, reproducible, free, and explainable. An LLM would give different answers each run and couldn't be defended as a measurement instrument. |
| **bcrypt for passwords** | Deliberately slow, which is what makes brute force impractical. |
| **Prepared statements everywhere** | Values never become part of the SQL string. |

---

## The technical problem we're proudest of solving

When matching first ran, the **best match scored 11%**. Everything looked
broken.

The cause: we were comparing two things that aren't the same quantity.

- A listener's preference is an **EQ setting** — dB relative to flat,
  centred on **0**
- An IEM's measurement is a **deviation from its own midrange**, which
  includes **ear-canal resonance** — a lift near 3 kHz present in *every*
  in-ear measurement, because it's a property of ears, not of the earphone

Across our 123 IEMs the median bass reading is **+5.2 dB** and presence
**+4.4 dB** — on every single one. So a listener who preferred perfectly
neutral EQ scored a distance of ~10 against everything, capping all
matches near zero.

**The fix:** subtract the catalogue median before comparing, so zero means
"average IEM" on both sides.

**The result:** best matches went from 11% to the low 90s, and — the real
test — three different preference profiles now return three genuinely
different lists. A neutral listener gets the Truthear Hexa; a bass-lover
gets the 7Hz Zero 2; a treble-lover gets the 7Hz Dioko. Those match what
the audio community says about those models.

---

## Security — the short version

| Area | What we did |
|---|---|
| Passwords | bcrypt hashing; strength rules; blocklist of common passwords |
| Sessions | Regenerated on login, 2-hour idle expiry, ID rotation every 30 min |
| SQL injection | Prepared statements; audited every query in the project |
| CSRF | Tokens on every state-changing request |
| Brute force | Rate limited per IP **and** per account |
| Account discovery | Login and password reset respond identically for unknown emails — including response *time* |
| Secrets | Credentials in a gitignored file; verified never committed |

**Worth mentioning if asked:** we found and removed a second, unprotected
registration page that bypassed all of the above and leaked database error
messages to the browser. Hardening usually lands on the path everyone
remembers, not the forgotten one.

---

## Limitations we'd raise before they do

- **Match scores are relative to our catalogue**, not absolute. They order
  IEMs well; the percentage isn't a physical measurement.
- **Descriptions are percentile-based.** "Bass-boosted" means bassier than
  ~80% of our 123 IEMs, not above a fixed threshold.
- **The frequency bands don't exactly match** between the test's filters
  (100 Hz / 3 kHz / 8 kHz) and the measurement analysis (20–250 / 2–6k /
  6–16k). Related quantities, not identical ones.
- **One measurement per IEM.** No averaging across units or fit positions.
- **Treble and presence get 3 questions, bass gets 4** — ten doesn't divide
  by three, so bass converges more precisely.
- **No hearing calibration.** Age-related high-frequency loss and headphone
  quality both affect answers and aren't recorded.

---

## Likely questions

**"Isn't this just an EQ app?"**
No — an EQ app changes how your current earphones sound. This measures
what you prefer and tells you which earphones already sound that way, so
you don't need EQ.

**"Where does the measurement data come from?"**
Squig.link, run by Mark Ryan Sallee. We have written permission for
non-commercial use. 123 IEMs imported.

**"What makes it AI-assisted?"**
The adaptive algorithm: each question is chosen based on the previous
answer, narrowing the search rather than following a fixed script. The
interpreter module then converts numeric results into plain language. Both
are deterministic and inspectable — we chose that over an LLM specifically
so results are reproducible and defensible.

**"How do you know the recommendations are correct?"**
We can't validate against ground truth without a listening panel. What we
can show: different profiles produce different, internally consistent
results, and the IEMs surfaced for each profile match their reputation
among reviewers.

**"Can it handle our whole class?"**
Yes. Registration, login and recommendations are ordinary web operations.
The audio is the part that used to limit us to one person, and moving EQ
into the browser removed that.

---

## Demo order

1. Register a new account — show the password rules rejecting a weak one
2. Take the test — play A and B, point out you can't choose until both
   are heard
3. Show the profile — three numbers
4. Show recommendations — charts with your preference overlaid
5. Log out, use "Forgot password", complete a real reset by email
