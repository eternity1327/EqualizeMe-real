# EqualizeME — Screenshot Index

Every image sent during this project, in date order. 43 images, 11–28 August 2026.

**Five are marked DO NOT PUBLISH.** They contain live credentials or account
security pages. Details in the last section.

---

## Aug 11 — first working build

| # | Time | What it shows | Use for |
|---|------|---------------|---------|
| 1 | 07:52 | The earliest listening-assessment page. Plain audio element, "Option A / Option B" text links, no styling. | Before/after — contrast with #41 |
| 2 | 08:03 | Settings page, dark mode, three toggles (Notifications, Dark Mode, Auto Play) | Settings feature |
| 3 | 08:06 | Same settings page in light mode | Theme switching |
| 4 | 08:10 | DevTools Network tab — a column of `me.php` requests all returning **401** | Debugging record: the auth failure |
| 5 | 08:14 | The settings HTML source, showing the three `.setting` checkbox blocks | Code reference |
| 6 | 08:32 | "PREPARING YOUR TEST…" loading overlay | Loading states |
| 7 | 08:37 | GitHub repo file listing for EqualizeMe | Repo structure |
| 8 | 08:48 | Test screen "Which do you prefer?" with A/B cards, alongside DevTools showing console errors | Test UI + debugging |
| 9 | 08:55 | **"COULD NOT REACH THE DSP SERVICE"** banner over the A/B cards | Error handling |
| 10 | 09:05 | A/B comparison mid-test — "TUNING TREBLE — ROUND 2 OF 4, PARAMETER 3 OF 3", one side playing | Adaptive test in progress |
| 11 | 09:05 | Recommended IEMs grid — five cards, match percentages, prices in ₱ | Recommendations v1 |
| 12 | 09:05 | A "You might also like" row of podcast thumbnails | Probably not yours — looks like an unrelated page |
| 13 | 09:20 | Recommended IEMs with product photography | Recommendations v1 |
| 14 | 09:22 | "STEP 1 OF 2 — Choose a track", dropdown set to "Show — Chorus" | Track picker |
| 15 | 11:42 | XAMPP welcome page, Apache + MariaDB + PHP 8.2.12 | Environment setup |
| 16 | 20:02 | "This site can't be reached" — a Cloudflare tunnel URL failing | Remote access attempt |

## Aug 11 evening — authentication

| # | Time | What it shows | Use for |
|---|------|---------------|---------|
| 17 | 22:27 | **DO NOT PUBLISH** — phpMyAdmin `users` row with the bcrypt `password_hash` visible | — |
| 18 | 22:27 | Login form with "Incorrect email or password" in red | The deliberately identical error message |
| 19 | 22:52 | "Forgot your password?" form, with the "(Email sending isn't set up… written to logs/sent-mail.log)" notice | Reset flow, log fallback |
| 20 | 22:53 | Login page via the Cloudflare tunnel, browser password-manager dropdown open | Remote access |
| 21 | 23:03 | **DO NOT PUBLISH** — Google Account → App passwords | — |
| 22 | 23:04 | **DO NOT PUBLISH** — Google 2-Step Verification setup | — |
| 23 | 23:13 | **DO NOT PUBLISH** — the reset email in Gmail, with a working token in the link | — |
| 24 | Aug 12 11:43 | A tech-stack banner strip: PHP, JavaScript, Python, HTML5, CSS3, MySQL, Web Audio API, Squiglink API, REST APIs, JSON, Git, GitHub | Ready-made for a slide |

## Aug 13 — database and catalogue

| # | Time | What it shows | Use for |
|---|------|---------------|---------|
| 25 | 12:55 | `iems` table structure — every column with type and nullability | **Schema documentation** |
| 26 | 13:35 | phpMyAdmin `SELECT COUNT(*) FROM iems` returning **133** | Catalogue size |
| 27 | 13:36 | Duplicate check — brand/name grouped `HAVING COUNT(*) > 1`, showing several with 2 copies | Data cleaning record |
| 28 | 13:37 | `iems` rows — brand, name, price, `has_curve`, `has_image`, retailer, description | Catalogue contents |
| 29 | 13:43 | `iems` sorted by price — Campfire Chimera ₱7,500 down to 64 Audio U12t ₱2,000 | Price range |
| 30 | 13:53 | Recommendation cards each with a frequency-response chart | Chart.js integration |
| 31 | 14:04 | Three finished cards — 64 Audio Duo 78.5% ₱73,440, Campfire Andromeda 78% ₱61,200, 64 Audio U18t 75% ₱183,600 | **Recommendations, polished** |
| 32 | Aug 19 09:03 | Landing page — "Find Your Perfect Sound", profile dropdown open | **Hero shot** |

## Aug 20–22 — schema cleanup and hardening

| # | Time | What it shows | Use for |
|---|------|---------------|---------|
| 33 | 20:08 | `SHOW INDEX FROM auditory_profiles WHERE Key_name = 'uq_auditory_profiles_user'` → **empty result** | The query that misled us — see note below |
| 34 | 20:09 | Full index listing across all tables, showing `unique_user_profile` on `auditory_profiles` | The correction |
| 35 | 20:10 | Duplicate IEM and duplicate email checks — both returning **0** | Data integrity evidence |
| 36 | 20:17 | Index listing after the unique constraint was dropped | Migration record |
| 37 | 22 Aug 14:23 | `SHOW GRANTS FOR 'equalizeme_app'@'localhost'` — SELECT, INSERT, UPDATE, DELETE only | **Least-privilege evidence** |
| 38 | 14:28 | VS Code explorer, `backend/` expanded | Project structure |
| 39 | 14:30 | **DO NOT PUBLISH** — `config.local.php` open in VS Code | — |
| 40 | 14:33 | The logout fatal error: `setcookie(): Argument #3 must be of type int, array given` | Bug record — fixed in `expired_cookie_options()` |

## Aug 28 — the results page

| # | Time | What it shows | Use for |
|---|------|---------------|---------|
| 41 | 09:58 | Preference Profile (Bass +2.1, Upper mid −0.5, Treble −2.4) and the Preference Target curve | **Current headline screenshot** |
| 42 | 09:58 | Audio Analysis section, and the IEM block showing "Could not load recommendations" | Superseded — retake after the double-load fix |
| 43 | 09:58 | The full Hearing Preservation section | **Responsible-listening feature** |

---

## The five to keep out of any document

| # | Why |
|---|-----|
| **17** | phpMyAdmin showing a real bcrypt `password_hash`. It's a hash, not a plaintext password, but it's still a credential artefact and there's no reason to publish it. |
| **21** | Google Account app-passwords page. |
| **22** | Google 2-Step Verification setup, with a partial phone number visible. |
| **23** | The reset email with a **working reset token in the URL**. That link is long expired and single-use, but publishing token format alongside a real example is a poor habit. |
| **39** | **The serious one.** `config.local.php` open in the editor with the Gmail app password in plaintext on line 10, plus two freshly generated 32-character passwords in the terminal pane below. Do not use this image anywhere, and rotate that app password. |

If you need a config screenshot for the write-up, take a fresh one of
`api/config.example.php` — it has the same shape with placeholder values.

---

## Gaps worth filling

- **A working recommendations screenshot.** #42 caught the double-load bug. Retake now that it's fixed.
- **The listening test mid-question**, current styling. The newest one is #10, from 11 August.
- **Profile history / comparison.** That page was rebuilt and never captured.
- **A "before" shot** — #1 is the honest one, and it makes the rest look like progress.

## Note on #33 and #34

These two belong together and are worth a paragraph in the write-up.

#33 was a query for a *guessed* index name, `uq_auditory_profiles_user`. It came
back empty, which was read as proof the constraint didn't exist. #34 — the full
index listing — showed it did exist, under the name `unique_user_profile`.

An empty result for a guessed identifier is not evidence of absence. The
migration was rewritten afterwards to find the index *by shape* — a non-primary
unique index on exactly one column, that column being `user_id` — rather than by
name.
