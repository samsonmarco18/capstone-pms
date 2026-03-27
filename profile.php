<?php
require_once 'includes/auth.php';
requireLogin();

$user = getCurrentUser();

include 'includes/header.php';
include 'includes/sidebar.php';
include 'includes/navbar.php';
?>

<style>
:root {
    --theme-primary: #1f6392;
    --theme-dark: #184f73;
    --theme-light: #3a7ca3;
    --gray-50: #f8f9fa;
    --gray-100: #f1f3f5;
    --gray-200: #e9ecef;
    --gray-300: #dee2e6;
    --gray-400: #ced4da;
    --gray-500: #adb5bd;
    --gray-600: #6c757d;
    --gray-700: #495057;
    --gray-800: #343a40;
    --gray-900: #212529;
    --shadow-sm: 0 2px 4px rgba(0,0,0,0.02), 0 1px 2px rgba(0,0,0,0.03);
    --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.02);
}

/* Profile Container */
.profile-container {
    max-width: 900px;
    margin: 0 auto;
    padding: 1rem;
}

/* Profile Card */
.profile-card {
    background: white;
    border-radius: 24px;
    border: 1px solid var(--gray-200);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    transition: all 0.3s ease;
}

.profile-card:hover {
    box-shadow: var(--shadow-md);
}

/* Profile Header with Avatar */
.profile-header {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    padding: 2rem;
    text-align: center;
    position: relative;
}

.profile-avatar {
    width: 100px;
    height: 100px;
    background: rgba(255,255,255,0.2);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 1rem;
    border: 3px solid rgba(255,255,255,0.3);
}

.profile-avatar i {
    font-size: 2.8rem;
    color: white;
}

.profile-header h2 {
    font-size: 1.5rem;
    font-weight: 600;
    color: white;
    margin-bottom: 0.25rem;
}

.profile-header .role-badge {
    display: inline-block;
    padding: 0.25rem 1rem;
    background: rgba(255,255,255,0.2);
    border-radius: 50px;
    font-size: 0.8rem;
    color: white;
}

/* Profile Body */
.profile-body {
    padding: 2rem;
}

/* Info Grid */
.info-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1.5rem;
}

.info-item {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.info-label {
    font-size: 0.7rem;
    text-transform: uppercase;
    font-weight: 600;
    color: var(--gray-500);
    letter-spacing: 0.5px;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.info-label i {
    color: var(--theme-primary);
    font-size: 0.8rem;
}

.info-value {
    font-size: 1rem;
    font-weight: 500;
    color: var(--gray-800);
    background: var(--gray-50);
    padding: 0.75rem 1rem;
    border-radius: 12px;
    border: 1px solid var(--gray-200);
    word-break: break-word;
}

/* Action Buttons */
.profile-actions {
    display: flex;
    gap: 1rem;
    margin-top: 2rem;
    padding-top: 1.5rem;
    border-top: 1px solid var(--gray-200);
}

.profile-actions.single {
    justify-content: flex-end;
}

.btn-edit {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    color: white;
    border: none;
    padding: 0.6rem 1.5rem;
    border-radius: 40px;
    font-weight: 500;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    text-decoration: none;
}

.btn-edit:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(31, 99, 146, 0.3);
    color: white;
}

.btn-password {
    background: white;
    border: 1px solid var(--gray-300);
    color: var(--gray-700);
    padding: 0.6rem 1.5rem;
    border-radius: 40px;
    font-weight: 500;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    text-decoration: none;
}

.btn-password:hover {
    background: var(--gray-50);
    border-color: var(--theme-primary);
    color: var(--theme-primary);
}

/* Responsive */
@media (max-width: 768px) {
    .profile-container {
        padding: 0.5rem;
    }
    
    .profile-header {
        padding: 1.5rem;
    }
    
    .profile-avatar {
        width: 80px;
        height: 80px;
    }
    
    .profile-avatar i {
        font-size: 2.2rem;
    }
    
    .profile-header h2 {
        font-size: 1.25rem;
    }
    
    .profile-body {
        padding: 1.25rem;
    }
    
    .info-grid {
        grid-template-columns: 1fr;
        gap: 1rem;
    }
    
    .profile-actions {
        flex-direction: column;
    }
    
    .btn-edit, .btn-password {
        width: 100%;
        justify-content: center;
    }
}
</style>

<div class="profile-container">
    <!-- Profile Card -->
    <div class="profile-card">
        <!-- Profile Header with Avatar -->
        <div class="profile-header">
            <div class="profile-avatar">
                <i class="fas fa-user"></i>
            </div>
            <h2><?php echo htmlspecialchars($user['full_name']); ?></h2>
            <div class="role-badge">
                <?php echo htmlspecialchars(getRoleDisplayName($user['role'])); ?>
            </div>
        </div>

        <!-- Profile Body -->
        <div class="profile-body">
            <!-- Personal Information Grid -->
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">
                        <i class="fas fa-envelope"></i> Email Address
                    </div>
                    <div class="info-value">
                        <?php echo htmlspecialchars($user['email']); ?>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-label">
                        <i class="fas fa-user"></i> Username
                    </div>
                    <div class="info-value">
                        <?php echo htmlspecialchars($user['username']); ?>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-label">
                        <i class="fas fa-building"></i> Department
                    </div>
                    <div class="info-value">
                        <?php echo htmlspecialchars($user['department'] ?: 'Not set'); ?>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-label">
                        <i class="fas fa-id-card"></i> Role
                    </div>
                    <div class="info-value">
                        <?php echo htmlspecialchars(getRoleDisplayName($user['role'])); ?>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-label">
                        <i class="fas fa-clock"></i> Last Login
                    </div>
                    <div class="info-value">
                        <?php echo htmlspecialchars($user['last_login'] ? date('M d, Y h:i A', strtotime($user['last_login'])) : 'No recorded login yet'); ?>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-label">
                        <i class="fas fa-calendar-alt"></i> Member Since
                    </div>
                    <div class="info-value">
                        <?php echo htmlspecialchars(date('M d, Y', strtotime($user['created_at']))); ?>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="profile-actions <?php echo ($user['role'] ?? '') === 'admin' ? '' : 'single'; ?>">
                <a href="edit-profile.php" class="btn-edit">
                    <i class="fas fa-edit"></i> Edit Profile
                </a>
                <?php if (($user['role'] ?? '') === 'admin'): ?>
                <a href="change-password.php" class="btn-password">
                    <i class="fas fa-key"></i> Change Password
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
