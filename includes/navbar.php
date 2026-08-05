<?php
require_once __DIR__ . '/auth.php';

$user = getCurrentUser();
$notifications = [];
$unreadCount = 0;
$inAppNotificationsEnabled = (int) ($user['in_app_notifications'] ?? 1) === 1;
$preferredDateFormat = trim((string) ($user['date_format'] ?? 'M d, Y')) ?: 'M d, Y';
$navbarThemePreference = (string) ($user['theme_preference'] ?? 'default');
if (!in_array($navbarThemePreference, ['tropical', 'futuristic'], true)) {
    $navbarThemePreference = 'default';
}

if (!function_exists('navbarResolveNotificationActionUrl')) {
    function navbarResolveNotificationActionUrl(?string $actionUrl, array $notification = []): string
    {
        $url = trim((string) $actionUrl);
        if ($url === '') {
            return BASE_URL . 'notifications.php';
        }

        $resolvedUrl = preg_match('#^https?://#i', $url)
            ? $url
            : BASE_URL . ltrim($url, '/');

        $text = strtolower((string) ($notification['title'] ?? '') . ' ' . (string) ($notification['message'] ?? ''));
        $isSulReviewNotification = str_contains($text, 'for review')
            || str_contains($text, 'sul review')
            || str_contains($text, 'test results submitted')
            || str_contains($text, 'design submitted');

        if ($isSulReviewNotification && str_contains($resolvedUrl, 'modules/sul/task-allocation.php') && !str_contains($resolvedUrl, '#')) {
            $resolvedUrl .= '#developer-review-panel';
        }

        return $resolvedUrl;
    }
}

if ($user && $inAppNotificationsEnabled) {
    $unreadCount = getUnreadNotificationsCount((int) $user['id']);

    try {
        $conn = getConnection();
        $stmt = $conn->prepare(
            "SELECT notifications.*,
                    TIMESTAMPDIFF(MINUTE, created_at, CURRENT_TIMESTAMP) AS age_minutes
             FROM notifications
             WHERE user_id = ?
               AND (notification_type IS NULL OR notification_type NOT IN ('message', 'group'))
             ORDER BY is_read ASC, created_at DESC
             LIMIT 8"
        );

        if ($stmt !== false) {
            $stmt->bind_param('i', $user['id']);

            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($result !== false) {
                    while ($row = $result->fetch_assoc()) {
                        $ageMinutes = max(0, (int) floor((float) ($row['age_minutes'] ?? 0)));
                        $row['time_ago'] = formatMinutesAgo($ageMinutes);
                        $row['formatted_created_at'] = date($preferredDateFormat . ' h:i A', time() - ($ageMinutes * 60));
                        unset($row['age_minutes']);
                        $notifications[] = $row;
                    }
                } else {
                    error_log('navbar notifications get_result failed: ' . ($conn->error ?? 'unknown error'));
                }
            } else {
                error_log('navbar notifications execute failed: ' . ($conn->error ?? 'unknown error'));
            }

            $stmt->close();
        } else {
            error_log('navbar notifications prepare failed: ' . ($conn->error ?? 'unknown error'));
        }
    } catch (Throwable $e) {
        error_log('navbar notifications load error: ' . $e->getMessage());
    }
}

$appUserContext = [
    'id' => (int) ($user['id'] ?? 0),
    'role' => (string) ($user['role'] ?? ''),
];
$navbarProfileImageUrl = getUserProfileImageUrl($user['profile_pic'] ?? null);
?>

<script>
    window.APP_USER_CONTEXT = <?php echo json_encode($appUserContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
</script>

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
                            <?php if (!$inAppNotificationsEnabled): ?>
                                <li><span class="dropdown-item text-muted">In-app notifications are paused.</span></li>
                            <?php elseif ($notifications): ?>
                                <?php foreach ($notifications as $notification): ?>
                                    <li>
                                        <a class="dropdown-item py-2 <?php echo !$notification['is_read'] ? 'bg-light' : ''; ?>"
                                           href="<?php echo htmlspecialchars(navbarResolveNotificationActionUrl($notification['action_url'] ?? '', $notification)); ?>"
                                           onclick="markNotificationRead(<?php echo (int) $notification['id']; ?>)">
                                            <div class="notification-item-title fw-semibold small"><?php echo htmlspecialchars($notification['title']); ?></div>
                                            <div class="notification-item-message text-muted small"><?php echo htmlspecialchars($notification['message']); ?></div>
                                            <div class="notification-item-time text-muted small mt-1" title="<?php echo htmlspecialchars((string) ($notification['formatted_created_at'] ?? '')); ?>">
                                                <i class="far fa-clock me-1"></i><?php echo htmlspecialchars((string) ($notification['time_ago'] ?? 'just now')); ?>
                                            </div>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                                <?php else: ?>
                                    <li><span class="dropdown-item text-muted">No notifications yet.</span></li>
                                <?php endif; ?>
                            </ul>
                        </li>
                        <li><hr class="dropdown-divider m-0"></li>
                        <?php if ($inAppNotificationsEnabled): ?>
                            <li><a class="dropdown-item text-center small py-2" href="<?php echo BASE_URL; ?>notifications.php">View All</a></li>
                        <?php else: ?>
                            <li><a class="dropdown-item text-center small py-2" href="<?php echo BASE_URL; ?>settings.php">Open Settings</a></li>
                        <?php endif; ?>
                    </ul>
                </li>

                <li class="nav-item">
                    <a class="nav-link nav-icon-link" href="<?php echo BASE_URL; ?>folders.php" title="Folders">
                        <i class="fas fa-folder-tree"></i>
                    </a>
                </li>

                <?php if ($navbarThemePreference === 'default'): ?>
                <li class="nav-item">
                    <button class="btn btn-link nav-link nav-icon-link" id="darkModeToggle" type="button" title="Switch light or dark mode" aria-label="Switch light or dark mode">
                        <i class="fas fa-moon"></i>
                    </button>
                </li>
                <?php endif; ?>

                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center gap-2 nav-user-link" href="#" id="navbarUserMenu" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <div class="user-profile-pic <?php echo $navbarProfileImageUrl !== '' ? 'has-profile-image ' : ''; ?>d-flex align-items-center justify-content-center text-white">
                            <?php if ($navbarProfileImageUrl !== ''): ?>
                                <img src="<?php echo htmlspecialchars($navbarProfileImageUrl); ?>" alt="" loading="lazy" onerror="this.remove(); this.parentElement.classList.remove('has-profile-image');">
                            <?php endif; ?>
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
            width: min(340px, calc(100vw - 24px));
            max-width: min(340px, calc(100vw - 24px));
        }

        #notificationsList {
            max-height: min(420px, calc(100vh - 170px));
            overflow-y: auto;
        }

        .notifications-menu .dropdown-item {
            white-space: normal;
            display: block;
            line-height: 1.35;
        }

        .notifications-menu .notification-item-title,
        .notifications-menu .notification-item-message {
            display: -webkit-box;
            -webkit-box-orient: vertical;
            overflow: hidden;
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        .notifications-menu .notification-item-title {
            margin-bottom: 0.2rem;
            -webkit-line-clamp: 1;
        }

        .notifications-menu .notification-item-message {
            line-height: 1.35;
            -webkit-line-clamp: 2;
        }

        html[data-theme="dark"] #logoutConfirmModal .modal-content,
        body[data-theme="dark"] #logoutConfirmModal .modal-content,
        body.dark-mode #logoutConfirmModal .modal-content {
            background: #071321;
            border: 1px solid rgba(31, 99, 146, 0.24);
            color: #d7e4f2;
        }

        html[data-theme="dark"] #logoutConfirmModal .modal-header,
        body[data-theme="dark"] #logoutConfirmModal .modal-header,
        body.dark-mode #logoutConfirmModal .modal-header,
        html[data-theme="dark"] #logoutConfirmModal .modal-footer,
        body[data-theme="dark"] #logoutConfirmModal .modal-footer,
        body.dark-mode #logoutConfirmModal .modal-footer {
            border-color: rgba(31, 99, 146, 0.24);
            background: #0a1b2c;
        }

        html[data-theme="dark"] #logoutConfirmModal .modal-body p,
        body[data-theme="dark"] #logoutConfirmModal .modal-body p,
        body.dark-mode #logoutConfirmModal .modal-body p {
            color: #9db6cf !important;
        }

        html[data-theme="dark"] #logoutConfirmModal h5,
        body[data-theme="dark"] #logoutConfirmModal h5,
        body.dark-mode #logoutConfirmModal h5 {
            color: #d7e4f2 !important;
        }

        html[data-theme="dark"] #logoutConfirmModal .btn-outline-secondary,
        body[data-theme="dark"] #logoutConfirmModal .btn-outline-secondary,
        body.dark-mode #logoutConfirmModal .btn-outline-secondary {
            color: #d7e4f2;
            border-color: rgba(157, 182, 207, 0.36);
            background: transparent;
        }

        html[data-theme="dark"] #logoutConfirmModal .btn-outline-secondary:hover,
        body[data-theme="dark"] #logoutConfirmModal .btn-outline-secondary:hover,
        body.dark-mode #logoutConfirmModal .btn-outline-secondary:hover {
            background: rgba(31, 99, 146, 0.14);
            border-color: rgba(31, 99, 146, 0.28);
        }

        .app-logout-modal .modal-content {
            border: 0;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 28px 72px rgba(8, 31, 50, 0.24);
        }

        .app-logout-modal .modal-header {
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            padding: 1.1rem 1.25rem;
            background: linear-gradient(135deg, #0d6786 0%, #0a5570 100%);
            color: #fff;
            border: 0;
        }

        .app-logout-modal .modal-title {
            margin: 0;
            font-family: 'Montserrat', 'Source Sans 3', sans-serif;
            font-weight: 700;
            color: #fff;
            line-height: 1.2;
        }

        .app-logout-modal .app-logout-modal-eyebrow {
            margin-bottom: 0.2rem;
            color: rgba(255, 255, 255, 0.75);
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .app-logout-modal .btn-close {
            flex: 0 0 auto;
            margin: 0;
            filter: invert(1) grayscale(100%) brightness(2);
            opacity: 0.95;
            box-shadow: none;
        }

        .app-logout-modal .modal-body {
            padding: 1.6rem 1.5rem 1.25rem;
        }

        .app-logout-modal .app-logout-icon {
            width: 72px;
            height: 72px;
            margin: 0 auto 1rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(13, 103, 134, 0.12);
            color: #0d6786;
            font-size: 1.7rem;
            box-shadow: inset 0 0 0 1px rgba(13, 103, 134, 0.08);
        }

        .app-logout-modal .app-logout-title {
            margin-bottom: 0.55rem;
            color: #123b4d;
            font-family: 'Montserrat', 'Source Sans 3', sans-serif;
            font-weight: 700;
        }

        .app-logout-modal .app-logout-text {
            margin: 0;
            color: #5f7482;
            line-height: 1.5;
        }

        .app-logout-modal .modal-footer {
            padding: 1rem 1.25rem 1.25rem;
            background: #f8fbfd;
            border-top: 1px solid rgba(18, 80, 127, 0.08);
        }

        .app-logout-modal .app-logout-actions {
            width: 100%;
        }

        .app-logout-modal .app-logout-actions .btn {
            min-width: 156px;
            padding: 0.72rem 1.1rem;
            font-weight: 700;
            border-radius: 12px;
        }

        @media (max-width: 576px) {
            .app-logout-modal .modal-header,
            .app-logout-modal .modal-body,
            .app-logout-modal .modal-footer {
                padding-left: 1rem;
                padding-right: 1rem;
            }

            .app-logout-modal .app-logout-actions .btn {
                min-width: 0;
                width: 100%;
            }
        }

        html[data-theme="dark"] .app-logout-modal .modal-content,
        body[data-theme="dark"] .app-logout-modal .modal-content,
        body.dark-mode .app-logout-modal .modal-content {
            background: #071321;
            border: 1px solid rgba(138, 210, 255, 0.12);
            box-shadow: 0 28px 72px rgba(0, 0, 0, 0.42);
            color: #d8e6f3;
        }

        html[data-theme="dark"] .app-logout-modal .modal-header,
        body[data-theme="dark"] .app-logout-modal .modal-header,
        body.dark-mode .app-logout-modal .modal-header {
            background: linear-gradient(135deg, #0b3654 0%, #0f5f94 100%);
        }

        html[data-theme="dark"] .app-logout-modal .app-logout-title,
        body[data-theme="dark"] .app-logout-modal .app-logout-title,
        body.dark-mode .app-logout-modal .app-logout-title,
        html[data-theme="dark"] .app-logout-modal .app-logout-text,
        body[data-theme="dark"] .app-logout-modal .app-logout-text,
        body.dark-mode .app-logout-modal .app-logout-text {
            color: #d8e6f3;
        }

        html[data-theme="dark"] .app-logout-modal .app-logout-modal-eyebrow,
        body[data-theme="dark"] .app-logout-modal .app-logout-modal-eyebrow,
        body.dark-mode .app-logout-modal .app-logout-modal-eyebrow {
            color: rgba(216, 230, 243, 0.78);
        }

        html[data-theme="dark"] .app-logout-modal .app-logout-icon,
        body[data-theme="dark"] .app-logout-modal .app-logout-icon,
        body.dark-mode .app-logout-modal .app-logout-icon {
            background: rgba(138, 210, 255, 0.12);
            color: #bfe6ff;
            box-shadow: inset 0 0 0 1px rgba(138, 210, 255, 0.08);
        }

        html[data-theme="dark"] .app-logout-modal .modal-footer,
        body[data-theme="dark"] .app-logout-modal .modal-footer,
        body.dark-mode .app-logout-modal .modal-footer {
            background: #06111e;
            border-top: 1px solid rgba(138, 210, 255, 0.08);
        }

        html[data-theme="dark"] .app-logout-modal .btn-outline-secondary,
        body[data-theme="dark"] .app-logout-modal .btn-outline-secondary,
        body.dark-mode .app-logout-modal .btn-outline-secondary {
            border-color: rgba(138, 210, 255, 0.18);
            color: #d8e6f3;
        }

        html[data-theme="dark"] .app-logout-modal .btn-outline-secondary:hover,
        body[data-theme="dark"] .app-logout-modal .btn-outline-secondary:hover,
        body.dark-mode .app-logout-modal .btn-outline-secondary:hover {
            background: rgba(144, 174, 202, 0.12);
            color: #ffffff;
        }

        #page-content-wrapper .app-logout-modal .modal-content {
            border: 0 !important;
            border-radius: 20px !important;
            overflow: hidden !important;
            box-shadow: 0 28px 72px rgba(8, 31, 50, 0.24) !important;
        }

        #page-content-wrapper .app-logout-modal .modal-header {
            background: linear-gradient(135deg, #0d6786 0%, #0a5570 100%) !important;
            border: 0 !important;
            color: #fff !important;
        }

        #page-content-wrapper .app-logout-modal .modal-title {
            color: #fff !important;
        }

        #page-content-wrapper .app-logout-modal .app-logout-modal-eyebrow {
            color: rgba(255, 255, 255, 0.75) !important;
        }

        #page-content-wrapper .app-logout-modal .modal-footer {
            background: #f8fbfd !important;
            border-top: 1px solid rgba(18, 80, 127, 0.08) !important;
        }

        :is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #page-content-wrapper .app-logout-modal .modal-content {
            background: #071321 !important;
            border: 1px solid rgba(138, 210, 255, 0.12) !important;
            box-shadow: 0 28px 72px rgba(0, 0, 0, 0.42) !important;
            color: #d8e6f3 !important;
        }

        :is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #page-content-wrapper .app-logout-modal .modal-header {
            background: linear-gradient(135deg, #0b3654 0%, #0f5f94 100%) !important;
        }

        :is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #page-content-wrapper .app-logout-modal .modal-footer {
            background: #06111e !important;
            border-top-color: rgba(138, 210, 255, 0.08) !important;
        }

        /* The futuristic theme isolates the content wrapper for its animated
           background. Release only that stacking context while this dialog is
           visible so the same-page logout confirmation stays above its backdrop. */
        body:has(#logoutConfirmModal.show) #page-content-wrapper {
            isolation: auto !important;
        }

        #logoutConfirmModal {
            z-index: 1060;
        }

        body:has(#logoutConfirmModal.show) > .modal-backdrop {
            z-index: 1055;
        }
    </style>

    <div class="modal fade app-logout-modal" id="logoutConfirmModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0 rounded-4 shadow">
                <div class="modal-header">
                    <div>
                        <div class="app-logout-modal-eyebrow">Session action</div>
                        <h5 class="modal-title">Confirm logout</h5>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <div class="app-logout-icon">
                        <i class="fas fa-arrow-right-from-bracket"></i>
                    </div>
                    <h5 class="app-logout-title">Sign out now?</h5>
                    <p class="app-logout-text">You will need to log in again to continue using the system.</p>
                </div>
                <div class="modal-footer border-0">
                    <div class="app-logout-actions d-flex flex-column flex-sm-row justify-content-center gap-2">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Stay signed in</button>
                        <button type="button" class="btn btn-primary" id="logoutConfirmBtn" data-logout-url="<?php echo htmlspecialchars(BASE_URL . 'logout.php', ENT_QUOTES, 'UTF-8'); ?>" style="background:linear-gradient(180deg,#0d6786,#0a5570);border:0;">Yes, log me out</button>
                    </div>
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

            if (logoutConfirmBtn) {
                logoutConfirmBtn.addEventListener('click', function () {
                    if (logoutConfirmBtn.disabled) {
                        return;
                    }

                    logoutConfirmBtn.disabled = true;
                    showLogoutLoading();

                    // Build the POST form only when it is needed and attach it directly
                    // to <body>. A form rendered in the navbar can be discarded by the
                    // browser when a page happens to include the navbar inside another
                    // form, which previously made logout work only on some screens.
                    const logoutForm = document.createElement('form');
                    logoutForm.method = 'POST';
                    logoutForm.action = logoutConfirmBtn.dataset.logoutUrl;
                    logoutForm.style.display = 'none';
                    document.body.appendChild(logoutForm);
                    logoutForm.submit();
                });
            }

            window.addEventListener('pageshow', function () {
                hideLogoutLoading();
                if (logoutConfirmBtn) {
                    logoutConfirmBtn.disabled = false;
                }
            });
        })();
    </script>
