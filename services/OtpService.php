<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/EmailService.php';

final class OtpService
{
    public const TTL = 300;
    public const RESEND_COOLDOWN = 60;
    public const MAX_RESENDS = 5;
    public const RESEND_LOCK_SECONDS = 7200;
    public const MAX_ATTEMPTS = 5;

    public static function issue(PDO $pdo, string $email, string $purpose, ?string $accountTable = null, ?int $accountId = null, ?int $existingChallengeId = null): array
    {
        $now = time();
        $lockStmt = $pdo->prepare('SELECT resend_locked_until FROM otp_challenges WHERE email = ? AND purpose = ? AND resend_locked_until IS NOT NULL ORDER BY id DESC LIMIT 1');
        $lockStmt->execute([$email, $purpose]);
        $lock = $lockStmt->fetchColumn();
        if ($lock && strtotime($lock) > $now) {
            throw new RuntimeException('Too many resend requests. Please wait 2 hours before trying again.');
        }
        if ($existingChallengeId !== null) {
            $stmt = $pdo->prepare('SELECT resend_available_at, resend_count, resend_locked_until FROM otp_challenges WHERE id = ? AND invalidated_at IS NULL AND used_at IS NULL');
            $stmt->execute([$existingChallengeId]);
            $existing = $stmt->fetch();
            if (!$existing || strtotime($existing['resend_available_at']) > $now) {
                throw new RuntimeException('Please wait before requesting another code.');
            }
            if (!empty($existing['resend_locked_until']) && strtotime($existing['resend_locked_until']) > $now) {
                throw new RuntimeException('Too many resend requests. Please wait 2 hours before trying again.');
            }
            if ((int)$existing['resend_count'] >= self::MAX_RESENDS) {
                $pdo->prepare('UPDATE otp_challenges SET resend_locked_until = FROM_UNIXTIME(?) WHERE id = ?')->execute([$now + self::RESEND_LOCK_SECONDS, $existingChallengeId]);
                throw new RuntimeException('Too many resend requests. Please wait 2 hours before trying again.');
            }
        }

        $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        EmailService::sendOtp($email, $otp, $purpose, self::TTL);

        $challengeToken = bin2hex(random_bytes(32));
        $stmt = $pdo->prepare('UPDATE otp_challenges SET invalidated_at = CURRENT_TIMESTAMP WHERE email = ? AND purpose = ? AND invalidated_at IS NULL AND used_at IS NULL');
        $stmt->execute([$email, $purpose]);
        $resendCount = 0;
        if ($existingChallengeId !== null) {
            $resendCount = (int)$existing['resend_count'] + 1;
        }
        $lockedUntil = $resendCount >= self::MAX_RESENDS ? $now + self::RESEND_LOCK_SECONDS : null;
        $stmt = $pdo->prepare('INSERT INTO otp_challenges (purpose, email, account_table, account_id, challenge_token_hash, otp_hash, expires_at, resend_available_at, resend_count, resend_locked_until, ip_address) VALUES (?, ?, ?, ?, ?, ?, FROM_UNIXTIME(?), FROM_UNIXTIME(?), ?, FROM_UNIXTIME(?), ?)');
        $stmt->execute([
            $purpose,
            $email,
            $accountTable,
            $accountId,
            hash('sha256', $challengeToken),
            password_hash($otp, PASSWORD_DEFAULT),
            $now + self::TTL,
            $now + self::RESEND_COOLDOWN,
            $resendCount,
            $lockedUntil,
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);

        return [
            'id' => (int)$pdo->lastInsertId(),
            'token' => $challengeToken,
            'expires_at' => $now + self::TTL,
            'resend_at' => $now + self::RESEND_COOLDOWN,
            'resend_count' => $resendCount,
            'locked_until' => $lockedUntil
        ];
    }

    public static function verify(PDO $pdo, int $id, string $token, string $purpose, string $otp): array
    {
        $stmt = $pdo->prepare('SELECT * FROM otp_challenges WHERE id = ? AND purpose = ? AND invalidated_at IS NULL AND used_at IS NULL FOR UPDATE');
        $pdo->beginTransaction();
        try {
            $stmt->execute([$id, $purpose]);
            $challenge = $stmt->fetch();
            if (!$challenge || !hash_equals($challenge['challenge_token_hash'], hash('sha256', $token))) {
                throw new RuntimeException('This verification request is no longer valid.');
            }
            if (!empty($challenge['resend_locked_until']) && strtotime($challenge['resend_locked_until']) > time()) {
                throw new RuntimeException('Too many resend requests. Please wait 2 hours before trying again.');
            }
            if (strtotime($challenge['expires_at']) <= time()) {
                $pdo->prepare('UPDATE otp_challenges SET invalidated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$id]);
                throw new RuntimeException('This code has expired. Request a new code.');
            }
            if (!preg_match('/^\d{6}$/', $otp) || !password_verify($otp, $challenge['otp_hash'])) {
                $attempts = (int)$challenge['attempts'] + 1;
                $invalidate = $attempts >= self::MAX_ATTEMPTS ? ', invalidated_at = CURRENT_TIMESTAMP' : '';
                $pdo->prepare("UPDATE otp_challenges SET attempts = ?{$invalidate} WHERE id = ?")->execute([$attempts, $id]);
                $pdo->commit();
                throw new RuntimeException($attempts >= self::MAX_ATTEMPTS ? 'Too many incorrect attempts. Request a new code.' : 'Invalid verification code.');
            }
            $pdo->prepare('UPDATE otp_challenges SET used_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$id]);
            $pdo->commit();
            return $challenge;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}
