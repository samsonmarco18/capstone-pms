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
    max-width: 800px;
    margin: 0 auto;
    padding: 1.5rem 1rem;
}

/* Profile Card */
.profile-card {
    background: white;
    border-radius: 18px;
    border: 1px solid var(--gray-200);
    overflow: hidden;
    box-shadow: 0 14px 36px rgba(15, 76, 129, 0.08);
    transition: box-shadow 0.2s ease;
}

.profile-card:hover {
    box-shadow: 0 16px 40px rgba(15, 76, 129, 0.1);
}

/* Profile Header with Avatar */
.profile-header {
    display: flex;
    align-items: center;
    gap: 1rem;
    min-height: 104px;
    padding: 1.25rem 2rem;
    border-bottom: 1px solid var(--gray-200);
    background: #ffffff;
    text-align: left;
    position: relative;
}

.profile-avatar {
    flex: 0 0 64px;
    width: 64px;
    height: 64px;
    background: linear-gradient(135deg, #42a9d8 0%, var(--theme-primary) 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0;
    border: 3px solid #e4f2f9;
    box-shadow: 0 8px 18px rgba(31, 99, 146, 0.16);
}

.profile-avatar i {
    font-size: 1.8rem;
    color: white;
}

.profile-identity {
    min-width: 0;
}

.profile-header h2 {
    margin: 0 0 0.2rem;
    color: var(--gray-900);
    font-size: 1.15rem;
    font-weight: 700;
}

.profile-subline {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.45rem 0.65rem;
}

.profile-email {
    color: var(--gray-600);
    font-size: 0.78rem;
}

.profile-header .role-badge {
    display: inline-block;
    padding: 0.22rem 0.65rem;
    border: 1px solid #cfe4ef;
    background: #edf6fb;
    border-radius: 50px;
    color: var(--theme-primary);
    font-size: 0.68rem;
    font-weight: 700;
}

/* Profile Body */
.profile-body {
    padding: 0 2rem 1.5rem;
}

/* Info Grid */
.info-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 0;
}

.info-item {
    display: grid;
    grid-template-columns: minmax(145px, 0.65fr) minmax(0, 1fr);
    align-items: center;
    gap: 1.5rem;
    min-height: 64px;
    padding: 0.85rem 0;
    border-bottom: 1px solid var(--gray-200);
}

.info-label {
    color: var(--gray-700);
    font-size: 0.78rem;
    font-weight: 600;
    letter-spacing: 0;
    text-transform: none;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.info-label i {
    color: var(--theme-primary);
    font-size: 0.8rem;
}

.info-value {
    padding: 0;
    border: 0;
    border-radius: 0;
    background: transparent;
    color: var(--gray-700);
    font-size: 0.86rem;
    font-weight: 500;
    text-align: right;
    word-break: break-word;
}

/* Action Buttons */
.profile-actions {
    display: flex;
    gap: 1rem;
    margin-top: 1.25rem;
    padding-top: 0.25rem;
    border-top: 0;
}

.profile-actions.single {
    justify-content: flex-end;
}

.btn-edit {
    padding: 0.65rem 1rem;
    border: 1px solid var(--theme-primary);
    border-radius: 10px;
    background: var(--theme-primary) !important;
    color: #ffffff !important;
    font-weight: 600;
    transition: background-color 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    text-decoration: none;
}

.btn-edit:hover {
    transform: translateY(-1px);
    border-color: var(--theme-dark);
    background: var(--theme-dark) !important;
    box-shadow: 0 8px 18px rgba(31, 99, 146, 0.2);
    color: #ffffff !important;
}

.btn-password {
    background: white;
    border: 1px solid var(--gray-300);
    color: var(--gray-700);
    padding: 0.65rem 1rem;
    border-radius: 10px;
    font-weight: 600;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    text-decoration: none;
}

.btn-password:hover {
    background: #edf6fb;
    border-color: var(--theme-primary);
    color: var(--theme-primary) !important;
}

.profile-header .page-header-help-btn {
    top: 18px;
    right: 18px;
    border-color: #cfe4ef;
    background: #edf6fb;
    color: var(--theme-primary);
    box-shadow: none;
}

.profile-header .page-header-help-btn:hover,
.profile-header .page-header-help-btn:focus-visible {
    background: #dceef7;
    color: var(--theme-dark);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .profile-card {
    border-color: rgba(138, 210, 255, 0.14);
    background: #091827;
    box-shadow: 0 24px 58px rgba(0, 0, 0, 0.34);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .profile-card:hover {
    box-shadow: 0 24px 58px rgba(0, 0, 0, 0.34);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .profile-header {
    border-bottom: 1px solid rgba(138, 210, 255, 0.16);
    background: #0b1e2e;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .profile-avatar {
    border-color: rgba(197, 235, 255, 0.38);
    background: linear-gradient(135deg, #2d94c2 0%, #175b86 100%);
    box-shadow: 0 12px 28px rgba(2, 15, 27, 0.28);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .profile-header h2 {
    color: #eff9ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .profile-email {
    color: #8fb1c9;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .profile-header .role-badge {
    border: 1px solid rgba(197, 235, 255, 0.16);
    background: rgba(72, 177, 222, 0.12);
    color: #9fdcff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .profile-body {
    background: linear-gradient(180deg, #091827 0%, #081522 100%);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .info-label {
    color: #c2d7e6;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .info-label i {
    color: #72d2f5;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .info-value {
    border: 0;
    background: transparent;
    color: #9fbcd0;
    box-shadow: none;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .info-item {
    border-bottom-color: rgba(138, 210, 255, 0.11);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .profile-actions {
    border-top-color: rgba(138, 210, 255, 0.12);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-edit {
    border: 1px solid #2f82b2;
    border-radius: 12px;
    background: #1f6392 !important;
    color: #ffffff !important;
    box-shadow: 0 8px 20px rgba(3, 14, 25, 0.24);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-edit:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-edit:focus-visible {
    border-color: #58b4e1;
    background: #2876a5 !important;
    color: #ffffff !important;
    box-shadow: 0 10px 24px rgba(25, 116, 166, 0.28);
    transform: translateY(-1px);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-edit:focus-visible,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-password:focus-visible {
    outline: 3px solid rgba(114, 210, 245, 0.28);
    outline-offset: 2px;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-password {
    border-color: rgba(138, 210, 255, 0.18);
    border-radius: 12px;
    background: #0d2234;
    color: #cfe5f4;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-password:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-password:focus-visible {
    border-color: rgba(114, 210, 245, 0.42);
    background: #12304a;
    color: #ffffff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .profile-header .page-header-help-btn {
    border-color: rgba(138, 210, 255, 0.18);
    background: rgba(31, 99, 146, 0.2);
    color: #9fdcff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .profile-header .page-header-help-btn:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .profile-header .page-header-help-btn:focus-visible {
    background: rgba(31, 99, 146, 0.34);
    color: #ffffff;
}

/* Responsive */
@media (max-width: 768px) {
    .profile-container {
        padding: 0.5rem;
    }
    
    .profile-header {
        align-items: flex-start;
        padding: 1.5rem;
    }
    
    .profile-avatar {
        flex-basis: 56px;
        width: 56px;
        height: 56px;
    }
    
    .profile-avatar i {
        font-size: 1.55rem;
    }
    
    .profile-header h2 {
        font-size: 1.25rem;
    }
    
    .profile-body {
        padding: 0 1.25rem 1.25rem;
    }
    
    .info-item {
        grid-template-columns: 1fr;
        gap: 0.3rem;
        padding: 0.8rem 0;
    }

    .info-value {
        text-align: left;
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
            <div class="profile-identity">
                <h2><?php echo htmlspecialchars($user['full_name']); ?></h2>
                <div class="profile-subline">
                    <span class="profile-email"><?php echo htmlspecialchars($user['email']); ?></span>
                    <span class="role-badge"><?php echo htmlspecialchars(getRoleDisplayName($user['role'])); ?></span>
                </div>
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
                <a href="settings.php" class="btn-edit">
                    <i class="fas fa-user-cog"></i> Account Settings
                </a>
                <?php if (($user['role'] ?? '') === 'admin'): ?>
                <a href="modules/admin/settings.php" class="btn-password">
                    <i class="fas fa-sliders-h"></i> Admin Settings
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
