<?php
declare(strict_types=1);
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/whatsapp.php';
require_once __DIR__ . '/email_templates.php';
require_once __DIR__ . '/../config/mailer.php';

/** OTP storage / verification for WhatsApp login and "link my WhatsApp number" (table otp_codes). */
final class OtpService
{
    public const MAX_PER_HOUR = 5;
    public const MAX_ATTEMPTS = 5;
    public const RESEND_SECONDS = 45;

    public function __construct(private PDO $db) {}

    /** @return array{0:?string,1:?string} [otp, error] */
    public function issue(?int $userId, string $purpose, string $target): array
    {
        $c = $this->db->prepare('SELECT COUNT(*) FROM otp_codes WHERE purpose = ? AND target = ? AND created_at > (NOW() - INTERVAL 1 HOUR)');
        $c->execute([$purpose, $target]);
        if ((int)$c->fetchColumn() >= self::MAX_PER_HOUR) return [null, 'Too many OTP requests for this number. Please try again after an hour.'];

        $otp = (string)random_int(100000, 999999);
        $this->db->prepare('UPDATE otp_codes SET used_at = NOW() WHERE purpose = ? AND target = ? AND used_at IS NULL')->execute([$purpose, $target]);
        $this->db->prepare('INSERT INTO otp_codes (purpose, user_id, target, otp_hash, expires_at) VALUES (?,?,?,?,?)')
            ->execute([$purpose, $userId, $target, password_hash($otp, PASSWORD_BCRYPT), date('Y-m-d H:i:s', time() + OTP_TTL_MINUTES * 60)]);
        $this->db->exec('DELETE FROM otp_codes WHERE created_at < (NOW() - INTERVAL 2 DAY)');
        return [$otp, null];
    }

    private function latest(string $purpose, string $target): ?array
    {
        $st = $this->db->prepare('SELECT * FROM otp_codes WHERE purpose = ? AND target = ? AND used_at IS NULL ORDER BY otp_id DESC LIMIT 1');
        $st->execute([$purpose, $target]);
        return $st->fetch() ?: null;
    }

    public function expiresAt(string $purpose, string $target): int
    {
        $r = $this->latest($purpose, $target);
        return $r ? (int)strtotime((string)$r['expires_at']) : 0;
    }

    /** @return string ok | none | expired | locked | invalid */
    public function verify(string $purpose, string $target, string $code, ?int $userId = null): string
    {
        $r = $this->latest($purpose, $target);
        if (!$r || ($userId !== null && (int)$r['user_id'] !== $userId)) return 'none';
        if ((int)$r['attempts'] >= self::MAX_ATTEMPTS) return 'locked';
        if (strtotime((string)$r['expires_at']) < time()) return 'expired';
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== 6 || !password_verify($code, (string)$r['otp_hash'])) {
            $this->db->prepare('UPDATE otp_codes SET attempts = attempts + 1 WHERE otp_id = ?')->execute([$r['otp_id']]);
            return 'invalid';
        }
        $this->db->prepare('UPDATE otp_codes SET used_at = NOW() WHERE otp_id = ?')->execute([$r['otp_id']]);
        return 'ok';
    }

    public static function message(string $result): string
    {
        return match ($result) {
            'expired' => 'This OTP has expired. Please request a new one.',
            'locked'  => 'Too many wrong attempts. Please request a new OTP.',
            'none'    => 'No active OTP found. Please request a new one.',
            default   => 'Incorrect OTP. Please check the code and try again.',
        };
    }

    /** Sends the OTP by the chosen channel. Returns null on success or an error message. */
    public static function deliver(string $channel, array $user, string $waNumber, string $otp): ?string
    {
        if ($channel === 'whatsapp') return wa_send($waNumber, $otp);
        $t = tpl_otp((string)$user['full_name'], $otp);
        return send_mail((string)$user['email'], (string)$user['full_name'], $t['subject'], $t['html'], $t['text']) ? null : 'The email could not be sent. Please try again.';
    }

    /** True if another verified account already owns this WhatsApp number. */
    public static function numberTaken(PDO $db, string $number, int $exceptUserId = 0): bool
    {
        $st = $db->prepare('SELECT 1 FROM users WHERE whatsapp_number = ? AND whatsapp_verified = 1 AND user_id <> ? LIMIT 1');
        $st->execute([$number, $exceptUserId]);
        return (bool)$st->fetchColumn();
    }
}
