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
    --gray-50: #f8f9fa;
    --gray-100: #f1f3f5;
    --gray-200: #e9ecef;
    --gray-300: #dee2e6;
    --gray-600: #6c757d;
    --gray-900: #212529;
    --shadow-sm: 0 2px 4px rgba(0,0,0,0.02), 0 1px 2px rgba(0,0,0,0.03);
    --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03);
}

.page-header-modern {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-primary-dark) 100%);
    border-radius: 24px;
    padding: 2rem;
    margin-bottom: 1.5rem;
    color: white;
}

.stats-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.stat-card {
    background: white;
    border: 1px solid var(--gray-200);
    border-radius: 20px;
    padding: 1.25rem 1.5rem;
    box-shadow: var(--shadow-sm);
}

.stat-card .label {
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--gray-600);
    margin-bottom: 0.4rem;
    font-weight: 600;
}

.stat-card .value {
    font-size: 2rem;
    color: var(--theme-primary);
    font-weight: 700;
    line-height: 1;
}

.info-banner,
.requests-card {
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
}

.requests-card {
    overflow: hidden;
}

.card-header-modern {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--gray-200);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}

.request-list {
    display: flex;
    flex-direction: column;
}

.request-item {
    padding: 1.5rem;
    border-bottom: 1px solid var(--gray-200);
}

.request-item:last-child {
    border-bottom: none;
}

.request-item.is-selected {
    background: #f7fbfe;
}

.request-head {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    align-items: flex-start;
    margin-bottom: 1rem;
    flex-wrap: wrap;
}

.request-title {
    font-size: 1.1rem;
    font-weight: 700;
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

.status-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.4rem 0.8rem;
    border-radius: 999px;
    font-size: 0.76rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
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
    gap: 0.9rem;
    margin-bottom: 1rem;
}

.detail-card {
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-radius: 16px;
    padding: 0.9rem 1rem;
}

.detail-card .label {
    font-size: 0.72rem;
    color: var(--gray-600);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 0.35rem;
    font-weight: 700;
}

.detail-card .value {
    color: var(--gray-900);
    font-size: 0.9rem;
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
    border-radius: 14px;
    padding: 0.85rem 1rem;
    resize: vertical;
}

.action-btn {
    border: none;
    border-radius: 14px;
    padding: 0.85rem 1rem;
    font-weight: 700;
    min-width: 180px;
}

.action-btn-primary {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-primary-dark) 100%);
    color: white;
}

.action-btn-secondary {
    background: white;
    border: 1px solid var(--gray-300);
    color: var(--gray-900);
}

.request-error {
    margin-top: 0.9rem;
    padding: 0.85rem 1rem;
    border-radius: 14px;
    background: #fff1f2;
    border: 1px solid #fecdd3;
    color: #9f1239;
    font-size: 0.84rem;
}

.empty-state {
    padding: 3rem 2rem;
    text-align: center;
    color: var(--gray-600);
}

@media (max-width: 992px) {
    .stats-row,
    .request-grid,
    .request-actions {
        grid-template-columns: 1fr;
    }

    .action-btn {
        width: 100%;
    }
}
</style>

<div class="container-fluid px-4 py-4">
    <div class="page-header-modern">
        <h2 class="mb-2"><i class="fas fa-key me-2"></i>Password Reset Requests</h2>
        <p class="mb-0">Review forgot-password requests, approve them, and send temporary passwords through Gmail.</p>
    </div>

    <div class="stats-row">
        <div class="stat-card">
            <div class="label">Pending</div>
            <div class="value"><?php echo (int) $counts['pending']; ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Failed Sends</div>
            <div class="value"><?php echo (int) $counts['failed']; ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Sent</div>
            <div class="value"><?php echo (int) $counts['sent']; ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Total Requests</div>
            <div class="value"><?php echo (int) $counts['total']; ?></div>
        </div>
    </div>

    <?php if (!$mailReady): ?>
        <div class="info-banner">
            Gmail SMTP is not configured yet. Admins can still receive requests, but sending temporary passwords will fail until mail settings are completed in Settings.
        </div>
    <?php endif; ?>

    <div class="requests-card">
        <div class="card-header-modern">
            <div>
                <h5 class="mb-1">Request Queue</h5>
                <small class="text-muted">Newest pending requests stay at the top. Sending again will generate a fresh temporary password.</small>
            </div>
        </div>

        <?php if (!$requests): ?>
            <div class="empty-state">
                <i class="fas fa-inbox fa-3x mb-3"></i>
                <h5>No password reset requests yet</h5>
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
                    <div class="request-item <?php echo $isSelected ? 'is-selected' : ''; ?>" id="request-<?php echo $requestId; ?>">
                        <div class="request-head">
                            <div>
                                <h3 class="request-title"><?php echo htmlspecialchars((string) ($request['full_name'] ?? 'Unknown User')); ?></h3>
                                <div class="request-meta">
                                    <span><i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars((string) ($request['email'] ?? '')); ?></span>
                                    <span><i class="fas fa-user-tag me-1"></i><?php echo htmlspecialchars(getRoleDisplayName((string) ($request['role'] ?? 'developer'))); ?></span>
                                    <span><i class="fas fa-clock me-1"></i><?php echo htmlspecialchars(date('M d, Y h:i A', strtotime((string) ($request['requested_at'] ?? 'now')))); ?></span>
                                </div>
                            </div>
                            <span class="status-badge <?php echo passwordResetStatusClass($status); ?>">
                                <?php echo htmlspecialchars(passwordResetStatusLabel($status)); ?>
                            </span>
                        </div>

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
                                    <?php echo $status === 'sent' ? 'Send New Temporary Password' : 'Send Temporary Password'; ?>
                                </button>
                                <button type="submit" name="action" value="close_request" class="action-btn action-btn-secondary">
                                    Close Request
                                </button>
                            <?php else: ?>
                                <button type="button" class="action-btn action-btn-secondary" disabled>
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
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
