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
        $lockStmt = $pdo->prepare('SELECT resend_locked_until FROM otp_challenges WHERE email = ? AND purpose = ? AND resend_locked_until > CURRENT_TIMESTAMP ORDER BY id DESC LIMIT 1');
        $lockStmt->execute([$email, $purpose]);
        $lock = $lockStmt->fetchColumn();
        if ($lock) {
            throw new RuntimeException('Too many resend requests. Please wait 2 hours before trying again.');
        }
        if ($existingChallengeId !== null) {
            $stmt = $pdo->prepare('SELECT resend_available_at, resend_count, resend_locked_until,
                CASE WHEN resend_available_at > CURRENT_TIMESTAMP THEN 1 ELSE 0 END AS cooldown_active,
                CASE WHEN resend_locked_until > CURRENT_TIMESTAMP THEN 1 ELSE 0 END AS lock_active
                FROM otp_challenges WHERE id = ? AND invalidated_at IS NULL AND used_at IS NULL');
            $stmt->execute([$existingChallengeId]);
            $existing = $stmt->fetch();
            if (!$existing) {
                throw new RuntimeException('This verification request is no longer valid.');
            }
            if ((int)$existing['cooldown_active'] === 1) {
                throw new RuntimeException('Please wait before requesting another code.');
            }
            if ((int)$existing['lock_active'] === 1) {
                throw new RuntimeException('Too many resend requests. Please wait 2 hours before trying again.');
            }
            if ((int)$existing['resend_count'] >= self::MAX_RESENDS) {
                $pdo->prepare("UPDATE otp_challenges SET resend_locked_until = CURRENT_TIMESTAMP + INTERVAL '2 hours' WHERE id = ?")->execute([$existingChallengeId]);
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
        $stmt = $pdo->prepare("INSERT INTO otp_challenges (purpose, email, account_table, account_id, challenge_token_hash, otp_hash, expires_at, resend_available_at, resend_count, resend_locked_until, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP + INTERVAL '5 minutes', CURRENT_TIMESTAMP + INTERVAL '60 seconds', ?, CASE WHEN ? >= ? THEN CURRENT_TIMESTAMP + INTERVAL '2 hours' ELSE NULL END, ?)
            RETURNING id");
        $stmt->execute([
            $purpose,
            $email,
            $accountTable,
            $accountId,
            hash('sha256', $challengeToken),
            password_hash($otp, PASSWORD_DEFAULT),
            $resendCount,
            $resendCount,
            self::MAX_RESENDS,
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);
        $challengeId = (int)$stmt->fetchColumn();
        $challengeStmt = $pdo->prepare('SELECT id,
            EXTRACT(EPOCH FROM expires_at)::bigint AS expires_at,
            EXTRACT(EPOCH FROM resend_available_at)::bigint AS resend_at,
            CASE WHEN resend_locked_until IS NULL THEN NULL ELSE EXTRACT(EPOCH FROM resend_locked_until)::bigint END AS locked_until
            FROM otp_challenges WHERE id = ?');
        $challengeStmt->execute([$challengeId]);
        $stored = $challengeStmt->fetch(PDO::FETCH_ASSOC);
        return [
            'id' => $challengeId,
            'token' => $challengeToken,
            'expires_at' => (int)$stored['expires_at'],
            'resend_at' => (int)$stored['resend_at'],
            'resend_count' => $resendCount,
            'locked_until' => $stored['locked_until'] === null ? null : (int)$stored['locked_until']
        ];
    }

    public static function verify(PDO $pdo, int $id, string $token, string $purpose, string $otp): array
    {
        $stmt = $pdo->prepare('SELECT *,
            CASE WHEN expires_at <= CURRENT_TIMESTAMP THEN 1 ELSE 0 END AS is_expired
            FROM otp_challenges WHERE id = ? AND purpose = ? AND invalidated_at IS NULL AND used_at IS NULL FOR UPDATE');
        $pdo->beginTransaction();
        try {
            $stmt->execute([$id, $purpose]);
            $challenge = $stmt->fetch();
            if (!$challenge || !hash_equals($challenge['challenge_token_hash'], hash('sha256', $token))) {
                throw new RuntimeException('This verification request is no longer valid.');
            }
            if ((int)$challenge['is_expired'] === 1) {
                $pdo->prepare('UPDATE otp_challenges SET invalidated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$id]);
                $pdo->commit();
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
