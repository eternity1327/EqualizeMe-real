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

function app_base_url() {
    $configured = trim(app_config()['base_url'] ?? '');
    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $isHttps ? 'https' : 'http';

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $projectPath = rtrim(preg_replace('#/api(/auth)?$#', '', $scriptDir), '/');

    return $scheme . '://' . $host . $projectPath;
}
