<?php
// Minimal SendGrid helper using HTTP API (no Composer required)
function sendgrid_send($toEmail, $subject, $htmlBody, $plainBody = '', $fromEmail = null, $fromName = null) {
    $cfgPath = __DIR__ . '/sendgrid_config.php';
    if (!file_exists($cfgPath)) return [ 'ok' => false, 'error' => 'sendgrid_config missing' ];
    $cfg = require $cfgPath;
    if (empty($cfg['api_key'])) return [ 'ok' => false, 'error' => 'api_key missing' ];

    // defaults
    $fromEmail = $fromEmail ?? ($cfg['from_email'] ?? 'noreply@example.com');
    $fromName = $fromName ?? ($cfg['from_name'] ?? 'SomaZoi');

    $payload = [
        'personalizations' => [
            [
                'to' => [ [ 'email' => $toEmail ] ],
                'subject' => $subject
            ]
        ],
        'from' => [ 'email' => $fromEmail, 'name' => $fromName ],
        'content' => []
    ];

    if (!empty($plainBody)) $payload['content'][] = [ 'type' => 'text/plain', 'value' => $plainBody ];
    if (!empty($htmlBody)) $payload['content'][] = [ 'type' => 'text/html', 'value' => $htmlBody ];
    if (empty($payload['content'])) $payload['content'][] = [ 'type' => 'text/plain', 'value' => $subject ];

    $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $cfg['api_key'],
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($resp === false) return [ 'ok' => false, 'error' => $err ];
    if ($code >= 200 && $code < 300) return [ 'ok' => true, 'response' => $resp ];
    return [ 'ok' => false, 'error' => $resp, 'code' => $code ];
}