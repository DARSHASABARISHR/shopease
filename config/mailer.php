<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

$autoload = ROOT_PATH . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;                       // composer install
} else {                                          // manual download of PHPMailer into /PHPMailer/src
    foreach (['Exception', 'PHPMailer', 'SMTP'] as $f) {
        $p = ROOT_PATH . '/PHPMailer/src/' . $f . '.php';
        if (is_file($p)) require_once $p;
    }
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

/** Centralised PHPMailer factory (Gmail SMTP, STARTTLS on port 587). */
function create_mailer(): PHPMailer
{
    $m = new PHPMailer(true);
    $m->isSMTP();
    $m->Host       = SMTP_HOST;
    $m->Port       = SMTP_PORT;
    $m->SMTPAuth   = true;
    $m->Username   = SMTP_USER;
    $m->Password   = str_replace(' ', '', SMTP_PASS);
    $m->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $m->CharSet    = 'UTF-8';
    $m->Timeout    = 15;
    $m->SMTPDebug  = 0;
    $m->setFrom(MAIL_FROM, MAIL_FROM_NAME);
    $m->isHTML(true);
    return $m;
}

/** Sends an email; returns true on success. Never throws (errors are logged). */
function send_mail(string $toEmail, string $toName, string $subject, string $html, string $text = ''): bool
{
    if (!class_exists(PHPMailer::class)) {
        error_log('PHPMailer not installed. Run "composer install".');
        return false;
    }
    try {
        $m = create_mailer();
        $m->addAddress($toEmail, $toName);
        $m->Subject = $subject;
        $m->Body    = $html;
        $m->AltBody = $text !== '' ? $text : strip_tags($html);
        return $m->send();
    } catch (MailException $e) {
        error_log('Mail error to ' . $toEmail . ': ' . $e->getMessage());
        return false;
    } catch (\Throwable $e) {
        error_log('Mail failure: ' . $e->getMessage());
        return false;
    }
}
