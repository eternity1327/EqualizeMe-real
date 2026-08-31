<?php

function app_config() {
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $defaults = [
        'database' => [
            'host' => '127.0.0.1',
            'name' => 'equalizeme',
            'user' => 'root',
            'password' => '',
        ],
        'smtp' => [
            'enabled' => false,
            'host' => '',
            'port' => 587,
            'secure' => 'tls',
            'username' => '',
            'password' => '',
            'from_email' => 'no-reply@equalizeme.local',
            'from_name' => 'EqualizeME',
        ],
        'base_url' => '',
        'reset_token_lifetime_minutes' => 60,

        'force_https' => false,

        // 'development' or 'production'. Defaults to development so a fresh
        // XAMPP checkout behaves as it always has; a deployed copy sets
        // 'production' in config.local.php and gets the stricter behaviour
        // in apply_environment() below.
        'environment' => 'development',

        // Whether a new account has to click a link in its email before it
        // can log in. Off by default because with SMTP unconfigured the
        // "email" is a line in logs/sent-mail.log, and nobody testing
        // locally wants to go fishing in there. Turn it on when the site is
        // public and anyone can register.
        'require_email_verification' => false,

        // Where the rate-limit counters are written. Empty means "work it
        // out" — see _rate_limit_dir() in api/rate_limit.php. Only worth
        // setting on hosting that gives PHP no writable temp directory,
        // where the limits would otherwise fail silently.
        'rate_limit_dir' => '',
    ];

    $localPath = __DIR__ . '/config.local.php';
    $local = file_exists($localPath) ? require $localPath : [];
    if (!is_array($local)) {
        $local = [];
    }

    $config = array_merge($defaults, $local);
    $config['smtp'] = array_merge($defaults['smtp'], $local['smtp'] ?? []);
    $config['database'] = array_merge($defaults['database'], $local['database'] ?? []);

    return $config;
}

function is_production() {
    return (app_config()['environment'] ?? 'development') === 'production';
}

function require_email_verification() {
    return !empty(app_config()['require_email_verification']);
}

/**
 * Settings that must differ between a laptop and a public server.
 *
 * Called once from start_secure_session(), which every entry point already
 * goes through — so there is no page that can forget to apply it.
 */
/**
 * Security headers, in case Apache is not sending them.
 *
 * The real set lives in the project's .htaccess, which covers every
 * response including the static .html pages. This is a fallback for
 * hosting where mod_headers is unavailable or .htaccess is ignored —
 * shared hosting varies, and the failure mode is silent: no error, no
 * warning, just no headers.
 *
 * It can only protect the .php responses, so it is a safety net rather
 * than a replacement. After deploying anywhere new, check the real thing:
 *
 *     curl -I https://your-site/ | grep -i content-security
 *
 * headers_sent() guards against a warning if output has already begun.
 */
function send_security_headers() {
    if (headers_sent()) {
        return;
    }

    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: DENY");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header(
        "Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; "
        . "style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; "
        . "font-src 'self' data:; connect-src 'self'; object-src 'none'; "
        . "base-uri 'self'; form-action 'self'; frame-ancestors 'none'"
    );
}


function apply_environment() {
    // Sent in both environments. There is nothing about development that
    // benefits from missing them, and a header that only appears in
    // production is a header nobody ever tests.
    send_security_headers();

    if (!is_production()) {
        return;
    }

    // XAMPP ships with display_errors on, which prints stack traces —
    // absolute file paths, and often fragments of the failing query —
    // straight to whoever triggered the error. Fine on a laptop. On a
    // public box it is free reconnaissance.
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);
}

/**
 * The site's own address, used to build links that go out by email.
 *
 * In production this MUST come from config. The fallback below reads
 * $_SERVER['HTTP_HOST'], which is just a request header — the client sends
 * it and can put anything in it.
 *
 * That matters here specifically because this function builds password
 * reset links. An attacker asks for a reset of your email while sending
 * "Host: evil.example". You receive a genuine email, from the real site,
 * carrying your real single-use token — pointed at their server. You click
 * it, they read the token out of their access log, and they own the
 * account. You did nothing wrong and nothing looks suspicious.
 *
 * On localhost the fallback is a convenience. In production it is a
 * vulnerability, so there it is not offered: a missing base_url raises
 * rather than quietly producing poisonable links.
 */
function app_base_url() {
    $configured = trim(app_config()['base_url'] ?? '');
    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    if (is_production()) {
        throw new RuntimeException(
            "base_url must be set in api/config.local.php when environment is "
            . "'production'. Without it, links sent by email would be built "
            . "from the client-supplied Host header."
        );
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $isHttps ? 'https' : 'http';

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $projectPath = rtrim(preg_replace('#/api(/auth)?$#', '', $scriptDir), '/');

    return $scheme . '://' . $host . $projectPath;
}
