<?php
/**
 * Sending email from XAMPP.
 *
 * PHP's built-in mail() doesn't work on a default XAMPP install (there's
 * no local mail server), so this uses PHPMailer over SMTP when it's
 * available and configured.
 *
 * There are three modes, chosen automatically:
 *
 *   1. PHPMailer present + SMTP enabled in config.local.php
 *      -> real email is sent.
 *
 *   2. Anything missing (no PHPMailer, or SMTP not configured)
 *      -> the message is appended to logs/sent-mail.log instead.
 *         The reset flow still works end to end: open that file, copy the
 *         link, paste it in the browser. This is the intended way to test
 *         and demo without setting up a mail account at all.
 *
 *   3. SMTP configured but sending fails
 *      -> falls back to the log as well, and reports failure to the
 *         caller so it can decide what to tell the user.
 *
 * To enable real sending:
 *   1. Download PHPMailer: https://github.com/PHPMailer/PHPMailer/releases
 *      (the source zip — no Composer needed)
 *   2. Extract so that these exist:
 *        lib/PHPMailer/src/PHPMailer.php
 *        lib/PHPMailer/src/SMTP.php
 *        lib/PHPMailer/src/Exception.php
 *   3. Copy api/config.example.php to api/config.local.php, fill in your
 *      SMTP details, and set 'enabled' => true.
 */

require_once __DIR__ . '/config.php';

function _mailer_log_path() {
    $dir = __DIR__ . '/../logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . '/sent-mail.log';
}

/**
 * Writes the message to the log instead of sending it. Always succeeds
 * (short of the disk being unwritable), so the caller never dead-ends.
 */
function _mailer_write_to_log($to, $subject, $body, $reason) {
    $entry = str_repeat('=', 70) . "\n"
        . "TIME:    " . date('Y-m-d H:i:s') . "\n"
        . "TO:      {$to}\n"
        . "SUBJECT: {$subject}\n"
        . "REASON:  {$reason}\n"
        . str_repeat('-', 70) . "\n"
        . $body . "\n\n";

    return @file_put_contents(_mailer_log_path(), $entry, FILE_APPEND | LOCK_EX) !== false;
}

function _mailer_load_phpmailer() {
    // Composer install, if the project ever gains one.
    $composer = __DIR__ . '/../vendor/autoload.php';
    if (file_exists($composer)) {
        require_once $composer;
        if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            return true;
        }
    }

    // Manual install — the documented path above.
    $base = __DIR__ . '/../lib/PHPMailer/src/';
    $files = ['Exception.php', 'PHPMailer.php', 'SMTP.php'];

    foreach ($files as $file) {
        if (!file_exists($base . $file)) {
            return false;
        }
    }
    foreach ($files as $file) {
        require_once $base . $file;
    }

    return class_exists('PHPMailer\\PHPMailer\\PHPMailer');
}

/**
 * Sends an email, or logs it if sending isn't possible.
 *
 * Returns ['sent' => bool, 'logged' => bool, 'reason' => string].
 * Callers should treat 'sent' => false as "tell the user to check the
 * log", not as a hard error, since the log path is a supported mode.
 */
function send_email($to, $subject, $bodyText) {
    $config = app_config();
    $smtp = $config['smtp'];

    if (empty($smtp['enabled'])) {
        $logged = _mailer_write_to_log($to, $subject, $bodyText, 'SMTP not enabled in config.local.php');
        return ['sent' => false, 'logged' => $logged, 'reason' => 'smtp_disabled'];
    }

    if (!_mailer_load_phpmailer()) {
        $logged = _mailer_write_to_log($to, $subject, $bodyText, 'PHPMailer not installed (see api/mailer.php)');
        return ['sent' => false, 'logged' => $logged, 'reason' => 'phpmailer_missing'];
    }

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = $smtp['host'];
        $mail->Port = (int)$smtp['port'];
        $mail->SMTPAuth = true;
        $mail->Username = $smtp['username'];
        $mail->Password = $smtp['password'];
        $mail->SMTPSecure = $smtp['secure'] === 'ssl'
            ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 15;

        $mail->setFrom($smtp['from_email'], $smtp['from_name']);
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->Body = $bodyText;
        $mail->isHTML(false);

        $mail->send();
        return ['sent' => true, 'logged' => false, 'reason' => 'ok'];
    } catch (Exception $e) {
        // Deliberately not surfacing $e->getMessage() to the user — SMTP
        // errors can echo back the username being authenticated.
        $logged = _mailer_write_to_log($to, $subject, $bodyText, 'SMTP send failed: ' . $e->getMessage());
        return ['sent' => false, 'logged' => $logged, 'reason' => 'send_failed'];
    }
}
