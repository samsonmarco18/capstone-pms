<?php
require_once '../../includes/auth.php';
requireRole('admin');

$conn = getConnection();
ensureUserDeveloperClassificationColumn();
$userId = (int) ($_GET['id'] ?? 0);
$stmt = $conn->prepare('SELECT * FROM users WHERE id = ?');
$stmt->bind_param('i', $userId);
$stmt->execute();
$selectedUser = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$selectedUser) {
    header('Location: user-management.php');
    exit();
}

$fullName = trim((string) ($selectedUser['full_name'] ?? 'User'));
$nameParts = preg_split('/\s+/', $fullName) ?: [];
$initials = '';
foreach (array_slice($nameParts, 0, 2) as $part) {
    $initials .= strtoupper(substr($part, 0, 1));
}
if ($initials === '') {
    $initials = 'U';
}

$status = (string) ($selectedUser['status'] ?? 'inactive');
$role = (string) ($selectedUser['role'] ?? '');
$roleLabel = getRoleDisplayName($role);
$hasClassification = in_array(strtolower(trim($role)), ['developer', 'qa'], true);
$developerClassificationLabel = getDeveloperClassificationLabel($selectedUser['developer_classification'] ?? null);
$createdAt = !empty($selectedUser['created_at']) ? date('F d, Y', strtotime((string) $selectedUser['created_at'])) : 'Not available';
$lastLogin = !empty($selectedUser['last_login']) ? date('F d, Y g:i A', strtotime((string) $selectedUser['last_login'])) : 'No recorded login';

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<style>
:root {
    --theme-primary: #1f6392;
    --theme-primary-dark: #0f4a6e;
    --gray-50: #f8f9fa;
    --gray-100: #f1f3f5;
    --gray-200: #e9ecef;
    --gray-300: #dee2e6;
    --gray-500: #adb5bd;
    --gray-600: #6c757d;
    --gray-700: #495057;
    --gray-900: #212529;
    --shadow-sm: 0 2px 4px rgba(0,0,0,0.02), 0 1px 2px rgba(0,0,0,0.03);
}

.user-profile-shell {
    display: grid;
    gap: 1rem;
}

.profile-hero {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-primary-dark) 100%);
    border-radius: 20px;
    box-shadow: 0 12px 30px rgba(31, 99, 146, 0.18);
    padding: 1.35rem 1.5rem;
    color: white;
    position: relative;
    overflow: hidden;
}

.profile-hero::after {
    content: "";
    position: absolute;
    right: -60px;
    top: -50px;
    width: 220px;
    height: 220px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.08);
}

.profile-hero-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1.25rem;
    flex-wrap: wrap;
}

.profile-identity {
    display: flex;
    align-items: center;
    gap: 1rem;
    flex-wrap: wrap;
}

.profile-avatar {
    width: 72px;
    height: 72px;
    border-radius: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(255,255,255,0.16);
    border: 1px solid rgba(255,255,255,0.24);
    color: white;
    font-size: 1.55rem;
    font-weight: 800;
}

.profile-title h1 {
    margin: 0 0 0.2rem;
    font-size: 1.55rem;
    font-weight: 700;
    color: white;
}

.profile-title p {
    margin: 0 0 0.65rem;
    color: rgba(255,255,255,0.88);
    font-size: 0.95rem;
}

.profile-badges {
    display: flex;
    gap: 0.45rem;
    flex-wrap: wrap;
}

.profile-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.35rem 0.68rem;
    border-radius: 999px;
    background: rgba(255,255,255,0.14);
    border: 1px solid rgba(255,255,255,0.22);
    font-size: 0.76rem;
    font-weight: 700;
    color: white;
}

.profile-actions {
    display: flex;
    gap: 0.7rem;
    flex-wrap: wrap;
}

.btn-profile {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    border-radius: 10px;
    padding: 0.65rem 0.9rem;
    font-size: 0.85rem;
    font-weight: 700;
    text-decoration: none;
    border: 1px solid transparent;
    transition: all 0.2s ease;
}

.btn-profile.primary {
    background: white;
    color: var(--theme-primary);
    box-shadow: 0 4px 12px rgba(5, 36, 57, 0.14);
}

.btn-profile.secondary {
    background: rgba(255,255,255,0.12);
    color: white !important;
    border-color: rgba(255,255,255,0.22);
}

.btn-profile:hover {
    transform: translateY(-1px);
}

.btn-profile.primary:hover {
    color: var(--theme-primary-dark);
}

.btn-profile.secondary:hover {
    color: white;
    background: rgba(255,255,255,0.18);
}

.profile-card {
    background: white;
    border: 1px solid var(--gray-200);
    border-radius: 18px;
    overflow: hidden;
    box-shadow: var(--shadow-sm);
}

.profile-card .card-header {
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--gray-200);
    background: white;
}

.profile-card .card-header h5 {
    margin: 0;
    font-size: 1rem;
    font-weight: 700;
    color: var(--gray-900);
    display: flex;
    align-items: center;
    gap: 0.55rem;
}

.profile-card .card-header h5 i {
    color: var(--theme-primary);
}

.profile-card .card-body {
    padding: 1.2rem;
}

.profile-form-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.8rem;
}

.profile-field {
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-radius: 13px;
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
    min-width: 0;
    padding: 0.9rem 1rem;
}

.profile-field.full {
    grid-column: auto;
}

.profile-field label {
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    font-weight: 700;
    color: var(--gray-600);
}

.profile-value {
    min-height: 24px;
    display: flex;
    align-items: center;
    padding: 0;
    border-radius: 0;
    background: transparent;
    border: 0;
    color: var(--gray-900);
    font-size: 0.9rem;
    font-weight: 600;
    word-break: break-word;
}

.status-pill,
.role-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 999px;
    padding: 0.45rem 0.8rem;
    font-size: 0.76rem;
    font-weight: 700;
}

.status-pill.active {
    background: #d1fae5;
    color: #059669;
}

.status-pill.inactive {
    background: #fef3c7;
    color: #d97706;
}

.status-pill.suspended {
    background: #fee2e2;
    color: #dc2626;
}

.role-pill {
    background: #e9f2f8;
    color: var(--theme-primary);
}

.profile-footer-note {
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px solid var(--gray-200);
    font-size: 0.82rem;
    color: var(--gray-600);
}

@media (max-width: 1100px) {
    .profile-form-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 768px) {
    .profile-hero {
        padding: 1.5rem;
    }

    .profile-title h1 {
        font-size: 1.6rem;
    }

    .profile-avatar {
        width: 78px;
        height: 78px;
        font-size: 1.7rem;
    }

    .profile-form-grid {
        grid-template-columns: 1fr;
    }
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .profile-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .profile-card .card-header {
    background: #081421;
    border-color: rgba(138, 210, 255, 0.12);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .profile-card .card-header h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .profile-value {
    color: #d8e6f3;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .profile-field {
    background: #0a1b2c;
    border-color: rgba(138, 210, 255, 0.12);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .profile-field label {
    color: #90aeca;
}
</style>

<div class="container-fluid px-4 py-4">
    <div class="user-profile-shell">
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success shadow-sm mb-0">
                <?php echo htmlspecialchars((string) $_SESSION['success']); unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger shadow-sm mb-0">
                <?php echo htmlspecialchars((string) $_SESSION['error']); unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>
        <section class="profile-hero">
            <div class="profile-hero-top">
                <div class="profile-identity">
                    <div class="profile-avatar"><?php echo htmlspecialchars($initials); ?></div>
                    <div class="profile-title">
                        <h1><?php echo htmlspecialchars($fullName); ?></h1>
                        <p><?php echo htmlspecialchars($selectedUser['email']); ?></p>
                        <div class="profile-badges">
                            <span class="profile-badge">
                                <i class="fas fa-user-tie"></i>
                                <?php echo htmlspecialchars($roleLabel); ?>
                            </span>
                            <?php if ($hasClassification): ?>
                            <span class="profile-badge">
                                <i class="fas fa-layer-group"></i>
                                <?php echo htmlspecialchars($developerClassificationLabel); ?>
                            </span>
                            <?php endif; ?>
                            <span class="profile-badge">
                                <i class="fas fa-building"></i>
                                <?php echo htmlspecialchars($selectedUser['department'] ?: 'No department'); ?>
                            </span>
                            <span class="profile-badge">
                                <i class="fas fa-at"></i>
                                @<?php echo htmlspecialchars($selectedUser['username']); ?>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="profile-actions">
                    <a href="user-management.php" class="btn-profile secondary">
                        <i class="fas fa-arrow-left"></i>
                        Back to Users
                    </a>
                    <a href="edit-user.php?id=<?php echo (int) $selectedUser['id']; ?>" class="btn-profile primary">
                        <i class="fas fa-pen"></i>
                        Edit Profile
                    </a>
                </div>
            </div>
        </section>

        <section class="profile-card">
            <div class="card-header">
                <h5><i class="fas fa-address-card"></i> User Profile Details</h5>
            </div>
            <div class="card-body">
                <div class="profile-form-grid">
                    <div class="profile-field">
                        <label>Full Name</label>
                        <div class="profile-value"><?php echo htmlspecialchars($fullName); ?></div>
                    </div>
                    <div class="profile-field">
                        <label>Username</label>
                        <div class="profile-value">@<?php echo htmlspecialchars($selectedUser['username']); ?></div>
                    </div>
                    <div class="profile-field full">
                        <label>Email Address</label>
                        <div class="profile-value"><?php echo htmlspecialchars($selectedUser['email']); ?></div>
                    </div>
                    <div class="profile-field">
                        <label>Role</label>
                        <div class="profile-value"><span class="role-pill"><?php echo htmlspecialchars($roleLabel); ?></span></div>
                    </div>
                    <div class="profile-field">
                        <label>Status</label>
                        <div class="profile-value"><span class="status-pill <?php echo htmlspecialchars($status); ?>"><?php echo htmlspecialchars(ucfirst($status)); ?></span></div>
                    </div>
                    <div class="profile-field">
                        <label>Department</label>
                        <div class="profile-value"><?php echo htmlspecialchars($selectedUser['department'] ?: 'Not set'); ?></div>
                    </div>
                    <div class="profile-field">
                        <label>Designation</label>
                        <div class="profile-value"><?php echo htmlspecialchars($selectedUser['designation'] ?: 'Not set'); ?></div>
                    </div>
                    <?php if ($hasClassification): ?>
                    <div class="profile-field">
                        <label>Classification</label>
                        <div class="profile-value"><?php echo htmlspecialchars($developerClassificationLabel); ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="profile-field">
                        <label>Member Since</label>
                        <div class="profile-value"><?php echo htmlspecialchars($createdAt); ?></div>
                    </div>
                    <div class="profile-field">
                        <label>Last Login</label>
                        <div class="profile-value"><?php echo htmlspecialchars($lastLogin); ?></div>
                    </div>
                </div>

                
            </div>
        </section>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
