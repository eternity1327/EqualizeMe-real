# lib/

Third-party libraries that aren't installed via a package manager.

## PHPMailer (needed for password reset emails)

Download the **Source code (zip)** from:
https://github.com/PHPMailer/PHPMailer/releases

Extract it, rename the extracted folder (e.g. `PHPMailer-6.9.1`) to
`PHPMailer`, and place it here so these paths exist:

    lib/PHPMailer/src/PHPMailer.php
    lib/PHPMailer/src/SMTP.php
    lib/PHPMailer/src/Exception.php

`api/mailer.php` looks for exactly those three files. If they're missing,
reset emails are written to `logs/sent-mail.log` instead of being sent —
the app still works, it just can't email anyone.

Nothing else in this folder is required.
