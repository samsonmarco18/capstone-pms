<?php
require_once 'includes/auth.php';
requireLogin();

$user = getCurrentUser();
$conn = getConnection();

// Handle delete request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_notification'])) {
    $notificationId = (int)$_POST['notification_id'];
    $stmt = $conn->prepare('DELETE FROM notifications WHERE id = ? AND user_id = ?');
    $stmt->bind_param('ii', $notificationId, $user['id']);
    $stmt->execute();
    $stmt->close();
    
    header('Location: notifications.php');
    exit();
}

$stmt = $conn->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC');
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$notifications = [];
$result = $stmt->get_result();
if (is_object($result) && method_exists($result, 'fetch_assoc')) {
    while ($row = $result->fetch_assoc()) {
        $notifications[] = $row;
    }
}
$stmt->close();

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
    --shadow-sm: 0 1px 3px rgba(0,0,0,0.04), 0 1px 2px rgba(0,0,0,0.03);
    --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.02);
}

/* Notification Card */
.notification-card {
    background: white;
    border-radius: 24px;
    border: 1px solid var(--gray-200);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
}

.notification-card .card-header {
    padding: 1.25rem 1.5rem;
    background: white;
    border-bottom: 1px solid var(--gray-200);
}

.notification-card .card-header h5 {
    font-size: 1rem;
    font-weight: 600;
    color: var(--gray-800);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.notification-card .card-header h5 i {
    color: var(--theme-primary);
}

/* Notification List */
.notification-list {
    display: flex;
    flex-direction: column;
}

.notification-item {
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--gray-200);
    transition: background 0.2s;
}

.notification-item:last-child {
    border-bottom: none;
}

.notification-item:hover {
    background: var(--gray-50);
}

.notification-item.unread {
    background: var(--gray-50);
}

.notification-icon {
    width: 40px;
    height: 40px;
    background: rgba(31, 99, 146, 0.1);
    border-radius: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.notification-icon i {
    font-size: 1.1rem;
    color: var(--theme-primary);
}

.notification-content {
    flex: 1;
}

.notification-title {
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--gray-800);
    margin-bottom: 0.25rem;
}

.notification-message {
    font-size: 0.8rem;
    color: var(--gray-600);
    margin-bottom: 0.5rem;
    line-height: 1.4;
}

.notification-time {
    font-size: 0.7rem;
    color: var(--gray-400);
    display: flex;
    align-items: center;
    gap: 0.25rem;
}

.unread-dot {
    width: 8px;
    height: 8px;
    background: var(--theme-primary);
    border-radius: 50%;
    display: inline-block;
    margin-right: 0.5rem;
}

/* Delete Button */
.btn-delete-notification {
    background: transparent;
    border: none;
    color: var(--gray-400);
    font-size: 0.9rem;
    cursor: pointer;
    padding: 0.25rem;
    transition: all 0.2s;
    width: 28px;
    height: 28px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.btn-delete-notification:hover {
    background: var(--gray-200);
    color: #dc2626;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 4rem 2rem;
}

.empty-state i {
    font-size: 4rem;
    color: var(--gray-300);
    margin-bottom: 1rem;
}

.empty-state h4 {
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--gray-700);
    margin-bottom: 0.5rem;
}

.empty-state p {
    font-size: 0.85rem;
    color: var(--gray-500);
    margin: 0;
}

/* Mark All Read Button */
.btn-mark-read {
    background: transparent;
    border: 1px solid var(--gray-300);
    color: var(--gray-600);
    padding: 0.4rem 1rem;
    border-radius: 40px;
    font-size: 0.75rem;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.btn-mark-read:hover {
    background: var(--gray-50);
    border-color: var(--theme-primary);
    color: var(--theme-primary);
}

/* Responsive */
@media (max-width: 768px) {
    .notification-item {
        padding: 1rem;
    }
    
    .notification-icon {
        width: 32px;
        height: 32px;
    }
    
    .notification-icon i {
        font-size: 0.9rem;
    }
    
    .notification-title {
        font-size: 0.85rem;
    }
    
    .btn-delete-notification {
        width: 24px;
        height: 24px;
    }
}
</style>

<div class="container-fluid px-4 py-4">
    <!-- Notifications Card -->
    <div class="notification-card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5>
                <i class="fas fa-bell"></i>
                All Notifications
                <?php if (!empty($notifications)): ?>
                    <span class="badge bg-primary ms-2"><?php echo count($notifications); ?></span>
                <?php endif; ?>
            </h5>
            <?php if (!empty($notifications)): ?>
                <button class="btn-mark-read" onclick="markAllAsRead()">
                    <i class="fas fa-check-double"></i> Mark all as read
                </button>
            <?php endif; ?>
        </div>
        
        <div class="notification-list">
            <?php if (!empty($notifications)): ?>
                <?php foreach ($notifications as $notification): ?>
                    <div class="notification-item <?php echo !$notification['is_read'] ? 'unread' : ''; ?>" 
                         data-id="<?php echo $notification['id']; ?>">
                        <div class="notification-icon">
                            <i class="fas fa-bell"></i>
                        </div>
                        <div class="notification-content" onclick="markAsRead(<?php echo $notification['id']; ?>)">
                            <div class="notification-title">
                                <?php if (!$notification['is_read']): ?>
                                    <span class="unread-dot"></span>
                                <?php endif; ?>
                                <?php echo htmlspecialchars($notification['title']); ?>
                            </div>
                            <div class="notification-message">
                                <?php echo htmlspecialchars($notification['message']); ?>
                            </div>
                            <div class="notification-time">
                                <i class="far fa-clock"></i>
                                <?php echo date('M d, Y h:i A', strtotime($notification['created_at'])); ?>
                            </div>
                        </div>
                        <button class="btn-delete-notification" onclick="deleteNotification(<?php echo $notification['id']; ?>, event)">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-bell-slash"></i>
                    <h4>No notifications yet</h4>
                    <p>When you receive notifications, they'll appear here</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function markAsRead(notificationId) {
    $.ajax({
        url: '<?php echo BASE_URL; ?>api/notifications/mark-read.php',
        method: 'POST',
        data: { id: notificationId },
        success: function() {
            location.reload();
        }
    });
}

function markAllAsRead() {
    if (confirm('Mark all notifications as read?')) {
        $.ajax({
            url: '<?php echo BASE_URL; ?>api/notifications/mark-all-read.php',
            method: 'POST',
            success: function() {
                location.reload();
            }
        });
    }
}

function deleteNotification(notificationId, event) {
    event.stopPropagation();
    if (confirm('Delete this notification?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="delete_notification" value="1">
            <input type="hidden" name="notification_id" value="${notificationId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<?php include 'includes/footer.php'; ?>
