<?php
require_once __DIR__ . '/../config/brevo.php';

function sendBrevoOtpEmail(
    string $toEmail,
    string $toName,
    string $otp
): bool {
    if (!function_exists('curl_init')) {
        error_log('Brevo error: PHP cURL is not enabled.');
        return false;
    }

    $payload = [
        'sender' => [
            'name' => BREVO_SENDER_NAME,
            'email' => BREVO_SENDER_EMAIL
        ],
        'to' => [[
            'email' => $toEmail,
            'name' => $toName
        ]],
        'subject' => 'PLMun Ticketing System - Verify Your Email',
        'textContent' =>
            "Hello {$toName}, your PLMun verification code is {$otp}. "
            . "This code expires in 10 minutes. If you did not register, ignore this email.",
        'htmlContent' =>
            '<h2>PLMun Email Verification</h2>'
            . '<p>Hello ' . htmlspecialchars($toName, ENT_QUOTES, 'UTF-8') . ',</p>'
            . '<p>Your verification code is:</p>'
            . '<h1 style="letter-spacing:6px;">'
            . htmlspecialchars($otp, ENT_QUOTES, 'UTF-8')
            . '</h1><p>This code expires in 10 minutes.</p>'
    ];

    $json = json_encode($payload);
    if ($json === false) {
        error_log('Brevo error: Could not encode email payload.');
        return false;
    }

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'accept: application/json',
            'api-key: ' . BREVO_API_KEY,
            'content-type: application/json'
        ],
        CURLOPT_POSTFIELDS => $json
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $error !== '') {
        error_log('Brevo connection error: ' . $error);
        return false;
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        error_log('Brevo API HTTP ' . $httpCode . ': ' . substr((string)$response, 0, 1000));
        return false;
    }

    return true;
}
