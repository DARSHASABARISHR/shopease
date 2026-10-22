<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';

/**
 * Return eligibility for one delivered order line.
 * $row needs: order_status, delivered_at, created_at, is_returnable, return_window_days
 * @return array{ok:bool,reason:string,deadline:int}
 */
function return_eligibility(array $row): array
{
    $days = max(0, (int)$row['return_window_days']);
    $base = strtotime((string)($row['delivered_at'] ?: $row['created_at']));
    $deadline = $base + $days * 86400;
    if ($row['order_status'] !== 'Delivered')  return ['ok' => false, 'reason' => 'Returns open after delivery', 'deadline' => $deadline];
    if (!(int)$row['is_returnable'])           return ['ok' => false, 'reason' => 'Not returnable', 'deadline' => $deadline];
    if (time() > $deadline)                    return ['ok' => false, 'reason' => 'Return window closed (' . $days . ' day' . ($days === 1 ? '' : 's') . ')', 'deadline' => $deadline];
    return ['ok' => true, 'reason' => 'Return until ' . date('d M Y, h:i A', $deadline), 'deadline' => $deadline];
}

function return_status_label(string $s): string
{
    return ['Pending' => 'Verification pending', 'OTP_Verified' => 'Return requested', 'Approved' => 'Return approved',
            'Rejected' => 'Return rejected', 'Picked_Up' => 'Picked up'][$s] ?? $s;
}
