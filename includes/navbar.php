<?php
require_once __DIR__ . '/../includes/auth.php';

$user = getCurrentUser();
$unreadCount = getUnreadNotificationsCount($user['id']);
$notifications = [];

if ($user) {
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
    $stmt->bind_param("i", $user['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $notifications[] = $row;
    }
    $stmt->close();
}
?>

<!-- Page Content -->
<div id="page-content-wrapper">
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm sticky-top">
        <div class="container-fluid">
            <!-- Menu Toggle Button -->
            <button class="btn btn-link btn-sm me-3" id="menu-toggle" style="color: var(--primary-color);">
                <i class="fas fa-bars fa-lg"></i>
            </button>

            <!-- Brand -->
            <a class="navbar-brand" href="<?php echo BASE_URL; ?>/index.php">
                <i class="fas fa-shield-alt" style="color: var(--primary-color);"></i>
                <span style="color: var(--primary-color); font-weight: 600;">ERNI</span>
            </a>

            <!-- Toggler for mobile -->
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>

            <!-- Navbar Items -->
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto gap-2">
                    <!-- Notifications Dropdown -->
                    <li class="nav-item dropdown">
                        <a class="nav-link position-relative" href="#" id="navbarNotifications" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-bell"></i>
                            <?php if ($unreadCount > 0): ?>
                                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" 
                                      style="font-size: 10px; padding: 2px 5px;">
                                    <?php echo $unreadCount; ?>
                                </span>
                            <?php endif; ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end shadow" style="min-width: 380px;" aria-labelledby="navbarNotifications">
                            <li><h6 class="dropdown-header">
                                <i class="fas fa-bell me-2"></i>Notifications
                            </h6></li>
                            <li><hr class="dropdown-divider m-0"></li>
                            
                            <?php if (count($notifications) > 0): ?>
                                <div style="max-height: 350px; overflow-y: auto;">
                                    <?php foreach ($notifications as $notif): ?>
                                        <li>
                                            <a class="dropdown-item py-2 border-bottom text-decoration-none" href="<?php echo $notif['action_url'] ?? '#'; ?>" 
                                               onclick="markNotificationRead(<?php echo $notif['id']; ?>); return false;">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div>
                                                        <p class="mb-1 fw-bold small"><?php echo htmlspecialchars(substr($notif['title'], 0, 50)); ?></p>
                                                        <small class="text-muted"><?php echo htmlspecialchars(substr($notif['message'], 0, 70)); ?></small>
                                                    </div>
                                                    <?php if (!$notif['is_read']): ?>
                                                        <span class="badge bg-primary rounded-circle ms-2" style="width: 8px; height: 8px; flex-shrink: 0; margin-top: 6px;"></span>
                                                    <?php endif; ?>
                                                </div>
                                                <small class="text-muted d-block" style="font-size: 10px; margin-top: 5px;">
                                                    <?php echo date('M d, H:i', strtotime($notif['created_at'])); ?>
                                                </small>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <li><p class="dropdown-item text-center text-muted py-3 mb-0">No notifications</p></li>
                            <?php endif; ?>
                            
                            <li><hr class="dropdown-divider m-0"></li>
                            <li>
                                <a class="dropdown-item text-center small py-2" href="<?php echo BASE_URL; ?>/notifications.php">
                                    View All
                                </a>
                            </li>
                        </ul>
                    </li>

                    <!-- Dark Mode Toggle -->
                    <li class="nav-item">
                        <button class="btn btn-link nav-link" id="darkModeToggle" title="Toggle Dark Mode" style="color: inherit;">
                            <i class="fas fa-moon"></i>
                        </button>
                    </li>

                    <!-- Files/Folders -->
                    <li class="nav-item">
<a class="nav-link" href="<?php echo BASE_URL; ?>folders.php" title="Project Folders & Files" data-bs-toggle="tooltip">
                            <i class="fas fa-folder-tree text-info"></i>
                            <span class="d-none d-md-inline ms-1 small">Folders</span>
                        </a>
                    </li>

                    <!-- User Profile Dropdown -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle d-flex align-items-center gap-2 text-decoration-none" href="#" id="navbarUserMenu" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <div class="user-profile-pic" style="width: 32px; height: 32px; border-radius: 50%; background: var(--primary-color); 
                                                                 display: flex; align-items: center; justify-content: center; color: white; font-size: 14px; font-weight: 600; flex-shrink: 0;">
                                <?php echo htmlspecialchars(strtoupper(substr($user['full_name'], 0, 1))); ?>
                            </div>
                            <span class="d-none d-md-inline small text-dark"><?php echo htmlspecialchars(substr($user['full_name'], 0, 20)); ?></span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end shadow" aria-labelledby="navbarUserMenu">
                            <li>
                                <h6 class="dropdown-header">
                                    <small class="text-muted"><?php echo getRoleDisplayName($user['role']); ?></small>
                                </h6>
                            </li>
                            <li><hr class="dropdown-divider m-0"></li>
                            <li>
                                <a class="dropdown-item" href="<?php echo BASE_URL; ?>/profile.php">
                                    <i class="fas fa-user me-2"></i>My Profile
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="<?php echo BASE_URL; ?>/settings.php">
                                    <i class="fas fa-cog me-2"></i>Settings
                                </a>
                            </li>
                            <li><hr class="dropdown-divider m-0"></li>
                            <li>
                                <a class="dropdown-item text-danger" href="<?php echo BASE_URL; ?>/logout.php">
                                    <i class="fas fa-sign-out-alt me-2"></i>Logout
                                </a>
                            </li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    