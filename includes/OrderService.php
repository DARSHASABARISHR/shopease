<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/email_templates.php';
require_once __DIR__ . '/../config/mailer.php';

/** All order / payment business logic lives here. */
final class OrderService
{
    public function __construct(private PDO $db) {}

    /**
     * Creates order + items + payment row, reserves stock. Returns order_id.
     * @throws RuntimeException with a user-safe message on validation/stock failure.
     */
    public function create(int $userId, array $ship, string $method, array $cart): int
    {
        if (!$cart) throw new RuntimeException('Your cart is empty.');
        $this->db->beginTransaction();
        try {
            $ids = array_map('intval', array_keys($cart));
            $in  = implode(',', array_fill(0, count($ids), '?'));
            $st  = $this->db->prepare("SELECT product_id, name, selling_price, stock_quantity FROM products WHERE product_id IN ($in) FOR UPDATE");
            $st->execute($ids);
            $products = [];
            foreach ($st->fetchAll() as $p) $products[(int)$p['product_id']] = $p;

            $itemsTotal = 0.0; $lines = [];
            foreach ($cart as $pid => $qty) {
                $pid = (int)$pid; $qty = (int)$qty;
                if (!isset($products[$pid]) || $qty < 1) throw new RuntimeException('A product in your cart is no longer available.');
                $p = $products[$pid];
                if ((int)$p['stock_quantity'] < $qty) throw new RuntimeException('Only ' . (int)$p['stock_quantity'] . ' unit(s) of "' . $p['name'] . '" left in stock.');
                $itemsTotal += (float)$p['selling_price'] * $qty;
                $lines[] = [$pid, $qty, (float)$p['selling_price']];
            }
            $bd = price_breakdown($itemsTotal);
            $address = $ship['street'] . "\n" . $ship['city'] . ', ' . $ship['state'] . ' - ' . $ship['pincode'];

            $this->db->prepare('INSERT INTO orders (user_id,total_amount,items_total,delivery_charge,gst_amount,payment_method,payment_status,order_status,shipping_name,shipping_phone,shipping_address)
                                VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$userId, $bd['total'], $itemsTotal, $bd['delivery'], $bd['gst'], $method === 'COD' ? 'COD' : 'Razorpay',
                           $method === 'COD' ? 'COD' : 'Pending', 'Pending', $ship['name'], $ship['phone'], $address]);
            $orderId = (int)$this->db->lastInsertId();

            $ins = $this->db->prepare('INSERT INTO order_items (order_id,product_id,quantity,price) VALUES (?,?,?,?)');
            $dec = $this->db->prepare('UPDATE products SET stock_quantity = stock_quantity - ? WHERE product_id = ?');
            $log = $this->db->prepare("INSERT INTO stock_log (product_id, order_id, change_qty, reason) VALUES (?,?,?,'order')");
            foreach ($lines as [$pid, $qty, $price]) { $ins->execute([$orderId, $pid, $qty, $price]); $dec->execute([$qty, $pid]); $log->execute([$pid, $orderId, -$qty]); }

            $this->db->prepare('INSERT INTO payments (order_id,payment_gateway,amount,status) VALUES (?,?,?,?)')
                ->execute([$orderId, $method === 'COD' ? 'COD' : 'Razorpay', $bd['total'], $method === 'COD' ? 'Pending' : 'Created']);

            $this->db->commit();
            return $orderId;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    public function attachGatewayOrder(int $orderId, string $gatewayOrderId): void
    {
        $this->db->prepare('UPDATE payments SET gateway_order_id = ? WHERE order_id = ?')->execute([$gatewayOrderId, $orderId]);
    }

    public function find(int $orderId, ?int $userId = null): ?array
    {
        $sql = 'SELECT * FROM orders WHERE order_id = ?'; $args = [$orderId];
        if ($userId !== null) { $sql .= ' AND user_id = ?'; $args[] = $userId; }
        $st = $this->db->prepare($sql); $st->execute($args);
        return $st->fetch() ?: null;
    }

    public function items(int $orderId): array
    {
        $st = $this->db->prepare('SELECT oi.*, p.name, p.is_returnable, p.return_window_days FROM order_items oi JOIN products p ON p.product_id = oi.product_id WHERE oi.order_id = ?');
        $st->execute([$orderId]);
        return $st->fetchAll();
    }

    public function findByGatewayOrder(string $gatewayOrderId, int $userId): ?array
    {
        $st = $this->db->prepare('SELECT o.*, p.status AS pay_status FROM payments p JOIN orders o ON o.order_id = p.order_id WHERE p.gateway_order_id = ? AND o.user_id = ?');
        $st->execute([$gatewayOrderId, $userId]);
        return $st->fetch() ?: null;
    }

    /** Idempotent: marks a Razorpay order as paid. Returns true only the first time. */
    public function markPaid(int $orderId, string $paymentId): bool
    {
        $this->db->beginTransaction();
        try {
            $st = $this->db->prepare('SELECT status FROM payments WHERE order_id = ? FOR UPDATE');
            $st->execute([$orderId]);
            if ($st->fetchColumn() === 'Success') { $this->db->commit(); return false; }
            $this->db->prepare("UPDATE payments SET status='Success', transaction_reference=?, payment_date=NOW() WHERE order_id=?")->execute([$paymentId, $orderId]);
            $this->db->prepare("UPDATE orders SET payment_status='Paid' WHERE order_id=?")->execute([$orderId]);
            $this->db->commit();
            return true;
        } catch (\Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }

    /** Marks payment failed, cancels order and returns stock (only if still unpaid). */
    public function markFailed(int $orderId): void
    {
        $this->db->beginTransaction();
        try {
            $st = $this->db->prepare("SELECT payment_status, order_status FROM orders WHERE order_id=? FOR UPDATE");
            $st->execute([$orderId]);
            $o = $st->fetch();
            if ($o && $o['payment_status'] === 'Pending') {
                $this->db->prepare("UPDATE orders SET payment_status='Failed', order_status='Cancelled' WHERE order_id=?")->execute([$orderId]);
                $this->db->prepare("UPDATE payments SET status='Failed' WHERE order_id=?")->execute([$orderId]);
                $this->restoreStock($orderId);
            }
            $this->db->commit();
        } catch (\Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }

    public function restoreStock(int $orderId): void
    {
        $this->db->prepare("INSERT INTO stock_log (product_id, order_id, change_qty, reason) SELECT product_id, order_id, quantity, 'cancel' FROM order_items WHERE order_id = ?")
            ->execute([$orderId]);
        $this->db->prepare('UPDATE products p JOIN order_items oi ON oi.product_id = p.product_id SET p.stock_quantity = p.stock_quantity + oi.quantity WHERE oi.order_id = ?')
            ->execute([$orderId]);
    }

    public const DELIVERY_OTP_HOURS    = 24;
    public const DELIVERY_OTP_ATTEMPTS = 5;
    public const DELIVERY_OTP_RESEND_S = 30;

    private static function res(bool $ok, string $msg): array { return ['ok' => $ok, 'msg' => $msg]; }

    /**
     * Admin status change. "Delivered" is NOT allowed here - it only happens through verifyDelivery().
     * Moving to "Out for Delivery" generates a 4-digit OTP and emails it to the customer.
     * @return array{ok:bool,msg:string}
     */
    public function updateStatus(int $orderId, string $new, array $extra = []): array
    {
        if ($new === 'Delivered') return self::res(false, 'An order becomes Delivered only after the customer\'s delivery OTP is verified.');
        if ($new === 'Cancelled') return self::res(false, 'Cancellation needs the customer\'s OTP. Use the Cancel Order button.');
        if (!in_array($new, ['Pending', 'Shipped', 'Out for Delivery', 'Cancelled'], true)) return self::res(false, 'Invalid status.');

        $this->db->beginTransaction();
        try {
            $st = $this->db->prepare('SELECT order_status, payment_method, payment_status FROM orders WHERE order_id=? FOR UPDATE');
            $st->execute([$orderId]);
            $o = $st->fetch();
            if (!$o) { $this->db->rollBack(); return self::res(false, 'Order not found.'); }
            $cur = $o['order_status'];
            if ($cur === $new) { $this->db->rollBack(); return self::res(false, "Order #$orderId is already $new."); }
            if (in_array($cur, ['Cancelled', 'Delivered'], true)) { $this->db->rollBack(); return self::res(false, "Order #$orderId is $cur and can no longer be changed."); }
            if (in_array($new, ['Shipped', 'Out for Delivery'], true) && $o['payment_method'] === 'Razorpay' && $o['payment_status'] !== 'Paid') {
                $this->db->rollBack(); return self::res(false, 'Online payment for this order is not completed yet.');
            }

            $otp = null;
            if ($new === 'Out for Delivery') {
                $otp = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
                $this->db->prepare('UPDATE orders SET order_status=?, delivery_otp_hash=?, delivery_otp_expires=?, delivery_otp_attempts=0, delivery_otp_sent_at=NOW() WHERE order_id=?')
                    ->execute([$new, password_hash($otp, PASSWORD_BCRYPT), date('Y-m-d H:i:s', time() + self::DELIVERY_OTP_HOURS * 3600), $orderId]);
            } else {
                $this->db->prepare('UPDATE orders SET order_status=?, delivery_otp_hash=NULL, delivery_otp_expires=NULL, delivery_otp_attempts=0 WHERE order_id=?')->execute([$new, $orderId]);
                if ($new === 'Shipped') {
                    $this->db->prepare('UPDATE orders SET courier_name=?, tracking_number=?, shipped_at=NOW() WHERE order_id=?')
                        ->execute([mb_substr(trim((string)($extra['courier'] ?? '')), 0, 80) ?: null, mb_substr(trim((string)($extra['tracking'] ?? '')), 0, 80) ?: null, $orderId]);
                }
                if ($new === 'Cancelled') $this->restoreStock($orderId);
            }
            $this->db->commit();
        } catch (\Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }

        if ($otp !== null) {
            if (!$this->sendDeliveryOtp($orderId, $otp)) {      // customer must receive the OTP, otherwise undo
                $this->db->prepare('UPDATE orders SET order_status=?, delivery_otp_hash=NULL, delivery_otp_expires=NULL WHERE order_id=?')->execute([$cur, $orderId]);
                return self::res(false, 'Could not email the delivery OTP, so the status was not changed. Check the SMTP settings and try again.');
            }
            return self::res(true, "Order #$orderId is Out for Delivery. Delivery OTP emailed to the customer.");
        }
        if ($new === 'Shipped') {
            return self::res(true, "Order #$orderId marked as Shipped. " . ($this->sendShippedEmail($orderId) ? 'Shipping email sent to the customer.' : 'Status saved, but the email could not be sent.'));
        }
        return self::res(true, "Order #$orderId marked as $new.");
    }

    /** Generates a fresh delivery OTP for an order that is already Out for Delivery. */
    public function resendDeliveryOtp(int $orderId): array
    {
        $this->db->beginTransaction();
        try {
            $st = $this->db->prepare('SELECT order_status, delivery_otp_sent_at FROM orders WHERE order_id=? FOR UPDATE');
            $st->execute([$orderId]);
            $o = $st->fetch();
            if (!$o || $o['order_status'] !== 'Out for Delivery') { $this->db->rollBack(); return self::res(false, 'This order is not out for delivery.'); }
            $wait = self::DELIVERY_OTP_RESEND_S - (time() - (int)strtotime((string)$o['delivery_otp_sent_at']));
            if ($o['delivery_otp_sent_at'] && $wait > 0) { $this->db->rollBack(); return self::res(false, "Please wait $wait seconds before resending the OTP."); }
            $otp = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            $this->db->prepare('UPDATE orders SET delivery_otp_hash=?, delivery_otp_expires=?, delivery_otp_attempts=0, delivery_otp_sent_at=NOW() WHERE order_id=?')
                ->execute([password_hash($otp, PASSWORD_BCRYPT), date('Y-m-d H:i:s', time() + self::DELIVERY_OTP_HOURS * 3600), $orderId]);
            $this->db->commit();
        } catch (\Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
        return $this->sendDeliveryOtp($orderId, $otp)
            ? self::res(true, "A new delivery OTP was emailed to the customer for order #$orderId.")
            : self::res(false, 'Could not email the OTP. Check the SMTP settings.');
    }

    /**
     * Agent enters the customer's OTP: order -> Delivered, payment -> Paid (COD cash collected),
     * COD payment row -> Success, then the "Delivered & Payment Received" email is sent.
     */
    public function verifyDelivery(int $orderId, string $otp): array
    {
        $otp = preg_replace('/\D/', '', $otp);
        $this->db->beginTransaction();
        try {
            $st = $this->db->prepare('SELECT * FROM orders WHERE order_id=? FOR UPDATE');
            $st->execute([$orderId]);
            $o = $st->fetch();
            if (!$o) { $this->db->rollBack(); return self::res(false, 'Order not found.'); }
            if ($o['order_status'] !== 'Out for Delivery' || !$o['delivery_otp_hash']) { $this->db->rollBack(); return self::res(false, 'This order is not out for delivery.'); }
            if ((int)$o['delivery_otp_attempts'] >= self::DELIVERY_OTP_ATTEMPTS) { $this->db->rollBack(); return self::res(false, 'Too many wrong OTP attempts. Use "Resend OTP" to send a new code to the customer.'); }
            if (strtotime((string)$o['delivery_otp_expires']) < time()) { $this->db->rollBack(); return self::res(false, 'The delivery OTP has expired. Use "Resend OTP".'); }
            if (strlen($otp) !== 4 || !password_verify($otp, (string)$o['delivery_otp_hash'])) {
                $this->db->prepare('UPDATE orders SET delivery_otp_attempts = delivery_otp_attempts + 1 WHERE order_id=?')->execute([$orderId]);
                $this->db->commit();
                $left = max(0, self::DELIVERY_OTP_ATTEMPTS - ((int)$o['delivery_otp_attempts'] + 1));
                return self::res(false, "Incorrect OTP. $left attempt(s) left.");
            }
            $isCod = $o['payment_method'] === 'COD';
            $this->db->prepare("UPDATE orders SET order_status='Delivered', payment_status='Paid', delivered_at=NOW(), cod_collected_at=" . ($isCod ? 'NOW()' : 'NULL') . ",
                                delivery_otp_hash=NULL, delivery_otp_expires=NULL, delivery_otp_attempts=0 WHERE order_id=?")->execute([$orderId]);
            if ($isCod) {
                $this->db->prepare("UPDATE payments SET status='Success', payment_date=NOW() WHERE order_id=? AND payment_gateway='COD'")->execute([$orderId]);
            }
            $this->db->commit();
        } catch (\Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }

        $this->sendDeliveredEmail($orderId);
        return self::res(true, "Order #$orderId delivered" . ($isCod ? ' and cash collected (' . money($o['total_amount']) . ')' : '') . '. Confirmation email sent to the customer.');
    }

    /* ---------------- Admin cancellation with customer OTP ---------------- */
    public const CANCEL_OTP_MINUTES  = 10;
    public const CANCEL_OTP_ATTEMPTS = 5;
    public const CANCEL_RESEND_S     = 45;
    private const CANCELLABLE = ['Pending', 'Shipped', 'Out for Delivery'];

    /** Step 1: store a hashed 6-digit OTP + reason, email both to the customer. Overwrites any earlier OTP. */
    public function requestCancelOtp(int $orderId, string $reason): array
    {
        $reason = trim($reason);
        $this->db->beginTransaction();
        try {
            $st = $this->db->prepare('SELECT * FROM orders WHERE order_id=? FOR UPDATE');
            $st->execute([$orderId]);
            $o = $st->fetch();
            if (!$o) { $this->db->rollBack(); return self::res(false, 'Order not found.'); }
            if (!in_array($o['order_status'], self::CANCELLABLE, true)) { $this->db->rollBack(); return self::res(false, 'Only orders that are not Delivered or Cancelled can be cancelled.'); }
            if ($reason === '') $reason = (string)$o['cancel_reason'];                       // resend without retyping
            if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) { $this->db->rollBack(); return self::res(false, 'Enter a cancellation reason (5-500 characters).'); }
            $wait = self::CANCEL_RESEND_S - (time() - (int)strtotime((string)$o['cancel_otp_sent_at']));
            if ($o['cancel_otp_sent_at'] && $wait > 0) { $this->db->rollBack(); return self::res(false, "Please wait $wait seconds before sending another OTP."); }

            $otp = (string)random_int(100000, 999999);
            $this->db->prepare('UPDATE orders SET cancel_otp=?, cancel_otp_expiry=?, cancel_otp_attempts=0, cancel_otp_sent_at=NOW(), cancel_reason=? WHERE order_id=?')
                ->execute([password_hash($otp, PASSWORD_BCRYPT), date('Y-m-d H:i:s', time() + self::CANCEL_OTP_MINUTES * 60), $reason, $orderId]);
            $this->db->commit();
        } catch (\Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }

        $order = $this->find($orderId);
        $u = $order ? $this->customerOf($order) : null;
        $t = $u ? tpl_cancel_otp($order, $u['full_name'], $otp, $reason) : null;
        if (!$t || !send_mail($u['email'], $u['full_name'], $t['subject'], $t['html'], $t['text'])) {
            $this->db->prepare('UPDATE orders SET cancel_otp=NULL, cancel_otp_expiry=NULL, cancel_otp_sent_at=NULL WHERE order_id=?')->execute([$orderId]);
            return self::res(false, 'Could not email the OTP to the customer. Check the SMTP settings and try again.');
        }
        [$n, $d] = explode('@', (string)$u['email'], 2);
        return ['ok' => true, 'msg' => 'OTP emailed to the customer.', 'email' => substr($n, 0, 1) . '***@' . $d, 'expires_in' => self::CANCEL_OTP_MINUTES * 60, 'resend_in' => self::CANCEL_RESEND_S];
    }

    /** Step 2: verify OTP, then cancel + restore stock + clear OTP fields, and email the customer. */
    public function verifyCancel(int $orderId, string $otp): array
    {
        $otp = preg_replace('/\D/', '', $otp);
        $this->db->beginTransaction();
        try {
            $st = $this->db->prepare('SELECT * FROM orders WHERE order_id=? FOR UPDATE');
            $st->execute([$orderId]);
            $o = $st->fetch();
            if (!$o) { $this->db->rollBack(); return self::res(false, 'Order not found.'); }
            if (!in_array($o['order_status'], self::CANCELLABLE, true)) { $this->db->rollBack(); return self::res(false, 'This order can no longer be cancelled (status: ' . $o['order_status'] . ').'); }
            if (!$o['cancel_otp']) { $this->db->rollBack(); return self::res(false, 'No OTP has been sent. Send an OTP to the customer first.'); }
            if ((int)$o['cancel_otp_attempts'] >= self::CANCEL_OTP_ATTEMPTS) { $this->db->rollBack(); return self::res(false, 'Too many wrong attempts. Send a new OTP.'); }
            if (strtotime((string)$o['cancel_otp_expiry']) < time()) { $this->db->rollBack(); return self::res(false, 'The OTP has expired. Send a new OTP.'); }
            if (strlen($otp) !== 6 || !password_verify($otp, (string)$o['cancel_otp'])) {
                $this->db->prepare('UPDATE orders SET cancel_otp_attempts = cancel_otp_attempts + 1 WHERE order_id=?')->execute([$orderId]);
                $this->db->commit();
                return self::res(false, 'Incorrect OTP. ' . max(0, self::CANCEL_OTP_ATTEMPTS - ((int)$o['cancel_otp_attempts'] + 1)) . ' attempt(s) left.');
            }
            $this->db->prepare("UPDATE orders SET order_status='Cancelled', cancelled_by='admin', cancelled_at=NOW(), cancel_otp=NULL, cancel_otp_expiry=NULL, cancel_otp_attempts=0, cancel_otp_sent_at=NULL,
                                delivery_otp_hash=NULL, delivery_otp_expires=NULL, delivery_otp_attempts=0 WHERE order_id=?")->execute([$orderId]);
            $this->restoreStock($orderId);
            $this->db->commit();
        } catch (\Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }

        $order = $this->find($orderId);
        $u = $order ? $this->customerOf($order) : null;
        if ($u) { $t = tpl_order_cancelled($order, $u['full_name'], (string)$order['cancel_reason']); send_mail($u['email'], $u['full_name'], $t['subject'], $t['html'], $t['text']); }
        return self::res(true, "Order #$orderId cancelled. Stock restored and the customer has been notified.");
    }

    /* ---------------- Customer self-cancellation (+ Razorpay refund) ---------------- */

    /** Customer cancels a Pending order. COD -> cancelled; paid Razorpay -> refunded via API first. */
    public function customerCancel(int $orderId, int $userId, string $reason): array
    {
        $reason = trim(strip_tags($reason));
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 300) return self::res(false, 'Please tell us why you are cancelling (5-300 characters).');

        $refundId = null;
        $this->db->beginTransaction();
        try {
            $st = $this->db->prepare('SELECT * FROM orders WHERE order_id = ? AND user_id = ? FOR UPDATE');
            $st->execute([$orderId, $userId]);
            $o = $st->fetch();
            if (!$o) { $this->db->rollBack(); return self::res(false, 'Order not found.'); }
            if ($o['order_status'] !== 'Pending') { $this->db->rollBack(); return self::res(false, 'This order can no longer be cancelled because it is ' . $o['order_status'] . '.'); }

            $payStatus = 'Cancelled';
            if ($o['payment_method'] === 'Razorpay' && $o['payment_status'] === 'Paid') {
                $p = $this->db->prepare("SELECT transaction_reference FROM payments WHERE order_id = ? AND payment_gateway = 'Razorpay' AND status = 'Success' LIMIT 1");
                $p->execute([$orderId]);
                $payId = (string)$p->fetchColumn();
                if ($payId === '') { $this->db->rollBack(); return self::res(false, 'Payment reference not found. Please contact support.'); }
                [$refundId, $err] = $this->razorpayRefund($payId, (float)$o['total_amount'], $orderId);
                if ($err) { $this->db->rollBack(); return self::res(false, $err); }
                $payStatus = 'Refunded';
            }
            $this->db->prepare("UPDATE orders SET order_status='Cancelled', payment_status=?, refund_id=?, cancel_reason=?, cancelled_by='customer', cancelled_at=NOW(),
                                cancel_otp=NULL, cancel_otp_expiry=NULL, cancel_otp_attempts=0, cancel_otp_sent_at=NULL, delivery_otp_hash=NULL, delivery_otp_expires=NULL WHERE order_id=?")
                ->execute([$payStatus, $refundId, $reason, $orderId]);
            $this->db->prepare('UPDATE payments SET status = ? WHERE order_id = ?')->execute([$payStatus, $orderId]);
            $this->restoreStock($orderId);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            if ($refundId) error_log("REFUND $refundId was issued for order $orderId but the database update failed - reconcile manually");
            throw $e;
        }

        $order = $this->find($orderId);
        $u = $order ? $this->customerOf($order) : null;
        if ($u) { $t = tpl_cancel_confirmation($order, $u['full_name'], $reason, $refundId); send_mail($u['email'], $u['full_name'], $t['subject'], $t['html'], $t['text']); }
        return ['ok' => true, 'msg' => $refundId ? "Order #$orderId cancelled. Refund $refundId initiated - it reaches you in 5-7 business days." : "Order #$orderId cancelled.",
                'refund_id' => $refundId, 'payment_status' => $payStatus];
    }

    /** POST /v1/payments/{id}/refund. @return array{0:?string,1:?string} [refund_id, error] */
    private function razorpayRefund(string $paymentId, float $amount, int $orderId): array
    {
        $ch = curl_init('https://api.razorpay.com/v1/payments/' . rawurlencode($paymentId) . '/refund');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_USERPWD => RAZORPAY_KEY_ID . ':' . RAZORPAY_KEY_SECRET,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['amount' => (int)round($amount * 100), 'speed' => 'normal', 'receipt' => 'RF' . $orderId, 'notes' => ['shopease_order_id' => (string)$orderId, 'reason' => 'customer_cancellation']]),
        ]);
        $resp = curl_exec($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $cerr = curl_error($ch); curl_close($ch);
        $j = is_string($resp) ? (json_decode($resp, true) ?: []) : [];
        if ($http === 200 && !empty($j['id'])) return [(string)$j['id'], null];
        error_log("Razorpay refund failed HTTP $http order $orderId: $cerr " . (is_string($resp) ? $resp : ''));
        $d = (string)($j['error']['description'] ?? '');
        return [null, 'The payment gateway could not process the refund' . ($d !== '' ? ' (' . $d . ')' : '') . '. Your order was NOT cancelled - please try again or contact support.'];
    }

    public function sendShippedEmail(int $orderId): bool
    {
        $order = $this->find($orderId);
        $u = $order ? $this->customerOf($order) : null;
        if (!$u) return false;
        $t = tpl_shipped($order, $this->items($orderId), $u['full_name']);
        return send_mail($u['email'], $u['full_name'], $t['subject'], $t['html'], $t['text']);
    }

    private function customerOf(array $order): ?array
    {
        $st = $this->db->prepare('SELECT full_name, email FROM users WHERE user_id = ?');
        $st->execute([$order['user_id']]);
        return $st->fetch() ?: null;
    }

    private function sendDeliveryOtp(int $orderId, string $otp): bool
    {
        $order = $this->find($orderId);
        $u = $order ? $this->customerOf($order) : null;
        if (!$u) return false;
        $t = tpl_delivery_otp($order, $u['full_name'], $otp);
        return send_mail($u['email'], $u['full_name'], $t['subject'], $t['html'], $t['text']);
    }

    private function sendDeliveredEmail(int $orderId): bool
    {
        $order = $this->find($orderId);
        $u = $order ? $this->customerOf($order) : null;
        if (!$u) return false;
        $t = tpl_delivered($order, $u['full_name']);
        return send_mail($u['email'], $u['full_name'], $t['subject'], $t['html'], $t['text']);
    }

    public function sendInvoiceEmail(int $orderId): bool
    {
        $order = $this->find($orderId);
        if (!$order) return false;
        $st = $this->db->prepare('SELECT full_name, email FROM users WHERE user_id = ?');
        $st->execute([$order['user_id']]);
        $u = $st->fetch();
        if (!$u) return false;
        $t = tpl_order($order, $this->items($orderId), $u['full_name']);
        return send_mail($u['email'], $u['full_name'], $t['subject'], $t['html'], $t['text']);
    }
}
