<?php

return [
    'database' => [
        'host'     => '127.0.0.1',
        'name'     => 'equalizeme',
        'user'     => 'equalizeme_app',
        'password' => 'put-a-strong-password-here',
    ],

    'smtp' => [
        'enabled'   => false,
        'host'      => 'smtp.gmail.com',
        'port'      => 587,
        'secure'    => 'tls',
        'username'  => 'you@gmail.com',
        'password'  => 'your-app-password-here',
        'from_email'=> 'you@gmail.com',
        'from_name' => 'EqualizeME',
    ],

    'base_url' => '',

    'reset_token_lifetime_minutes' => 60,

    'force_https' => false,
];
