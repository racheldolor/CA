<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/services/OtpService.php';

if (empty($_SESSION['recovery_csrf'])) {
    $_SESSION['recovery_csrf'] = bin2hex(random_bytes(32));
}
$error = '';
$success = '';
$step = isset($_SESSION['recovery_challenge']) ? 'verify' : (isset($_SESSION['recovery_grant']) ? 'reset' : 'request');

function recoveryCsrfIsValid(): bool
{
    return isset($_POST['csrf'], $_SESSION['recovery_csrf']) && hash_equals($_SESSION['recovery_csrf'], $_POST['csrf']);
}

function recoveryPasswordIsValid(string $password): bool
{
    return strlen($password) >= 8 && preg_match('/[A-Z]/', $password) && preg_match('/[a-z]/', $password)
        && preg_match('/\d/', $password) && preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!recoveryCsrfIsValid()) {
        $error = 'Your session expired. Please reload the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            $pdo = getDBConnection();
            if ($action === 'request') {
                $email = trim($_POST['email'] ?? '');
                $account = null;
                $stmt = $pdo->prepare('SELECT id, email FROM users WHERE LOWER(email) = LOWER(?) AND status = \'active\' LIMIT 1');
                $stmt->execute([$email]);
                if ($row = $stmt->fetch()) {
                    $account = ['table' => 'users', 'id' => (int)$row['id'], 'email' => $row['email']];
                } else {
                    $stmt = $pdo->prepare('SELECT id, email FROM student_artists WHERE LOWER(email) = LOWER(?) AND status = \'active\' LIMIT 1');
                    $stmt->execute([$email]);
                    if ($row = $stmt->fetch()) {
                        $account = ['table' => 'student_artists', 'id' => (int)$row['id'], 'email' => $row['email']];
                    }
                }
                if ($account) {
                    try {
                        $_SESSION['recovery_challenge'] = OtpService::issue($pdo, $account['email'], 'password_reset', $account['table'], $account['id']);
                        $_SESSION['recovery_account'] = $account;
                    } catch (Throwable $ignored) {
                        unset($_SESSION['recovery_challenge'], $_SESSION['recovery_account']);
                        error_log('Password recovery email failed: ' . $ignored->getMessage());
                    }
                }
                $step = isset($_SESSION['recovery_challenge']) ? 'verify' : 'request';
                $success = 'If an eligible account exists for that email, a verification code has been sent.';
            } elseif ($action === 'resend' && isset($_SESSION['recovery_challenge'], $_SESSION['recovery_account'])) {
                $account = $_SESSION['recovery_account'];
                $_SESSION['recovery_challenge'] = OtpService::issue($pdo, $account['email'], 'password_reset', $account['table'], $account['id'], (int)$_SESSION['recovery_challenge']['id']);
                $step = 'verify';
                $success = 'A new verification code was sent.';
            } elseif ($action === 'verify' && isset($_SESSION['recovery_challenge'], $_SESSION['recovery_account'])) {
                $challenge = $_SESSION['recovery_challenge'];
                OtpService::verify($pdo, (int)$challenge['id'], $challenge['token'], 'password_reset', trim($_POST['otp'] ?? ''));
                $grant = bin2hex(random_bytes(32));
                $_SESSION['recovery_grant'] = ['hash' => hash('sha256', $grant), 'token' => $grant, 'expires_at' => time() + 600, 'account' => $_SESSION['recovery_account']];
                unset($_SESSION['recovery_challenge']);
                $step = 'reset';
                $success = 'Code verified. You may now set a new password.';
            } elseif ($action === 'reset' && isset($_SESSION['recovery_grant'])) {
                $grant = $_SESSION['recovery_grant'];
                $password = $_POST['password'] ?? '';
                if ($grant['expires_at'] < time()) {
                    throw new RuntimeException('Your reset session expired. Request a new code.');
                }
                if (!recoveryPasswordIsValid($password)) {
                    throw new RuntimeException('Password must be at least 8 characters and include upper, lower, number, and special characters.');
                }
                if ($password !== ($_POST['confirm_password'] ?? '')) {
                    throw new RuntimeException('Passwords do not match.');
                }
                $account = $grant['account'];
                $stmt = $pdo->prepare("UPDATE {$account['table']} SET password = ? WHERE id = ?");
                $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $account['id']]);
                $stmt = $pdo->prepare("UPDATE otp_challenges SET invalidated_at = CURRENT_TIMESTAMP WHERE purpose = 'password_reset' AND email = ? AND invalidated_at IS NULL AND used_at IS NULL");
                $stmt->execute([$account['email']]);
                unset($_SESSION['recovery_grant'], $_SESSION['recovery_account']);
                $step = 'request';
                $success = 'Your password was changed successfully. You can now sign in.';
            }
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
            $step = isset($_SESSION['recovery_grant']) ? 'reset' : (isset($_SESSION['recovery_challenge']) ? 'verify' : 'request');
        }
    }
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Forgot Password | Culture and Arts</title><style>
body{font-family:Arial,sans-serif;background:#f4f5f7;margin:0;padding:2rem;color:#243047}.card{max-width:480px;margin:auto;background:#fff;padding:2rem;border-radius:14px;box-shadow:0 12px 35px #0002}h1{text-align:center;color:#c93636}.field{display:flex;flex-direction:column;gap:.35rem;margin:1rem 0}input,button{font:inherit;padding:.8rem;border:1px solid #ccd2db;border-radius:8px}button{background:#c93636;color:#fff;border:0;cursor:pointer;font-weight:bold}.message{padding:.8rem;border-radius:8px;margin:1rem 0}.error{background:#ffe5e5;color:#8b1e1e}.success{background:#e2f5e8;color:#176b35}.otp{letter-spacing:.5rem;text-align:center;font-size:1.4rem}.link{text-align:center;margin-top:1.5rem}.link a{color:#c93636}
</style></head><body><main class="card"><h1>OCA Password Recovery</h1>
<?php if ($error): ?><div class="message error"><?= htmlspecialchars($error) ?></div><?php endif; ?><?php if ($success): ?><div class="message success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($step === 'verify'): ?><p>Enter the six-digit code sent to your email. It expires in five minutes.</p><p>Code expires in <span id="expiryCountdown">5:00</span>.</p><form method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['recovery_csrf']) ?>"><input type="hidden" name="action" value="verify"><div class="field"><label for="otp">Verification code</label><input class="otp" id="otp" name="otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required></div><button type="submit">Verify code</button></form><form method="post" style="margin-top:1rem"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['recovery_csrf']) ?>"><input type="hidden" name="action" value="resend"><button id="resendButton" type="submit" disabled>Resend code in <span id="resendCountdown">60</span>s</button></form>
<?php elseif ($step === 'reset'): ?><p>Choose a new password for your account.</p><form method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['recovery_csrf']) ?>"><input type="hidden" name="action" value="reset"><div class="field"><label for="password">New password</label><input type="password" id="password" name="password" minlength="8" required></div><div class="field"><label for="confirm_password">Confirm password</label><input type="password" name="confirm_password" minlength="8" required></div><button type="submit">Change password</button></form>
<?php else: ?><p>Enter your registered email. If an eligible account exists, we will send a verification code.</p><form method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['recovery_csrf']) ?>"><input type="hidden" name="action" value="request"><div class="field"><label for="email">Email address</label><input type="email" id="email" name="email" required></div><button type="submit">Send verification code</button></form><?php endif; ?><div class="link"><a href="index.php">Back to sign in</a></div></main><script>
<?php if ($step === 'verify' && isset($_SESSION['recovery_challenge'])): ?>
const challengeExpiresAt=<?= (int)$_SESSION['recovery_challenge']['expires_at'] ?>*1000;const resendAt=<?= (int)$_SESSION['recovery_challenge']['resend_at'] ?>*1000;const expiry=document.getElementById('expiryCountdown');const resend=document.getElementById('resendCountdown');const resendButton=document.getElementById('resendButton');function updateCountdown(){const now=Date.now();const left=Math.max(0,challengeExpiresAt-now);const seconds=Math.ceil(left/1000);expiry.textContent=Math.floor(seconds/60)+':'+String(seconds%60).padStart(2,'0');const resendLeft=Math.max(0,Math.ceil((resendAt-now)/1000));resend.textContent=resendLeft;resendButton.disabled=resendLeft>0;resendButton.textContent=resendLeft>0?'Resend code in '+resendLeft+'s':'Resend code'}updateCountdown();setInterval(updateCountdown,1000);
<?php endif; ?></script></body></html>
