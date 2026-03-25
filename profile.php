<?php
require_once 'includes/auth.php';
requireLogin();

$user = getCurrentUser();

include 'includes/header.php';
include 'includes/sidebar.php';
include 'includes/navbar.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">My Profile</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Full Name</label>
                            <div class="form-control bg-light"><?php echo htmlspecialchars($user['full_name']); ?></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <div class="form-control bg-light"><?php echo htmlspecialchars($user['email']); ?></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Username</label>
                            <div class="form-control bg-light"><?php echo htmlspecialchars($user['username']); ?></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Role</label>
                            <div class="form-control bg-light"><?php echo htmlspecialchars(getRoleDisplayName($user['role'])); ?></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Department</label>
                            <div class="form-control bg-light"><?php echo htmlspecialchars($user['department'] ?: 'Not set'); ?></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Last Login</label>
                            <div class="form-control bg-light"><?php echo htmlspecialchars($user['last_login'] ?: 'No recorded login yet'); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
