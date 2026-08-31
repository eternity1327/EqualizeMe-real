<?php

require_once __DIR__ . '/config.php';

function _mailer_log_path() {
    $dir = __DIR__ . '/../logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . '/sent-mail.log';
}

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
    $composer = __DIR__ . '/../vendor/autoload.php';
    if (file_exists($composer)) {
        require_once $composer;
        if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            return true;
        }
    }

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
 * Tidy an SMTP password before it goes on the wire.
 *
 * Google shows app passwords in four groups of four — "abcd efgh ijkl
 * mnop" — and people copy them exactly as displayed. The spaces are
 * presentation; the credential is the sixteen letters. PHPMailer passes
 * whatever it is handed straight into SMTP AUTH, so a copied-with-spaces
 * password fails with nothing more helpful than "Could not authenticate",
 * which sends you looking for a problem that is not there.
 *
 * Internal spaces are only removed on a Google host, and only when the
 * result is the exact shape Google generates: sixteen lowercase letters,
 * no digits. Elsewhere a space may be a real character in a passphrase,
 * and silently deleting part of somebody's password would be a far worse
 * bug than the one this fixes.
 *
 * That test is tight but not perfect. A Gmail passphrase of four lowercase
 * words totalling sixteen letters — "my long pass phrase" — would match
 * the pattern and be mangled. It is accepted because Gmail SMTP has not
 * taken account passwords since 2022: on this host a value of that shape
 * is an app password in all but the most contrived case. api/smtp_test.php
 * says out loud when it has stripped, so the behaviour is visible rather
 * than magic.
 *
 * Leading and trailing whitespace is always dropped, on every host. Nobody
 * has ever meant to include that.
 */
function _mailer_clean_password($password, $host) {
    $password = trim((string)$password);

    $isGoogle = stripos((string)$host, 'gmail.com') !== false
        || stripos((string)$host, 'googlemail.com') !== false;

    if (!$isGoogle) {
        return $password;
    }

    $stripped = preg_replace('/\s+/', '', $password);

    return preg_match('/^[a-z]{16}$/', $stripped) ? $stripped : $password;
}


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
        $mail->Username = trim($smtp['username']);
        $mail->Password = _mailer_clean_password($smtp['password'], $smtp['host']);
        $mail->SMTPSecure = $smtp['secure'] === 'ssl'
            ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 15;

        // Only ever set by api/smtp_test.php, which is CLI-only. Prints the
        // full SMTP exchange, which is the fastest way to see what a server
        // is actually objecting to — and far too chatty for a web request.
        if (!empty($GLOBALS['MAILER_DEBUG']) && PHP_SAPI === 'cli') {
            $mail->SMTPDebug = 2;
            $mail->Debugoutput = 'echo';
        }

        $mail->setFrom($smtp['from_email'], $smtp['from_name']);
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->Body = $bodyText;
        $mail->isHTML(false);

        $mail->send();
        return ['sent' => true, 'logged' => false, 'reason' => 'ok'];
    } catch (\Throwable $e) {
        // Throwable rather than Exception. Since PHP 7, engine failures are
        // Errors, and an Error does not extend Exception — so a TypeError
        // or an ArgumentCountError raised inside PHPMailer would sail past
        // a `catch (Exception)` untouched.
        //
        // That matters here more than it looks. This whole block exists so
        // that a broken mail setup degrades to writing the message into a
        // log file instead of taking the request down. Catching only half
        // the failures meant the fallback quietly did not cover the case
        // most likely to happen: a misconfigured SMTP block passing a
        // wrong-typed value into the library.
        $logged = _mailer_write_to_log($to, $subject, $bodyText, 'SMTP send failed: ' . $e->getMessage());
        return ['sent' => false, 'logged' => $logged, 'reason' => 'send_failed'];
    }
}
