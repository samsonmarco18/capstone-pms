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

    $themePreference = (string) ($_POST['theme_preference'] ?? 'system');
    $timezone = (string) ($_POST['timezone'] ?? 'Asia/Manila');
    $dateFormat = (string) ($_POST['date_format'] ?? 'M d, Y');

    if (!isset($themeOptions[$themePreference])) {
        $themePreference = 'system';
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
    max-width: 1120px;
}

.settings-card {
    border: 1px solid rgba(31, 99, 146, .12);
    border-radius: 16px;
    box-shadow: 0 10px 26px rgba(15, 76, 129, .07);
}

.settings-card .card-header {
    align-items: center;
    background: transparent;
    display: flex;
    gap: .65rem;
    padding: 1rem 1.15rem;
}

.settings-card .card-header i {
    color: var(--primary-color);
}

.settings-option {
    align-items: center;
    border-bottom: 1px solid rgba(31, 99, 146, .09);
    display: flex;
    gap: 1rem;
    justify-content: space-between;
    padding: .8rem 0;
}

.settings-option:last-child {
    border-bottom: 0;
}

.settings-option-copy strong,
.settings-option-copy small {
    display: block;
}

.settings-option-copy small {
    color: var(--text-muted);
    margin-top: .15rem;
}

</style>

<div class="container-fluid personal-settings-shell">
    <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap mb-4">
        <div>
            <h4 class="mb-1">Settings</h4>
            <p class="text-muted mb-0"><?php echo $isAdmin ? 'Manage account security and system settings.' : 'Personalize your experience without changing the workspace layout.'; ?></p>
        </div>
    </div>

    <?php if ($forcePasswordChange): ?>
        <div class="alert alert-warning">Please update your password to continue.</div>
    <?php endif; ?>
    <?php if ($message): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="row g-4">
        <?php if (!$isAdmin): ?>
            <div class="col-xl-7">
                <form method="POST" id="personalPreferencesForm">
                    <div class="card settings-card mb-4">
                        <div class="card-header">
                            <i class="fas fa-palette"></i>
                            <h5 class="mb-0">Appearance</h5>
                        </div>
                        <div class="card-body">
                            <label class="form-label" for="themePreference">Theme</label>
                            <select class="form-select mb-3" id="themePreference" name="theme_preference">
                                <?php foreach ($themeOptions as $value => $label): ?>
                                    <option value="<?php echo htmlspecialchars($value); ?>" <?php echo $preferences['theme_preference'] === $value ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Each theme includes its own coordinated colors and backgrounds.</small>
                        </div>
                    </div>

                    <div class="card settings-card mb-4">
                        <div class="card-header">
                            <i class="fas fa-robot"></i>
                            <h5 class="mb-0">Evortix</h5>
                        </div>
                        <div class="card-body py-2">
                            <?php
                            $evortixSettings = [
                                'evortix_enabled' => ['Show Evortix robot', 'Keep the assistant available beside the chat button.'],
                                'evortix_suggestions' => ['Show suggestions', 'Allow automatic guidance and important recommendations.'],
                                'evortix_sound' => ['Suggestion sound', 'Play a short sound when Evortix presents guidance.'],
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
                    </div>

                    <div class="card settings-card mb-4">
                        <div class="card-header">
                            <i class="fas fa-bell"></i>
                            <h5 class="mb-0">Notifications</h5>
                        </div>
                        <div class="card-body py-2">
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
                    </div>

                    <div class="card settings-card mb-4">
                        <div class="card-header">
                            <i class="fas fa-globe"></i>
                            <h5 class="mb-0">Regional</h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="timezone">Timezone</label>
                                    <select class="form-select" id="timezone" name="timezone">
                                        <?php foreach ($timezoneOptions as $value => $label): ?>
                                            <option value="<?php echo htmlspecialchars($value); ?>" <?php echo $preferences['timezone'] === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="dateFormat">Date format</label>
                                    <select class="form-select" id="dateFormat" name="date_format">
                                        <?php foreach ($dateFormatOptions as $value => $label): ?>
                                            <option value="<?php echo htmlspecialchars($value); ?>" <?php echo $preferences['date_format'] === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <button type="submit" name="save_preferences" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i> Save Personal Settings
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <div class="<?php echo $isAdmin ? 'col-lg-7' : 'col-xl-5'; ?>">
            <div class="card settings-card mb-4">
                <div class="card-header">
                    <i class="fas fa-lock"></i>
                    <h5 class="mb-0">Security</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">Current Password</label>
                            <input type="password" name="current_password" class="form-control" required autocomplete="current-password">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">New Password</label>
                            <input type="password" name="password" class="form-control" minlength="6" required autocomplete="new-password">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="form-control" minlength="6" required autocomplete="new-password">
                        </div>
                        <button type="submit" class="btn btn-primary">Update Password</button>
                    </form>

                    <?php if (!$isAdmin): ?>
                        <hr>
                        <form method="POST" data-app-confirm="Sign out every other active login?" data-app-confirm-title="Sign Out Other Sessions">
                            <button type="submit" name="logout_other_sessions" class="btn btn-outline-secondary">
                                <i class="fas fa-right-from-bracket me-1"></i> Sign Out Other Sessions
                            </button>
                        </form>
                    <?php else: ?>
                        <hr>
                        <a href="<?php echo BASE_URL; ?>modules/admin/settings.php" class="btn btn-outline-primary">Open Admin Settings</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($preferencesSaved): ?>
<script>
try {
    const savedThemePreference = <?php echo json_encode((string) $preferences['theme_preference']); ?>;
    if (savedThemePreference === 'system') {
        window.localStorage.removeItem('theme');
    } else if (savedThemePreference === 'tropical') {
        window.localStorage.setItem('theme', 'light');
    } else if (savedThemePreference === 'futuristic') {
        window.localStorage.setItem('theme', 'dark');
    } else {
        window.localStorage.setItem('theme', savedThemePreference);
    }
    const visualTheme = ['tropical', 'futuristic'].includes(savedThemePreference)
        ? savedThemePreference
        : '';
    const appliedTheme = savedThemePreference === 'system'
        ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
        : (['dark', 'futuristic'].includes(savedThemePreference) ? 'dark' : 'light');
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
