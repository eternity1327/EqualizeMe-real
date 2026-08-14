# EqualizeME — Methodology and Design Decisions

Written August 2026. This documents the choices behind how EqualizeME
measures preference and matches it to IEMs, including the ones that are
defensible but arguable. Each section states what was done, why, and what
someone could reasonably object to.

---

## 1. Measuring listener preference

### The test

Ten A/B comparisons. Each question plays the same audio clip twice, once
with each of two EQ settings, and the listener picks the one they prefer.
A and B differ in exactly one parameter; everything else stays at whatever
earlier questions settled on.

Three parameters are tuned, split 4 / 3 / 3:

| Questions | Parameter | Filter applied |
|---|---|---|
| 1–4 | Bass | Low shelf, 100 Hz, Q 0.7 |
| 5–7 | Presence | Peaking, 3 kHz, Q 1.4 |
| 8–10 | Treble | High shelf, 8 kHz, Q 0.7 |

Each parameter is found by **binary search**. The first question offers the
extremes of the range (−6 dB against +6 dB); whichever the listener prefers
becomes the new bound, and the range halves. Four rounds narrow a 12 dB
span to roughly 0.75 dB.

### Why binary search rather than a fixed questionnaire

A fixed list of settings would need many more questions to reach the same
resolution. Halving the range each round means precision grows
exponentially with question count, which is what makes a useful result
possible in ten questions — short enough that people finish it.

### Every question uses a different clip

The ten clips are two excerpts from each of five songs. Question 1 uses
clip 1, question 2 uses clip 2, and so on.

This is deliberate. Judging every question on one track would measure
preference *for that track* — a listener might like extra bass on a
bass-light recording without wanting it generally. Rotating material means
the final profile reflects preference across varied programme material.

**The cost:** consecutive rounds of the same binary search happen on
different audio. A listener could prefer +6 dB bass on question 1's clip
and −6 dB on question 2's, and the search would still narrow as though
those answers were about the same thing. The result is a preference
averaged over material rather than a precise per-track optimum. That's the
intended trade-off, but it is a trade-off.

### The 4 / 3 / 3 split

Ten questions across three parameters cannot divide evenly. Bass gets the
extra round because it is first and because bass preference varies most
between listeners. Consequently **treble and presence converge to a
coarser value than bass** — roughly 1.5 dB rather than 0.75 dB.

Twelve questions (4/4/4) would equalise this. Ten was chosen to keep the
test short.

---

## 2. The pre-quiz

Before the listening test, six written questions ask about genre, ideal
sound signature, vocal placement, sensitivity to harshness, perceived lack
of punch, and listening environment. Each answer carries small dB weights
that sum to a starting estimate per band.

That estimate **seeds** the binary search: instead of starting at the full
−6…+6, each parameter starts in a ±3 dB window around the estimate. Same
number of questions, finer final result.

### Why the window is ±3 and not tighter

A tighter window trusts the quiz more and converges faster, but a wrong
estimate becomes impossible to escape — the true preference may lie outside
the window entirely. ±3 keeps half the original range reachable, so the
listening test can still override the quiz. The quiz is a hint, not a
verdict.

### Why the scoring is rule-based, not an LLM

The mapping from answers to dB weights is a fixed table
(`pre_quiz.py`). This is deterministic, inspectable, reproducible, and
free. An LLM would produce different results run to run and could not be
defended as a measurement instrument. The interface (answers in, dB
estimate out) would allow swapping one in later if desired.

---

## 3. Characterising IEMs from measurements

### Source

Frequency response measurements from **squig.link**, operated by Mark Ryan
Sallee (Super* Review), used with written permission (2026-08-11) for
non-commercial purposes. 123 IEMs currently imported.

Each IEM's left and right channel measurements are **averaged**, matching
what squig.link's own graphs display.

### From a curve to three numbers

A measurement is several hundred frequency/SPL points. It is reduced to
three figures by averaging within bands and expressing each relative to a
midrange reference:

```
bass_gain     = avg(20–250 Hz)    − avg(500–2000 Hz)
presence_gain = avg(2000–6000 Hz) − avg(500–2000 Hz)
treble_gain   = avg(6000–16000 Hz)− avg(500–2000 Hz)
```

### Why relative to a reference rather than absolute SPL

Absolute SPL depends on how loudly the measurement rig was driven, not on
the IEM's tuning. Two measurements of the same IEM at different volumes
would produce different absolute numbers but identical tonal balance.
Subtracting the midrange average removes that, leaving shape.

### The band definitions do not match the test's filter centres

This is the sharpest inconsistency in the project and should be stated
plainly.

| | Listening test | Measurement analysis |
|---|---|---|
| Bass | Low shelf at **100 Hz** | Average of **20–250 Hz** |
| Presence | Peak at **3 kHz**, Q 1.4 | Average of **2–6 kHz** |
| Treble | High shelf at **8 kHz** | Average of **6–16 kHz** |

A shelf filter at 100 Hz affects a different weighting of frequencies than
a flat average across 20–250 Hz. So "bass" on the preference side and
"bass" on the measurement side are related but not identical quantities.

**Why it was accepted:** the bands are broad and overlapping enough that
the correlation is strong, and the alternative — deriving each IEM's gains
by fitting the same three filter shapes to its measured curve — is
considerably more complex for a gain in precision that would be swamped by
other sources of error (fit variation between listeners, single
measurement samples, ear-shape differences).

**Why it is a real limitation:** the match percentage should be read as an
ordering, not a physical measurement. An IEM at 94% is a better fit than
one at 88%; the six-point difference does not correspond to a specific
audible quantity.

---

## 4. Matching a listener to an IEM

### The problem that had to be solved

The two sides of the comparison are not the same quantity:

- **Preference gains** are EQ settings — dB of boost relative to flat.
  They centre on **0**.
- **IEM gains** are measured deviations from that IEM's own midrange. They
  carry **ear-canal resonance**, a broad lift near 3 kHz present in every
  in-ear measurement because it is a property of ears, not tuning.

Across the 123 imported IEMs the medians are **bass +5.20 dB**, **presence
+4.38 dB**, **treble −0.44 dB**. Compared raw, a listener who preferred
perfectly neutral EQ scored a distance of about 10 dB against *every* IEM,
capping match scores near zero and ranking IEMs by how unusual their
measurement was rather than how well they fitted.

### The correction

Each IEM's gains are **centred on the catalogue median** before comparison:

```
centred_gain = iem_gain − catalogue_median(band)
```

Zero then means "average IEM" on the measurement side, which corresponds
to "no EQ change wanted" on the listener side.

Median rather than mean, because the catalogue contains genuine outliers
(gains beyond ±13 dB) that would drag a mean.

### Scoring

```
distance = Σ |preference_band − centred_gain_band|
score    = max(0, 100 − distance × 5)
```

The ×5 scale factor was chosen after centring: at ×10 even good matches
bottomed out near zero and lost ordering information.

**What this means for interpretation:** the score is *relative to this
catalogue*. Adding many differently-tuned IEMs shifts the median and
therefore every score. This is defensible for a recommender — the question
being answered is "which of these suits you best" — but the number is not
an absolute measure of fit.

### Per-band scores

Each band also gets its own percentage, computed the same way on that
band's gap alone. A single overall figure hides the case where an IEM
matches bass perfectly and misses treble badly, yet scores the same as one
that is mediocre everywhere.

---

## 5. Ranking and presentation

### Cheapest good match first, not best match first

Recommendations are produced by taking the **15 best-matching IEMs**, then
sorting *those* by price ascending and showing the cheapest 5.

Sorting purely by match consistently surfaced $650–$7,500 flagship IEMs,
which for a student audience reads as "you cannot afford our advice".
Sorting purely by price would recommend cheap IEMs that don't suit the
listener, defeating the point of the test.

The compromise shows only genuinely good matches, ordered so affordable
ones appear first. The trade-off is visible to the user: match percentages
are displayed on every card, so someone can see they would pay $170 instead
of $35 for a few more points of fit.

Tunable via `CANDIDATE_POOL_SIZE = 15` and `RESULTS_SHOWN = 5` in
`ai_service.py`.

### Plain-language descriptions are percentile-based

Thresholds separating "bass-boosted" from "warm, full bass" and so on are
set at **percentiles of the imported catalogue**, not fixed dB values, and
were calibrated against the real distribution using
`calibrate_interpreter.py`.

The original hand-picked thresholds assumed gains scattered around zero.
Against real data, every IEM cleared the "forward presence" threshold and
almost none reached either treble threshold — twelve of the first fifteen
imports produced one of just two sentences. Percentile thresholds guarantee
the labels differentiate.

**The consequence, which must be stated:** "bass-boosted" means *bassier
than roughly 80% of this catalogue*, not above an absolute dB figure. The
labels are comparative, not objective acoustic categories. If the
catalogue changes substantially, recalibration is required.

Current thresholds (123 IEMs):

| Band | Cut points (dB) |
|---|---|
| Bass | 6.88 / 5.57 / 4.65 / 3.30 |
| Presence | 5.22 / 3.50 |
| Treble | 0.68 / −1.50 |

### Prices

Stored in USD, since squig.link quotes USD. Displayed as pesos with the
dollar figure alongside, converted at a fixed rate (61.2 PHP/USD, Aug
2026).

Both are shown because the peso figure is a **conversion, not a local
price** — Philippine retail includes shipping, duties and reseller margin,
so actual Shopee prices run higher. Showing pesos alone would present a
converted number as though it were what a buyer pays.

The rate is fixed at build time rather than fetched live, so results are
reproducible and the system doesn't depend on a currency API.

---

## 6. Audio delivery

EQ is applied **in the listener's browser** using the Web Audio API, with
`BiquadFilterNode`s configured identically to the server-side CamillaDSP
pipeline (100 Hz Q0.7 low shelf, 3 kHz Q1.4 peak, 8 kHz Q0.7 high shelf).

### Why not server-side

The original design applied EQ via CamillaDSP on the server, which outputs
to that machine's sound card. Only someone physically at that machine could
hear anything. Remote participants heard silence — **and the test still
recorded their answers**, which would have produced confident-looking data
about audio nobody heard.

Browser-side EQ also removes a confound: every listener hearing the same
speakers would have measured those speakers' characteristics as much as
their own preference. A listening-preference test should measure the
listener.

CamillaDSP remains as a fallback where Web Audio is unavailable.

### Audio format

Clips are 10-second excerpts delivered as **256 kbps MP3**.

The source WAVs are 24-bit WAVE_FORMAT_EXTENSIBLE, which browsers cannot
decode (`decodeAudioData` supports 16-bit and 32-bit float PCM). Conversion
was therefore required for browser playback regardless of size.

MP3 at 256 kbps also reduces the set from 28 MB to 3.1 MB, which matters
when 45 participants download it over a home connection.

**The objection and the answer:** lossy source audio in a listening study
is a fair concern. The differences being judged are EQ shifts of up to
±6 dB — vastly larger than any codec artefact at 256 kbps. If lossless is
required, 16-bit WAV is browser-compatible at roughly 19 MB total.

---

## 7. Known limitations

- **Single measurement per IEM.** No averaging across samples or fit
  positions, so unit variation and insertion depth are unaccounted for.
- **Match scores are catalogue-relative.** They order well; they are not
  absolute measures of fit.
- **Band definitions differ** between the test filters and the measurement
  analysis (section 3).
- **Treble and presence are measured more coarsely than bass** (4/3/3
  split).
- **No listener-side hearing calibration.** Age-related high-frequency
  loss, headphone quality and listening level all affect answers and are
  not recorded.
- **The pre-quiz weights are hand-assigned**, not empirically derived.
  They are a reasonable prior, not a validated instrument.
- **Descriptions require recalibration** if the catalogue composition
  changes substantially.

---

## 8. Data sources and permissions

**Measurements:** squig.link, operated by Mark Ryan Sallee (Super* Review).
Written permission granted 2026-08-11 for non-commercial use, on condition
the data is not redistributed in a way that duplicates Squiglink's
functionality. Permission covers `squig.link`, `squig.link/headphones/` and
`squig.link/earbuds/` only — other squiglink databases host data owned by
different measurers and would need separate permission.

**Catalogue:** `phone_book.json` from the same source, providing names,
prices and shop links.

Attribution to Mark Ryan Sallee / Super* Review should appear anywhere
these measurements are presented.
