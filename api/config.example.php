<?php
/**
 * TEMPLATE — copy this file to config.local.php and fill in real values.
 *
 *     copy api\config.example.php api\config.local.php
 *
 * config.local.php is gitignored, so your credentials never get committed
 * or pushed. This template is safe to commit because it contains no real
 * secrets.
 *
 * If config.local.php doesn't exist, the app still runs — password reset
 * emails just get written to a log file instead of actually being sent
 * (see api/mailer.php), which is fine for local testing.
 */

return [
    // ---------------------------------------------------------------
    // Database
    //
    // Defaults to XAMPP's root account with no password, which is fine
    // only while MySQL listens on localhost alone. Create a limited
    // account (see sql/create_app_user.sql) and put it here instead —
    // this file is gitignored, so the credentials stay out of the repo.
    //
    // A limited account also contains the damage from any future bug:
    // it can read and write the application's tables and nothing else,
    // so it cannot drop the schema or create users.
    // ---------------------------------------------------------------
    'database' => [
        'host'     => '127.0.0.1',
        'name'     => 'equalizeme',
        'user'     => 'equalizeme_app',
        'password' => 'put-a-strong-password-here',
    ],

    // ---------------------------------------------------------------
    // SMTP — used to send password reset emails.
    //
    // For Gmail you must use an APP PASSWORD, not your normal account
    // password. Generate one at https://myaccount.google.com/apppasswords
    // (requires 2-Step Verification to be enabled on the account).
    // Never put your real Google password here.
    // ---------------------------------------------------------------
    'smtp' => [
        'enabled'   => false,               // flip to true once filled in
        'host'      => 'smtp.gmail.com',
        'port'      => 587,
        'secure'    => 'tls',               // 'tls' for 587, 'ssl' for 465
        'username'  => 'you@gmail.com',
        'password'  => 'your-app-password-here',
        'from_email'=> 'you@gmail.com',
        'from_name' => 'EqualizeME',
    ],

    // ---------------------------------------------------------------
    // Public base URL of the site, used to build reset links in emails.
    //
    // With Cloudflare quick tunnels this changes every restart, so update
    // it whenever you restart cloudflared — otherwise reset links will
    // point at a dead address. Leave empty to auto-detect from the
    // incoming request, which is usually correct.
    // ---------------------------------------------------------------
    'base_url' => '',

    // How long a password reset link stays valid, in minutes.
    'reset_token_lifetime_minutes' => 60,

    // ---------------------------------------------------------------
    // Redirect plain HTTP requests to HTTPS.
    //
    // Leave false for local development — XAMPP serves plain HTTP on
    // localhost and there's no certificate to redirect to. (localhost is
    // exempt even when this is on, but keeping it off locally avoids
    // surprises.)
    //
    // Turn it on once the site is served over TLS. Behind Cloudflare this
    // is belt-and-braces, since Cloudflare already forces HTTPS at its
    // edge; it matters on hosting that doesn't.
    // ---------------------------------------------------------------
    'force_https' => false,
];
