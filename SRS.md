# Software Requirements Specification
## EqualizeME — AI-Assisted IEM Recommendation System

**Version 1.0 — August 2026**

---

## 1. Introduction

### 1.1 Purpose

This document specifies the requirements for **EqualizeME**, a web
application that determines a listener's audio tonal preference through a
guided listening test and recommends in-ear monitors (IEMs) whose measured
frequency response matches that preference.

It is intended for the development team, the project adviser, and
examiners assessing the system.

### 1.2 Scope

EqualizeME replaces the usual way people choose IEMs — reading reviews
written in subjective language ("warm", "V-shaped", "musical") — with a
measurement-based match to the listener's own demonstrated preference.

The system:

- Collects stated preference through a short written questionnaire
- Measures actual preference through a blind A/B listening test
- Produces a numerical auditory profile across three frequency bands
- Matches that profile against a database of real IEM measurements
- Presents ranked recommendations with charts, plain-language
  descriptions, prices and purchase links

It does **not** sell products, process payments, or provide professional
audiological assessment.

### 1.3 Definitions

| Term | Meaning |
|---|---|
| **IEM** | In-Ear Monitor — an earphone inserted into the ear canal |
| **Frequency response (FR)** | How loudly a device reproduces each frequency |
| **EQ** | Equalisation — adjusting the level of frequency ranges |
| **Auditory profile** | A listener's preferred gain in each of three bands |
| **Staircase method** | Narrowing a range by repeated forced-choice comparison |
| **Binary search** | Halving a search range at each step |
| **Biquad filter** | A second-order filter used to shape audio |
| **Squig.link** | A public database of IEM frequency response measurements |
| **REW** | Room EQ Wizard — software that produces measurement files |
| **Ear-gain resonance** | Amplification near 3 kHz caused by ear canal shape |

### 1.4 References

- Squiglink documentation — https://documentation.squig.link
- Web Audio API specification — W3C
- CamillaDSP documentation
- Written permission from Mark Ryan Sallee (squig.link), 2026-08-11

---

## 2. Overall Description

### 2.1 Product Perspective

EqualizeME is a self-contained web application with two tiers:

```
     Browser (HTML / CSS / JavaScript)
        │  audio playback + EQ, charts, UI
        │
        └──────────────► PHP / Apache ──── MySQL
                         auth, sessions, security,
                         test logic, matching
```

An earlier revision ran the test logic and matching in a separate
Python / Flask service, with a PHP proxy in front of it. Both were
removed when the application was deployed to shared hosting, which
cannot run a long-lived process. The logic was ported to PHP and
verified against the original by replaying fixed inputs through both
implementations (2,081 checks, 0 failures).

Python is still used, but only offline: the scripts in `backend/` fetch
measurements and import the catalogue into MySQL. They run on a
maintainer's machine and are not part of the deployed application.

The listening test's EQ is applied **in the browser** via the Web Audio
API, so each participant hears it through their own headphones.

### 2.2 User Classes

| Class | Description | Technical skill |
|---|---|---|
| **Listener** | Takes the test, receives recommendations | None assumed |
| **Administrator** | Imports measurements, maintains the catalogue | Command line, SQL |

### 2.3 Operating Environment

- **Client:** any modern browser supporting the Web Audio API
  (Chrome, Firefox, Edge, Safari); headphones or IEMs required
- **Server:** Apache + PHP 8, MySQL 8 / MariaDB
- **Maintenance only:** Python 3 for the catalogue import scripts,
  run offline and not required by the deployed application
- **Development:** XAMPP on Windows

### 2.4 Constraints

- Measurement data is used under a non-commercial permission that
  prohibits redistribution duplicating Squiglink's functionality
- Audio clips must be in a format browsers can decode (not 24-bit WAV)
- Recommendations are limited to IEMs with imported measurements
- bcrypt ignores password bytes beyond 72

### 2.5 Assumptions and Dependencies

- Participants use headphones, not laptop speakers
- Participants answer the listening test honestly
- Published measurements are representative of retail units
- Squig.link remains available for catalogue updates

---

## 3. Functional Requirements

### 3.1 Account Management

| ID | Requirement | Priority |
|---|---|---|
| FR-1.1 | Register with name, email and password | Must |
| FR-1.2 | Reject passwords under 8 characters, over 72 bytes, without mixed case and a digit, on a common-password blocklist, or containing the user's name or email | Must |
| FR-1.3 | Reject duplicate email addresses | Must |
| FR-1.4 | Log in with email and password | Must |
| FR-1.5 | Log out, destroying session state and cookie | Must |
| FR-1.6 | Request a password reset link by email | Should |
| FR-1.7 | Reset tokens expire after 60 minutes and work once | Must |
| FR-1.8 | Upload a profile picture (JPG/PNG/WEBP, max 5 MB) | Could |

### 3.2 Preference Questionnaire

| ID | Requirement | Priority |
|---|---|---|
| FR-2.1 | Present six written preference questions before the listening test | Must |
| FR-2.2 | Require all questions answered before continuing | Must |
| FR-2.3 | Convert answers to a starting gain estimate per band | Must |
| FR-2.4 | Withhold scoring weights from the client so answers can't be gamed | Should |
| FR-2.5 | Continue to the listening test if the questionnaire fails to load | Should |

### 3.3 Listening Test

| ID | Requirement | Priority |
|---|---|---|
| FR-3.1 | Present exactly 10 A/B comparisons | Must |
| FR-3.2 | Use a different audio clip for each question, covering 5 songs × 2 excerpts | Must |
| FR-3.3 | Within a question, A and B must be the same clip with different EQ | Must |
| FR-3.4 | Apply EQ in the listener's browser | Must |
| FR-3.5 | Allow each option to be replayed before choosing | Must |
| FR-3.6 | Narrow each band by binary search over −6…+6 dB | Must |
| FR-3.7 | Seed the initial range from the questionnaire (±3 dB window) | Should |
| FR-3.8 | Allocate questions 4 / 3 / 3 across bass, presence, treble | Must |
| FR-3.9 | Show progress (question number, band, round) | Should |
| FR-3.10 | Save the resulting profile on completion | Must |
| FR-3.11 | Optionally auto-play A then B | Could |
| FR-3.12 | Optionally notify the browser when the test completes | Could |

### 3.4 Recommendations

| ID | Requirement | Priority |
|---|---|---|
| FR-4.1 | Compare the listener's profile against all measured IEMs | Must |
| FR-4.2 | Centre measured gains on the catalogue median before comparison | Must |
| FR-4.3 | Produce an overall match percentage | Must |
| FR-4.4 | Produce a per-band match percentage | Should |
| FR-4.5 | Show the 5 cheapest IEMs among the 15 best matches | Should |
| FR-4.6 | Display the measured FR curve against the listener's preference curve | Should |
| FR-4.7 | Display a plain-language description of each IEM's tonality | Should |
| FR-4.8 | Display price in pesos with the source USD figure | Should |
| FR-4.9 | Provide a purchase link, falling back to a marketplace search | Should |
| FR-4.10 | Exclude IEMs with incomplete measurements from scoring | Must |
| FR-4.11 | Prompt the listener to take the test if no profile exists | Must |

### 3.5 Data Import (Administrator)

| ID | Requirement | Priority |
|---|---|---|
| FR-5.1 | Download measurement files from squig.link with rate limiting | Should |
| FR-5.2 | Parse the catalogue, handling malformed prices and review scores | Must |
| FR-5.3 | Parse REW measurement files and average L/R channels | Must |
| FR-5.4 | Reduce each curve to three band gains | Must |
| FR-5.5 | Generate a description from those gains | Must |
| FR-5.6 | Preview generated SQL before writing to the database | Must |
| FR-5.7 | Update existing rows rather than duplicating on re-import | Must |
| FR-5.8 | Report which catalogue entries were skipped and why | Should |
| FR-5.9 | Recalibrate description thresholds against the imported distribution | Should |

### 3.6 Settings

| ID | Requirement | Priority |
|---|---|---|
| FR-6.1 | Toggle dark mode, persisted across sessions | Should |
| FR-6.2 | Toggle auto-play during the test | Could |
| FR-6.3 | Toggle completion notifications | Could |

---

## 4. Non-Functional Requirements

### 4.1 Security

| ID | Requirement |
|---|---|
| NFR-1.1 | Passwords stored using bcrypt; never logged or displayed |
| NFR-1.2 | All database access via prepared statements |
| NFR-1.3 | CSRF tokens on all state-changing requests |
| NFR-1.4 | Session ID regenerated on login and registration |
| NFR-1.5 | Sessions expire after 2 hours idle; ID rotates every 30 minutes |
| NFR-1.6 | Cookies HttpOnly and SameSite=Lax; Secure over HTTPS |
| NFR-1.7 | Login rate limited per IP and per account |
| NFR-1.8 | Login response time independent of whether the account exists |
| NFR-1.9 | Reset tokens stored hashed, never in plaintext |
| NFR-1.10 | Internal error details logged server-side, never shown to users |
| NFR-1.11 | Credentials kept outside version control |

### 4.2 Performance

| ID | Requirement |
|---|---|
| NFR-2.1 | Pages respond within 2 seconds on a local network |
| NFR-2.2 | Audio clips under 500 KB each |
| NFR-2.3 | Decoded audio cached so each clip downloads once per session |
| NFR-2.4 | Support at least 45 registered users |
| NFR-2.5 | Database connections released on every code path |

### 4.3 Usability

| ID | Requirement |
|---|---|
| NFR-3.1 | Test completable without audio knowledge |
| NFR-3.2 | Consistent visual language across all pages |
| NFR-3.3 | Dark and light modes with adequate contrast |
| NFR-3.4 | Responsive from mobile to desktop |
| NFR-3.5 | Interactive controls keyboard reachable with visible focus |
| NFR-3.6 | Errors stated in plain language with a next step |

### 4.4 Reliability

| ID | Requirement |
|---|---|
| NFR-4.1 | A missing measurement must not break a recommendation card |
| NFR-4.2 | API errors returned as JSON, never HTML |
| NFR-4.3 | Import previewable before any write |
| NFR-4.4 | Browser audio failure falls back to server playback |

---

## 5. Use Cases

### UC-1: Take the listening test

**Actor:** Listener
**Precondition:** Logged in, headphones connected

1. Listener opens the Sound Test page
2. System shows what the test involves
3. Listener starts and answers six written questions
4. System scores them into a starting estimate
5. System presents question 1 with options A and B
6. Listener plays A, plays B, selects the preferred option
7. System narrows the range and presents the next question
8. Steps 5–7 repeat for 10 questions
9. System computes and saves the auditory profile
10. System displays the profile and offers recommendations

**Alternate — 6a:** Listener replays either option any number of times
**Alternate — 3a:** Questionnaire fails to load; system proceeds to step 5
**Exception — 6a:** Browser cannot play audio; system falls back to server playback

### UC-2: View recommendations

**Actor:** Listener
**Precondition:** A saved auditory profile exists

1. Listener opens the Recommendations page
2. System retrieves the profile
3. System centres each IEM's gains on the catalogue median
4. System scores every IEM with complete measurements
5. System takes the 15 best matches and orders them by price
6. System displays the 5 cheapest with charts, descriptions, prices, links
7. System overlays the listener's preference curve on each chart

**Exception — 2a:** No profile; system invites the listener to take the test

### UC-3: Reset a forgotten password

**Actor:** Listener

1. Listener selects "Forgot your password?"
2. Listener submits their email
3. System responds identically whether or not the account exists
4. If registered, system stores a hashed token and emails a link
5. Listener opens the link and enters a new password twice
6. System validates the token and the password policy
7. System updates the password and invalidates the token

**Exception — 6a:** Token expired, used, or unknown — one generic message

### UC-4: Import measurements

**Actor:** Administrator

1. Administrator runs the downloader for a chosen subset
2. System fetches L/R files with a delay between requests, skipping existing
3. Administrator runs the importer in preview mode
4. System prints the SQL it would execute and lists skipped entries
5. Administrator reviews and re-runs with `--live`
6. System inserts new IEMs and updates existing ones
7. Administrator recalibrates description thresholds

---

## 6. External Interfaces

### 6.1 User Interface

Pages: Home, Login/Register, Forgot Password, Reset Password, Sound Test,
Recommendations, Profile, Settings.

Shared navigation, logo, theme toggle and profile menu across all pages.

### 6.2 Software Interfaces

| Interface | Purpose |
|---|---|
| PHP ↔ MySQL (PDO) | Accounts, sessions, settings, profiles, catalogue |
| Browser ↔ PHP (JSON) | Everything: auth, settings, test flow, recommendations |
| Importer ↔ squig.link (HTTPS) | Catalogue and measurement retrieval (offline) |
| Importer ↔ MySQL | Writing the IEM catalogue (offline) |
| Mailer ↔ SMTP | Password reset delivery |

### 6.3 API Endpoints

**PHP**

| Method | Path | Purpose |
|---|---|---|
| POST | `/api/auth/register.php` | Create account |
| POST | `/api/auth/login.php` | Authenticate |
| POST | `/api/auth/logout.php` | End session |
| GET | `/api/auth/me.php` | Current user |
| POST | `/api/auth/request-reset.php` | Request reset link |
| POST | `/api/auth/reset-password.php` | Set new password |
| GET | `/api/csrf-token.php` | Issue CSRF token |
| GET/PUT | `/api/settings.php` | Read/update settings |
| GET | `/api/profile.php` | Auditory profile |
| POST | `/api/upload-picture.php` | Profile picture |

**Listening test and recommendations**

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/quiz.php` | Questionnaire, without scoring weights |
| POST | `/api/test-start.php` | Begin test |
| POST | `/api/test-answer.php` | Submit choice, save profile on the last one |
| GET | `/api/recommendations.php` | Preference target, analysis, ranked IEMs |
| GET | `/api/iem-curve.php?id=` | Measured curve and description |

No endpoint takes a user id. Every one reads it from the session, so
there is nothing in a request for a caller to tamper with. The previous
revision needed a proxy (`api/dsp.php`) to overwrite a client-supplied
id before passing it to Flask; that whole class of bug is now
unreachable rather than guarded against.

Server-side audio playback was removed. It rendered through the server's
own sound card, which was only meaningful while the server and the
listener were the same machine.

---

## 7. Out of Scope

- E-commerce or payment processing
- Clinical hearing assessment
- User-submitted measurements
- Mobile applications
- Simultaneous server-side audio for multiple listeners
- Social features

---

## 8. Acceptance Criteria

The system is accepted when:

1. A new user can register, complete the test and receive recommendations
   without assistance
2. The test presents exactly 10 questions using 10 distinct clips
3. Audio plays through the listener's own device
4. Three different preference profiles produce three different lists
5. Recommendation charts show both the measured curve and the preference
   curve
6. Password reset works end to end by email
7. All security requirements in section 4.1 hold
8. At least 40 users can hold accounts without degradation


---

## Appendix A — Design History

### A.1 Superseded approach: rule-based branching questionnaire

The first implementation of the listening test used a **rule-based branching
questionnaire**. Questions were stored in a `questions` table, the path
between them in `question_rules`, and each answer's effect on the three bands
in `question_score_impact`. After each answer the server looked up the next
question; on completion it summed the recorded deltas to produce a profile.

It worked, but had three limitations.

**Authoring cost.** Every question, branch and score impact had to be written
by hand as database rows. Adding a single question meant editing three tables
and reasoning about how it interacted with the existing rules.

**Poor convergence.** Because the path was fixed in advance, the number of
questions needed did not adapt to the listener. Reaching the precision the
current test achieves in ten questions would have required substantially
more.

**Coarse output.** A score was the sum of hand-assigned deltas, so the result
could only ever be as granular as the values someone had typed in.

### A.2 Current approach: adaptive binary search

The current test replaces the rule graph with a **binary search over each
band's decibel range**. Sample A always carries the low end of the remaining
range and sample B the high end, so every answer discards half of what
remains.

This addresses all three limitations. There is no rule graph to author, since
each pair is generated from the current bounds. Convergence is exponential:
four rounds locate a preference to within ±0.375 dB across a 12 dB range. And
the result is a continuous value rather than a sum of authored constants.

The written questionnaire was kept, but repurposed. Rather than driving the
test, its six answers now **seed** the starting bounds — narrowing the initial
window from 12 dB to 6 dB and roughly doubling final precision at no extra
cost to the listener. The confidence score reported at the end is derived from
the achieved precision, which is why a seeded run scores around 95% and an
unseeded one around 90%.

### A.3 Removal

The superseded implementation was retained in the codebase for a period as a
reference. It has since been removed — `assessment.php`, the
`/start-assessment` and `/next-question` routes, their nine helper functions,
and the five database tables — so that two systems no longer appear to serve
the same purpose. This appendix records the design and the reasoning in their
place.

See `sql/drop_legacy_assessment.sql` for the schema migration.
