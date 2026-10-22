<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/email_templates.php';
require_once __DIR__ . '/../config/mailer.php';

/** OTP issuing/verification + password update, shared by forgot-password and change-password. */
final class PasswordService
{
    public const MAX_REQUESTS_PER_HOUR = 5;
    public const RESEND_SECONDS        = 45;
    public const MAX_ATTEMPTS          = 5;
    public const OTP_MINUTES           = 10;

    public function __construct(private PDO $db) {}

    public function findUserByEmail(string $email): ?array
    {
        $st = $this->db->prepare('SELECT user_id, full_name, email FROM users WHERE email = ? AND is_verified = 1');
        $st->execute([strtolower(trim($email))]);
        return $st->fetch() ?: null;
    }

    /** @return string|null error message, null on success */
    public function issueOtp(array $user, string $purpose): ?string
    {
        $uid = (int)$user['user_id'];
        $c = $this->db->prepare('SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at > (NOW() - INTERVAL 1 HOUR)');
        $c->execute([$uid]);
        if ((int)$c->fetchColumn() >= self::MAX_REQUESTS_PER_HOUR) return 'Too many OTP requests. Please try again after an hour.';

        $otp = (string)random_int(100000, 999999);
        $this->db->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND purpose = ? AND used_at IS NULL')->execute([$uid, $purpose]);
        $this->db->prepare('INSERT INTO password_resets (user_id, purpose, otp_hash, expires_at) VALUES (?,?,?,?)')
            ->execute([$uid, $purpose, password_hash($otp, PASSWORD_BCRYPT), date('Y-m-d H:i:s', time() + self::OTP_MINUTES * 60)]);
        $rid = (int)$this->db->lastInsertId();

        $t = tpl_password_otp((string)$user['full_name'], $otp, $purpose);
        if (!send_mail((string)$user['email'], (string)$user['full_name'], $t['subject'], $t['html'], $t['text'])) {
            $this->db->prepare('DELETE FROM password_resets WHERE reset_id = ?')->execute([$rid]);
            return 'We could not send the email right now. Please try again shortly.';
        }
        $this->db->exec('DELETE FROM password_resets WHERE created_at < (NOW() - INTERVAL 2 DAY)');   // housekeeping
        return null;
    }

    private function latest(int $uid, string $purpose): ?array
    {
        $st = $this->db->prepare('SELECT * FROM password_resets WHERE user_id = ? AND purpose = ? AND used_at IS NULL ORDER BY reset_id DESC LIMIT 1');
        $st->execute([$uid, $purpose]);
        return $st->fetch() ?: null;
    }

    public function expiresAt(int $uid, string $purpose): int
    {
        $r = $this->latest($uid, $purpose);
        return $r ? (int)strtotime((string)$r['expires_at']) : 0;
    }

    /** @return string ok | none | expired | locked | invalid */
    public function verifyOtp(int $uid, string $purpose, string $code): string
    {
        $r = $this->latest($uid, $purpose);
        if (!$r) return 'none';
        if ((int)$r['attempts'] >= self::MAX_ATTEMPTS) return 'locked';
        if (strtotime((string)$r['expires_at']) < time()) return 'expired';
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== 6 || !password_verify($code, (string)$r['otp_hash'])) {
            $this->db->prepare('UPDATE password_resets SET attempts = attempts + 1 WHERE reset_id = ?')->execute([$r['reset_id']]);
            return 'invalid';
        }
        $this->db->prepare('UPDATE password_resets SET verified_at = NOW() WHERE reset_id = ?')->execute([$r['reset_id']]);
        return 'ok';
    }

    /** Server-side proof that the OTP was verified (not just a session flag), valid for 10 minutes. */
    public function isVerified(int $uid, string $purpose): bool
    {
        $r = $this->latest($uid, $purpose);
        return $r && $r['verified_at'] && strtotime((string)$r['verified_at']) > time() - self::OTP_MINUTES * 60;
    }

    public function markUsed(int $uid, string $purpose): void
    {
        $this->db->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND purpose = ? AND used_at IS NULL')->execute([$uid, $purpose]);
    }

    public static function otpMessage(string $result): string
    {
        return match ($result) {
            'expired' => 'This OTP has expired. Please request a new one.',
            'locked'  => 'Too many wrong attempts. Please request a new OTP.',
            'none'    => 'No active OTP found. Please request a new one.',
            default   => 'Incorrect OTP. Please check the code and try again.',
        };
    }

    /** @return string|null error message, null when the password is acceptable */
    public static function validatePassword(string $new, string $confirm): ?string
    {
        if ($new !== $confirm)             return 'Passwords do not match';
        if (strlen($new) < 8)              return 'Password must be at least 8 characters.';
        if (strlen($new) > 72)             return 'Password must be 72 characters or fewer.';
        if (!preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) return 'Password must contain both letters and numbers.';
        return null;
    }

    public function setPassword(int $uid, string $new): void
    {
        $this->db->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')
            ->execute([password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]), $uid]);
    }

    public static function maskEmail(string $email): string
    {
        [$n, $d] = array_pad(explode('@', $email, 2), 2, '');
        return substr($n, 0, 1) . str_repeat('*', max(2, strlen($n) - 1)) . '@' . $d;
    }
}
