<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';

$error = '';
$success = '';

if (isLoggedIn()) {
    $user = getCurrentUser();
    switch ($user['role']) {
        case 'admin': header('Location: modules/admin/dashboard.php'); break;
        case 'sales': header('Location: modules/sales/dashboard.php'); break;
        case 'architect': header('Location: modules/architect/dashboard.php'); break;
        case 'sul': header('Location: modules/sul/dashboard.php'); break;
        case 'expert': header('Location: modules/expert/dashboard.php'); break;
        case 'developer': header('Location: modules/developer/dashboard.php'); break;
        default: header('Location: index.php');
    }
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $selectedRole = trim($_POST['role'] ?? '');

    if ($email === '') {
        $error = 'Email address is required.';
    } elseif ($password === '') {
        $error = 'Password is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $conn = getConnection();
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';

        $attemptStmt = $conn->prepare("SELECT COUNT(*) AS attempts FROM audit_logs WHERE action = 'login_failed' AND ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
        $attemptStmt->bind_param('s', $ipAddress);
        $attemptStmt->execute();
        $attempts = (int) ($attemptStmt->get_result()->fetch_assoc()['attempts'] ?? 0);
        $attemptStmt->close();

        if ($attempts >= 5) {
            $error = 'Too many failed login attempts. Please try again after 15 minutes.';
        } else {
            $stmt = $conn->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($user = $result->fetch_assoc()) {
                if ($user['status'] !== 'active') {
                    $error = 'Your account is ' . $user['status'] . '. Please contact the administrator.';
                    logActivity((int) $user['id'], 'login_failed_inactive', 'users', (int) $user['id'], null, null, 'failed');
                } elseif (!password_verify($password, $user['password'])) {
                    $error = 'Invalid email or password.';
                    logActivity((int) $user['id'], 'login_failed_wrong_password', 'users', (int) $user['id'], null, null, 'failed');
                } elseif ($selectedRole !== '' && $user['role'] !== $selectedRole) {
                    $error = 'The selected role does not match this account.';
                } else {
                    session_regenerate_id(true);

                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_role'] = $user['role'];
                    $_SESSION['user_name'] = $user['full_name'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['login_time'] = time();
                    $_SESSION['ip_address'] = $ipAddress;

                    $updateStmt = $conn->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
                    $updateStmt->bind_param('i', $user['id']);
                    $updateStmt->execute();
                    $updateStmt->close();

                    logActivity((int) $user['id'], 'login_success', 'users', (int) $user['id']);

                    switch ($user['role']) {
                        case 'admin': header('Location: modules/admin/dashboard.php'); break;
                        case 'sales': header('Location: modules/sales/dashboard.php'); break;
                        case 'architect': header('Location: modules/architect/dashboard.php'); break;
                        case 'sul': header('Location: modules/sul/dashboard.php'); break;
                        case 'expert': header('Location: modules/expert/dashboard.php'); break;
                        case 'developer': header('Location: modules/developer/dashboard.php'); break;
                        default: header('Location: index.php');
                    }
                    exit();
                }
            } else {
                $error = 'Invalid email or password.';
                logActivity(null, 'login_failed_user_not_found', 'users', null, null, ['email' => $email], 'failed');
            }

            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In | ERNI PMS</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&family=Source+Sans+3:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
       <style>
        :root {
            --login-primary: #0d6786;
            --login-primary-dark: #0a5570;
            --login-accent: #1a9ee0;
            --login-text-soft: rgba(255, 255, 255, 0.82);
            --login-card-text: #0c5776;
            --login-line: rgba(12, 87, 118, 0.34);
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Source Sans 3', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #0b617b;
        }

        .login-shell {
            position: relative;
            min-height: 100vh;
            display: grid;
            grid-template-columns: 58% 42%;
            overflow: hidden;
            background:
                linear-gradient(120deg, rgba(7, 70, 90, 0.9), rgba(12, 108, 136, 0.88)),
                url('assets/img/loginBackground.png');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
        }

        .login-shell::before {
            content: '';
            position: absolute;
            inset: 0;
            background:
                linear-gradient(90deg, transparent 0 40%, rgba(255, 255, 255, 0.03) 40% 41%, transparent 41%),
                linear-gradient(0deg, transparent 0 58%, rgba(255, 255, 255, 0.035) 58% 59%, transparent 59%),
                radial-gradient(circle at 75% 35%, rgba(255, 255, 255, 0.08), transparent 28%);
            pointer-events: none;
        }

        .login-shell::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(180deg, rgba(8, 88, 114, 0.25) 0%, rgba(7, 62, 80, 0.55) 100%);
            mix-blend-mode: multiply;
            pointer-events: none;
        }

        .login-brand {
            position: relative;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 90px 64px 64px;
            margin-top: 20px;
            text-align: left;
        }

        .brand-panel {
            position: relative;
            z-index: 1;
            max-width: 520px;
            color: #fff;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            padding-left: 150px;
        }

        .brand-logo {
            width: 350px;
            height: auto;
            display: block;
            margin-bottom: 30px;
            transform: translateX(-30px);
            filter: drop-shadow(0 10px 24px rgba(0, 0, 0, 0.18));
        }

        .brand-lockup {
            display: flex;
            flex-direction: row;
            align-items: flex-end;
            font-size: 1rem;
            gap: 16px;
            margin-bottom: 0;
        }

        .brand-name {
            font-family: 'Montserrat', 'Source Sans 3', sans-serif;
            font-size: 3.1rem;
            line-height: 1;
            letter-spacing: 0.1em;
            font-weight: 300;
            color: #1da4f1;
        }

        .brand-name strong {
            font-weight: 300;
        }

        .brand-subtitle {
            font-size: 1.05rem;
            line-height: 1.45;
            color: rgba(255, 255, 255, 0.92);
            margin-bottom: 2px;
            text-align: left;
        }

        .brand-system {
            color: var(--login-text-soft);
            font-size: 0.875rem;
            letter-spacing: 0.02em;
            margin-top: 6px;
            margin-bottom: 0;
            text-align: left;
        }

        .login-form-wrap {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 44px 150px 44px 72px;
        }

        .login-card {
            width: 100%;
            max-width: 360px;
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 26px 60px rgba(0, 0, 0, 0.22);
            padding: 36px 40px 32px;
            transform: translateX(-30px);
        }

        .login-card-header {
            margin-bottom: 28px;
            text-align: center;
        }

        .login-card h1 {
            margin: 0;
            font-family: 'Montserrat', 'Source Sans 3', sans-serif;
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--login-card-text);
        }

        .login-card p {
            margin: 8px 0 0;
            color: #6f8697;
            font-size: 0.72rem;
        }

        .form-group {
            margin-bottom: 22px;
        }

        .form-label {
            font-size: 0.85rem;
            font-weight: 700;
            color: #176689;
            margin-bottom: 10px;
        }

        .input-wrap {
            position: relative;
        }

        .form-control,
        .form-select {
            border: 0;
            border-bottom: 1.5px solid var(--login-line);
            border-radius: 0;
            padding: 8px 34px 8px 0;
            background: transparent;
            box-shadow: none !important;
            min-height: 0;
            font-size: 0.94rem;
            color: #17324d;
        }

        .form-control:focus,
        .form-select:focus {
            border-bottom-color: var(--login-accent);
            background: transparent;
        }

        .password-toggle {
            position: absolute;
            right: 0;
            top: 50%;
            transform: translateY(-50%);
            border: 0;
            background: transparent;
            color: #0f83be;
        }

        .login-row {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 8px;
            margin: 6px 0 12px;
        }

        .forgot-password {
            font-size: 0.8rem;
            font-weight: 600;
            color: #0f83be;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            line-height: 1;
        }

        .forgot-password:hover {
            color: #0a557b;
        }

        .btn-signin {
            width: 100%;
            border: 0;
            border-radius: 5px;
            padding: 10px 18px;
            background: linear-gradient(180deg, var(--login-primary), var(--login-primary-dark));
            color: #fff;
            font-weight: 700;
            box-shadow: none;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .btn-signin:hover {
            color: #fff;
            background: linear-gradient(180deg, #0b5f7c, #084f68);
        }

        .alert {
            border: 0;
            border-radius: 12px;
            padding: 10px 12px;
            margin-bottom: 16px;
            font-size: 0.86rem;
        }

        .alert-danger {
            background: #fff1f2;
            color: #b42318;
        }

        .alert-success {
            background: #edfdf3;
            color: #027a48;
        }

        .modal-content {
            border-radius: 18px;
        }

        .role-select {
            width: 140px;
            padding-right: 26px;
            color: #42738b;
            font-size: 0.78rem;
            border: 0 !important;
            border-top: 0 !important;
            border-right: 0 !important;
            border-left: 0 !important;
            border-bottom: 1.5px solid var(--login-line) !important;
            border-radius: 0;
            background-color: transparent;
            box-shadow: none !important;
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            background-image: linear-gradient(45deg, transparent 50%, #0f83be 50%),
                linear-gradient(135deg, #0f83be 50%, transparent 50%);
            background-position: calc(100% - 12px) 55%, calc(100% - 7px) 55%;
            background-size: 6px 6px, 6px 6px;
            background-repeat: no-repeat;
        }

        .role-select:focus {
            border-top: 0 !important;
            border-right: 0 !important;
            border-left: 0 !important;
            border-bottom: 1.5px solid var(--login-accent) !important;
            box-shadow: none !important;
        }

        .login-actions {
            margin-top: 14px;
        }

        @media (max-width: 1100px) {
            .login-shell {
                grid-template-columns: 1fr;
            }

            .login-brand {
                justify-content: center;
                min-height: 44vh;
                padding: 36px 28px 12px;
            }

            .brand-panel {
                padding-left: 0;
                align-items: center;
                text-align: center;
            }

            .brand-logo {
                transform: translateX(0);
            }

            .login-form-wrap {
                padding: 12px 20px 28px;
            }
        }

        @media (max-width: 576px) {
            .login-form-wrap,
            .login-brand {
                padding-left: 18px;
                padding-right: 18px;
            }

            .login-card {
                max-width: 100%;
                padding: 28px 24px 24px;
            }
        }
    </style>
</head>
<body>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-page">
    <div class="login-shell">
        <section class="login-brand">
            <div class="brand-panel">
                <img class="brand-logo" src="assets/img/erniLogo_white.png" alt="ERNI logo">
                <div class="brand-lockup">
                    <div class="brand-name"><strong>ERNI</strong></div>
                    <div class="brand-subtitle">Development<br>Center&nbsp;&nbsp;Philippines, Inc.</div>
                </div>
                <div class="brand-system">Decision-Driven Project Management Support System</div>
            </div>
        </section>

        <section class="login-form-wrap">
            <div class="login-card">
                <div class="login-card-header">
                    <h1>Welcome to</h1>
                    <p>Project Management System</p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-circle-exclamation me-2"></i><?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-circle-check me-2"></i><?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" id="loginForm">
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input
                            type="email"
                            name="email"
                            class="form-control"
                            value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                            placeholder="Enter your email"
                            autocomplete="email"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label">Password</label>
                        <div class="input-wrap">
                            <input
                                type="password"
                                name="password"
                                id="password"
                                class="form-control"
                                placeholder="Enter your password"
                                autocomplete="current-password"
                                required
                            >
                            <button type="button" class="password-toggle" onclick="togglePassword()">
                                <i class="fas fa-eye" id="toggleIcon"></i>
                            </button>
                        </div>
                        <div class="login-row justify-content-end">
                            <a href="#" class="forgot-password" onclick="showForgotPassword(); return false;">Forgot Password?</a>
                        </div>
                    </div>

                    <div class="form-group">
                        <select name="role" class="form-select role-select">
                            <option value="" disabled <?php echo empty($_POST['role']) ? 'selected' : ''; ?>>Select role</option>
                            <option value="admin" <?php echo ($_POST['role'] ?? '') === 'admin' ? 'selected' : ''; ?>>Admin</option>
                            <option value="sales" <?php echo ($_POST['role'] ?? '') === 'sales' ? 'selected' : ''; ?>>Sales</option>
                            <option value="architect" <?php echo ($_POST['role'] ?? '') === 'architect' ? 'selected' : ''; ?>>Architect</option>
                            <option value="sul" <?php echo ($_POST['role'] ?? '') === 'sul' ? 'selected' : ''; ?>>Service Unit Leader</option>
                            <option value="expert" <?php echo ($_POST['role'] ?? '') === 'expert' ? 'selected' : ''; ?>>Expert / Senior</option>
                            <option value="developer" <?php echo ($_POST['role'] ?? '') === 'developer' ? 'selected' : ''; ?>>Developer</option>
                        </select>
                    </div>

                    <div class="login-actions">
                        <button type="submit" class="btn-signin" id="loginBtn">Sign in</button>
                    </div>
                </form>
            </div>
        </section>
    </div>

    <div class="modal fade" id="forgotPasswordModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Reset Password</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Enter your email address and we will prepare a reset request for the administrator.</p>
                    <input type="email" id="resetEmail" class="form-control" placeholder="Email address">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="sendResetLink()">Send Reset Link</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function togglePassword() {
            const input = document.getElementById('password');
            const icon = document.getElementById('toggleIcon');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }

        function showForgotPassword() {
            new bootstrap.Modal(document.getElementById('forgotPasswordModal')).show();
        }

        function sendResetLink() {
            const email = document.getElementById('resetEmail').value;
            if (!email) {
                alert('Please enter your email address');
                return;
            }
            alert('Password reset request recorded for ' + email + '.');
            bootstrap.Modal.getInstance(document.getElementById('forgotPasswordModal')).hide();
        }

        document.getElementById('loginForm').addEventListener('submit', function () {
            const btn = document.getElementById('loginBtn');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Signing in...';
            btn.disabled = true;
        });

    </script>
</body>
</html>
