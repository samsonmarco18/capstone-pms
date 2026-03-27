<?php
require_once __DIR__ . '/auth.php';

$user = getCurrentUser();
$notifications = [];
$unreadCount = 0;

if ($user) {
    $unreadCount = getUnreadNotificationsCount((int) $user['id']);
    $conn = getConnection();
    $stmt = $conn->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 8');
    $stmt->bind_param('i', $user['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $notifications[] = $row;
    }
    $stmt->close();
}
?>

<div id="page-content-wrapper">
    <nav class="navbar app-navbar navbar-expand-lg navbar-light bg-white shadow-sm sticky-top">
        <div class="container-fluid">
            <div class="d-flex align-items-center gap-3">
                <button class="btn btn-link btn-sm app-toggle" id="menu-toggle" type="button" aria-label="Toggle sidebar">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="nav-context">
                    <div class="nav-context-title">ERNI PMS</div>
                    <small>Decision-Driven Project Management Support System</small>
                </div>
            </div>

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-lg-center gap-2">
                    <li class="nav-item dropdown" id="notificationsDropdown">
                        <a class="nav-link position-relative nav-icon-link" href="#" id="navbarNotifications" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-bell"></i>
                            <span id="notificationBadge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger <?php echo $unreadCount > 0 ? '' : 'd-none'; ?>">
                                <?php echo $unreadCount; ?>
                            </span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end shadow" style="min-width: 360px;">
                            <li><h6 class="dropdown-header">Notifications</h6></li>
                            <li><hr class="dropdown-divider m-0"></li>
                            <div id="notificationsList">
                                <?php if ($notifications): ?>
                                    <?php foreach ($notifications as $notification): ?>
                                        <li>
                                            <a class="dropdown-item py-2 <?php echo !$notification['is_read'] ? 'bg-light' : ''; ?>"
                                               href="<?php echo htmlspecialchars($notification['action_url'] ?: BASE_URL . 'notifications.php'); ?>"
                                               onclick="markNotificationRead(<?php echo (int) $notification['id']; ?>)">
                                                <div class="fw-semibold small"><?php echo htmlspecialchars($notification['title']); ?></div>
                                                <div class="text-muted small"><?php echo htmlspecialchars($notification['message']); ?></div>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <li><span class="dropdown-item text-muted">No notifications yet.</span></li>
                                <?php endif; ?>
                            </div>
                            <li><hr class="dropdown-divider m-0"></li>
                            <li><a class="dropdown-item text-center small py-2" href="<?php echo BASE_URL; ?>notifications.php">View All</a></li>
                        </ul>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link nav-icon-link" href="<?php echo BASE_URL; ?>folders.php" title="Folders">
                            <i class="fas fa-folder-tree"></i>
                        </a>
                    </li>

                    <li class="nav-item">
                        <button class="btn btn-link nav-link nav-icon-link" id="darkModeToggle" title="Dark mode">
                            <i class="fas fa-moon"></i>
                        </button>
                    </li>

                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle d-flex align-items-center gap-2 nav-user-link" href="#" id="navbarUserMenu" role="button" data-bs-toggle="dropdown">
                            <div class="user-profile-pic d-flex align-items-center justify-content-center text-white">
                                <i class="fas fa-user"></i>
                            </div>
                            <div class="d-none d-md-flex flex-column text-start">
                                <span class="nav-user-name"><?php echo htmlspecialchars($user['full_name'] ?? 'User'); ?></span>
                                <small class="nav-user-role"><?php echo htmlspecialchars(getRoleDisplayName($user['role'] ?? '')); ?></small>
                            </div>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end shadow">
                            <li><h6 class="dropdown-header"><?php echo htmlspecialchars(getRoleDisplayName($user['role'] ?? '')); ?></h6></li>
                            <li><hr class="dropdown-divider m-0"></li>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>profile.php"><i class="fas fa-user me-2"></i>Profile</a></li>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
                            <li><hr class="dropdown-divider m-0"></li>
                            <li><a class="dropdown-item text-danger" href="<?php echo BASE_URL; ?>logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
