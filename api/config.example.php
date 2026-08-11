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
];
