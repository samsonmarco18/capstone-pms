<?php
require_once 'includes/auth.php';
require_once 'includes/user-preferences.php';
requireLogin();

$conn = getConnection();
$user = getCurrentUser();
$isAdmin = strtolower((string) ($user['role'] ?? '')) === 'admin';
$message = '';
$error = '';
$forcePasswordChange = !empty($_SESSION['force_password_change']);
$preferencesSaved = false;

if (!$isAdmin) {
    try {
        userPreferenceEnsureSchema($conn);
        $user = getCurrentUser();
    } catch (Throwable $exception) {
        error_log('User preference schema setup failed: ' . $exception->getMessage());
        $error = 'Personal settings are temporarily unavailable.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_preferences']) && !$isAdmin && $error === '') {
    $themeOptions = userPreferenceThemeOptions();
    $timezoneOptions = userPreferenceTimezoneOptions();
    $dateFormatOptions = userPreferenceDateFormatOptions();

    $themePreference = (string) ($_POST['theme_preference'] ?? 'default');
    $timezone = (string) ($_POST['timezone'] ?? 'Asia/Manila');
    $dateFormat = (string) ($_POST['date_format'] ?? 'M d, Y');

    if (!isset($themeOptions[$themePreference])) {
        $themePreference = 'default';
    }
    if (!isset($timezoneOptions[$timezone])) {
        $timezone = 'Asia/Manila';
    }
    if (!isset($dateFormatOptions[$dateFormat])) {
        $dateFormat = 'M d, Y';
    }

    $evortixEnabled = isset($_POST['evortix_enabled']) ? 1 : 0;
    $evortixSuggestions = isset($_POST['evortix_suggestions']) ? 1 : 0;
    $evortixSound = isset($_POST['evortix_sound']) ? 1 : 0;
    $inAppNotifications = isset($_POST['in_app_notifications']) ? 1 : 0;
    $emailNotifications = isset($_POST['email_notifications']) ? 1 : 0;
    $taskReminders = isset($_POST['task_reminders']) ? 1 : 0;
    $projectUpdates = isset($_POST['project_updates']) ? 1 : 0;

    $stmt = $conn->prepare(
        'UPDATE users
         SET theme_preference = ?,
             evortix_enabled = ?,
             evortix_suggestions = ?,
             evortix_sound = ?,
             in_app_notifications = ?,
             email_notifications = ?,
             task_reminders = ?,
             project_updates = ?,
             timezone = ?,
             date_format = ?
         WHERE id = ?'
    );

    if ($stmt === false) {
        $error = 'Unable to prepare your settings update.';
    } else {
        $stmt->bind_param(
            'siiiiiiissi',
            $themePreference,
            $evortixEnabled,
            $evortixSuggestions,
            $evortixSound,
            $inAppNotifications,
            $emailNotifications,
            $taskReminders,
            $projectUpdates,
            $timezone,
            $dateFormat,
            $user['id']
        );

        if ($stmt->execute()) {
            $message = 'Personal settings saved.';
            $preferencesSaved = true;
            $user = getCurrentUser();
            date_default_timezone_set($timezone);
            logActivity((int) $user['id'], 'update_personal_settings', 'users', (int) $user['id']);
        } else {
            $error = 'Unable to save your personal settings.';
        }
        $stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    $password = trim((string) $_POST['password']);
    $confirmPassword = trim((string) ($_POST['confirm_password'] ?? ''));
    $currentPassword = (string) ($_POST['current_password'] ?? '');

    $stmt = $conn->prepare('SELECT password FROM users WHERE id = ?');
    $stmt->bind_param('i', $user['id']);
    $stmt->execute();
    $currentUserRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$currentUserRow || !password_verify($currentPassword, (string) $currentUserRow['password'])) {
        $error = 'Please verify your current password before changing it.';
    } elseif (strlen($password) < 6) {
        $error = 'New password must be at least 6 characters.';
    } elseif ($password !== $confirmPassword) {
        $error = 'New password and confirmation do not match.';
    } else {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $hasPasswordChangedAt = ensureUserPasswordSecurityColumns();
        $sql = $hasPasswordChangedAt
            ? 'UPDATE users SET password = ?, password_changed_at = CURRENT_TIMESTAMP WHERE id = ?'
            : 'UPDATE users SET password = ? WHERE id = ?';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('si', $hashedPassword, $user['id']);
        $stmt->execute();
        $stmt->close();
        $message = 'Password updated successfully.';
        unset($_SESSION['force_password_change']);
        logActivity((int) $user['id'], 'change_password', 'users', (int) $user['id']);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout_other_sessions']) && !$isAdmin && $error === '') {
    $stmt = $conn->prepare(
        'UPDATE users
         SET session_version = COALESCE(session_version, 1) + 1,
             sessions_invalid_before = CURRENT_TIMESTAMP
         WHERE id = ?'
    );
    if ($stmt === false) {
        $error = 'Unable to close the other sessions.';
    } else {
        $stmt->bind_param('i', $user['id']);
        if ($stmt->execute()) {
            $user = getCurrentUser();
            $_SESSION['session_version'] = (int) ($user['session_version'] ?? 1);
            $_SESSION['login_time'] = time();
            $message = 'Other active sessions have been signed out.';
            logActivity((int) $user['id'], 'logout_other_sessions', 'users', (int) $user['id']);
        } else {
            $error = 'Unable to close the other sessions.';
        }
        $stmt->close();
    }
}

$preferences = userPreferencesFromUser($user);
$themeOptions = userPreferenceThemeOptions();
$timezoneOptions = userPreferenceTimezoneOptions();
$dateFormatOptions = userPreferenceDateFormatOptions();

include 'includes/header.php';
include 'includes/sidebar.php';
include 'includes/navbar.php';
?>

<style>
.personal-settings-shell {
    max-width: 1440px;
}

.settings-page-header {
    align-items: center;
    background:
        radial-gradient(circle at 92% -30%, rgba(255, 255, 255, .14) 0 145px, transparent 146px),
        radial-gradient(circle at 78% 135%, rgba(255, 255, 255, .1) 0 112px, transparent 113px),
        linear-gradient(115deg, #154f76 0%, #176f9d 100%);
    border-radius: 24px;
    color: #fff;
    display: flex;
    justify-content: space-between;
    min-height: 152px;
    overflow: hidden;
    padding: 1.75rem 2rem;
    position: relative;
}

.settings-page-header::after {
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, .75), transparent);
    content: "";
    height: 1px;
    opacity: .35;
    position: absolute;
    right: 4%;
    top: 58%;
    width: 34%;
}

.settings-page-header h1 {
    color: #fff;
    font-size: clamp(1.75rem, 2.5vw, 2.15rem);
    font-weight: 650;
    letter-spacing: -.025em;
    margin: 0;
}

.settings-page-header p {
    color: rgba(255, 255, 255, .82);
    font-size: 1rem;
    margin: .55rem 0 0;
    max-width: 650px;
}

.settings-feedback {
    align-items: center;
    border: 0;
    border-radius: 12px;
    display: flex;
    gap: .65rem;
    margin: 1rem 0 0;
    padding: .85rem 1rem;
    transition: opacity .24s ease, transform .24s ease;
}

.settings-feedback.is-dismissing {
    opacity: 0;
    transform: translateY(-6px);
}

.settings-feedback-close {
    align-items: center;
    background: transparent;
    border: 0;
    border-radius: 50%;
    color: currentColor;
    display: inline-flex;
    height: 30px;
    justify-content: center;
    margin-left: auto;
    opacity: .68;
    width: 30px;
}

.settings-feedback-close:hover {
    background: rgba(15, 23, 42, .08);
    opacity: 1;
}

.settings-workspace {
    align-items: start;
    background: var(--card-bg, #fff);
    border: 1px solid rgba(31, 99, 146, .14);
    border-radius: 18px;
    box-shadow: 0 14px 34px rgba(15, 76, 129, .08);
    display: grid;
    gap: 0;
    grid-template-columns: 230px minmax(0, 1fr);
    margin-top: 1.25rem;
    min-height: 590px;
    overflow: hidden;
}

.settings-navigation {
    align-self: stretch;
    background: rgba(238, 247, 252, .58);
    border-right: 1px solid rgba(31, 99, 146, .12);
    padding: 1.5rem 1rem;
}

.settings-navigation-label {
    color: var(--text-muted);
    display: block;
    font-size: .72rem;
    font-weight: 700;
    letter-spacing: .08em;
    margin: 0 .75rem .65rem;
    text-transform: uppercase;
}

.settings-navigation a {
    align-items: center;
    border-radius: 10px;
    color: var(--text-color);
    display: flex;
    font-size: .9rem;
    gap: .7rem;
    margin-bottom: .22rem;
    padding: .7rem .75rem;
    text-decoration: none;
    transition: background-color .18s ease, color .18s ease, transform .18s ease;
}

.settings-navigation a:hover,
.settings-navigation a:focus {
    background: rgba(31, 111, 158, .1);
    color: var(--primary-color);
}

.settings-navigation a.is-active {
    background: rgba(31, 111, 158, .12);
    color: var(--primary-color);
    font-weight: 650;
}

.settings-navigation a i {
    color: var(--primary-color);
    text-align: center;
    width: 18px;
}

.settings-navigation-separator {
    border-top: 1px solid rgba(31, 99, 146, .12);
    margin: .85rem .65rem;
}

.settings-content {
    min-width: 0;
}

#personalPreferencesForm {
    display: block;
}

.settings-section {
    background: transparent;
    border: 0;
    border-radius: 0;
    box-shadow: none;
    scroll-margin-top: 90px;
}

.settings-content > .settings-section {
    margin-top: 0;
}

.settings-section[hidden],
#personalPreferencesForm[hidden] {
    display: none !important;
}

.settings-section-header {
    align-items: flex-start;
    border-bottom: 1px solid rgba(31, 99, 146, .1);
    display: flex;
    gap: .85rem;
    padding: 1.5rem 1.65rem 1.15rem;
}

.settings-section-icon {
    align-items: center;
    background: rgba(31, 111, 158, .09);
    border-radius: 10px;
    color: var(--primary-color);
    display: flex;
    flex: 0 0 40px;
    height: 40px;
    justify-content: center;
}

.settings-section-header h2 {
    color: var(--text-color);
    font-size: 1.05rem;
    font-weight: 650;
    margin: .05rem 0 .2rem;
}

.settings-section-header p {
    color: var(--text-muted);
    font-size: .86rem;
    margin: 0;
}

.settings-section-body {
    padding: 1.15rem 1.65rem 1.55rem;
}

.settings-field {
    max-width: 620px;
}

.settings-field .form-label,
.settings-security-grid .form-label {
    color: var(--text-color);
    font-size: .84rem;
    font-weight: 600;
}

.settings-field-note {
    color: var(--text-muted);
    display: block;
    font-size: .8rem;
    margin-top: .55rem;
}

.settings-theme-field {
    max-width: 900px;
}

.settings-theme-field > .settings-field-note {
    margin: .15rem 0 .85rem;
}

.theme-preview-grid {
    display: grid;
    gap: .85rem;
    grid-template-columns: repeat(3, minmax(150px, 1fr));
}

.theme-preview-option {
    min-width: 0;
    padding: 0;
    border: 0;
    background: transparent;
    color: var(--text-color);
    cursor: pointer;
    font: inherit;
    text-align: center;
}

.theme-preview-frame {
    --preview-bg: #eef4f8;
    --preview-window: #ffffff;
    --preview-bar: #e7eef3;
    --preview-sidebar: #cfe5f1;
    --preview-line: #9fb5c2;
    --preview-shadow: rgba(20, 80, 108, .16);
    display: block;
    height: 92px;
    padding: 12px;
    position: relative;
    isolation: isolate;
    overflow: hidden;
    border: 2px solid rgba(31, 99, 146, .2);
    border-radius: 14px;
    background: var(--preview-bg);
    box-shadow:
        inset 0 1px 0 rgba(255, 255, 255, .72),
        inset 0 0 0 1px rgba(31, 99, 146, .08),
        0 8px 18px var(--preview-shadow);
    transition: border-color .18s ease, box-shadow .18s ease, transform .18s ease;
}

.theme-preview-frame::before {
    position: absolute;
    inset: 0;
    z-index: 0;
    background: linear-gradient(145deg, rgba(255, 255, 255, .42), transparent 48%, rgba(15, 76, 129, .07));
    content: "";
    pointer-events: none;
}

.theme-preview-window {
    display: grid;
    grid-template-columns: 24% 1fr;
    grid-template-rows: 17px 1fr;
    height: 100%;
    position: relative;
    z-index: 1;
    overflow: hidden;
    border: 1px solid rgba(15, 76, 129, .12);
    border-radius: 7px;
    background: var(--preview-window);
    box-shadow:
        0 7px 15px rgba(15, 76, 129, .16),
        inset 0 1px 0 rgba(255, 255, 255, .58);
    transform: translateY(-1px);
}

.theme-preview-topbar {
    grid-column: 1 / -1;
    position: relative;
    background: var(--preview-bar);
}

.theme-preview-topbar::before {
    width: 16px;
    height: 4px;
    position: absolute;
    top: 6px;
    left: 7px;
    border-radius: 999px;
    background: var(--preview-line);
    content: "";
    opacity: .72;
}

.theme-preview-sidebar {
    background: var(--preview-sidebar);
}

.theme-preview-content {
    display: flex;
    flex-direction: column;
    gap: 7px;
    padding: 9px 8px;
}

.theme-preview-content i {
    display: block;
    width: 82%;
    height: 4px;
    border-radius: 999px;
    background: var(--preview-line);
    opacity: .7;
}

.theme-preview-content i:nth-child(2) {
    width: 62%;
}

.theme-preview-content i:nth-child(3) {
    width: 72%;
}

.theme-preview-option[data-theme-preview="system"] .theme-preview-frame {
    --preview-bg: linear-gradient(90deg, #eef4f8 0 50%, #0a1b2c 50% 100%);
    --preview-window: linear-gradient(90deg, #ffffff 0 50%, #10263a 50% 100%);
    --preview-bar: linear-gradient(90deg, #e7eef3 0 50%, #18334b 50% 100%);
    --preview-sidebar: linear-gradient(90deg, #cfe5f1 0 50%, #0d4e70 50% 100%);
    --preview-line: #7ba9bf;
}

.theme-preview-option[data-theme-preview="default"] .theme-preview-frame {
    --preview-bg: #edf4f8;
    --preview-window: #ffffff;
    --preview-bar: #e7eef3;
    --preview-sidebar: #cfe5f1;
    --preview-line: #7895a6;
}

.theme-preview-option[data-theme-preview="light"] .theme-preview-frame {
    --preview-bg: #edf4f8;
    --preview-window: #ffffff;
    --preview-bar: #f0f4f7;
    --preview-sidebar: #d8edf7;
    --preview-line: #7895a6;
}

.theme-preview-option[data-theme-preview="dark"] .theme-preview-frame {
    --preview-bg: #071522;
    --preview-window: #0c1f31;
    --preview-bar: #163149;
    --preview-sidebar: #0d4e70;
    --preview-line: #7dbbd6;
}

.theme-preview-option[data-theme-preview="tropical"] .theme-preview-frame {
    --preview-bg: linear-gradient(135deg, #c8f1ec, #e8f7e4);
    --preview-window: #f9fffc;
    --preview-bar: #d9f5ef;
    --preview-sidebar: #33a79d;
    --preview-line: #23877f;
    --preview-shadow: rgba(35, 145, 137, .2);
}

.theme-preview-option[data-theme-preview="futuristic"] .theme-preview-frame {
    --preview-bg: linear-gradient(135deg, #0a1532, #24104b);
    --preview-window: #101c3b;
    --preview-bar: #1a2a58;
    --preview-sidebar: #4c38a7;
    --preview-line: #66d9ff;
    --preview-shadow: rgba(30, 38, 105, .3);
}

.theme-preview-check {
    display: grid;
    place-items: center;
    width: 21px;
    height: 21px;
    position: absolute;
    top: 7px;
    right: 7px;
    z-index: 2;
    border: 2px solid #ffffff;
    border-radius: 50%;
    background: var(--primary-color);
    color: #ffffff;
    font-size: .58rem;
    opacity: 0;
    box-shadow: 0 4px 10px rgba(15, 76, 129, .2);
    transform: scale(.8);
    transition: opacity .18s ease, transform .18s ease;
}

.theme-preview-label {
    display: block;
    margin-top: .48rem;
    overflow: hidden;
    color: var(--text-muted);
    font-size: .76rem;
    font-weight: 600;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.theme-preview-option:hover .theme-preview-frame,
.theme-preview-option:focus-visible .theme-preview-frame {
    border-color: rgba(31, 111, 158, .45);
    box-shadow:
        inset 0 1px 0 rgba(255, 255, 255, .78),
        0 14px 26px var(--preview-shadow);
    transform: translateY(-3px);
}

.theme-preview-option:focus-visible {
    outline: none;
}

.theme-preview-option.is-selected .theme-preview-frame {
    border-color: var(--primary-color);
    box-shadow:
        inset 0 1px 0 rgba(255, 255, 255, .78),
        0 0 0 3px rgba(31, 111, 158, .11),
        0 14px 28px var(--preview-shadow);
}

.theme-preview-option.is-selected .theme-preview-check {
    opacity: 1;
    transform: scale(1);
}

.theme-preview-option.is-selected .theme-preview-label {
    color: var(--primary-color);
    font-weight: 700;
}

[data-theme="dark"] .theme-preview-frame {
    box-shadow:
        inset 0 1px 0 rgba(138, 210, 255, .12),
        inset 0 0 0 1px rgba(138, 210, 255, .08),
        0 10px 22px rgba(0, 0, 0, .24);
}

[data-theme="dark"] .theme-preview-option:hover .theme-preview-frame,
[data-theme="dark"] .theme-preview-option:focus-visible .theme-preview-frame {
    border-color: rgba(125, 211, 252, .42);
    box-shadow: 0 10px 24px rgba(0, 0, 0, .24);
}

[data-theme="dark"] .theme-preview-option.is-selected .theme-preview-frame {
    border-color: #64c7ef;
    box-shadow: 0 0 0 3px rgba(100, 199, 239, .12), 0 10px 24px rgba(0, 0, 0, .24);
}

[data-theme="dark"] .theme-preview-option.is-selected .theme-preview-label {
    color: #8fdcff;
}

.settings-option {
    align-items: center;
    border-bottom: 1px solid rgba(31, 99, 146, .09);
    display: flex;
    gap: 1rem;
    justify-content: space-between;
    max-width: 780px;
    min-height: 64px;
    padding: .8rem 0;
}

.settings-option:last-child {
    border-bottom: 0;
}

.settings-option-copy strong,
.settings-option-copy small {
    display: block;
}

.settings-option-copy strong {
    color: var(--text-color);
    font-size: .9rem;
    font-weight: 600;
}

.settings-option-copy small {
    color: var(--text-muted);
    font-size: .8rem;
    margin-top: .18rem;
}

.settings-option .form-check {
    flex: 0 0 auto;
    margin: 0;
}

.settings-regional-grid,
.settings-security-grid {
    display: grid;
    gap: 1rem;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    max-width: 780px;
}

.settings-security-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
    max-width: none;
}

.settings-form-actions {
    align-items: center;
    border-top: 1px solid rgba(31, 99, 146, .1);
    display: flex;
    justify-content: flex-end;
    padding: 1rem 1.65rem;
}

.settings-form-actions .btn,
.settings-security-actions .btn {
    min-height: 44px;
    padding-inline: 1.2rem;
}

.settings-security-actions {
    align-items: center;
    display: flex;
    gap: .75rem;
    justify-content: flex-end;
    margin-top: 1rem;
}

.settings-session-action {
    align-items: center;
    border-top: 1px solid rgba(31, 99, 146, .1);
    display: flex;
    gap: 1rem;
    justify-content: space-between;
    margin-top: 1.35rem;
    padding-top: 1rem;
    width: 100%;
}

.settings-session-action > form {
    align-items: center;
    display: flex;
    justify-content: flex-end;
    margin-left: auto;
}

.settings-session-action strong,
.settings-session-action small {
    display: block;
}

.settings-session-action strong {
    font-size: .88rem;
    font-weight: 600;
}

.settings-session-action small {
    color: var(--text-muted);
    font-size: .78rem;
    margin-top: .12rem;
}

[data-theme="dark"] .settings-workspace {
    background: var(--card-bg);
}

[data-theme="dark"] .settings-navigation {
    background: rgba(8, 35, 55, .5);
}

@media (max-width: 991.98px) {
    .settings-workspace {
        grid-template-columns: 1fr;
    }

    .settings-navigation {
        border-bottom: 1px solid rgba(31, 99, 146, .12);
        border-right: 0;
        display: flex;
        gap: .25rem;
        overflow-x: auto;
        padding: .75rem 1rem;
    }

    .settings-navigation-label,
    .settings-navigation-separator {
        display: none;
    }

    .settings-navigation a {
        flex: 0 0 auto;
        margin: 0;
    }

    .settings-security-grid {
        grid-template-columns: 1fr;
    }

    .theme-preview-grid {
        grid-template-columns: repeat(3, minmax(120px, 1fr));
    }
}

@media (max-width: 767.98px) {
    .settings-page-header {
        border-radius: 18px;
        min-height: 150px;
        padding: 1.5rem;
    }

    .settings-section-header {
        padding: 1.25rem 1.1rem .85rem;
    }

    .settings-section-body {
        padding: 1rem 1.1rem 1.2rem;
    }

    .settings-regional-grid {
        grid-template-columns: 1fr;
    }

    .theme-preview-grid {
        grid-template-columns: repeat(2, minmax(110px, 1fr));
    }

    .settings-session-action {
        align-items: flex-start;
        flex-direction: column;
    }

    .settings-session-action > form {
        justify-content: flex-start;
        margin-left: 0;
    }

    .settings-form-actions {
        padding-inline: 1.1rem;
    }
}
</style>

<div class="container-fluid personal-settings-shell">
    <header class="settings-page-header">
        <div>
            <h1>Settings</h1>
            <p><?php echo $isAdmin ? 'Manage account security and access system-wide configuration.' : 'Manage your appearance, assistant, notification, regional, and security preferences.'; ?></p>
        </div>
    </header>

    <?php if ($forcePasswordChange): ?>
        <div class="alert alert-warning settings-feedback">
            <i class="fas fa-triangle-exclamation"></i>
            <span>Please update your password to continue.</span>
        </div>
    <?php endif; ?>
    <?php if ($message): ?>
        <div class="alert alert-success settings-feedback" data-settings-feedback="success" role="status">
            <i class="fas fa-circle-check"></i>
            <span><?php echo htmlspecialchars($message); ?></span>
            <button type="button" class="settings-feedback-close" data-settings-feedback-close aria-label="Dismiss message">
                <i class="fas fa-xmark" aria-hidden="true"></i>
            </button>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger settings-feedback">
            <i class="fas fa-circle-exclamation"></i>
            <span><?php echo htmlspecialchars($error); ?></span>
        </div>
    <?php endif; ?>

    <div class="settings-workspace">
        <nav class="settings-navigation" aria-label="Settings sections">
            <span class="settings-navigation-label">Your preferences</span>
            <?php if (!$isAdmin): ?>
                <a class="settings-nav-link is-active" href="#settingsAppearance" data-settings-target="settingsAppearance" aria-controls="settingsAppearance" aria-current="page"><i class="fas fa-palette"></i><span>Appearance</span></a>
                <a class="settings-nav-link" href="#settingsEvortix" data-settings-target="settingsEvortix" aria-controls="settingsEvortix"><i class="fas fa-robot"></i><span>Evortix</span></a>
                <a class="settings-nav-link" href="#settingsNotifications" data-settings-target="settingsNotifications" aria-controls="settingsNotifications"><i class="fas fa-bell"></i><span>Notifications</span></a>
                <a class="settings-nav-link" href="#settingsRegional" data-settings-target="settingsRegional" aria-controls="settingsRegional"><i class="fas fa-globe"></i><span>Regional</span></a>
                <div class="settings-navigation-separator"></div>
            <?php endif; ?>
            <a class="settings-nav-link<?php echo $isAdmin ? ' is-active' : ''; ?>" href="#settingsSecurity" data-settings-target="settingsSecurity" aria-controls="settingsSecurity"<?php echo $isAdmin ? ' aria-current="page"' : ''; ?>><i class="fas fa-lock"></i><span>Security</span></a>
            <?php if ($isAdmin): ?>
                <div class="settings-navigation-separator"></div>
                <a href="<?php echo BASE_URL; ?>modules/admin/settings.php"><i class="fas fa-screwdriver-wrench"></i><span>System settings</span></a>
            <?php endif; ?>
        </nav>

        <main class="settings-content">
            <?php if (!$isAdmin): ?>
                <form method="POST" id="personalPreferencesForm">
                    <section class="settings-section" id="settingsAppearance" data-settings-panel>
                        <div class="settings-section-header">
                            <span class="settings-section-icon"><i class="fas fa-palette"></i></span>
                            <div>
                                <h2>Appearance</h2>
                                <p>Choose the visual theme used throughout your workspace.</p>
                            </div>
                        </div>
                        <div class="settings-section-body">
                            <div class="settings-field settings-theme-field">
                                <label class="form-label" id="themePreferenceLabel" for="themePreference">Interface theme</label>
                                <small class="settings-field-note">Default includes the navbar light/dark switch. Tropical Coast stays light, and Futuristic Neon stays dark.</small>
                                <select class="visually-hidden" id="themePreference" name="theme_preference" aria-labelledby="themePreferenceLabel">
                                    <?php foreach ($themeOptions as $value => $label): ?>
                                        <option value="<?php echo htmlspecialchars($value); ?>" <?php echo $preferences['theme_preference'] === $value ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="theme-preview-grid" role="group" aria-labelledby="themePreferenceLabel">
                                    <?php foreach ($themeOptions as $value => $label): ?>
                                    <button
                                        type="button"
                                        class="theme-preview-option<?php echo $preferences['theme_preference'] === $value ? ' is-selected' : ''; ?>"
                                        data-theme-preview="<?php echo htmlspecialchars($value); ?>"
                                        aria-pressed="<?php echo $preferences['theme_preference'] === $value ? 'true' : 'false'; ?>"
                                    >
                                        <span class="theme-preview-frame" aria-hidden="true">
                                            <span class="theme-preview-window">
                                                <span class="theme-preview-topbar"></span>
                                                <span class="theme-preview-sidebar"></span>
                                                <span class="theme-preview-content"><i></i><i></i><i></i></span>
                                            </span>
                                            <span class="theme-preview-check"><i class="fas fa-check"></i></span>
                                        </span>
                                        <span class="theme-preview-label"><?php echo htmlspecialchars($label); ?></span>
                                    </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="settings-section" id="settingsEvortix" data-settings-panel hidden>
                        <div class="settings-section-header">
                            <span class="settings-section-icon"><i class="fas fa-robot"></i></span>
                            <div>
                                <h2>Evortix assistant</h2>
                                <p>Control when the assistant appears and how it presents guidance.</p>
                            </div>
                        </div>
                        <div class="settings-section-body">
                            <?php
                            $evortixSettings = [
                                'evortix_enabled' => ['Show Evortix robot', 'Keep the assistant available beside the chat button.'],
                                'evortix_suggestions' => ['Show suggestions', 'Allow automatic guidance and important recommendations.'],
                                'evortix_sound' => ['Sound and voice', 'Play a short alert and let Evortix read guidance aloud.'],
                            ];
                            ?>
                            <?php foreach ($evortixSettings as $key => [$label, $description]): ?>
                                <div class="settings-option">
                                    <div class="settings-option-copy">
                                        <strong><?php echo htmlspecialchars($label); ?></strong>
                                        <small><?php echo htmlspecialchars($description); ?></small>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="<?php echo htmlspecialchars($key); ?>" <?php echo (int) $preferences[$key] === 1 ? 'checked' : ''; ?>>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <section class="settings-section" id="settingsNotifications" data-settings-panel hidden>
                        <div class="settings-section-header">
                            <span class="settings-section-icon"><i class="fas fa-bell"></i></span>
                            <div>
                                <h2>Notifications</h2>
                                <p>Select the updates you want to receive.</p>
                            </div>
                        </div>
                        <div class="settings-section-body">
                            <?php
                            $notificationSettings = [
                                'in_app_notifications' => ['In-app notifications', 'Show notification updates inside ERNI PMS.'],
                                'email_notifications' => ['Email notifications', 'Receive enabled alerts through email.'],
                                'task_reminders' => ['Task reminders', 'Receive task assignment and deadline reminders.'],
                                'project_updates' => ['Project updates', 'Receive project handoff and status updates.'],
                            ];
                            ?>
                            <?php foreach ($notificationSettings as $key => [$label, $description]): ?>
                                <div class="settings-option">
                                    <div class="settings-option-copy">
                                        <strong><?php echo htmlspecialchars($label); ?></strong>
                                        <small><?php echo htmlspecialchars($description); ?></small>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="<?php echo htmlspecialchars($key); ?>" <?php echo (int) $preferences[$key] === 1 ? 'checked' : ''; ?>>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <section class="settings-section" id="settingsRegional" data-settings-panel hidden>
                        <div class="settings-section-header">
                            <span class="settings-section-icon"><i class="fas fa-globe"></i></span>
                            <div>
                                <h2>Regional preferences</h2>
                                <p>Set how dates and local time appear across the system.</p>
                            </div>
                        </div>
                        <div class="settings-section-body">
                            <div class="settings-regional-grid">
                                <div>
                                    <label class="form-label" for="timezone">Timezone</label>
                                    <select class="form-select" id="timezone" name="timezone">
                                        <?php foreach ($timezoneOptions as $value => $label): ?>
                                            <option value="<?php echo htmlspecialchars($value); ?>" <?php echo $preferences['timezone'] === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="form-label" for="dateFormat">Date format</label>
                                    <select class="form-select" id="dateFormat" name="date_format">
                                        <?php foreach ($dateFormatOptions as $value => $label): ?>
                                            <option value="<?php echo htmlspecialchars($value); ?>" <?php echo $preferences['date_format'] === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </section>

                    <div class="settings-form-actions">
                        <button type="submit" name="save_preferences" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i> Save preferences
                        </button>
                    </div>
                </form>
            <?php endif; ?>

            <section class="settings-section" id="settingsSecurity" data-settings-panel<?php echo !$isAdmin ? ' hidden' : ''; ?>>
                <div class="settings-section-header">
                    <span class="settings-section-icon"><i class="fas fa-lock"></i></span>
                    <div>
                        <h2>Security</h2>
                        <p>Update your password and manage other active sessions.</p>
                    </div>
                </div>
                <div class="settings-section-body">
                    <form method="POST">
                        <div class="settings-security-grid">
                            <div>
                                <label class="form-label">Current password</label>
                                <input type="password" name="current_password" class="form-control" required autocomplete="current-password">
                            </div>
                            <div>
                                <label class="form-label">New password</label>
                                <input type="password" name="password" class="form-control" minlength="6" required autocomplete="new-password">
                            </div>
                            <div>
                                <label class="form-label">Confirm new password</label>
                                <input type="password" name="confirm_password" class="form-control" minlength="6" required autocomplete="new-password">
                            </div>
                        </div>
                        <div class="settings-security-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-key me-1"></i> Update password
                            </button>
                        </div>
                    </form>

                    <?php if (!$isAdmin): ?>
                        <div class="settings-session-action">
                            <div>
                                <strong>Other active sessions</strong>
                                <small>Sign out your account on every other browser or device.</small>
                            </div>
                            <form method="POST" data-app-confirm="Sign out every other active login?" data-app-confirm-title="Sign Out Other Sessions">
                                <button type="submit" name="logout_other_sessions" class="btn btn-outline-secondary">
                                    <i class="fas fa-right-from-bracket me-1"></i> Sign out other sessions
                                </button>
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="settings-session-action">
                            <div>
                                <strong>System configuration</strong>
                                <small>Open the administrator workspace for global settings.</small>
                            </div>
                            <a href="<?php echo BASE_URL; ?>modules/admin/settings.php" class="btn btn-outline-primary">Open admin settings</a>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-settings-feedback]').forEach(function (feedback) {
        let removeTimer = null;
        const dismissFeedback = function () {
            window.clearTimeout(removeTimer);
            feedback.classList.add('is-dismissing');
            window.setTimeout(function () {
                feedback.remove();
            }, 240);
        };

        feedback.querySelector('[data-settings-feedback-close]')?.addEventListener('click', dismissFeedback);
        if (feedback.dataset.settingsFeedback === 'success') {
            removeTimer = window.setTimeout(dismissFeedback, 4200);
        }
    });

    const workspace = document.querySelector('.settings-workspace');
    if (!workspace) {
        return;
    }

    const navigationItems = Array.from(workspace.querySelectorAll('[data-settings-target]'));
    const panels = Array.from(workspace.querySelectorAll('[data-settings-panel]'));
    const preferenceForm = document.getElementById('personalPreferencesForm');
    const themePreference = document.getElementById('themePreference');
    const themePreviewOptions = Array.from(workspace.querySelectorAll('[data-theme-preview]'));

    function selectThemePreview(themeValue) {
        const hasTheme = themePreference
            && Array.from(themePreference.options).some(function (option) {
                return option.value === themeValue;
            });
        if (!hasTheme) {
            return;
        }

        themePreference.value = themeValue;
        themePreviewOptions.forEach(function (option) {
            const isSelected = option.dataset.themePreview === themeValue;
            option.classList.toggle('is-selected', isSelected);
            option.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
        });
        themePreference.dispatchEvent(new Event('change', { bubbles: true }));
    }

    themePreviewOptions.forEach(function (option) {
        option.addEventListener('click', function () {
            selectThemePreview(option.dataset.themePreview || 'default');
        });
    });

    const serverSelectedPanel = <?php
        echo json_encode(
            ($isAdmin || isset($_POST['password']) || isset($_POST['logout_other_sessions']))
                ? 'settingsSecurity'
                : ''
        );
    ?>;

    function showSettingsPanel(panelId, updateAddress) {
        const selectedPanel = document.getElementById(panelId);
        if (!selectedPanel) {
            return;
        }

        const isPreferencePanel = preferenceForm && preferenceForm.contains(selectedPanel);
        if (preferenceForm) {
            preferenceForm.hidden = !isPreferencePanel;
        }

        panels.forEach(function (panel) {
            panel.hidden = panel !== selectedPanel;
        });

        navigationItems.forEach(function (item) {
            const isSelected = item.dataset.settingsTarget === panelId;
            item.classList.toggle('is-active', isSelected);
            if (isSelected) {
                item.setAttribute('aria-current', 'page');
            } else {
                item.removeAttribute('aria-current');
            }
        });

        if (updateAddress && window.history && window.history.replaceState) {
            window.history.replaceState(null, '', '#' + panelId);
        }
    }

    navigationItems.forEach(function (item) {
        item.addEventListener('click', function (event) {
            event.preventDefault();
            showSettingsPanel(item.dataset.settingsTarget, true);
        });
    });

    const hashPanel = window.location.hash ? window.location.hash.substring(1) : '';
    const requestedPanel = navigationItems.some(function (item) {
        return item.dataset.settingsTarget === hashPanel;
    }) ? hashPanel : serverSelectedPanel;
    const defaultPanel = requestedPanel
        || navigationItems.find(function (item) { return item.classList.contains('is-active'); })?.dataset.settingsTarget
        || navigationItems[0]?.dataset.settingsTarget;

    if (defaultPanel) {
        showSettingsPanel(defaultPanel, false);
    }
});
</script>

<?php if ($preferencesSaved): ?>
<script>
try {
    const savedThemePreference = <?php echo json_encode((string) $preferences['theme_preference']); ?>;
    const visualTheme = ['tropical', 'futuristic'].includes(savedThemePreference)
        ? savedThemePreference
        : '';
    if (savedThemePreference !== 'default') {
        window.localStorage.removeItem('theme');
    }
    const storedDefaultMode = window.localStorage.getItem('theme');
    const appliedTheme = savedThemePreference === 'futuristic'
        ? 'dark'
        : (savedThemePreference === 'default' && storedDefaultMode === 'dark' ? 'dark' : 'light');
    document.documentElement.setAttribute('data-theme', appliedTheme);
    document.body.setAttribute('data-theme', appliedTheme);
    if (visualTheme) {
        document.documentElement.setAttribute('data-app-theme', visualTheme);
        document.body.setAttribute('data-app-theme', visualTheme);
    } else {
        document.documentElement.removeAttribute('data-app-theme');
        document.body.removeAttribute('data-app-theme');
    }
} catch (error) {
    // The server preference still applies when browser storage is unavailable.
}
</script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
