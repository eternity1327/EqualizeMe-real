# Getting EqualizeME usable by the whole class

A write-up for the group to decide on. Written August 2026.

## The problem in one sentence

Everything except the listening test already handles 45 users fine. The
listening test handles exactly one — and that person has to be sitting at
the machine running the server.

## Why

`camilla_dsp.py` controls a single CamillaDSP process with a single audio
output. Every "Play A" from anyone pushes a new EQ config to that same
instance, and the sound comes out of the host machine's speakers.

If five classmates press Play at once, they overwrite each other's
settings and none of them hear anything on their own device.

The dangerous part isn't the silence — it's that the test *still records
their answers*. Each user's staircase progress is tracked separately, so
the system happily saves 45 sets of preferences from people who never
heard the audio they were judging. The data would look fine and mean
nothing.

## What actually scales fine

Measured against the current setup, 45 users is nowhere near any limit:

| Component | Limit | 45 users |
|---|---|---|
| MySQL connections | 151 default (XAMPP) | Not close |
| Apache threads (Windows) | ~150 default | Not close |
| Flask | Runs threaded by default | Fine for light JSON |
| Database size | — | ~50 KB total |

So registration, login, settings, profiles and recommendations would all
work today for the whole class. Only the audio is the blocker.

## Hosting doesn't fix this

Worth being blunt about, because it's the intuitive assumption: **moving
to Hostinger (or any host) makes the audio situation worse, not better.**
A server in a datacenter has no sound card. CamillaDSP cannot run there
at all.

Also relevant if we go this route:

- **Hostinger shared hosting cannot run Python.** No root access, no
  long-running processes — Flask apps don't work on shared plans.
- **Only Hostinger's VPS tier supports Python**, starting around
  $6.49/month.
- Even on a VPS, CamillaDSP still can't produce audio anyone can hear.

Hosting solves real problems — permanent URL, proper HTTPS, no
`cloudflared` windows to keep open, no URL changing every restart — but
it only becomes possible *after* the audio moves off the server.

## The fix: move EQ into the browser

The Web Audio API can apply the exact same filters CamillaDSP does:

| Filter | Setting |
|---|---|
| Low shelf | 100 Hz, Q 0.7 |
| High shelf | 8 kHz, Q 0.7 |
| Peaking | 3 kHz, Q 1.4 |

The browser downloads the clip, applies the EQ locally, and plays it
through *that person's* headphones.

This isn't just a workaround — it's more correct. Right now everyone
would be judging Gian's speakers. A listening test should measure each
person's own preference on their own gear.

CamillaDSP stays useful for local high-quality playback; it just stops
being required.

## Options

### A. Keep as-is, demo in person
- **Cost:** nothing
- **Work:** none
- **Result:** classmates take turns at one laptop with headphones
- **Good if:** the teacher only needs to see it work once

### B. Browser EQ, keep the tunnel
- **Cost:** nothing
- **Work:** one new JS module, small change to `playSide()`
- **Result:** all 45 can genuinely take the test, on their own devices
- **Still need:** Python + two `cloudflared` windows running on Gian's PC,
  and the URL changes on every restart

### C. Browser EQ + port Flask to PHP, then host it
- **Cost:** shared hosting (cheapest tier is enough — no Python needed)
- **Work:** browser EQ, plus porting four routes to PHP — recommendations
  scoring, quiz questions, the staircase state machine, profile saving
- **Result:** permanent URL, real HTTPS, nothing running on anyone's
  laptop, 45 users trivially
- **Note:** the staircase gets *simpler* in PHP — its per-user state fits
  naturally in `$_SESSION` instead of a Python dictionary

### D. Browser EQ + VPS, keep Python
- **Cost:** ~$6.49/month and up
- **Work:** browser EQ, plus VPS setup (Gunicorn, Nginx, deployment)
- **Result:** same as C, but keeps the Python code
- **Trade-off:** costs money and adds sysadmin work, to avoid a port that
  would take less time than the VPS setup itself

### E. Browser EQ + port to PHP, then host free on InfinityFree
- **Cost:** free
- **Work:** same as C, plus swapping the mailer (see below)
- **Result:** permanent URL, nothing running on anyone's laptop, no money

Our project sits comfortably inside the free limits:

| Limit | InfinityFree | We need |
|---|---|---|
| Storage | 5 GB | 37 MB |
| Files (inodes) | 30,000 | 143 |
| MySQL per database | 50 MB | under 1 MB |
| Hits per day | 50,000 | 45 users ≈ a few thousand |
| PHP version | 8.3 | fine |

**Two catches:**

1. **No Python**, same as Hostinger shared — this option depends on the
   PHP port either way.

2. **Outbound SMTP is unreliable there.** InfinityFree's support forums
   have years of "SMTP connect() failed" reports from people whose
   PHPMailer setup works on localhost and fails on the host. Our password
   reset email would likely break.

   Fix: send email through an **HTTP API** instead of SMTP. Brevo, Resend
   and SendGrid all offer REST endpoints called over port 443, which
   isn't blocked. That's a ~30-line change to `api/mailer.php`, swapping
   PHPMailer for a cURL call.

Also: no SSH (deploy by FTP or their file manager), and free accounts can
be suspended for inactivity. Do **not** upload `.venv/` — it's 874 files
of Python packages that would waste inodes for nothing.

## Recommendation

**B if the deadline is close. C or E if there's time.**

E is C with a free host instead of a paid one. If the SMTP workaround is
acceptable, it's hard to argue with free for a school project.

B is the smallest change that makes the project actually demonstrable to
45 people, and it costs nothing.

C is where this wants to end up. Once the audio is in the browser, the
Python service isn't doing anything that PHP can't, and dropping it means
the whole app runs on the cheapest hosting tier with a URL that doesn't
change. D pays monthly to avoid a port that's smaller than the setup work
it replaces.

Either way, **browser EQ comes first** — every other path depends on it.

## Other things to sort before demo day

- **Audio file size.** Clips are 2.8 MB each, 28 MB for the full set.
  Over 45 users that's ~1.26 GB. Converting to MP3 cuts it roughly
  tenfold and should happen before streaming to browsers.
- **Reset links and the changing URL.** Password reset emails embed the
  current tunnel URL. Restart `cloudflared` and links already sent point
  at a dead domain. Hosting solves this permanently.
- **Never expose MySQL or phpMyAdmin** to the internet, on any of these
  setups.

---

Sources for the Hostinger details:

- [Hostinger Python Hosting Review](https://hostadvice.com/hosting-company/hostinger-reviews/hostinger-python-hosting-review/)
- [Can Hostinger Run Python Scripts?](https://ratingeer.com/blog/can-hostinger-run-python-scripts)
- [Best Flask Hosting Services](https://hostadvice.com/python-hosting/flask-hosting/)

Sources for the InfinityFree details:

- [InfinityFree Review 2026](https://blog.webhostmost.com/infinityfree-review-2026/)
- [InfinityFree Hosting Review — Is It REALLY Free?](https://www.websiteplanet.com/web-hosting/infinityfree/)
- ["SMTP connect() failed" on InfinityFree but not on XAMPP Localhost](https://forum.infinityfree.com/t/smtp-connect-failed-on-infinityfree-but-not-on-xampp-localhost/49430)
- [Cannot send emails using PHPMailer — InfinityFree Forum](https://forum.infinityfree.com/t/cannot-send-emails-using-php-mailer/45291)
