<?php
// Helper wrapper for PHPMailer usage. Expects composer autoload available at vendor/autoload.php
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function getMailerInstance() {
    $cfg = require __DIR__ . '/smtp_config.php';

    $mail = new PHPMailer(true);
    // use exceptions
    $mail->isSMTP();
    $mail->Host = $cfg['host'];
    $mail->SMTPAuth = true;
    $mail->Username = $cfg['username'];
    $mail->Password = $cfg['password'];
    $mail->SMTPSecure = $cfg['secure'] ?? 'tls';
    $mail->Port = $cfg['port'] ?? 587;
    $mail->setFrom($cfg['from_email'], $cfg['from_name']);
    // Recommended defaults
    $mail->CharSet = 'UTF-8';
    $mail->isHTML(true);
    return $mail;
}
