<?php
require_once 'includes/auth.php';
requireLogin();

$user = getCurrentUser();
$conn = getConnection();
$stmt = $conn->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC');
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$notifications = $stmt->get_result();
$stmt->close();

include 'includes/header.php';
include 'includes/sidebar.php';
include 'includes/navbar.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">All Notifications</h5>
                </div>
                <div class="card-body">
                    <div class="list-group">
                        <?php while ($notification = $notifications->fetch_assoc()): ?>
                            <div class="list-group-item <?php echo !$notification['is_read'] ? 'bg-light' : ''; ?>">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($notification['title']); ?></div>
                                        <div class="text-muted"><?php echo htmlspecialchars($notification['message']); ?></div>
                                    </div>
                                    <small class="text-muted"><?php echo date('M d, Y h:i A', strtotime($notification['created_at'])); ?></small>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
