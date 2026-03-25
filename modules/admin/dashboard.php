<?php
require_once '../../includes/auth.php';
requireRole('admin');

$user = getCurrentUser();
$conn = getConnection();

// Get statistics
$techCount = $conn->query("SELECT COUNT(*) as count FROM technologies")->fetch_assoc()['count'];
$userCount = $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'];
$securityReports = $conn->query("SELECT COUNT(*) as count FROM audit_logs WHERE action LIKE '%security%'")->fetch_assoc()['count'];

// Get data for charts
$techStatus = $conn->query("SELECT status, COUNT(*) as count FROM technologies GROUP BY status");
$techStatusData = [];
while($row = $techStatus->fetch_assoc()) {
    $techStatusData[$row['status']] = $row['count'];
}

$userRoles = $conn->query("SELECT role, COUNT(*) as count FROM users GROUP BY role");
$userRolesData = [];
while($row = $userRoles->fetch_assoc()) {
    $userRolesData[$row['role']] = $row['count'];
}

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<div class="row">
    <div class="col-12 mb-4">
        <h2>Welcome back, <?php echo htmlspecialchars($user['full_name']); ?>!</h2>
        <p class="text-muted">Here's what's happening with your system today.</p>
    </div>
</div>

<div class="row">
    <div class="col-md-4 mb-4">
        <div class="card bg-primary text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="card-title">Technologies/Services</h6>
                        <h2 class="mb-0"><?php echo $techCount; ?></h2>
                    </div>
                    <i class="fas fa-microchip fa-3x opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-4 mb-4">
        <div class="card bg-success text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="card-title">Total Users</h6>
                        <h2 class="mb-0"><?php echo $userCount; ?></h2>
                    </div>
                    <i class="fas fa-users fa-3x opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-4 mb-4">
        <div class="card bg-warning text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="card-title">Security Reports</h6>
                        <h2 class="mb-0"><?php echo $securityReports; ?></h2>
                    </div>
                    <i class="fas fa-shield-alt fa-3x opacity-50"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header">
                <h5>Technology Status</h5>
            </div>
            <div class="card-body">
                <canvas id="techStatusChart"></canvas>
            </div>
        </div>
    </div>
    
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header">
                <h5>User Roles Distribution</h5>
            </div>
            <div class="card-body">
                <canvas id="userRolesChart"></canvas>
            </div>
        </div>
    </div>
</div>

<script>
// Technology Status Chart
const techCtx = document.getElementById('techStatusChart').getContext('2d');
new Chart(techCtx, {
    type: 'pie',
    data: {
        labels: <?php echo json_encode(array_keys($techStatusData)); ?>,
        datasets: [{
            data: <?php echo json_encode(array_values($techStatusData)); ?>,
            backgroundColor: ['#28a745', '#ffc107', '#dc3545']
        }]
    }
});

// User Roles Chart
const rolesCtx = document.getElementById('userRolesChart').getContext('2d');
new Chart(rolesCtx, {
    type: 'bar',
    data: {
        labels: <?php echo json_encode(array_keys($userRolesData)); ?>,
        datasets: [{
            label: 'Number of Users',
            data: <?php echo json_encode(array_values($userRolesData)); ?>,
            backgroundColor: '#0d6efd'
        }]
    },
    options: {
        responsive: true,
        scales: {
            y: {
                beginAtZero: true
            }
        }
    }
});
</script>

<?php
include '../../includes/footer.php';
?>