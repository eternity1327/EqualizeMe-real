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

    // 'development' on a laptop, 'production' on a public server.
    // Production turns off on-screen errors, forces HTTPS, and refuses to
    // build emailed links from the Host header — see api/config.php.
    'environment' => 'development',

    // The site's own address, without a trailing slash. Used for links sent
    // by email, so it must be the address a recipient can actually open.
    //
    //   local  ''                       (falls back to the request host)
    //   public 'https://equalizeme.example'
    //
    // REQUIRED when environment is 'production'. Left empty there, the app
    // raises rather than sending reset links built from a header the client
    // controls — which is how a reset token ends up pointing at somebody
    // else's server.
    'base_url' => '',

    'reset_token_lifetime_minutes' => 60,

    // Redundant once environment is 'production', which forces it anyway.
    // Kept separate so HTTPS can be enforced while still in development.
    'force_https' => false,

    // Require a new account to click a link in its email before it can log
    // in. Leave false locally — with SMTP off the "email" is a line in
    // logs/sent-mail.log. Turn it on before opening registration publicly,
    // otherwise anyone can sign up using an address that is not theirs.
    'require_email_verification' => false,
];
