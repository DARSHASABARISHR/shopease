<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';

/* ---- Defaults (override by adding these define()s to config/config.php - see README_UPDATE.txt) ---- */
$__wa = [
    'WHATSAPP_ENABLED'    => false,                 // set true after adding Twilio credentials
    'TWILIO_ACCOUNT_SID'  => '',                    // starts with AC...
    'TWILIO_AUTH_TOKEN'   => '',
    'TWILIO_WHATSAPP_FROM'=> '+14155238886',        // Twilio sandbox number
    'TWILIO_CONTENT_SID'  => '',                    // optional: approved template SID (HX...) for production
    'WHATSAPP_COUNTRY_CODE' => '91',
    'TWILIO_SANDBOX_JOIN' => 'join your-sandbox-code',   // shown to users as a hint while testing in the sandbox
];
foreach ($__wa as $k => $v) if (!defined($k)) define($k, $v);
unset($__wa);

function wa_enabled(): bool
{
    return WHATSAPP_ENABLED === true && str_starts_with((string)TWILIO_ACCOUNT_SID, 'AC') && TWILIO_AUTH_TOKEN !== '';
}

function wa_display(string $ten): string { return '+' . WHATSAPP_COUNTRY_CODE . ' ' . substr($ten, 0, 5) . ' ' . substr($ten, 5); }
function wa_mask(string $ten): string { return '+' . WHATSAPP_COUNTRY_CODE . ' ' . str_repeat('•', 6) . substr($ten, -4); }

/**
 * Sends the OTP on WhatsApp through Twilio.  Returns null on success, or a user-friendly error string.
 * (To use Meta WhatsApp Cloud API instead, only this function has to change.)
 */
function wa_send(string $tenDigit, string $otp): ?string
{
    if (!wa_enabled()) return 'WhatsApp OTP is not configured yet.';
    if (!function_exists('curl_init')) return 'The PHP cURL extension is disabled (enable extension=curl in php.ini).';

    $post = ['From' => 'whatsapp:' . TWILIO_WHATSAPP_FROM, 'To' => 'whatsapp:+' . WHATSAPP_COUNTRY_CODE . $tenDigit];
    if (TWILIO_CONTENT_SID !== '') {                       // production: approved authentication template
        $post['ContentSid'] = TWILIO_CONTENT_SID;
        $post['ContentVariables'] = json_encode(['1' => $otp]);
    } else {                                               // sandbox: free-form text
        $post['Body'] = "*{$otp}* is your " . APP_NAME . ' verification code. It is valid for ' . OTP_TTL_MINUTES . ' minutes. Do not share this code with anyone.';
    }
    $ch = curl_init('https://api.twilio.com/2010-04-01/Accounts/' . TWILIO_ACCOUNT_SID . '/Messages.json');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post), CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => TWILIO_ACCOUNT_SID . ':' . TWILIO_AUTH_TOKEN, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8,
    ]);
    $resp = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($resp === false) { error_log("Twilio cURL error: $cerr"); return 'Could not reach the WhatsApp service. ' . (stripos($cerr, 'SSL') !== false ? '(SSL certificate problem - see README_UPDATE.txt, troubleshooting.)' : 'Please try again.'); }
    $j = json_decode((string)$resp, true) ?: [];
    if ($http >= 200 && $http < 300 && !empty($j['sid'])) return null;

    error_log('Twilio error HTTP ' . $http . ': ' . $resp);
    return match ((int)($j['code'] ?? 0)) {
        63015, 63007, 63016 => 'This number has not joined the WhatsApp sandbox. On WhatsApp, send "' . TWILIO_SANDBOX_JOIN . '" to +' . ltrim(TWILIO_WHATSAPP_FROM, '+') . ' and try again.',
        21211, 21614        => 'That WhatsApp number is not valid.',
        20003               => 'WhatsApp service credentials are wrong (check the Twilio SID / Auth Token).',
        20429               => 'Too many WhatsApp messages sent. Please wait a minute.',
        default             => 'WhatsApp message could not be delivered right now.',
    };
}
