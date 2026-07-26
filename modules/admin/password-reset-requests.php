<?php
require_once '../../includes/password-reset.php';
requireRole('admin');

$currentUser = getCurrentUser();
ensurePasswordResetRequestsTable();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $adminNote = trim((string) ($_POST['admin_note'] ?? ''));

    if ($_POST['action'] === 'send_temporary_password') {
        $result = sendTemporaryPasswordForRequest($requestId, (int) $currentUser['id'], $adminNote);
        $_SESSION[$result['success'] ? 'success' : 'error'] = $result['success']
            ? 'Temporary password sent to the user through Gmail.'
            : (string) $result['error'];
    } elseif ($_POST['action'] === 'close_request') {
        $result = closePasswordResetRequest($requestId, (int) $currentUser['id'], $adminNote);
        $_SESSION[$result['success'] ? 'success' : 'error'] = $result['success']
            ? 'Password reset request closed.'
            : (string) $result['error'];
    }

    header('Location: password-reset-requests.php?request_id=' . $requestId);
    exit();
}

$requests = getPasswordResetRequests();
$counts = getPasswordResetRequestCounts();
$selectedRequestId = (int) ($_GET['request_id'] ?? 0);
$mailReady = isMailConfigured();

function passwordResetStatusClass(string $status): string
{
    return match ($status) {
        'pending' => 'status-pending',
        'failed' => 'status-failed',
        'sent' => 'status-sent',
        default => 'status-closed',
    };
}

function passwordResetStatusLabel(string $status): string
{
    return match ($status) {
        'pending' => 'Pending',
        'failed' => 'Failed',
        'sent' => 'Sent',
        default => 'Closed',
    };
}

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<style>
:root {
    --theme-primary: #1f6392;
    --theme-primary-dark: #0f4a6e;
    --theme-primary-light: #3a7ca3;
    --gray-50: #f8f9fa;
    --gray-100: #f1f3f5;
    --gray-200: #e9ecef;
    --gray-300: #dee2e6;
    --gray-600: #6c757d;
    --gray-900: #212529;
    --shadow-sm: 0 2px 4px rgba(0,0,0,0.02), 0 1px 2px rgba(0,0,0,0.03);
    --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03);
    --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.05), 0 4px 6px -2px rgba(0,0,0,0.02);
}

.page-header-modern {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-primary-dark) 100%);
    border-radius: 24px;
    padding: 2rem;
    margin-bottom: 2rem;
    color: white;
    position: relative;
    overflow: hidden;
}

.page-header-modern::before {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 300px;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1));
    pointer-events: none;
}

.page-header-modern h2 {
    font-size: 1.75rem;
    font-weight: 600;
    margin-bottom: 0.5rem;
    color: white;
}

.page-header-modern h2 i {
    color: white;
}

.page-header-modern p {
    color: rgba(255, 255, 255, 0.9);
    font-size: 0.9rem;
}

.stats-row {
    display: flex;
    gap: 1rem;
    margin-bottom: 2rem;
    flex-wrap: wrap;
}

.stat-card-modern {
    background: white;
    border: 1px solid var(--gray-200);
    border-radius: 20px;
    padding: 1.25rem 1.5rem;
    flex: 1;
    min-width: 180px;
    box-shadow: var(--shadow-sm);
    transition: all 0.2s ease;
    position: relative;
}

.stat-card-modern:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.stat-card-modern .stat-icon {
    position: absolute;
    right: 1rem;
    top: 1rem;
    opacity: 0.65;
    font-size: 1.6rem;
    color: var(--theme-primary);
}

.stat-card-modern .stat-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    font-weight: 600;
    color: var(--gray-600);
    letter-spacing: 0.5px;
    margin-bottom: 0.5rem;
}

.stat-card-modern .stat-number {
    font-size: 2rem;
    color: var(--theme-primary);
    font-weight: 700;
    line-height: 1;
}

.info-banner,
.main-card {
    background: white;
    border: 1px solid var(--gray-200);
    border-radius: 24px;
    box-shadow: var(--shadow-sm);
}

.info-banner {
    padding: 1rem 1.25rem;
    margin-bottom: 1.5rem;
    color: #92400e;
    background: #fff7ed;
    border-color: #fed7aa;
    display: flex;
    gap: 0.75rem;
    align-items: flex-start;
}

.info-banner i {
    margin-top: 0.15rem;
    color: #d97706;
}

.main-card {
    overflow: hidden;
}

.card-header-modern {
    padding: 1.5rem 2rem;
    background: #ffffff;
    border-bottom: 1px solid var(--gray-200);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}

.card-header-modern h5 {
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--gray-900);
    margin: 0;
}

.queue-count {
    color: var(--gray-600);
    font-size: 0.84rem;
    white-space: nowrap;
}

.request-list {
    display: grid;
    gap: 0;
}

.request-item {
    border-bottom: 1px solid var(--gray-200);
    background: white;
    transition: background 0.18s ease;
}

.request-item:hover,
.request-item[open] {
    background: var(--gray-50);
}

.request-item:last-child {
    border-bottom: none;
}

.request-item.is-selected {
    background: #eef8ff;
    box-shadow: inset 4px 0 0 var(--theme-primary);
}

.request-summary {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto auto;
    gap: 1rem;
    align-items: center;
    padding: 1.15rem 2rem;
    cursor: pointer;
    list-style: none;
}

.request-summary::-webkit-details-marker {
    display: none;
}

.request-head {
    display: flex;
    justify-content: flex-start;
    gap: 1rem;
    align-items: flex-start;
    flex-wrap: wrap;
    min-width: 0;
}

.request-title {
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--gray-900);
    margin: 0 0 0.25rem;
}

.request-meta {
    color: var(--gray-600);
    font-size: 0.85rem;
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
}

.request-toggle {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    background: #eef8ff;
    color: var(--theme-primary);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all 0.18s ease;
}

.request-item[open] .request-toggle {
    transform: rotate(180deg);
    background: var(--theme-primary);
    color: white;
}

.request-details {
    padding: 0 2rem 1.5rem;
}

.request-divider {
    height: 1px;
    background: var(--gray-200);
    margin-bottom: 1.2rem;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.3rem 0.8rem;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-pending {
    background: #dbeafe;
    color: #1d4ed8;
}

.status-failed {
    background: #fee2e2;
    color: #b91c1c;
}

.status-sent {
    background: #dcfce7;
    color: #15803d;
}

.status-closed {
    background: #e5e7eb;
    color: #4b5563;
}

.request-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 0.85rem;
    margin-bottom: 1.2rem;
}

.detail-card {
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-radius: 12px;
    padding: 0.9rem 1rem;
    min-width: 0;
}

.detail-card .label {
    font-size: 0.7rem;
    color: var(--gray-600);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 0.25rem;
    font-weight: 600;
}

.detail-card .value {
    color: var(--gray-900);
    font-size: 0.9rem;
    overflow-wrap: anywhere;
}

.request-actions {
    display: grid;
    grid-template-columns: 1.3fr auto auto;
    gap: 0.75rem;
    align-items: end;
}

.request-actions textarea {
    width: 100%;
    min-height: 92px;
    border: 1px solid var(--gray-300);
    border-radius: 12px;
    padding: 0.85rem 1rem;
    resize: vertical;
    font-size: 0.9rem;
    transition: all 0.2s ease;
}

.request-actions textarea:focus {
    outline: none;
    border-color: var(--theme-primary);
    box-shadow: 0 0 0 3px rgba(31, 99, 146, 0.1);
}

.action-btn {
    border: none;
    border-radius: 12px;
    padding: 0.85rem 1rem;
    font-weight: 600;
    font-size: 0.85rem;
    min-width: 180px;
    min-height: 46px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    transition: all 0.2s ease;
}

.action-btn-primary {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-primary-dark) 100%);
    color: white;
}

.action-btn-primary:hover {
    color: white;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(31, 99, 146, 0.3);
}

.action-btn-secondary {
    background: white;
    border: 1px solid var(--gray-300);
    color: var(--gray-900);
}

.action-btn-secondary:hover:not(:disabled) {
    background: var(--gray-50);
    border-color: var(--theme-primary);
    color: var(--theme-primary);
}

.action-btn:disabled {
    cursor: not-allowed;
    opacity: 0.65;
}

.request-error {
    margin-top: 0.9rem;
    padding: 0.85rem 1rem;
    border-radius: 12px;
    background: #fff1f2;
    border: 1px solid #fecdd3;
    color: #9f1239;
    font-size: 0.84rem;
}

.empty-state {
    padding: 4rem 2rem;
    text-align: center;
    color: var(--gray-600);
}

.empty-state i {
    font-size: 4rem;
    color: var(--gray-300);
    margin-bottom: 1rem;
}

.empty-state h4 {
    color: var(--gray-900);
    margin-bottom: 0.5rem;
}

@media (max-width: 992px) {
    .request-grid,
    .request-actions {
        grid-template-columns: 1fr;
    }

    .action-btn {
        width: 100%;
    }
}

@media (max-width: 768px) {
    .page-header-modern {
        padding: 1.5rem;
    }

    .stats-row {
        flex-direction: column;
    }

    .stat-card-modern {
        min-width: auto;
    }

    .card-header-modern {
        flex-direction: column;
        align-items: flex-start;
        padding: 1.25rem 1.5rem;
    }

    .request-summary {
        grid-template-columns: minmax(0, 1fr) auto;
        padding: 1.15rem 1.5rem;
    }

    .request-details {
        padding: 0 1.5rem 1.25rem;
    }

    .request-summary .status-badge {
        grid-column: 1 / -1;
        width: fit-content;
    }

    .request-meta {
        gap: 0.55rem;
    }
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .stat-card-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .main-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .request-item,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .detail-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .action-btn-secondary {
    background: #071321 !important;
    border-color: rgba(138, 210, 255, 0.12) !important;
    color: #d8e6f3 !important;
    box-shadow: 0 10px 28px rgba(0, 0, 0, 0.32) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern {
    box-shadow: 0 16px 38px rgba(0, 0, 0, 0.28);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern::before {
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.06));
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card-header-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .request-details,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .request-actions textarea {
    background: #0a1b2c !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card-header-modern h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .request-title,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .detail-card .value,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .empty-state h4,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .action-btn-secondary {
    color: #d8e6f3 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card-header-modern .text-muted,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .queue-count,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .request-meta,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .detail-card .label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .empty-state,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .empty-state p,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-label.text-muted,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .request-error {
    color: #90aeca !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .request-item:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .request-item[open],
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .request-item.is-selected {
    background: rgba(31, 99, 146, 0.12) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .request-toggle {
    background: rgba(31, 99, 146, 0.16);
    color: #bfe6ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .status-pending {
    background: rgba(29, 78, 216, 0.18);
    color: #8fc1ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .status-failed {
    background: rgba(185, 28, 28, 0.18);
    color: #ff9ca0;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .status-sent {
    background: rgba(21, 128, 61, 0.18);
    color: #94f1c6;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .status-closed {
    background: rgba(75, 85, 99, 0.22);
    color: #d8e6f3;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .info-banner {
    background: rgba(127, 29, 29, 0.34);
    border-color: rgba(248, 113, 113, 0.18);
    color: #ffd0d0;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .info-banner i {
    color: #ff9ca0;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .request-actions textarea,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .request-actions textarea:focus,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-control,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-select {
    background: rgba(10, 24, 38, 0.98);
    border-color: rgba(138, 210, 255, 0.12);
    color: #d8e6f3;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .action-btn-secondary:hover:not(:disabled) {
    background: rgba(31, 99, 146, 0.14);
    border-color: #1f6392;
    color: #ffffff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .request-divider {
    background: rgba(138, 210, 255, 0.12);
}
</style>

<div class="container-fluid px-4 py-4">
    <div class="page-header-modern">
        <div class="d-flex flex-wrap justify-content-between align-items-center">
            <div>
                <h2><i class="fas fa-key me-2"></i>Password Reset Requests</h2>
                <p class="mb-0">Review forgot-password requests, approve them, and send temporary passwords through Gmail.</p>
            </div>
        </div>
    </div>

    <div class="stats-row">
        <div class="stat-card-modern">
            <i class="fas fa-hourglass-half stat-icon"></i>
            <div class="stat-label">Pending</div>
            <div class="stat-number"><?php echo (int) $counts['pending']; ?></div>
        </div>
        <div class="stat-card-modern">
            <i class="fas fa-triangle-exclamation stat-icon"></i>
            <div class="stat-label">Failed Sends</div>
            <div class="stat-number" style="color: #dc2626;"><?php echo (int) $counts['failed']; ?></div>
        </div>
        <div class="stat-card-modern">
            <i class="fas fa-paper-plane stat-icon"></i>
            <div class="stat-label">Sent</div>
            <div class="stat-number" style="color: #059669;"><?php echo (int) $counts['sent']; ?></div>
        </div>
        <div class="stat-card-modern">
            <i class="fas fa-inbox stat-icon"></i>
            <div class="stat-label">Total Requests</div>
            <div class="stat-number"><?php echo (int) $counts['total']; ?></div>
        </div>
    </div>

    <?php if (!$mailReady): ?>
        <div class="info-banner">
            <i class="fas fa-circle-exclamation"></i>
            <div>Gmail SMTP is not configured yet. Admins can still receive requests, but sending temporary passwords will fail until mail settings are completed in Settings.</div>
        </div>
    <?php endif; ?>

    <div class="main-card">
        <div class="card-header-modern">
            <div>
                <h5><i class="fas fa-list me-2" style="color: var(--theme-primary);"></i>Request Queue</h5>
                <small class="text-muted">Newest pending requests stay at the top. Sending again will generate a fresh temporary password.</small>
            </div>
            <span class="queue-count">Showing <?php echo count($requests); ?> requests</span>
        </div>

        <?php if (!$requests): ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <h4>No password reset requests yet</h4>
                <p class="mb-0">Requests from the login page will appear here for admin review.</p>
            </div>
        <?php else: ?>
            <div class="request-list">
                <?php foreach ($requests as $request): ?>
                    <?php
                    $requestId = (int) ($request['id'] ?? 0);
                    $isSelected = $selectedRequestId === $requestId;
                    $status = (string) ($request['status'] ?? 'pending');
                    ?>
                    <details class="request-item <?php echo $isSelected ? 'is-selected' : ''; ?>" id="request-<?php echo $requestId; ?>" <?php echo $isSelected ? 'open' : ''; ?>>
                        <summary class="request-summary">
                            <div class="request-head">
                                <div>
                                    <h3 class="request-title"><?php echo htmlspecialchars((string) ($request['full_name'] ?? 'Unknown User')); ?></h3>
                                    <div class="request-meta">
                                        <span><i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars((string) ($request['email'] ?? '')); ?></span>
                                        <span><i class="fas fa-user-tag me-1"></i><?php echo htmlspecialchars(getRoleDisplayName((string) ($request['role'] ?? 'developer'))); ?></span>
                                        <span><i class="fas fa-clock me-1"></i><?php echo htmlspecialchars(date('M d, Y h:i A', strtotime((string) ($request['requested_at'] ?? 'now')))); ?></span>
                                    </div>
                                </div>
                            </div>
                            <span class="status-badge <?php echo passwordResetStatusClass($status); ?>">
                                <i class="fas <?php echo $status === 'pending' ? 'fa-clock' : ($status === 'failed' ? 'fa-circle-exclamation' : ($status === 'sent' ? 'fa-check' : 'fa-lock')); ?>"></i>
                                <?php echo htmlspecialchars(passwordResetStatusLabel($status)); ?>
                            </span>
                            <span class="request-toggle" aria-hidden="true">
                                <i class="fas fa-chevron-down"></i>
                            </span>
                        </summary>

                        <div class="request-details">
                            <div class="request-divider"></div>

                            <div class="request-grid">
                                <div class="detail-card">
                                    <div class="label">Username</div>
                                    <div class="value"><?php echo htmlspecialchars((string) ($request['username'] ?? '')); ?></div>
                                </div>
                                <div class="detail-card">
                                    <div class="label">User Status</div>
                                    <div class="value"><?php echo htmlspecialchars(ucfirst((string) ($request['user_status'] ?? 'unknown'))); ?></div>
                                </div>
                                <div class="detail-card">
                                    <div class="label">Processed By</div>
                                    <div class="value"><?php echo htmlspecialchars((string) ($request['processed_by_name'] ?? 'Not yet processed')); ?></div>
                                </div>
                                <div class="detail-card">
                                    <div class="label">Last Processed</div>
                                    <div class="value">
                                        <?php echo !empty($request['processed_at']) ? htmlspecialchars(date('M d, Y h:i A', strtotime((string) $request['processed_at']))) : 'Not yet processed'; ?>
                                    </div>
                                </div>
                            </div>

                            <form method="POST" class="request-actions">
                                <input type="hidden" name="request_id" value="<?php echo $requestId; ?>">
                                <div>
                                    <label class="form-label small text-uppercase fw-bold text-muted mb-2">Admin Note</label>
                                    <textarea name="admin_note" placeholder="Optional note to include in the email or close-out"><?php echo htmlspecialchars((string) ($request['admin_note'] ?? '')); ?></textarea>
                                </div>
                                <?php if ($status !== 'closed'): ?>
                                    <button type="submit" name="action" value="send_temporary_password" class="action-btn action-btn-primary">
                                        <i class="fas fa-paper-plane"></i>
                                        <?php echo $status === 'sent' ? 'Send New Temporary Password' : 'Send Temporary Password'; ?>
                                    </button>
                                    <button type="submit" name="action" value="close_request" class="action-btn action-btn-secondary">
                                        <i class="fas fa-lock"></i>
                                        Close Request
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="action-btn action-btn-secondary" disabled>
                                        <i class="fas fa-lock"></i>
                                        Request Closed
                                    </button>
                                <?php endif; ?>
                            </form>

                            <?php if (!empty($request['last_error'])): ?>
                                <div class="request-error">
                                    <strong>Last send error:</strong> <?php echo htmlspecialchars((string) $request['last_error']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.querySelectorAll('.request-item').forEach((item) => {
    item.addEventListener('toggle', () => {
        if (!item.open) {
            return;
        }

        document.querySelectorAll('.request-item[open]').forEach((openItem) => {
            if (openItem !== item) {
                openItem.open = false;
            }
        });
    });
});
</script>

<?php include '../../includes/footer.php'; ?>
