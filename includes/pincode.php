<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';

/**
 * Server-side double check of a pincode against the free India Post API.
 * Returns ['district'=>..,'state'=>..] on success, or null when the API is unreachable / has no record
 * (in which case callers must NOT block the customer).
 */
function pincode_lookup(string $pin): ?array
{
    if (!valid_pincode($pin) || !function_exists('curl_init')) return null;
    $ch = curl_init('https://api.postalpincode.in/pincode/' . $pin);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_FOLLOWLOCATION => false]);
    $resp = curl_exec($ch);
    curl_close($ch);
    if (!is_string($resp)) return null;
    $j = json_decode($resp, true);
    if (!is_array($j) || ($j[0]['Status'] ?? '') !== 'Success' || empty($j[0]['PostOffice'][0]['State'])) return null;
    return ['district' => (string)$j[0]['PostOffice'][0]['District'], 'state' => (string)$j[0]['PostOffice'][0]['State']];
}

function norm_state(string $s): string { return trim(preg_replace('/\s+/', ' ', str_replace('&', 'and', strtolower($s)))); }
function same_state(string $a, string $b): bool { return norm_state($a) === norm_state($b); }
