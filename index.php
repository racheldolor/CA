<?php
session_start();
require_once 'config/database.php';

// Server-side login handling (process modal form here instead of login.php)
$error_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email'], $_POST['password'])) {
    $email = trim($_POST['email']); // Keep original case - do NOT convert to lowercase
    $password = $_POST['password'];
    
    // Validate email domain (case-sensitive)
    if (!preg_match('/@g\.batstate-u\.edu\.ph$/', $email)) {
        $error_message = 'Only @g.batstate-u.edu.ph email addresses are allowed.';
    } elseif ($email !== '' && $password !== '') {
        // Hardcoded admin credential (per request)
        if ($email === 'admin@g.batstate-u.edu.ph' && $password === 'batstateu') {
            $_SESSION['user_id'] = 0;
            $_SESSION['user_email'] = $email;
            $_SESSION['user_name'] = 'Administrator';
            $_SESSION['user_role'] = 'admin';
            $_SESSION['logged_in'] = true;
            header('Location: admin/dashboard.php');
            exit();
        }

        try {
            $pdo = getDBConnection();
            
            // First, check the users table (for admin, head, director) - CASE-SENSITIVE
            $stmt = $pdo->prepare("SELECT id, first_name, middle_name, last_name, email, password, role, status, campus FROM users WHERE BINARY email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $user_table = 'users'; // Track which table the user comes from
            
            // If not found in users table, check student_artists table - CASE-SENSITIVE
            if (!$user) {
                $stmt = $pdo->prepare("SELECT id, first_name, middle_name, last_name, email, password, status, sr_code, campus FROM student_artists WHERE BINARY email = ?");
                $stmt->execute([$email]);
                $student = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($student) {
                    // Convert student data to user format
                    $user = $student;
                    $user['role'] = 'student';
                    $user_table = 'student_artists';
                }
            }
            
            if ($user) {
                if ($user['status'] !== 'active') {
                    $error_message = 'Your account is ' . $user['status'] . '. Please contact the administrator.';
                } elseif (password_verify($password, $user['password'])) {
                    // Update last login based on which table
                    if ($user_table === 'student_artists') {
                        $stmt = $pdo->prepare("UPDATE student_artists SET last_login = NOW() WHERE id = ?");
                        $stmt->execute([$user['id']]);
                    } else {
                        $stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                        $stmt->execute([$user['id']]);
                    }

                    // Set session
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_name'] = trim($user['first_name'] . ' ' . ($user['middle_name'] ? $user['middle_name'] . ' ' : '') . $user['last_name']);
                    $_SESSION['user_role'] = $user['role'];
                    $_SESSION['user_table'] = $user_table; // Store which table the user is from
                    $_SESSION['user_campus'] = $user['campus'] ?? null; // Store campus from database
                    $_SESSION['logged_in'] = true;
                    
                    // For students, also store SR code if available
                    if ($user['role'] === 'student' && isset($user['sr_code'])) {
                        $_SESSION['sr_code'] = $user['sr_code'];
                    }

                    // Redirect by role and campus
                    switch ($user['role']) {
                        case 'head':
                        case 'director':
                            header('Location: head-staff/dashboard.php');
                            exit();
                        case 'student':
                            header('Location: student/dashboard.php');
                            exit();
                        default:
                            header('Location: admin/dashboard.php');
                            exit();
                    }
                } else {
                    $error_message = 'Invalid email or password.';
                }
            } else {
                $error_message = 'Account not found. Please check your email address.';
            }
        } catch (Exception $e) {
            $error_message = 'Login failed. Please try again later.';
        }
    } else {
        $error_message = 'Please fill in all fields.';
    }
}

// Determine primary CTA based on session
$cta_url = 'login.php';
$cta_label = 'Sign In';
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true && isset($_SESSION['user_role'])) {
    switch ($_SESSION['user_role']) {
        case 'head':
        case 'director':
            $cta_url = 'head-staff/dashboard.php';
            break;
        case 'student':
            $cta_url = 'student/dashboard.php';
            break;
        default:
            $cta_url = 'admin/dashboard.php';
    }
    $cta_label = 'Go to Dashboard';
}
?>
<!DOCTYPE html>

<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Culture and Arts - BatStateU TNEU</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="assets/OCA Logo.png">
    <meta name="description" content="Culture and Arts Management System of BatStateU TNEU — manage student artists, events, groups, and reports.">
    <style>
        :root{
            --primary:#dc2626; /* BatStateU red tone */
            --primary-2:#b91c1c;
            --text:#1f2937;
            --muted:#6b7280;
            --bg:#f8fafc;
            --card:#ffffff;
        }

        /* Global font to match modal */
        html, body { font-family: 'Montserrat', sans-serif; color: var(--text); }
        button, input, select, textarea { font-family: inherit; }

        /* Prevent background scrolling when modals are open */
        body.modal-open {
            overflow: hidden;
        }

        /* Links - remove default underlines for a cleaner look */
        a{ text-decoration: none; color: inherit; }
        a:hover, a:focus{ text-decoration: none; }

        /* Top nav */
        .nav{ position:sticky; top:0; z-index:50; background:rgba(255,255,255,0.9); backdrop-filter: blur(8px); border-bottom:1px solid #e5e7eb; }
        /* Make nav span full width with a small safe padding */
        .nav-inner{ max-width:none; width:100%; margin:0; display:flex; align-items:center; justify-content:space-between; padding:0.65rem 1rem; }
        .brand{ display:flex; align-items:center; gap:0.75rem; font-weight:700; }
        .brand img{ width:40px; height:40px; border-radius:50%; }
        .nav-links{ display:flex; gap:1rem; align-items:center; }
        .btn{ display:inline-flex; align-items:center; justify-content:center; border:none; cursor:pointer; font-weight:600; border-radius:10px; padding:0.7rem 1.1rem; transition: all .2s ease; white-space: nowrap; }
        .btn-primary{ background:linear-gradient(135deg,var(--primary),var(--primary-2)); color:#fff; }
        .btn-primary:hover{ transform:translateY(-1px); box-shadow:0 10px 20px rgba(220,38,38,.25);} 
        .btn-ghost{ background:transparent; color:var(--text); }
        .btn-ghost:hover{ background:#f3f4f6; }

        /* Hero banner (collage-style) */
        .hero{ position:relative; overflow:hidden; }
        .hero::before{
            content:""; position:absolute; inset:0;
            background: 
                linear-gradient(180deg, rgba(0,0,0,0.30), rgba(0,0,0,0.30)),
                url('assets/main.jpg');
            background-size: cover; background-position: center; background-repeat: no-repeat;
            z-index:0;
        }
        /* Full-width hero with slight side padding */
        .hero-inner{ max-width:none; width:100%; margin:0; padding:3rem 1rem; display:flex; align-items:center; justify-content:center; height:340px; position:relative; z-index:1; }
        .hero-logo{ width:120px; height:120px; border-radius:50%; background:#fff; display:flex; align-items:center; justify-content:center; box-shadow:0 20px 40px rgba(0,0,0,.2); overflow:hidden; }
        .hero-logo img{ width:100%; height:100%; object-fit:cover; border-radius:50%; }

        /* Sections */
        .section{ padding:2rem 1rem; }
        .container{ max-width:1100px; margin:0 auto; }
        .section h2{ font-size:1.6rem; margin-bottom:1rem; text-align:center; }
        .muted{ color:var(--muted); }
        .grid{ display:grid; gap:1.25rem; }
        .campus-grid{ grid-template-columns: repeat(5, 1fr); }
        .campus-card{ background:#fff; border-radius:14px; box-shadow:0 8px 24px rgba(0,0,0,.08); overflow:hidden; transition:transform .2s ease, box-shadow .2s ease; cursor:pointer; }
        .campus-card:focus-visible{ outline:3px solid var(--primary); outline-offset:2px; }
        .campus-card:hover{ transform:translateY(-4px) scale(1.03); box-shadow:0 16px 40px rgba(0,0,0,.12); }
        .thumb{ width:100%; aspect-ratio: 4/3; background:#f2f2f2; background-size:cover; background-position:center; }
        .thumb.malvar{ background-image:linear-gradient(180deg, rgba(0,0,0,0.08), rgba(0,0,0,0.08)), url('assets/4malvar-1024x683.jpg'); }
        .thumb.nasugbu{ background-image:linear-gradient(180deg, rgba(0,0,0,0.08), rgba(0,0,0,0.08)), url('assets/nasugbu-campus-768x512.jpg'); }
        .thumb.pablo{ background-image:linear-gradient(180deg, rgba(0,0,0,0.08), rgba(0,0,0,0.08)), url('assets/1pabloborbon-1536x1024.jpg'); }
        .thumb.alangilan{ background-image:linear-gradient(180deg, rgba(0,0,0,0.08), rgba(0,0,0,0.08)), url('assets/alangilan-768x512.jpg'); }
        .thumb.lipa{ background-image:linear-gradient(180deg, rgba(0,0,0,0.08), rgba(0,0,0,0.08)), url('assets/5lipa-scaled-1024x683.jpg'); }
        .campus-name{ text-align:center; font-weight:800; color:var(--primary); padding:.75rem .5rem 1rem; }

        /* Footer */
        footer{ padding:2rem 1rem; border-top:1px solid #e5e7eb; background:#fff; }
    .footer-inner{ max-width:1100px; margin:0 auto; display:flex; align-items:center; justify-content:center; gap:1rem; flex-wrap:wrap; text-align:center; }
        .small{ font-size:.9rem; color:var(--muted); }

        /* Responsive */
        @media (max-width: 1100px){ .campus-grid{ grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 700px){ .campus-grid{ grid-template-columns: repeat(2, 1fr); } .hero-inner{ height:260px; } }
        @media (max-width: 480px){ 
            * { box-sizing: border-box; }
            body { overflow-x: hidden; margin: 0; padding: 0; }
            .campus-grid{ grid-template-columns: 1fr; gap: 1rem; padding: 0 0.75rem; } 
            .nav-inner{ padding: 0.6rem 0.8rem; }
            .nav-links{ gap: 0.5rem; flex-wrap: wrap; justify-content: flex-end; }
            .nav-links a { font-size: 0.85rem; padding: 0.4rem 0.75rem; }
            .hero { min-height: 280px; }
            .hero-inner { 
                height: auto; 
                padding: 1.5rem 0.75rem; 
                min-height: 280px;
            }
            .hero-text { 
                font-size: 1.5rem; 
                line-height: 1.3;
                margin-bottom: 0.75rem;
            }
            .hero-subtitle { 
                font-size: 0.95rem; 
                max-width: 100%;
                margin-bottom: 1rem;
            }
            .campus-card {
                padding: 1rem;
                border-radius: 12px;
            }
            .campus-icon {
                width: 50px;
                height: 50px;
                font-size: 1.5rem;
            }
            .campus-name {
                font-size: 1rem;
                margin: 0.75rem 0 0.35rem;
            }
            .campus-desc {
                font-size: 0.85rem;
                line-height: 1.4;
            }
            .campus-card .btn {
                padding: 0.5rem 1rem;
                font-size: 0.85rem;
                margin-top: 0.75rem;
            }
            .logo { 
                height: 36px; 
                width: auto;
            }
            .container {
                padding: 0 0.75rem;
            }
            section {
                padding: 2rem 0;
            }
            .section-title {
                font-size: 1.5rem;
                margin-bottom: 1rem;
            }
        }
    </style>
</head>
<body>
    <!-- NAV -->
    <nav class="nav">
        <div class="nav-inner">
            <a class="brand" href="./" aria-label="Culture and Arts Home">
                <img src="assets/OCA Logo.png" alt="BatStateU Culture and Arts logo">
                <span>Culture & Arts • BatStateU TNEU</span>
            </a>
            <div class="nav-links">
                <a class="btn btn-primary" href="login.php" onclick="openLoginModal(event)">Log In</a>
            </div>
        </div>
    </nav>

    <!-- HERO -->
    <section class="hero" aria-label="Office of Culture and Arts">
        <div class="hero-inner">
            <div class="hero-logo">
                <img src="assets/OCA Logo.png" alt="BatStateU Culture & Arts Logo">
            </div>
        </div>
    </section>

    <!-- CAMPUSES STRIP -->
    <section class="section" aria-label="Campuses">
        <div class="container">
            <h2>BatStateU Campuses</h2>
            <div class="grid campus-grid" style="margin-top:1rem;">
                <a class="campus-card" href="#" onclick="openCampusModal(event,'malvar')" aria-label="Malvar campus information">
                    <div class="thumb malvar" role="img" aria-label="Malvar campus"></div>
                    <div class="campus-name">Malvar</div>
                </a>
                <a class="campus-card" href="#" onclick="openCampusModal(event,'nasugbu')" aria-label="Nasugbu campus information">
                    <div class="thumb nasugbu" role="img" aria-label="Nasugbu campus"></div>
                    <div class="campus-name">Nasugbu</div>
                </a>
                <a class="campus-card" href="#" onclick="openCampusModal(event,'pablo')" aria-label="Pablo Borbon campus information">
                    <div class="thumb pablo" role="img" aria-label="Pablo Borbon campus"></div>
                    <div class="campus-name">Pablo Borbon</div>
                </a>
                <a class="campus-card" href="#" onclick="openCampusModal(event,'alangilan')" aria-label="Alangilan campus information">
                    <div class="thumb alangilan" role="img" aria-label="Alangilan campus"></div>
                    <div class="campus-name">Alangilan</div>
                </a>
                <a class="campus-card" href="#" onclick="openCampusModal(event,'lipa')" aria-label="Lipa campus information">
                    <div class="thumb lipa" role="img" aria-label="Lipa campus"></div>
                    <div class="campus-name">Lipa</div>
                </a>
            </div>
        </div>
    </section>

    <!-- Login Modal (uses same markup, styles, and behavior as login.php) -->
    <div id="loginModal" class="login-modal" aria-hidden="true" style="display:none; position:fixed; inset:0; z-index:1200; background:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
            <div style="width:100%; max-width:520px; margin:1rem;">
            

            <div class="modal-login-card">
                <div class="login-card" style="width:100%;">

                    <!-- Header to match standalone login page design -->
                    <div class="login-header">
                        <div class="logo-section">
                            <img src="assets/OCA Logo.png" alt="BatStateU Culture & Arts Logo" class="logo">
                            <h1 class="title">Culture and Arts</h1>
                            <p class="subtitle">BatStateU TNEU</p>
                        </div>
                    </div>

                    <div class="login-body" style="padding:1.25rem 1.25rem;">
                        <button type="button" aria-label="Close login" class="close-btn" onclick="closeLoginModal()">&times;</button>
                        <h2 class="form-title" style="margin-bottom: 0.25rem;">Login</h2>
                        <p class="form-description" style="margin-bottom: 0.75rem;">Use your BatStateU account to continue.</p>
                        <?php if (!empty($error_message)): ?>
                            <div class="error-message-box">
                                <?= htmlspecialchars($error_message) ?>
                            </div>
                        <?php endif; ?>

                        <form class="login-form" id="loginForm" method="POST" action="">
                            <div class="form-group">
                                <label for="email" class="form-label">Email Address</label>
                                <input type="email" id="email" name="email" class="form-input" required 
                                       pattern="[a-zA-Z0-9._%+-]+@g\.batstate-u\.edu\.ph$"
                                       title="Please use your @g.batstate-u.edu.ph email address"
                                       value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>">
                                <span class="error-message" id="emailError"></span>
                            </div>

                            <div class="form-group">
                                <label for="password" class="form-label">Password</label>
                                <input type="password" id="password" name="password" class="form-input" required>
                                <span class="error-message" id="passwordError"></span>
                            </div>

                            <div class="show-password-container">
                                <input type="checkbox" id="showPassword" onchange="toggleAllPasswords()">
                                <label for="showPassword">Show Password</label>
                            </div>

                            <button type="submit" class="login-btn">Login</button>
                        </form>

                        <div class="signin-link">
                            <p>Forgot your password? <a href="forgot-password.php" class="link">Recover it here</a></p>
                            <p>Check your application status? <a href="student/check-status.php" class="link">View status here</a></p>
                            <p>Interested in becoming a Student Performer? <a href="student/performer-profile-form.php" class="link">Register here</a></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Campus Info Modal -->
    <div id="campusModal" class="campus-modal" style="display:none; position:fixed; inset:0; z-index:1200; background:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
        <div class="modal-campus-card" style="width:100%; max-width:720px; margin:1rem;">
            <div class="campus-hero" id="campusHero"></div>
            <button type="button" aria-label="Close campus info" class="close-btn" onclick="closeCampusModal()">&times;</button>
            <div class="campus-body">
                <h3 class="campus-title" id="campusTitle"></h3>
                <p class="campus-desc" id="campusDesc"></p>
            </div>
        </div>
    </div>

            <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        .modal-login-card { font-family: 'Montserrat', sans-serif; }
        .modal-login-card .login-container { width: 100%; max-width: 500px; margin: 0 auto; }
        .modal-login-card .login-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 16px 48px rgba(0, 0, 0, 0.14);
            overflow: hidden;
            animation: slideUp 0.6s ease-out;
            position: relative;
        }
        @keyframes slideUp { from { opacity: 0; transform: translateY(20px);} to { opacity: 1; transform: translateY(0);} }
        .modal-login-card .login-header { background: linear-gradient(135deg, #ff5a5a, #ff7a6b); padding: 1.5rem 1.25rem; text-align: center; color: white; }
        .modal-login-card .logo-section { display: flex; flex-direction: column; align-items: center; gap: 0.35rem; }
        .modal-login-card .logo { width: 50px; height: 50px; border-radius: 50%; background: rgba(255, 255, 255, 0.2); }
        .modal-login-card .title { font-size: 1.45rem; font-weight: 700; }
        .modal-login-card .subtitle { font-size: 0.9rem; font-weight: 500; opacity: 0.9; }
        .modal-login-card .login-body { padding: 1.25rem 1.25rem; }
        .modal-login-card .form-title { font-size: 1.4rem; font-weight: 700; color: #2a3550; margin-bottom: 0.25rem; text-align: center; }
        .modal-login-card .form-description { color: #666; text-align: center; margin-bottom: 1rem; font-size: 0.92rem; }
        .modal-login-card .login-form { display: flex; flex-direction: column; gap: 1rem; }
        .modal-login-card .form-group { display: flex; flex-direction: column; }
        .modal-login-card .form-label { font-weight: 600; color: #2a3550; margin-bottom: 0.35rem; font-size: 0.9rem; }
        .modal-login-card .form-input { padding: 0.7rem 0.9rem; border: 2px solid #e1e5e9; border-radius: 10px; font-size: 0.95rem; background: #f8f9fa; transition: all 0.3s ease; }
        .modal-login-card .form-input:focus { outline: none; border-color: #ff5a5a; background: #fff; box-shadow: 0 0 0 3px rgba(255, 90, 90, 0.1); }
        .modal-login-card .form-input.error { border-color: #dc3545; }
        .modal-login-card .error-message { color: #dc3545; font-size: 0.8rem; margin-top: 0.25rem; min-height: 1rem; }
        .modal-login-card .error-message-box { background: #f8d7da; color: #721c24; padding: 0.85rem; border-radius: 8px; margin-bottom: 1rem; border: 1px solid #f5c6cb; font-size: 0.9rem; }
        .modal-login-card .success-message-box { background: #d4edda; color: #155724; padding: 0.85rem; border-radius: 8px; margin-bottom: 1rem; border: 1px solid #c3e6cb; font-size: 0.9rem; }
        .modal-login-card .show-password-container { display: flex; align-items: center; gap: 0.5rem; margin-top: -0.8rem; font-size: 0.9rem; }
        .modal-login-card .login-btn { background: linear-gradient(135deg, #ff5a5a, #ff7a6b); color: white; border: none; border-radius: 10px; padding: 0.8rem 1.25rem; font-size: 1rem; font-weight: 600; cursor: pointer; transition: all 0.3s ease; margin-top: 0.75rem; position: relative; overflow: hidden; }
        .modal-login-card .login-btn::before { content: ''; position: absolute; top: 0; left: -100%; width: 100%; height: 100%; background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent); transition: left 0.5s ease; }
        .modal-login-card .login-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(255, 90, 90, 0.3); }
        .modal-login-card .login-btn:hover::before { left: 100%; }
        .modal-login-card .login-btn:disabled { opacity: 0.6; cursor: not-allowed; }
        .modal-login-card .close-btn { position: absolute; top: 10px; right: 10px; width: 34px; height: 34px; border: none; border-radius: 10px; background: rgba(255,255,255,0.9); color: #7a1a1a; font-size: 20px; line-height: 1; cursor: pointer; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 8px rgba(0,0,0,0.12); }
        .modal-login-card .close-btn:hover { background: #ffffff; }
        .modal-login-card button, .modal-login-card input, .modal-login-card select, .modal-login-card textarea { font-family: inherit; }
        .modal-login-card .signin-link { text-align: center; margin-top: 1rem; padding-top: 0.9rem; border-top: 1px solid #e1e5e9; }
        .modal-login-card .link { color: #ff5a5a; text-decoration: none; font-weight: 600; }
        @media (max-width: 600px) { 
            .modal-login-card .login-container{padding:0.75rem;} 
            .modal-login-card .title{font-size:1.35rem;} 
            .modal-login-card .form-title{font-size:1.3rem;} 
        }
        @media (max-width: 480px) {
            .modal-login-card {
                width: 90% !important;
                max-width: 90% !important;
                margin: 1rem !important;
                border-radius: 16px !important;
            }
            .modal-login-card .login-header {
                padding: 1rem;
            }
            .modal-login-card .login-body {
                padding: 1rem;
            }
            .modal-login-card .title {
                font-size: 1.2rem;
            }
            .modal-login-card .subtitle {
                font-size: 0.85rem;
            }
            .modal-login-card .form-title {
                font-size: 1.1rem;
                margin-bottom: 0.5rem;
            }
            .modal-login-card .form-description {
                font-size: 0.85rem;
                margin-bottom: 0.5rem;
            }
            .modal-login-card .login-form {
                gap: 0.25rem;
            }
            .modal-login-card .error-message {
                min-height: 0;
                display: none;
            }
            .modal-login-card label {
                font-size: 0.85rem;
                margin-bottom: 0.3rem;
            }
            .modal-login-card input {
                padding: 0.65rem;
                font-size: 16px;
            }
            .modal-login-card .show-password-container {
                margin-top: 0.35rem;
                font-size: 0.85rem;
            }
            .modal-login-card .login-btn {
                padding: 0.7rem;
                font-size: 0.95rem;
                margin-top: 0.5rem;
            }
            .modal-login-card .close-btn {
                width: 38px;
                height: 38px;
                font-size: 22px;
            }
            .modal-login-card .signin-link {
                margin-top: 0.75rem;
                padding-top: 0.75rem;
                font-size: 0.85rem;
            }
            .modal-login-card .error-message {
                padding: 0.7rem;
                font-size: 0.85rem;
                margin-bottom: 0.75rem;
            }
        }
            </style>

            <style>
        /* Campus info modal styles */
        .modal-campus-card{ background:#fff; border-radius:16px; box-shadow:0 16px 48px rgba(0,0,0,.14); overflow:hidden; position:relative; animation: slideUp 0.4s ease-out; }
        .campus-hero{ height:180px; background:#e5e7eb center/cover no-repeat; position:relative; }
        .campus-body{ padding:1.25rem 1.25rem 1.5rem; }
        .campus-title{ font-size:1.4rem; font-weight:800; color:#1f2937; margin:0 0 .5rem; text-align:left; }
        .campus-desc{ color:#4b5563; line-height:1.6; font-size:.98rem; }
        .campus-modal .close-btn{ position:absolute; top:10px; right:10px; width:34px; height:34px; border:none; border-radius:10px; background:rgba(255,255,255,.95); color:#7a1a1a; font-size:20px; display:flex; align-items:center; justify-content:center; box-shadow:0 2px 8px rgba(0,0,0,.12); cursor:pointer; }
        .campus-modal .close-btn:hover{ background:#fff; }
        @media (max-width: 480px) {
            .modal-campus-card {
                width: 90% !important;
                max-width: 90% !important;
                margin: 1rem !important;
                border-radius: 16px !important;
                max-height: 85vh;
                overflow-y: auto;
            }
            .campus-hero {
                height: 140px;
            }
            .campus-body {
                padding: 1rem;
            }
            .campus-title {
                font-size: 1.2rem;
                margin-bottom: 0.75rem;
            }
            .campus-desc {
                font-size: 0.9rem;
                line-height: 1.5;
            }
            .campus-modal .close-btn {
                width: 38px;
                height: 38px;
                font-size: 22px;
            }
        }
            </style>

    <script>
        // Modal scroll prevention functions
        function preventBackgroundScroll() {
            document.body.classList.add('modal-open');
        }

        function allowBackgroundScroll() {
            document.body.classList.remove('modal-open');
        }

        // Modal controls
        function openLoginModal(e) {
            if (e) e.preventDefault();
            var modal = document.getElementById('loginModal');
            if (modal) {
                modal.style.display = 'flex';
                modal.setAttribute('aria-hidden','false');
                preventBackgroundScroll();
            }
            setTimeout(function(){ var inp = document.querySelector('#loginForm input[name="email"]'); if(inp) inp.focus(); }, 100);
        }
        function closeLoginModal() {
            var modal = document.getElementById('loginModal');
            if (modal) {
                modal.style.display = 'none';
                modal.setAttribute('aria-hidden','true');
                allowBackgroundScroll();
            }
        }
        // Close on Escape and on backdrop click
        document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeLoginModal(); });
        document.addEventListener('click', function(e){ var modal = document.getElementById('loginModal'); if (!modal) return; if (modal.style.display !== 'none' && e.target === modal) closeLoginModal(); });

        // Mirror the exact LoginForm behavior from login.php
        class LoginForm {
            constructor() {
                this.form = document.getElementById('loginForm');
                this.inputs = {
                    email: document.getElementById('email'),
                    password: document.getElementById('password')
                };
                this.errors = {
                    email: document.getElementById('emailError'),
                    password: document.getElementById('passwordError')
                };
                this.submitBtn = this.form ? this.form.querySelector('.login-btn') : null;
                if (this.form) this.init();
            }
            init() { this.setupEventListeners(); }
            setupEventListeners() {
                if (!this.form) return;
                this.form.addEventListener('submit', (e) => this.handleSubmit(e));
                Object.keys(this.inputs).forEach(key => {
                    const input = this.inputs[key];
                    if (input) {
                        input.addEventListener('blur', () => this.validateField(key));
                        input.addEventListener('input', () => { this.clearError(key); });
                    }
                });
            }
            validateField(fieldName) {
                const input = this.inputs[fieldName];
                const value = input.value.trim();
                if (fieldName === 'email') {
                    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    if (!value) return this.showError(fieldName, 'Email address is required'), false;
                    if (!emailRegex.test(value)) return this.showError(fieldName, 'Enter a valid email'), false;
                }
                if (fieldName === 'password') {
                    if (!value) return this.showError(fieldName, 'Password is required'), false;
                }
                this.clearError(fieldName); return true;
            }
            showError(fieldName, message) { const errorElement = this.errors[fieldName]; if (errorElement) { errorElement.textContent = message; this.inputs[fieldName].classList.add('error'); } }
            clearError(fieldName) { const errorElement = this.errors[fieldName]; if (errorElement) { errorElement.textContent = ''; this.inputs[fieldName].classList.remove('error'); } }
            validateForm() { return ['email','password'].every(name => this.validateField(name)); }
            async handleSubmit(e) { if (!this.validateForm()) { e.preventDefault(); return; } this.setLoading(true); }
            setLoading(isLoading) { if (this.submitBtn == null) return; if (isLoading) { this.submitBtn.textContent = 'Logging in...'; this.submitBtn.classList.add('loading'); this.submitBtn.disabled = true; } }
        }

        function toggleAllPasswords() { const checkbox = document.getElementById('showPassword'); const passwordField = document.getElementById('password'); if (passwordField) { passwordField.type = checkbox.checked ? 'text' : 'password'; } }

        // Campus modal content
        const CAMPUS_INFO = {
            pablo: {
                title: 'Pablo Borbon',
                image: 'assets/1pabloborbon-1536x1024.jpg',
                desc: `The university’s first campus in Batangas City, BatStateU-Pablo Borbon spans 5.96 hectares and accommodates nearly 17,000 students under the College of Accountancy, Business, Economics and International Hospitality Management (CABEIHM); College of Health Sciences; College of Arts and Sciences; College of Law; College of Teacher Education; College of Criminal Justice Education and Integrated School. Recently added is the newly established College of Medicine. Quite close to the city’s CBD, it provides opportunities to reinforce relations with the business community and to prepare students to become the business leaders of tomorrow. Teacher Education, as well as Development Communication, has been recognized by CHED as Centers of Development. It is also home to the central administration where the University President and other key officials hold office.`
            },
            alangilan: {
                title: 'Alangilan',
                image: 'assets/alangilan-768x512.jpg',
                desc: `Embodying the university campus of the future, BatStateU-Alangilan boasts a 5.62-hectare campus with a rich history of excellence in engineering and technology education, which has produced around 200 topnotchers in engineering and is a consistent top-performing school in mechanical engineering over the years. It is home to over 17,000 students under the College of Engineering, College of Architecture, Fine Arts and Design, College of Engineering Technology, and College of Informatics and Computing Sciences.`
            },
            lipa: {
                title: 'Lipa',
                image: 'assets/5lipa-scaled-1024x683.jpg',
                desc: `Established in 2000, BatStateU-Lipa is the fastest-growing campus located in Lipa City, catering to over 4,000 students enrolled in business, management, communication, and psychology programs. Business and management students study under the College of Accountancy, Business, Economics, and International Hospitality Management (CABEIHM), while those taking communication and psychology are part of the College of Arts and Sciences—both shaping future professionals in a fast-evolving academic landscape.`
            },
            nasugbu: {
                title: 'Nasugbu',
                image: 'assets/nasugbu-campus-768x512.jpg',
                desc: `Located in the coastal town of Nasugbu, BatStateU-ARASOF Nasugbu focuses on offering fisheries and aquatic sciences, hotel and restaurant management, hospitality management, and business-related courses. It provides a picturesque learning environment for its 6,000 students on a 4.2-hectare campus.`
            },
            malvar: {
                title: 'Malvar',
                image: 'assets/4malvar-1024x683.jpg',
                desc: `Integrated into the university in 2001, BatStateU-JPLPC Malvar specializes in industrial technology programs and plays a key role in the urbanization and industrial development of Malvar. Situated on a 3.26-hectare campus, it serves over 6,000 students from various parts of Batangas. The campus features modern facilities and buildings, with most programs offered under the College of Industrial Technology.`
            }
        };

        function openCampusModal(e, key){ if(e) e.preventDefault(); const m = document.getElementById('campusModal'); if(!m) return; const info = CAMPUS_INFO[key]; if(!info) return; document.getElementById('campusTitle').textContent = info.title; document.getElementById('campusDesc').textContent = info.desc; const hero = document.getElementById('campusHero'); if(hero) hero.style.backgroundImage = `linear-gradient(0deg, rgba(0,0,0,0.25), rgba(0,0,0,0)), url('${info.image}')`; m.style.display = 'flex'; m.setAttribute('aria-hidden','false'); preventBackgroundScroll(); }
        function closeCampusModal(){ const m = document.getElementById('campusModal'); if(!m) return; m.style.display='none'; m.setAttribute('aria-hidden','true'); allowBackgroundScroll(); }
        document.addEventListener('keydown', function(e){ if(e.key==='Escape') closeCampusModal(); });
        document.addEventListener('click', function(e){ const m=document.getElementById('campusModal'); if(!m) return; if(m.style.display!=='none' && e.target===m) closeCampusModal(); });

        document.addEventListener('DOMContentLoaded', () => { 
            new LoginForm(); 
            <?php if (!empty($error_message)): ?>
            // Open modal automatically if server-side login failed
            openLoginModal();
            <?php endif; ?>
        });
    </script>
</body>
</html>
