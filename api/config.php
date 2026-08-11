<?php
/**
 * Loads local configuration (SMTP credentials, base URL) from
 * config.local.php, falling back to safe defaults when that file doesn't
 * exist — so a fresh clone of the project still runs without anyone
 * having to set up email first.
 *
 * See config.example.php for the template and what each value does.
 */

function app_config() {
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $defaults = [
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
    ];

    $localPath = __DIR__ . '/config.local.php';
    $local = file_exists($localPath) ? require $localPath : [];
    if (!is_array($local)) {
        $local = [];
    }

    // Merge one level deep so a partial 'smtp' block in config.local.php
    // still inherits the defaults for anything it doesn't specify.
    $config = array_merge($defaults, $local);
    $config['smtp'] = array_merge($defaults['smtp'], $local['smtp'] ?? []);

    return $config;
}

/**
 * The site's public base URL, e.g. https://something.trycloudflare.com/equalizeme-ai
 *
 * Uses the configured value if set; otherwise reconstructs it from the
 * current request, which handles tunnels and LAN IPs without needing to
 * be updated by hand every time the URL changes.
 */
function app_base_url() {
    $configured = trim(app_config()['base_url'] ?? '');
    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $isHttps ? 'https' : 'http';

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // This file lives in <project>/api/, so the project root is one level up
    // from this script's directory as seen from the web root.
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $projectPath = rtrim(preg_replace('#/api(/auth)?$#', '', $scriptDir), '/');

    return $scheme . '://' . $host . $projectPath;
}
