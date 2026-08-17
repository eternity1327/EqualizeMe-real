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
        $logged = _mailer_write_to_log($to, $subject, $bodyText, 'SMTP send failed: ' . $e->getMessage());
        return ['sent' => false, 'logged' => $logged, 'reason' => 'send_failed'];
    }
}
