<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';

function email_layout(string $title, string $body): string
{
    return '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#eaeded;font-family:Arial,Helvetica,sans-serif;color:#0f1111;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eaeded;padding:20px 0;"><tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;">
<tr><td style="background:#131921;padding:18px 24px;"><span style="font-size:26px;font-weight:bold;color:#ffffff;">Shop<span style="color:#febd69;">Ease</span></span></td></tr>
<tr><td style="padding:28px 24px;"><h2 style="margin:0 0 16px;color:#232f3e;">' . $title . '</h2>' . $body . '</td></tr>
<tr><td style="background:#f7f7f7;padding:16px 24px;font-size:12px;color:#565959;text-align:center;">This is an automated message from ' . e(APP_NAME) . '. Please do not reply.<br>&copy; ' . date('Y') . ' ' . e(APP_NAME) . '</td></tr>
</table></td></tr></table></body></html>';
}

function tpl_otp(string $name, string $otp): array
{
    $body = '<p>Hi ' . e($name) . ',</p><p>Use the one-time password below to verify your ' . e(APP_NAME) . ' account. It is valid for ' . OTP_TTL_MINUTES . ' minutes.</p>
<p style="text-align:center;margin:28px 0;"><span style="display:inline-block;font-size:34px;letter-spacing:10px;font-weight:bold;background:#fff8e7;border:2px dashed #febd69;padding:14px 26px;border-radius:8px;">' . e($otp) . '</span></p>
<p style="color:#565959;font-size:13px;">Never share this OTP with anyone. If you did not create an account, ignore this email.</p>';
    return ['subject' => 'Your ' . APP_NAME . ' verification code: ' . $otp, 'html' => email_layout('Verify your email', $body), 'text' => "Your ShopEase OTP is $otp (valid " . OTP_TTL_MINUTES . ' minutes).'];
}

function tpl_welcome(string $name): array
{
    $body = '<p>Hi ' . e($name) . ',</p><p>Your email is verified and your account is now <strong>active</strong>. Welcome to ' . e(APP_NAME) . '!</p>
<ul style="line-height:1.8;"><li>Great deals across Electronics, Fashion, Home &amp; more</li><li>Pay securely with UPI, Cards, Net Banking or Cash on Delivery</li><li>Track every order from your account</li></ul>
<p style="text-align:center;margin:26px 0;"><a href="' . e(APP_URL) . '/index.php" style="background:#ffd814;color:#0f1111;text-decoration:none;padding:12px 28px;border-radius:20px;font-weight:bold;">Start Shopping</a></p>';
    return ['subject' => 'Welcome to ' . APP_NAME . '!', 'html' => email_layout('Welcome to ShopEase 🎉', $body), 'text' => "Welcome to ShopEase, $name! Your account is active."];
}

/** $order: orders row, $items: rows with name, quantity, price */
function tpl_order(array $order, array $items, string $customerName): array
{
    $rows = '';
    foreach ($items as $it) {
        $line = (float)$it['price'] * (int)$it['quantity'];
        $rows .= '<tr><td style="padding:10px;border-bottom:1px solid #eee;">' . e($it['name']) . '</td>
<td style="padding:10px;border-bottom:1px solid #eee;text-align:center;">' . (int)$it['quantity'] . '</td>
<td style="padding:10px;border-bottom:1px solid #eee;text-align:right;">' . money($it['price']) . '</td>
<td style="padding:10px;border-bottom:1px solid #eee;text-align:right;">' . money($line) . '</td></tr>';
    }
    $isPaid = $order['payment_status'] === 'Paid';
    $badge  = $isPaid
        ? '<span style="background:#067d62;color:#fff;padding:4px 12px;border-radius:12px;font-size:12px;font-weight:bold;">PAID</span>'
        : '<span style="background:#c45500;color:#fff;padding:4px 12px;border-radius:12px;font-size:12px;font-weight:bold;">CASH ON DELIVERY</span>';
    $method = $order['payment_method'] === 'COD' ? 'Cash on Delivery' : 'Online (Razorpay)';

    $body = '<p>Hi ' . e($customerName) . ', thank you for your order! We\'ll let you know when it ships.</p>
<table role="presentation" width="100%" style="margin:14px 0;font-size:14px;"><tr>
<td><strong>Order ID:</strong> #' . (int)$order['order_id'] . '<br><strong>Date:</strong> ' . e(date('d M Y, h:i A', strtotime((string)$order['created_at']))) . '<br><strong>Payment method:</strong> ' . e($method) . '</td>
<td style="text-align:right;">' . $badge . '</td></tr></table>
<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:14px;">
<tr style="background:#232f3e;color:#fff;"><th style="padding:10px;text-align:left;">Product</th><th style="padding:10px;">Qty</th><th style="padding:10px;text-align:right;">Price</th><th style="padding:10px;text-align:right;">Total</th></tr>' . $rows . '</table>
<table width="100%" style="margin-top:12px;font-size:14px;">
<tr><td style="text-align:right;padding:3px;">Items total:</td><td style="text-align:right;width:120px;">' . money($order['items_total']) . '</td></tr>
<tr><td style="text-align:right;padding:3px;">Delivery:</td><td style="text-align:right;">' . ((float)$order['delivery_charge'] > 0 ? money($order['delivery_charge']) : 'FREE') . '</td></tr>
<tr><td style="text-align:right;padding:3px;">GST (' . rtrim(rtrim(number_format(GST_RATE, 2), '0'), '.') . '%):</td><td style="text-align:right;">' . money($order['gst_amount']) . '</td></tr>
<tr><td style="text-align:right;padding:6px 3px;font-size:16px;"><strong>Net payable:</strong></td><td style="text-align:right;font-size:16px;"><strong>' . money($order['total_amount']) . '</strong></td></tr></table>
<div style="margin-top:18px;padding:14px;background:#f7f7f7;border-radius:6px;font-size:14px;"><strong>Shipping to</strong><br>' . e($order['shipping_name']) . '<br>' . nl2br(e($order['shipping_address'])) . '<br>Mobile: ' . e($order['shipping_phone']) . '</div>';
    return ['subject' => 'Order #' . (int)$order['order_id'] . ' confirmed - ' . APP_NAME, 'html' => email_layout('Order Confirmed ✔', $body), 'text' => 'Your ShopEase order #' . (int)$order['order_id'] . ' is confirmed. Total: ' . money($order['total_amount'])];
}

function tpl_password_otp(string $name, string $otp, string $purpose): array
{
    $what = $purpose === 'change' ? 'change your password' : 'reset your password';
    $body = '<p>Hi ' . e($name) . ',</p><p>Use this one-time password to ' . $what . '. It is valid for ' . OTP_TTL_MINUTES . ' minutes.</p>
<p style="text-align:center;margin:28px 0;"><span style="display:inline-block;font-size:34px;letter-spacing:10px;font-weight:bold;background:#fff8e7;border:2px dashed #febd69;padding:14px 26px;border-radius:8px;">' . e($otp) . '</span></p>
<p style="color:#565959;font-size:13px;">If you did not request this, ignore this email - your password will stay unchanged. Never share this OTP with anyone.</p>';
    return ['subject' => APP_NAME . ' password OTP: ' . $otp, 'html' => email_layout($purpose === 'change' ? 'Change your password' : 'Reset your password', $body), 'text' => "Your ShopEase password OTP is $otp (valid " . OTP_TTL_MINUTES . ' minutes).'];
}

function tpl_password_changed(string $name): array
{
    $body = '<p>Hi ' . e($name) . ',</p><p>Your ' . e(APP_NAME) . ' password was changed on ' . e(date('d M Y, h:i A')) . '.</p>
<p>If this was you, no action is needed. If not, reset your password immediately: <a href="' . e(APP_URL) . '/forgot_password.php">Reset password</a>.</p>';
    return ['subject' => 'Your ' . APP_NAME . ' password was changed', 'html' => email_layout('Password changed', $body), 'text' => 'Your ShopEase password was changed. If this was not you, reset it immediately.'];
}

/** "Out for delivery" email carrying the 4-digit delivery OTP and the exact COD amount. */
function tpl_delivery_otp(array $order, string $customerName, string $otp): array
{
    $isCod = $order['payment_method'] === 'COD';
    $pay = $isCod
        ? '<div style="background:#fff3e0;border-left:4px solid #c45500;padding:12px 14px;margin:16px 0;font-size:15px;"><strong>Cash on Delivery</strong><br>Please keep exactly <strong style="font-size:20px;color:#b12704;">' . money($order['total_amount']) . '</strong> ready to pay to the delivery agent.</div>'
        : '<div style="background:#e7f7ee;border-left:4px solid #067d62;padding:12px 14px;margin:16px 0;font-size:15px;"><strong>Already paid online.</strong> Nothing to pay on delivery.</div>';
    $body = '<p>Hi ' . e($customerName) . ',</p><p>Great news - your order <strong>#' . (int)$order['order_id'] . '</strong> is <strong>out for delivery</strong> and <strong>arrives today</strong>.</p><div style="background:#fff3e0;border-left:4px solid #c45500;padding:12px 14px;margin:14px 0;font-size:14px;">📍 <strong>Please be available</strong> at the address below (and keep your phone on) to receive the package.</div>' . $pay . '
<p style="margin-bottom:6px;">Your <strong>Delivery OTP</strong>:</p>
<p style="text-align:center;margin:12px 0 22px;"><span style="display:inline-block;font-size:40px;letter-spacing:14px;font-weight:bold;background:#fff8e7;border:2px dashed #febd69;padding:14px 26px 14px 38px;border-radius:8px;">' . e($otp) . '</span></p>
<p style="font-size:14px;"><strong>Share this OTP with the delivery agent only when you receive your package.</strong> Do not tell it to anyone else or over the phone before the delivery. It is valid for 24 hours.</p>
<div style="margin-top:14px;padding:14px;background:#f7f7f7;border-radius:6px;font-size:14px;"><strong>Delivering to</strong><br>' . e($order['shipping_name']) . '<br>' . nl2br(e($order['shipping_address'])) . '<br>Mobile: ' . e($order['shipping_phone']) . '</div>';
    return ['subject' => 'Out for Delivery: Your ShopEase Order #' . (int)$order['order_id'] . ' arrives today!',
            'html' => email_layout('Your order is out for delivery 🚚', $body),
            'text' => 'ShopEase order #' . (int)$order['order_id'] . ' is out for delivery. Delivery OTP: ' . $otp . '. ' . ($isCod ? 'Amount to pay in cash: ' . money($order['total_amount']) . '.' : 'Already paid online.') . ' Share the OTP only with the delivery agent.'];
}

/** Final "Order Delivered & Payment Received" email. */
function tpl_delivered(array $order, string $customerName): array
{
    $isCod = $order['payment_method'] === 'COD';
    $method = $isCod ? 'Cash on Delivery (cash received)' : 'Online payment (Razorpay)';
    $body = '<p>Hi ' . e($customerName) . ',</p><p>Your order <strong>#' . (int)$order['order_id'] . '</strong> has been <strong>delivered</strong> and your payment has been received. Thank you for shopping with ' . e(APP_NAME) . '!</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:16px 0;font-size:14px;border-collapse:collapse;">
<tr><td style="padding:8px;border-bottom:1px solid #eee;">Order ID</td><td style="padding:8px;border-bottom:1px solid #eee;text-align:right;">#' . (int)$order['order_id'] . '</td></tr>
<tr><td style="padding:8px;border-bottom:1px solid #eee;">Delivered on</td><td style="padding:8px;border-bottom:1px solid #eee;text-align:right;">' . e(date('d M Y, h:i A')) . '</td></tr>
<tr><td style="padding:8px;border-bottom:1px solid #eee;">Payment method</td><td style="padding:8px;border-bottom:1px solid #eee;text-align:right;">' . e($method) . '</td></tr>
<tr><td style="padding:8px;border-bottom:1px solid #eee;"><strong>Amount received</strong></td><td style="padding:8px;border-bottom:1px solid #eee;text-align:right;font-size:17px;"><strong>' . money($order['total_amount']) . '</strong></td></tr>
<tr><td style="padding:8px;">Payment status</td><td style="padding:8px;text-align:right;"><span style="background:#067d62;color:#fff;padding:4px 12px;border-radius:12px;font-size:12px;font-weight:bold;">PAID</span></td></tr></table>
<p style="text-align:center;margin:24px 0;"><a href="' . e(APP_URL) . '/index.php" style="background:#ffd814;color:#0f1111;text-decoration:none;padding:12px 28px;border-radius:20px;font-weight:bold;">Continue Shopping</a></p>';
    return ['subject' => 'Order #' . (int)$order['order_id'] . ' delivered & payment received - ' . APP_NAME,
            'html' => email_layout('Order Delivered & Payment Received ✔', $body),
            'text' => 'Your ShopEase order #' . (int)$order['order_id'] . ' was delivered. Payment of ' . money($order['total_amount']) . ' received. Thank you!'];
}

function tpl_return_otp(string $name, string $otp, string $product): array
{
    $body = '<p>Hi ' . e($name) . ',</p><p>Use this OTP to confirm your return request for <strong>' . e($product) . '</strong>. It is valid for 10 minutes.</p>
<p style="text-align:center;margin:28px 0;"><span style="display:inline-block;font-size:34px;letter-spacing:10px;font-weight:bold;background:#fff8e7;border:2px dashed #febd69;padding:14px 26px;border-radius:8px;">' . e($otp) . '</span></p>
<p style="color:#565959;font-size:13px;">If you did not request a return, ignore this email. Never share this OTP.</p>';
    return ['subject' => APP_NAME . ' return OTP: ' . $otp, 'html' => email_layout('Confirm your return request', $body), 'text' => "Your ShopEase return OTP is $otp (valid 10 minutes)."];
}

function tpl_return_update(string $name, string $product, int $orderId, string $status): array
{
    $msg = ['Approved' => 'Your return request has been <strong>approved</strong>. Our team will arrange the pickup.',
            'Rejected' => 'Sorry, your return request was <strong>rejected</strong>. Contact support if you need help.',
            'Picked_Up' => 'We have <strong>picked up</strong> your returned item. Any refund will be processed by our team.'][$status] ?? 'Your return status changed.';
    $body = '<p>Hi ' . e($name) . ',</p><p>Update for <strong>' . e($product) . '</strong> (order #' . $orderId . '):</p><p>' . $msg . '</p>';
    return ['subject' => 'Return update for order #' . $orderId . ' - ' . APP_NAME, 'html' => email_layout('Return request update', $body), 'text' => 'Return update for order #' . $orderId . ': ' . $status];
}

function tpl_cancel_otp(array $order, string $name, string $otp, string $reason): array
{
    $body = '<p>Hi ' . e($name) . ',</p><p>The ' . e(APP_NAME) . ' team has asked to <strong>cancel your order #' . (int)$order['order_id'] . '</strong> (' . money($order['total_amount']) . ').</p>
<div style="background:#f7f7f7;border-left:4px solid #c45500;padding:12px 14px;margin:14px 0;font-size:14px;"><strong>Reason given</strong><br>' . nl2br(e($reason)) . '</div>
<p>To confirm the cancellation, give this OTP to our team. It is valid for 10 minutes.</p>
<p style="text-align:center;margin:24px 0;"><span style="display:inline-block;font-size:34px;letter-spacing:10px;font-weight:bold;background:#fff8e7;border:2px dashed #febd69;padding:14px 26px;border-radius:8px;">' . e($otp) . '</span></p>
<p style="color:#b12704;font-size:13px;"><strong>If you do not agree to this cancellation, do not share the OTP</strong> - your order will stay active.</p>';
    return ['subject' => 'Confirm cancellation of order #' . (int)$order['order_id'] . ' - OTP ' . $otp, 'html' => email_layout('Confirm order cancellation', $body),
            'text' => 'ShopEase wants to cancel order #' . (int)$order['order_id'] . '. Reason: ' . $reason . '. OTP: ' . $otp . ' (valid 10 minutes). Do not share it if you disagree.'];
}

function tpl_order_cancelled(array $order, string $name, string $reason): array
{
    $refund = ($order['payment_method'] === 'Razorpay' && $order['payment_status'] === 'Paid')
        ? '<p>You paid online, so our team will contact you about the refund of <strong>' . money($order['total_amount']) . '</strong>.</p>' : '<p>No payment is due for this order.</p>';
    $body = '<p>Hi ' . e($name) . ',</p><p>Your order <strong>#' . (int)$order['order_id'] . '</strong> has been <strong>cancelled</strong>.</p>
<div style="background:#f7f7f7;padding:12px 14px;margin:14px 0;font-size:14px;"><strong>Reason</strong><br>' . nl2br(e($reason)) . '</div>' . $refund .
'<p style="text-align:center;margin:24px 0;"><a href="' . e(APP_URL) . '/index.php" style="background:#ffd814;color:#0f1111;text-decoration:none;padding:12px 28px;border-radius:20px;font-weight:bold;">Continue Shopping</a></p>';
    return ['subject' => 'Order #' . (int)$order['order_id'] . ' cancelled - ' . APP_NAME, 'html' => email_layout('Order cancelled', $body), 'text' => 'Your ShopEase order #' . (int)$order['order_id'] . ' has been cancelled.'];
}


function tpl_shipped(array $order, array $items, string $name): array
{
    $rows = '';
    foreach ($items as $it) $rows .= '<tr><td style="padding:8px;border-bottom:1px solid #eee;">' . e($it['name']) . '</td><td style="padding:8px;border-bottom:1px solid #eee;text-align:center;">' . (int)$it['quantity'] . '</td><td style="padding:8px;border-bottom:1px solid #eee;text-align:right;">' . money((float)$it['price'] * (int)$it['quantity']) . '</td></tr>';
    $courier = trim((string)($order['courier_name'] ?? '')) ?: 'Will be shared shortly';
    $track   = trim((string)($order['tracking_number'] ?? '')) ?: 'Will be shared shortly';
    $body = '<p>Hi ' . e($name) . ',</p><p>Good news - your order <strong>#' . (int)$order['order_id'] . '</strong> has been <strong>shipped</strong>!</p>
<table role="presentation" width="100%" style="font-size:14px;margin:12px 0;background:#f7f7f7;border-radius:6px;" cellpadding="8">
<tr><td><b>Dispatch date</b></td><td>' . e(date('d M Y, h:i A')) . '</td></tr><tr><td><b>Courier</b></td><td>' . e($courier) . '</td></tr><tr><td><b>Tracking number</b></td><td>' . e($track) . '</td></tr></table>
<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:14px;"><tr style="background:#232f3e;color:#fff;"><th style="padding:8px;text-align:left;">Item</th><th style="padding:8px;">Qty</th><th style="padding:8px;text-align:right;">Amount</th></tr>' . $rows . '</table>
<p style="margin-top:14px;font-size:14px;"><strong>Delivering to:</strong> ' . e($order['shipping_name']) . ', ' . nl2br(e($order['shipping_address'])) . '</p>';
    return ['subject' => 'Your Order #' . (int)$order['order_id'] . ' has been Shipped! - ' . APP_NAME, 'html' => email_layout('Your order is on its way 📦', $body), 'text' => 'Your ShopEase order #' . (int)$order['order_id'] . ' has been shipped. Courier: ' . $courier . '. Tracking: ' . $track];
}

function tpl_cancel_confirmation(array $order, string $name, string $reason, ?string $refundId): array
{
    $refund = $refundId
        ? '<div style="background:#e7f7ee;border-left:4px solid #067d62;padding:12px 14px;margin:14px 0;font-size:14px;"><strong>Refund initiated</strong><br>Amount: <strong>' . money($order['total_amount']) . '</strong><br>Refund ID: <strong>' . e($refundId) . '</strong><br>It will reach your original payment method in <strong>5-7 business days</strong>.</div>'
        : '<p>As this was a Cash on Delivery order, no payment was taken and nothing needs to be refunded.</p>';
    $body = '<p>Hi ' . e($name) . ',</p><p>As requested, your order <strong>#' . (int)$order['order_id'] . '</strong> has been <strong>cancelled</strong>.</p>
<div style="background:#f7f7f7;padding:12px 14px;margin:14px 0;font-size:14px;"><strong>Your reason</strong><br>' . nl2br(e($reason)) . '</div>' . $refund;
    return ['subject' => 'Order #' . (int)$order['order_id'] . ' cancelled' . ($refundId ? ' - refund initiated' : '') . ' - ' . APP_NAME, 'html' => email_layout('Order cancelled', $body),
            'text' => 'Your ShopEase order #' . (int)$order['order_id'] . ' was cancelled.' . ($refundId ? ' Refund ID ' . $refundId . ' (5-7 business days).' : '')];
}

function tpl_pickup_otp(string $name, string $otp, string $product, int $orderId): array
{
    $body = '<p>Hi ' . e($name) . ',</p><p>Our pickup agent is collecting your returned item <strong>' . e($product) . '</strong> (order #' . $orderId . ').</p>
<p>Give this OTP to the agent <strong>only when you hand over the item</strong>. It is valid for 10 minutes.</p>
<p style="text-align:center;margin:26px 0;"><span style="display:inline-block;font-size:34px;letter-spacing:10px;font-weight:bold;background:#fff8e7;border:2px dashed #febd69;padding:14px 26px;border-radius:8px;">' . e($otp) . '</span></p>
<p style="color:#b12704;font-size:13px;"><strong>Do not share this OTP before the agent has the item in hand.</strong></p>';
    return ['subject' => 'Return pickup OTP for order #' . $orderId . ' - ' . APP_NAME, 'html' => email_layout('Return pickup verification', $body),
            'text' => "ShopEase return pickup OTP for order #$orderId: $otp (valid 10 minutes). Share it only when handing over the item."];
}
