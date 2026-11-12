<?php
session_start();
require_once 'config/database.php';

$error_message = '';
$success_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validation
    if (empty($email) || empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $error_message = 'All fields are required.';
    } elseif ($new_password !== $confirm_password) {
        $error_message = 'New passwords do not match.';
    } elseif (strlen($new_password) < 6 || strlen($new_password) > 12) {
        $error_message = 'New password must be between 6 and 12 characters long.';
    } elseif (!preg_match('/[A-Za-z]/', $new_password)) {
        $error_message = 'New password must contain at least one letter.';
    } elseif (!preg_match('/[0-9]/', $new_password)) {
        $error_message = 'New password must contain at least one number.';
    } else {
        try {
            $pdo = getDBConnection();
            
            // First check users table
            $stmt = $pdo->prepare("SELECT id, password FROM users WHERE LOWER(email) = LOWER(?)");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $found_user = false;
            $table_name = '';
            
            if ($user) {
                $found_user = true;
                $table_name = 'users';
            } else {
                // Check student_artists table
                $stmt = $pdo->prepare("SELECT id, password FROM student_artists WHERE LOWER(email) = LOWER(?)");
                $stmt->execute([$email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user) {
                    $found_user = true;
                    $table_name = 'student_artists';
                }
            }
            
            if (!$found_user) {
                $error_message = 'Email address not found.';
            } elseif (!password_verify($current_password, $user['password'])) {
                $error_message = 'Current password is incorrect.';
            } else {
                // Update password
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE $table_name SET password = ? WHERE id = ?");
                $stmt->execute([$hashed_password, $user['id']]);
                
                $success_message = 'Password updated successfully! You can now login with your new password.';
                
                // Clear form data on success
                $_POST = array();
            }
        } catch (Exception $e) {
            $error_message = 'An error occurred while updating your password. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/jpeg" href="assets/logo.jpg?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - Culture and Arts - BatStateU TNEU</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Montserrat', sans-serif;
            background: #f5f5f5;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }

        .login-container {
            width: 100%;
            max-width: 500px;
        }

        .login-card {
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
            overflow: hidden;
            animation: slideUp 0.8s ease-out;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .login-header {
            background: linear-gradient(135deg, #ff5a5a, #ff7a6b);
            padding: 2.5rem 2rem;
            text-align: center;
            color: white;
        }

        .logo-section {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
        }

        .logo {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
        }

        .title {
            font-size: 1.8rem;
            font-weight: 700;
            margin: 0;
        }

        .subtitle {
            font-size: 1rem;
            font-weight: 500;
            opacity: 0.9;
            margin: 0;
        }

        .login-body {
            padding: 2.5rem 2rem;
        }

        .form-title {
            font-size: 2rem;
            font-weight: 700;
            color: #2a3550;
            margin-bottom: 0.5rem;
            text-align: center;
        }

        .form-description {
            color: #666;
            text-align: center;
            margin-bottom: 2rem;
            font-size: 0.95rem;
        }

        .login-form {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            flex: 1;
        }

        .form-label {
            font-weight: 600;
            color: #2a3550;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }

        .form-input {
            padding: 0.875rem 1rem;
            border: 2px solid #e1e5e9;
            border-radius: 10px;
            font-size: 1rem;
            font-family: inherit;
            transition: all 0.3s ease;
            background: #f8f9fa;
        }

        .form-input:focus {
            outline: none;
            border-color: #ff5a5a;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(255, 90, 90, 0.1);
        }

        .form-input:valid {
            border-color: #28a745;
        }

        .form-input.error {
            border-color: #dc3545;
        }

        .error-message {
            color: #dc3545;
            font-size: 0.8rem;
            margin-top: 0.25rem;
            min-height: 1rem;
        }

        .error-message-box {
            background: #f8d7da;
            color: #721c24;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border: 1px solid #f5c6cb;
            font-size: 0.9rem;
        }

        .password-requirements {
            font-size: 0.75rem;
            color: #666;
            margin-top: 0.25rem;
        }

        .show-password-container {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: -1.2rem;
            font-size: 0.9rem;
        }

        .show-password-container input[type="checkbox"] {
            cursor: pointer;
            accent-color: #dc2626;
        }

        .show-password-container label {
            cursor: pointer;
            color: #666;
            font-weight: normal;
        }

        .success-message-box {
            background: #d4edda;
            color: #155724;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border: 1px solid #c3e6cb;
            font-size: 0.9rem;
        }

        .login-btn {
            background: linear-gradient(135deg, #ff5a5a, #ff7a6b);
            color: white;
            border: none;
            border-radius: 12px;
            padding: 1rem 2rem;
            font-size: 1.1rem;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 1rem;
            position: relative;
            overflow: hidden;
        }

        .login-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s ease;
        }

        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(255, 90, 90, 0.3);
        }

        .login-btn:hover::before {
            left: 100%;
        }

        .login-btn:active {
            transform: translateY(0);
        }

        .login-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .signin-link {
            text-align: center;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid #e1e5e9;
        }

        .signin-link p {
            color: #666;
            font-size: 0.95rem;
        }

        .link {
            color: #ff5a5a;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s ease;
        }

        .link:hover {
            color: #ff3333;
            text-decoration: underline;
        }

        .password-requirements {
            font-size: 0.8rem;
            color: #666;
            margin-top: 0.25rem;
        }

        /* Success Message */
        .success-message {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            text-align: center;
            font-weight: 500;
        }

        /* Loading State */
        .login-btn.loading {
            pointer-events: none;
        }

        .login-btn.loading::after {
            content: '';
            position: absolute;
            width: 16px;
            height: 16px;
            margin: auto;
            border: 2px solid transparent;
            border-top-color: #ffffff;
            border-radius: 50%;
            animation: button-loading-spinner 1s ease infinite;
        }

        @keyframes button-loading-spinner {
            from {
                transform: rotate(0turn);
            }
            to {
                transform: rotate(1turn);
            }
        }

        /* Responsive Design */
        @media (max-width: 600px) {
            .login-container {
                padding: 1rem;
            }
            
            .login-card {
                border-radius: 15px;
            }
            
            .login-header {
                padding: 2rem 1.5rem;
            }
            
            .login-body {
                padding: 2rem 1.5rem;
            }
            
            .title {
                font-size: 1.5rem;
            }
            
            .form-title {
                font-size: 1.7rem;
            }
        }

        @media (max-width: 400px) {
            body {
                padding: 1rem 0.5rem;
            }
            
            .login-header {
                padding: 1.5rem 1rem;
            }
            
            .login-body {
                padding: 1.5rem 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="logo-section">
                    <img src="assets/logo.jpg" alt="BatstateU Logo" class="logo">
                    <h1 class="title">Culture and Arts</h1>
                    <p class="subtitle">BatStateU TNEU</p>
                </div>
            </div>
            
            <div class="login-body">
                <h2 class="form-title">Change Password</h2>
                <p class="form-description">Update your account password</p>
                
                <?php if ($error_message): ?>
                    <div class="error-message-box">
                        <?= htmlspecialchars($error_message) ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($success_message): ?>
                    <div class="success-message-box">
                        <?= htmlspecialchars($success_message) ?>
                    </div>
                <?php endif; ?>

                <form class="login-form" method="POST" action="">
                    <div class="form-group">
                        <label for="email" class="form-label">Email Address</label>
                        <input type="email" id="email" name="email" class="form-input" 
                               value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>" 
                               required pattern=".*@g\.batstate-u\.edu\.ph$" 
                               title="Must be a valid BatStateU email">
                        <div class="password-requirements">Must be a valid @g.batstate-u.edu.ph email</div>
                    </div>

                    <div class="form-group">
                        <label for="current_password" class="form-label">Current Password</label>
                        <input type="password" id="current_password" name="current_password" class="form-input" required>
                    </div>

                    <div class="form-group">
                        <label for="new_password" class="form-label">New Password</label>
                        <input type="password" id="new_password" name="new_password" class="form-input" required minlength="6" maxlength="12">
                        <div class="password-requirements">Must be 6-12 characters with at least one letter and one number</div>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password" class="form-label">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-input" required minlength="6" maxlength="12">
                    </div>

                    <div class="show-password-container">
                        <input type="checkbox" id="showPassword" onchange="toggleAllPasswords()">
                        <label for="showPassword">Show Password</label>
                    </div>

                    <button type="submit" class="login-btn">Update Password</button>
                </form>

                <div class="signin-link">
                    <p><a href="index.php" class="link">← Back to Login</a></p>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Show/hide all password fields
        function toggleAllPasswords() {
            const checkbox = document.getElementById('showPassword');
            const passwordFields = ['current_password', 'new_password', 'confirm_password'];
            
            passwordFields.forEach(fieldId => {
                const field = document.getElementById(fieldId);
                if (field) {
                    field.type = checkbox.checked ? 'text' : 'password';
                }
            });
        }

        // Enhanced password validation
        function validatePassword(password) {
            const hasLetter = /[A-Za-z]/.test(password);
            const hasNumber = /[0-9]/.test(password);
            const validLength = password.length >= 6 && password.length <= 12;
            
            return {
                valid: hasLetter && hasNumber && validLength,
                hasLetter,
                hasNumber,
                validLength
            };
        }

        // Real-time password validation feedback
        document.getElementById('new_password').addEventListener('input', function() {
            const password = this.value;
            const validation = validatePassword(password);
            const confirmPassword = document.getElementById('confirm_password');
            
            // Update custom validity based on validation rules
            if (!validation.validLength) {
                this.setCustomValidity('Password must be 6-12 characters long');
            } else if (!validation.hasLetter) {
                this.setCustomValidity('Password must contain at least one letter');
            } else if (!validation.hasNumber) {
                this.setCustomValidity('Password must contain at least one number');
            } else {
                this.setCustomValidity('');
            }
            
            // Check password confirmation match
            if (confirmPassword.value && password !== confirmPassword.value) {
                confirmPassword.setCustomValidity('Passwords do not match');
            } else if (validation.valid) {
                confirmPassword.setCustomValidity('');
            }
        });

        // Client-side password confirmation validation
        document.getElementById('confirm_password').addEventListener('input', function() {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = this.value;
            const validation = validatePassword(newPassword);
            
            if (newPassword !== confirmPassword) {
                this.setCustomValidity('Passwords do not match');
            } else if (!validation.valid) {
                this.setCustomValidity('New password must meet all requirements');
            } else {
                this.setCustomValidity('');
            }
        });
    </script>
</body>
</html>