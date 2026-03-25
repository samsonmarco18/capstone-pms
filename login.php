<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';

$error = '';
$success = '';

// Check if user is already logged in
if (isLoggedIn()) {
    $user = getCurrentUser();
    // Redirect based on role
    switch($user['role']) {
        case 'admin':
            header('Location: modules/admin/dashboard.php');
            break;
        case 'sales':
            header('Location: modules/sales/dashboard.php');
            break;
        case 'architect':
            header('Location: modules/architect/dashboard.php');
            break;
        case 'sul':
            header('Location: modules/sul/dashboard.php');
            break;
        case 'expert':
            header('Location: modules/expert/dashboard.php');
            break;
        case 'developer':
            header('Location: modules/developer/dashboard.php');
            break;
        default:
            header('Location: index.php');
    }
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $selected_role = trim($_POST['role'] ?? '');
    
    // Validate input
    if (empty($email)) {
        $error = 'Email address is required.';
    } elseif (empty($password)) {
        $error = 'Password is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $conn = getConnection();
        
        // Check for too many failed attempts
        $ip_address = $_SERVER['REMOTE_ADDR'];
        $stmt = $conn->prepare("SELECT COUNT(*) as attempts FROM audit_logs WHERE action = 'login_failed' AND ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
        $stmt->bind_param("s", $ip_address);
        $stmt->execute();
        $result = $stmt->get_result();
        $attempts = $result->fetch_assoc()['attempts'];
        
        if ($attempts >= 5) {
            $error = 'Too many failed login attempts. Please try again after 15 minutes.';
        } else {
            // Get user by email
            $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($user = $result->fetch_assoc()) {
                // Check if user account is active
                if ($user['status'] !== 'active') {
                    $error = 'Your account is ' . $user['status'] . '. Please contact administrator.';
                    logActivity($user['id'], 'login_failed_inactive', 'users', $user['id']);
                } 
                // Verify password
                elseif (password_verify($password, $user['password'])) {
                    // Check role if selected
                    if (!empty($selected_role) && $user['role'] !== $selected_role) {
                        $error = 'Invalid role selection for this account. Your role is: ' . ucfirst($user['role']);
                    } else {
                        // Regenerate session ID for security
                        session_regenerate_id(true);
                        
                        // Set session variables
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['user_role'] = $user['role'];
                        $_SESSION['user_name'] = $user['full_name'];
                        $_SESSION['user_email'] = $user['email'];
                        $_SESSION['login_time'] = time();
                        $_SESSION['ip_address'] = $ip_address;
                        
                        // Update last login timestamp (add column if needed)
                        $updateStmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                        $updateStmt->bind_param("i", $user['id']);
                        $updateStmt->execute();
                        
                        // Log successful login
                        logActivity($user['id'], 'login_success', 'users', $user['id']);
                        
                        // Redirect based on role
                        switch($user['role']) {
                            case 'admin':
                                header('Location: modules/admin/dashboard.php');
                                break;
                            case 'sales':
                                header('Location: modules/sales/dashboard.php');
                                break;
                            case 'architect':
                                header('Location: modules/architect/dashboard.php');
                                break;
                            case 'sul':
                                header('Location: modules/sul/dashboard.php');
                                break;
                            case 'expert':
                                header('Location: modules/expert/dashboard.php');
                                break;
                            case 'developer':
                                header('Location: modules/developer/dashboard.php');
                                break;
                            default:
                                header('Location: index.php');
                        }
                        exit();
                    }
                } else {
                    $error = 'Invalid email or password.';
                    logActivity($user['id'], 'login_failed_wrong_password', 'users', $user['id']);
                }
            } else {
                $error = 'Invalid email or password.';
                // Log failed attempt with email
                logActivity(0, 'login_failed_user_not_found', 'users', 0, null, ['email' => $email]);
            }
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - ERNI Development Center Philippines</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            overflow: hidden;
        }

        .login-container {
            display: flex;
            min-height: 100vh;
        }

        .login-sidebar {
            flex: 1;
            background: linear-gradient(135deg, #0d6efd 0%, #0b5ed7 100%);
            color: white;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 40px 20px;
            position: relative;
            overflow: hidden;
        }

        .login-sidebar::before {
            content: '';
            position: absolute;
            width: 400px;
            height: 400px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 50%;
            top: -100px;
            right: -100px;
        }

        .login-sidebar::after {
            content: '';
            position: absolute;
            width: 300px;
            height: 300px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 50%;
            bottom: -80px;
            left: -80px;
        }

        .logo-section {
            text-align: center;
            z-index: 1;
            position: relative;
        }

        .logo-icon {
            width: 100px;
            height: 100px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 30px;
            font-size: 50px;
            color: white;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.05);
            }
            100% {
                transform: scale(1);
            }
        }

        .company-name {
            font-size: 32px;
            font-weight: bold;
            margin-bottom: 10px;
            letter-spacing: 1px;
        }

        .company-subtitle {
            font-size: 16px;
            opacity: 0.9;
            margin-bottom: 10px;
            font-weight: 300;
        }

        .company-description {
            font-size: 14px;
            opacity: 0.85;
            line-height: 1.6;
            max-width: 300px;
        }

        .login-form-container {
            flex: 1;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 60px;
        }

        .login-form {
            width: 100%;
            max-width: 400px;
        }

        .form-header {
            margin-bottom: 40px;
        }

        .form-header h2 {
            font-size: 32px;
            color: #0d6efd;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .form-header p {
            color: #6c757d;
            font-size: 14px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-size: 13px;
            font-weight: 600;
            color: #495057;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .input-group {
            position: relative;
            display: flex;
            align-items: center;
        }

        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            background-color: #f8f9fa;
        }

        .form-control:focus {
            outline: none;
            border-color: #0d6efd;
            background-color: white;
            box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.1);
        }

        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #6c757d;
            z-index: 10;
            background: transparent;
            border: none;
        }

        .password-toggle:hover {
            color: #0d6efd;
        }

        select.form-control {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%230d6efd' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            padding-right: 36px;
        }

        .form-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            font-size: 13px;
        }

        .remember-me {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }

        .remember-me input {
            cursor: pointer;
        }

        .forgot-password {
            color: #0d6efd;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s;
        }

        .forgot-password:hover {
            color: #0b5ed7;
            text-decoration: underline;
        }

        .btn-signin {
            width: 100%;
            padding: 14px 20px;
            background: linear-gradient(135deg, #0d6efd 0%, #0b5ed7 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 1px;
            box-shadow: 0 4px 15px rgba(13, 110, 253, 0.3);
        }

        .btn-signin:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(13, 110, 253, 0.4);
        }

        .btn-signin:active {
            transform: translateY(0);
        }

        .btn-signin:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .alert {
            margin-bottom: 20px;
            border: none;
            border-radius: 8px;
            padding: 12px 16px;
            font-size: 13px;
            animation: slideDown 0.3s ease;
        }

        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @media (max-width: 992px) {
            .login-container {
                flex-direction: column;
            }

            .login-sidebar {
                min-height: 250px;
                padding: 30px 20px;
            }

            .login-form-container {
                min-height: auto;
                padding: 40px 20px;
            }

            .login-form {
                max-width: 100%;
            }

            .company-name {
                font-size: 28px;
            }

            .logo-icon {
                width: 80px;
                height: 80px;
                font-size: 40px;
            }
        }

        @media (max-width: 576px) {
            .form-header h2 {
                font-size: 24px;
            }

            .form-control {
                padding: 10px 12px;
                font-size: 13px;
            }

            .login-form-container {
                padding: 30px 15px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <!-- Left Sidebar -->
        <div class="login-sidebar">
            <div class="logo-section">
                <div class="logo-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="company-name">ERNI PMS</div>
                <div class="company-subtitle">Decision-Driven Project Management</div>
                <div class="company-description">
                    Support System for ERNI Development Center Philippines, Inc.
                </div>
            </div>
        </div>

        <!-- Right Login Form -->
        <div class="login-form-container">
            <div class="login-form">
                <div class="form-header">
                    <h2>Welcome Back</h2>
                    <p>Sign in to access your dashboard</p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle me-2"></i>
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" id="loginForm">
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-envelope me-1"></i> Email Address
                        </label>
                        <input type="email" name="email" class="form-control" 
                               placeholder="Enter your email" 
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" 
                               autocomplete="email"
                               required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-lock me-1"></i> Password
                        </label>
                        <div class="input-group">
                            <input type="password" name="password" id="password" class="form-control" 
                                   placeholder="Enter your password" 
                                   autocomplete="current-password"
                                   required>
                            <button type="button" class="password-toggle" onclick="togglePassword()">
                                <i class="fas fa-eye" id="toggleIcon"></i>
                            </button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-user-tag me-1"></i> Select Role (Optional)
                        </label>
                        <select name="role" class="form-control">
                            <option value="">-- Auto-detect from account --</option>
                            <option value="admin" <?php echo ($_POST['role'] ?? '') == 'admin' ? 'selected' : ''; ?>>Admin</option>
                            <option value="sales" <?php echo ($_POST['role'] ?? '') == 'sales' ? 'selected' : ''; ?>>Sales</option>
                            <option value="architect" <?php echo ($_POST['role'] ?? '') == 'architect' ? 'selected' : ''; ?>>Architect</option>
                            <option value="sul" <?php echo ($_POST['role'] ?? '') == 'sul' ? 'selected' : ''; ?>>Service Unit Leader</option>
                            <option value="expert" <?php echo ($_POST['role'] ?? '') == 'expert' ? 'selected' : ''; ?>>Expert/Senior</option>
                            <option value="developer" <?php echo ($_POST['role'] ?? '') == 'developer' ? 'selected' : ''; ?>>Developer</option>
                        </select>
                        <small class="text-muted">Select only if you want to override auto-detection</small>
                    </div>

                    <div class="form-footer">
                        <label class="remember-me">
                            <input type="checkbox" name="remember_me" id="remember_me">
                            <span>Remember me</span>
                        </label>
                        <a href="#" class="forgot-password" onclick="showForgotPassword()">Forgot Password?</a>
                    </div>

                    <button type="submit" class="btn-signin" id="loginBtn">
                        <i class="fas fa-sign-in-alt me-2"></i> Sign In
                    </button>
                </form>

                <hr class="my-4">

                <div class="text-center">
                    <small class="text-muted">
                        <i class="fas fa-info-circle"></i> 
                        Default demo accounts: contact administrator
                    </small>
                </div>
            </div>
        </div>
    </div>

    <!-- Forgot Password Modal -->
    <div class="modal fade" id="forgotPasswordModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Reset Password</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Enter your email address and we'll send you a link to reset your password.</p>
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
        // Toggle password visibility
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.getElementById('toggleIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }

        // Show forgot password modal
        function showForgotPassword() {
            new bootstrap.Modal(document.getElementById('forgotPasswordModal')).show();
        }

        // Send reset link
        function sendResetLink() {
            const email = document.getElementById('resetEmail').value;
            if (!email) {
                alert('Please enter your email address');
                return;
            }
            
            // Here you would implement actual password reset functionality
            alert('Password reset link has been sent to ' + email + ' (Demo)');
            bootstrap.Modal.getInstance(document.getElementById('forgotPasswordModal')).hide();
        }

        // Form submission loading state
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const btn = document.getElementById('loginBtn');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Signing in...';
            btn.disabled = true;
        });

        // Remember me functionality
        if (localStorage.getItem('remembered_email')) {
            document.querySelector('input[name="email"]').value = localStorage.getItem('remembered_email');
            document.getElementById('remember_me').checked = true;
        }

        document.getElementById('remember_me').addEventListener('change', function(e) {
            if (e.target.checked) {
                const email = document.querySelector('input[name="email"]').value;
                localStorage.setItem('remembered_email', email);
            } else {
                localStorage.removeItem('remembered_email');
            }
        });

        // Auto-detect role based on email (demo feature)
        document.querySelector('input[name="email"]').addEventListener('blur', function() {
            const email = this.value;
            const roleSelect = document.querySelector('select[name="role"]');
            
            // This is just a demo - in production, you'd have real role detection
            if (email.includes('admin')) {
                roleSelect.value = 'admin';
            } else if (email.includes('sales')) {
                roleSelect.value = 'sales';
            } else if (email.includes('architect')) {
                roleSelect.value = 'architect';
            }
        });

        // Prevent multiple form submissions
        let submitted = false;
        document.getElementById('loginForm').addEventListener('submit', function() {
            if (submitted) return false;
            submitted = true;
            return true;
        });
    </script>
</body>
</html>