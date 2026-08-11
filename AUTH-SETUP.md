# Authentication setup

Two manual steps are needed before password reset works. Everything else
(CSRF, session timeouts, password rules, the rate-limit fix) is already
active with no setup.

## 1. Create the password_resets table — required

Open phpMyAdmin, select the **equalizeme** database, go to the **SQL**
tab, paste the contents of `sql/password_resets.sql`, and press Go.

Without this table, the "Forgot password" page returns a generic error and
the reason gets written to Apache's PHP error log.

## 2. Email delivery — optional

Reset links have to reach the user somehow. There are two modes.

### Mode A: no setup (works right now)

If SMTP isn't configured, reset emails are written to
`logs/sent-mail.log` instead of being sent. The flow still works end to
end — open that file, copy the reset link, paste it into the browser.

This is the easiest way to test and demo. `logs/` is gitignored, since
those files contain working reset links.

### Mode B: real email

1. **Download PHPMailer** — grab the source zip from
   <https://github.com/PHPMailer/PHPMailer/releases> (no Composer needed).
   Extract it so these paths exist:

   ```
   lib/PHPMailer/src/PHPMailer.php
   lib/PHPMailer/src/SMTP.php
   lib/PHPMailer/src/Exception.php
   ```

2. **Create your local config:**

   ```powershell
   copy api\config.example.php api\config.local.php
   ```

3. **Fill in your SMTP details** in `api/config.local.php` and set
   `'enabled' => true`.

   For Gmail you must use an **app password**, not your normal Google
   password — generate one at <https://myaccount.google.com/apppasswords>
   (needs 2-Step Verification enabled first). Never put your real account
   password in this file.

   `api/config.local.php` is gitignored so credentials don't get committed.

### Reset links and the changing tunnel URL

Reset links are built from the URL the request arrived on, so they
normally point at the right place automatically. But a link emailed under
one Cloudflare quick-tunnel URL stops working once you restart
`cloudflared` and the URL changes — the token is still valid, the domain
just no longer exists. Either request a fresh link after restarting, or
set `base_url` in `api/config.local.php` to pin it.

## What's protecting what

| Protection | Where |
|---|---|
| Password hashing (bcrypt) | `api/auth/login.php`, `register.php`, `reset-password.php` |
| Password strength rules | `api/password_policy.php` |
| Brute-force rate limiting | `api/rate_limit.php` |
| CSRF tokens | `api/csrf.php`, `api/csrf-token.php` |
| Session hardening + idle timeout | `api/session.php` |
| Reset tokens (hashed, single-use, expiring) | `api/auth/request-reset.php`, `reset-password.php` |

Session behaviour: sessions expire after **2 hours idle**, and the session
ID is rotated every **30 minutes** for active sessions. Both are constants
at the top of `api/session.php`.

## Known limitations

- **Resetting a password doesn't log out other devices.** PHP's default
  file-based sessions can't be looked up by user id, so there's no way to
  find and kill them. Would need a database-backed session store.
- **No email verification at signup.** Anyone can register with an address
  they don't own.
- **Registration still reveals whether an email is taken** (409 response).
  Password reset deliberately does not — it returns the same message
  either way. Making registration match would mean sending a "you already
  have an account" email instead of an inline error.
