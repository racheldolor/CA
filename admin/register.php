<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../services/OtpService.php';

if (empty($_SESSION['registration_csrf'])) {
    $_SESSION['registration_csrf'] = bin2hex(random_bytes(32));
}
$error = '';
$success = '';
$step = isset($_SESSION['registration_challenge']) ? 'verify' : 'details';
$registration = $_SESSION['registration_data'] ?? [];

function registrationPasswordIsValid(string $password): bool
{
    return strlen($password) >= 8 && preg_match('/[A-Z]/', $password) && preg_match('/[a-z]/', $password)
        && preg_match('/\d/', $password) && preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password);
}

function registrationCsrfIsValid(): bool
{
    return isset($_POST['csrf'], $_SESSION['registration_csrf'])
        && hash_equals($_SESSION['registration_csrf'], $_POST['csrf']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!registrationCsrfIsValid()) {
        $error = 'Your session expired. Please reload the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            $pdo = getDBConnection();
            if ($action === 'start') {
                $registration = [
                    'first_name' => trim($_POST['first_name'] ?? ''),
                    'last_name' => trim($_POST['last_name'] ?? ''),
                    'email' => trim($_POST['email'] ?? ''),
                    'sr_code' => trim($_POST['sr_code'] ?? ''),
                    'campus' => trim($_POST['campus'] ?? ''),
                    'password_hash' => password_hash($_POST['password'] ?? '', PASSWORD_DEFAULT)
                ];
                $password = $_POST['password'] ?? '';
                if ($password !== ($_POST['confirm_password'] ?? '')) {
                    throw new RuntimeException('Passwords do not match.');
                }
                if ($registration['first_name'] === '' || $registration['last_name'] === '' || $registration['sr_code'] === '' || $registration['campus'] === '') {
                    throw new RuntimeException('All registration fields are required.');
                }
                if (!filter_var($registration['email'], FILTER_VALIDATE_EMAIL) || !registrationEmailIsAllowed($registration['email'])) {
                    throw new RuntimeException('Use a valid BatStateU G-Suite email address.');
                }
                if (!registrationPasswordIsValid($password)) {
                    throw new RuntimeException('Password must be at least 8 characters and include upper, lower, number, and special characters.');
                }
                $stmt = $pdo->prepare("SELECT email FROM users WHERE BINARY email = ? UNION SELECT email FROM student_artists WHERE BINARY email = ? UNION SELECT email FROM applications WHERE BINARY email = ? AND application_status NOT IN ('rejected') LIMIT 1");
                $stmt->execute([$registration['email'], $registration['email'], $registration['email']]);
                if ($stmt->fetch()) {
                    throw new RuntimeException('This email already has an account or pending application.');
                }
                $challenge = OtpService::issue($pdo, $registration['email'], 'registration');
                $_SESSION['registration_data'] = $registration;
                $_SESSION['registration_challenge'] = $challenge;
                $step = 'verify';
                $success = 'A verification code was sent to your email address.';
            } elseif ($action === 'resend' && isset($_SESSION['registration_challenge'], $_SESSION['registration_data'])) {
                $registration = $_SESSION['registration_data'];
                $old = $_SESSION['registration_challenge'];
                $challenge = OtpService::issue($pdo, $registration['email'], 'registration', null, null, (int)$old['id']);
                $_SESSION['registration_challenge'] = $challenge;
                $step = 'verify';
                $success = 'A new verification code was sent.';
            } elseif ($action === 'verify' && isset($_SESSION['registration_challenge'], $_SESSION['registration_data'])) {
                $registration = $_SESSION['registration_data'];
                $challenge = $_SESSION['registration_challenge'];
                OtpService::verify($pdo, (int)$challenge['id'], $challenge['token'], 'registration', trim($_POST['otp'] ?? ''));
                $stmt = $pdo->prepare("INSERT INTO applications (application_type, performance_type, consent, first_name, last_name, full_name, email, campus, sr_code, registration_password_hash, application_status, status, submitted_at) VALUES ('student_artist', 'Student performer registration', 'yes', ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending', CURRENT_TIMESTAMP)");
                $stmt->execute([$registration['first_name'], $registration['last_name'], $registration['first_name'] . ' ' . $registration['last_name'], $registration['email'], $registration['campus'], $registration['sr_code'], $registration['password_hash']]);
                unset($_SESSION['registration_data'], $_SESSION['registration_challenge']);
                $step = 'details';
                $registration = [];
                $success = 'Email verified. Your application was submitted for administrator approval.';
            }
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
            $step = isset($_SESSION['registration_challenge']) ? 'verify' : 'details';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Register | Culture and Arts</title>
<style>
body{font-family:Arial,sans-serif;background:#f4f5f7;margin:0;padding:2rem;color:#243047}.card{max-width:560px;margin:auto;background:#fff;padding:2rem;border-radius:14px;box-shadow:0 12px 35px #0002}h1{text-align:center;color:#c93636}p{line-height:1.5}.grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem}.field{display:flex;flex-direction:column;gap:.35rem;margin:1rem 0}.field.full{grid-column:1/-1}input,select,button{font:inherit;padding:.8rem;border:1px solid #ccd2db;border-radius:8px}button{background:#c93636;color:#fff;border:0;cursor:pointer;font-weight:bold}button:disabled{opacity:.6}.message{padding:.8rem;border-radius:8px;margin:1rem 0}.error{background:#ffe5e5;color:#8b1e1e}.success{background:#e2f5e8;color:#176b35}.muted{color:#667085;font-size:.9rem}.otp{letter-spacing:.5rem;text-align:center;font-size:1.4rem}.link{text-align:center;margin-top:1.5rem}.link a{color:#c93636}@media(max-width:600px){.grid{grid-template-columns:1fr}.field.full{grid-column:auto}}
</style>
</head>
<body><main class="card"><h1>Culture and Arts</h1><p class="muted">BatStateU TNEU student performer registration</p>
<?php if ($error): ?><div class="message error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="message success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($step === 'verify'): ?>
<h2>Verify your email</h2><p>Enter the six-digit code sent to <strong><?= htmlspecialchars($registration['email'] ?? '') ?></strong>. It expires in five minutes.</p>
<form method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['registration_csrf']) ?>"><input type="hidden" name="action" value="verify"><div class="field"><label for="otp">Verification code</label><input class="otp" id="otp" name="otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required></div><button type="submit">Verify and submit application</button></form>
<p class="muted">Code expires in <span id="expiryCountdown">5:00</span>.</p><form method="post" style="margin-top:1rem"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['registration_csrf']) ?>"><input type="hidden" name="action" value="resend"><button id="resendButton" type="submit" disabled>Resend code in <span id="resendCountdown">60</span>s</button></form>
<?php else: ?>
<h2>Create your application</h2><p class="muted">Your application is sent to the administrator only after email verification.</p>
<form method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['registration_csrf']) ?>"><input type="hidden" name="action" value="start"><div class="grid">
<div class="field"><label for="first_name">First name</label><input id="first_name" name="first_name" required value="<?= htmlspecialchars($registration['first_name'] ?? '') ?>"></div>
<div class="field"><label for="last_name">Last name</label><input id="last_name" name="last_name" required value="<?= htmlspecialchars($registration['last_name'] ?? '') ?>"></div>
<div class="field full"><label for="email">BatStateU G-Suite email</label><input type="email" id="email" name="email" required value="<?= htmlspecialchars($registration['email'] ?? '') ?>"></div>
<div class="field"><label for="sr_code">SR code</label><input id="sr_code" name="sr_code" required value="<?= htmlspecialchars($registration['sr_code'] ?? '') ?>"></div>
<div class="field"><label for="campus">Campus</label><select id="campus" name="campus" required><option value="">Select campus</option><?php foreach (['Pablo Borbon','Alangilan','Lipa','Nasugbu','Malvar'] as $campus): ?><option <?= ($registration['campus'] ?? '') === $campus ? 'selected' : '' ?>><?= htmlspecialchars($campus) ?></option><?php endforeach; ?></select></div>
<div class="field"><label for="password">Password</label><input type="password" id="password" name="password" minlength="8" required></div>
<div class="field"><label for="confirm_password">Confirm password</label><input type="password" id="confirm_password" name="confirm_password" minlength="8" required></div>
</div><button type="submit">Send verification code</button></form>
<?php endif; ?><div class="link"><a href="../index.php">Back to sign in</a></div></main><script>
<?php if ($step === 'verify' && isset($_SESSION['registration_challenge'])): ?>
const challengeExpiresAt=<?= (int)$_SESSION['registration_challenge']['expires_at'] ?>*1000;const resendAt=<?= (int)$_SESSION['registration_challenge']['resend_at'] ?>*1000;const expiry=document.getElementById('expiryCountdown');const resend=document.getElementById('resendCountdown');const resendButton=document.getElementById('resendButton');function updateCountdown(){const now=Date.now();const left=Math.max(0,challengeExpiresAt-now);const seconds=Math.ceil(left/1000);expiry.textContent=Math.floor(seconds/60)+':'+String(seconds%60).padStart(2,'0');const resendLeft=Math.max(0,Math.ceil((resendAt-now)/1000));resend.textContent=resendLeft;resendButton.disabled=resendLeft>0;resendButton.textContent=resendLeft>0?'Resend code in '+resendLeft+'s':'Resend code'}updateCountdown();setInterval(updateCountdown,1000);
<?php endif; ?></script></body></html>
