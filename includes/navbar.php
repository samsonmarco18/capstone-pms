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
    <nav class="navbar app-navbar navbar-light bg-white shadow-sm sticky-top">
        <div class="container-fluid app-navbar-inner">
            <button class="btn btn-link nav-link nav-icon-link app-toggle sidebar-toggle-btn d-inline-flex d-lg-none" id="menu-toggle-mobile" type="button" aria-label="Toggle sidebar" title="Toggle sidebar">
                <i class="fas fa-bars"></i>
            </button>

            <ul class="navbar-nav ms-auto flex-row align-items-center gap-2 flex-wrap justify-content-end">
                <li class="nav-item d-none d-lg-inline-flex">
                    <button class="btn btn-link nav-link nav-icon-link app-toggle sidebar-toggle-btn" id="menu-toggle-desktop" type="button" aria-label="Toggle sidebar" title="Toggle sidebar">
                        <i class="fas fa-bars"></i>
                    </button>
                </li>

                <li class="nav-item dropdown" id="notificationsDropdown">
                    <a class="nav-link position-relative nav-icon-link" href="#" id="navbarNotifications" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-bell"></i>
                        <span id="notificationBadge" class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger <?php echo $unreadCount > 0 ? '' : 'd-none'; ?>">
                            <?php echo $unreadCount; ?>
                        </span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow notifications-menu">
                        <li><h6 class="dropdown-header">Notifications</h6></li>
                        <li><hr class="dropdown-divider m-0"></li>
                        <li class="p-0">
                            <ul id="notificationsList" class="list-unstyled mb-0">
                            <?php if ($notifications): ?>
                                <?php foreach ($notifications as $notification): ?>
                                    <li>
                                        <a class="dropdown-item py-2 <?php echo !$notification['is_read'] ? 'bg-light' : ''; ?>"
                                           href="<?php echo htmlspecialchars($notification['action_url'] ?: BASE_URL . 'notifications.php'); ?>"
                                           onclick="markNotificationRead(<?php echo (int) $notification['id']; ?>)">
                                            <div class="notification-item-title fw-semibold small"><?php echo htmlspecialchars($notification['title']); ?></div>
                                            <div class="notification-item-message text-muted small"><?php echo htmlspecialchars($notification['message']); ?></div>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                                <?php else: ?>
                                    <li><span class="dropdown-item text-muted">No notifications yet.</span></li>
                                <?php endif; ?>
                            </ul>
                        </li>
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
                    <button class="btn btn-link nav-link nav-icon-link" id="darkModeToggle" type="button" title="Dark mode">
                        <i class="fas fa-moon"></i>
                    </button>
                </li>

                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center gap-2 nav-user-link" href="#" id="navbarUserMenu" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <div class="user-profile-pic d-flex align-items-center justify-content-center text-white">
                            <i class="fas fa-user"></i>
                        </div>
                        <div class="d-none d-md-flex flex-column text-start">
                            <span class="nav-user-name"><?php echo htmlspecialchars($user['full_name'] ?? 'User'); ?></span>
                            <small class="nav-user-role"><?php echo htmlspecialchars(getRoleDisplayName($user['role'] ?? '')); ?></small>
                        </div>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow profile-menu">
                        <li><h6 class="dropdown-header"><?php echo htmlspecialchars(getRoleDisplayName($user['role'] ?? '')); ?></h6></li>
                        <li><hr class="dropdown-divider m-0"></li>
                        <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>profile.php"><i class="fas fa-user me-2"></i>Profile</a></li>
                        <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
                        <li><hr class="dropdown-divider m-0"></li>
                        <li>
                            <button type="button" class="dropdown-item text-danger" id="logoutTriggerBtn">
                                <i class="fas fa-sign-out-alt me-2"></i>Logout
                            </button>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>
    </nav>

    <style>
        .app-logout-loading-overlay {
            position: fixed;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: rgba(6, 10, 18, 0.72);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            z-index: 2000;
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transition: opacity 0.2s ease, visibility 0.2s ease;
        }

        .app-logout-loading-overlay.is-visible {
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
        }

        .app-logout-loading-content {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: #fff;
        }

        .app-logout-loading-spinner {
            width: 3.25rem;
            height: 3.25rem;
            border-width: 0.3em;
            color: #66c8ff;
        }

        .app-logout-loading-title {
            margin: 18px 0 6px;
            font-family: 'Montserrat', 'Source Sans 3', sans-serif;
            font-size: 1.05rem;
            font-weight: 700;
        }

        .app-logout-loading-text {
            margin: 0;
            font-size: 0.92rem;
            color: rgba(255, 255, 255, 0.78);
        }

        .notifications-menu {
            max-width: min(380px, calc(100vw - 24px));
        }

        .notifications-menu .dropdown-item {
            white-space: normal;
            display: block;
            line-height: 1.35;
        }

        .notifications-menu .notification-item-title,
        .notifications-menu .notification-item-message {
            white-space: normal;
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        .notifications-menu .notification-item-title {
            margin-bottom: 0.2rem;
        }

        .notifications-menu .notification-item-message {
            line-height: 1.35;
        }
    </style>

    <form method="POST" action="<?php echo BASE_URL; ?>logout.php" id="logoutForm" class="d-none"></form>

    <div class="modal fade" id="logoutConfirmModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 rounded-4 shadow">
                <div class="modal-header border-0 pb-0 justify-content-end">
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center px-4 pt-0 pb-3">
                    <div class="mx-auto mb-3 d-flex align-items-center justify-content-center rounded-circle" style="width:62px;height:62px;background:rgba(13,103,134,.12);color:#0d6786;font-size:1.5rem;">
                        <i class="fas fa-arrow-right-from-bracket"></i>
                    </div>
                    <h5 class="fw-bold mb-2" style="color:#123b4d;font-family:'Montserrat','Source Sans 3',sans-serif;">Sign out now?</h5>
                    <p class="mb-0" style="color:#5f7482;">You will need to log in again to continue using the system.</p>
                </div>
                <div class="modal-footer border-0 justify-content-center gap-2 px-4 pb-4 pt-0">
                    <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Stay signed in</button>
                    <button type="button" class="btn btn-primary px-4" id="logoutConfirmBtn" style="background:linear-gradient(180deg,#0d6786,#0a5570);border:0;">Yes, log me out</button>
                </div>
            </div>
        </div>
    </div>

    <div class="app-logout-loading-overlay" id="logoutLoadingOverlay" aria-hidden="true">
        <div class="app-logout-loading-content" role="status" aria-live="polite" aria-label="Logging out">
            <div class="spinner-border app-logout-loading-spinner" role="presentation"></div>
            <div class="app-logout-loading-title">Signing you out</div>
            <p class="app-logout-loading-text">Please wait while we secure your session.</p>
        </div>
    </div>

    <script>
        (function () {
            const logoutTriggerBtn = document.getElementById('logoutTriggerBtn');
            const logoutConfirmBtn = document.getElementById('logoutConfirmBtn');
            const logoutForm = document.getElementById('logoutForm');
            const logoutLoadingOverlay = document.getElementById('logoutLoadingOverlay');

            function showLogoutLoading() {
                if (!logoutLoadingOverlay) {
                    return;
                }

                logoutLoadingOverlay.classList.add('is-visible');
                logoutLoadingOverlay.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';
            }

            function hideLogoutLoading() {
                if (!logoutLoadingOverlay) {
                    return;
                }

                logoutLoadingOverlay.classList.remove('is-visible');
                logoutLoadingOverlay.setAttribute('aria-hidden', 'true');
                document.body.style.overflow = '';
            }

            if (logoutTriggerBtn) {
                logoutTriggerBtn.addEventListener('click', function () {
                    const modalElement = document.getElementById('logoutConfirmModal');
                    if (window.bootstrap && modalElement) {
                        bootstrap.Modal.getOrCreateInstance(modalElement).show();
                    }
                });
            }

            if (logoutConfirmBtn && logoutForm) {
                logoutConfirmBtn.addEventListener('click', function () {
                    showLogoutLoading();
                    logoutForm.submit();
                });
            }

            window.addEventListener('pageshow', function () {
                hideLogoutLoading();
            });
        })();
    </script>
