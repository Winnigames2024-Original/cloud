<?php
require_once __DIR__ . '/config.php';

function send_mail(string $to, string $subject, string $html): bool {
    if (!defined('SMTPBZ_KEY') || SMTPBZ_KEY === '') { error_log('iskra: SMTPBZ_KEY пуст'); return false; }
    $ch = curl_init('https://api.smtp.bz/v1/smtp/send');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Authorization: ' . SMTPBZ_KEY],
        CURLOPT_POSTFIELDS => http_build_query([
            'subject' => $subject, 'html' => $html,
            'from' => MAIL_FROM, 'name' => MAIL_NAME, 'to' => $to,
        ]),
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) { error_log("iskra mail $code: $resp"); return false; }
    return true;
}
