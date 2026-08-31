# Deploying EqualizeME to InfinityFree

Free, no credit card, no expiry, PHP 8.3, MySQL, free SSL. This works
because the Python service is gone — everything is PHP now.

There is **no shell access**. You cannot run `php -l`, you cannot run the
test suite, you cannot tail a log. So the order below front-loads
everything that can be checked locally.

`DEPLOYMENT.md` is the VPS version and does not apply here.

---

## Before you upload

**Rotate the Gmail app password.** The one in `api/config.local.php` has
been on screen. New one at
[myaccount.google.com/apppasswords](https://myaccount.google.com/apppasswords),
typed straight into the server's config.

**Run the tests locally**, because you cannot run them there:

```
C:\xampp\php\php.exe tests\compare_with_python.php
C:\xampp\php\php.exe api\totp_selftest.php
```

**Take a full listening test with `ai_service.py` stopped.** If the site
works with no Python running, it will work on InfinityFree. If it does not,
InfinityFree is not the place to find out why.

---

## 1. Account and site

1. Sign up at [infinityfree.com](https://www.infinityfree.com/) — email only
2. Create an account, take the free subdomain
   (`something.infinityfree.me`) or point your own
3. Note the four things the control panel gives you:
   - FTP hostname, username, password
   - MySQL hostname — something like `sql123.infinityfree.com`, **not**
     `localhost`
   - MySQL database name — prefixed, like `if0_12345678_equalizeme`
   - MySQL username — usually the same prefix

## 2. Database

Control panel → **MySQL Databases** → create one. Then **phpMyAdmin**, and
import in this order:

```
your existing schema dump
sql/password_resets.sql
sql/add_profile_history.sql
sql/add_two_factor.sql
sql/add_email_verification.sql
```

The easiest way to get your existing data across: in local phpMyAdmin,
Export → SQL → Go, then import that file here.

**Watch the size.** Free hosting caps the import, and `iems.fr_curve_json`
holds a measurement curve per earphone. If the import fails, export in two
passes — structure first, then data — or drop the curve column's data and
re-import it separately.

## 3. Config

Do **not** upload `api/config.local.php` from your machine. Make a new one,
because the database host and name are different here.

Create `api/config.local.php` with the control panel's file manager:

```php
<?php
return [
    'database' => [
        'host'     => 'sql123.infinityfree.com',
        'name'     => 'if0_12345678_equalizeme',
        'user'     => 'if0_12345678',
        'password' => 'the password you set',
    ],

    'smtp' => [
        'enabled'   => true,
        'host'      => 'smtp.gmail.com',
        'port'      => 587,
        'secure'    => 'tls',
        'username'  => 'you@gmail.com',
        'password'  => 'your NEW app password',
        'from_email'=> 'you@gmail.com',
        'from_name' => 'EqualizeME',
    ],

    'environment' => 'production',
    'base_url'    => 'https://yoursite.infinityfree.me',
    'force_https' => true,

    'require_email_verification' => true,

    // InfinityFree's PHP may have no writable temp directory. Without a
    // usable path the rate limits fail silently — the site looks fine and
    // nothing is being counted. logs/ is the fallback; set it explicitly
    // if the counters do not appear there.
    'rate_limit_dir' => '',
];
```

**`base_url` must be exact**, including `https://` and no trailing slash.
With `environment` set to `production` the app refuses to start without it,
rather than building emailed links from a header the client controls.

### Email does work here

InfinityFree blocks PHP's built-in `mail()`, but not outbound SMTP. This
app has always used PHPMailer over SMTP, so password resets and
verification emails are fine.

## 4. Upload

FTP everything to `htdocs/` — FileZilla, or the control panel's file
manager for a zip.

**Upload:**

```
api/  css/  data/  images/  js/  lib/  logs/  uploads/
*.html  *.php  .htaccess
```

**Do not upload:**

```
backend/     the Python service — retired, and it cannot run here
tests/       golden vectors, no use on a server with no shell
sql/         run these in phpMyAdmin instead; .htaccess denies them anyway
.venv/  .git/  screenshots/  *.md
requirements.txt
```

### Leave the .wav files behind

`data/audio/samples/` holds each clip twice: ten `.mp3` (3 MB) and ten
`.wav` (**27 MB**). Upload only the MP3s.

The browser has always fetched MP3 — `browserSampleName()` swaps the
extension before requesting it. The WAVs existed for the server-side
CamillaDSP playback, which is retired, so nothing reads them any more. The
`.wav` names still appear in `api/adaptive_test.php`, but only as
identifiers in the label table; no file is ever fetched by that name.

That is the difference between a **42 MB** upload and a **15 MB** one, over
FTP, on free hosting. Keep the WAVs locally — they are the masters the MP3s
were made from.

Make sure `uploads/` and `logs/` exist and are writable (755 or 775).
`uploads/.htaccess` must come across too — it is what stops an uploaded
file being executed.

## 5. SSL

Control panel → **SSL/TLS** → order a free Let's Encrypt certificate. Ten
minutes or so, then force HTTPS in the panel.

Only after that is live should `force_https` be `true`. Setting it first
means a redirect loop to a scheme that is not being served yet.

---

## Checks

You cannot read a log here, so check from outside.

**Headers are actually being sent.** The six live in `.htaccess`, and if
`mod_headers` is unavailable they vanish with no error. PHP sends a subset
as a fallback, but only on `.php` responses:

```
curl -I https://yoursite.infinityfree.me/           | findstr /i "content-security x-frame"
curl -I https://yoursite.infinityfree.me/login.php  | findstr /i "content-security x-frame"
```

If the first is empty and the second is not, `.htaccess` is being ignored
and your static pages are unprotected. Tell me if so — it is fixable by
making the HTML pages PHP.

**Nothing sensitive is served:**

```
https://yoursite.infinityfree.me/api/config.local.php   -> blank or 403, never your password
https://yoursite.infinityfree.me/logs/sent-mail.log     -> 403
https://yoursite.infinityfree.me/api/totp_selftest.php  -> 403 or 404
```

**And the app itself:** register a new account, confirm the email arrives
and the link works, log in, take the full test, see recommendations with
charts.

---

## What is different here

**Sessions may be shorter.** Shared hosting cleans up session files
aggressively. A listening test kept in `$_SESSION` could expire mid-test on
a slow session; if that happens the fix is storing test state in the
database instead.

**~50,000 hits a day, fair use.** One listening test is 10 audio files plus
the page. Far beyond what you need, but it is a real ceiling.

**Execution time is capped.** Every query here is indexed and small. If the
recommendations page ever times out, the catalogue query is the one to look
at.

**No shell, so no `php -l`.** A syntax error on a page you edited in the
file manager shows as a blank white page. Edit locally, test locally,
upload.

**Free hosting can vanish.** Keep the code in git and export the database
now and then.

---

## If it breaks

There is no error log you can reach, so the fastest way to see a real
message is to temporarily set:

```php
'environment' => 'development',
```

That turns errors back on screen. **Find the problem, then put it back to
`production`** — leaving it means every visitor sees your file paths when
something goes wrong.
