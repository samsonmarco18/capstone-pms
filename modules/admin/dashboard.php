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

<div class="row g-4">
    <div class="col-12">
        <div class="dashboard-hero">
            <div>
                <span class="hero-badge">Administration Overview</span>
                <h1 class="dashboard-title">Welcome back, <?php echo htmlspecialchars($user['full_name']); ?>!</h1>
                <p class="dashboard-subtitle">Track technologies, user activity, and governance signals from one clean control center.</p>
            </div>
            <div class="hero-mini-grid">
                <div class="hero-mini-card">
                    <span>Platform Health</span>
                    <strong><?php echo $securityReports === 0 ? 'Stable' : 'Attention Needed'; ?></strong>
                </div>
                <div class="hero-mini-card">
                    <span>Active Services</span>
                    <strong><?php echo $techCount; ?></strong>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-1">
    <div class="col-xl-4 col-md-6">
        <div class="stat-card stat-card-tech">
            <div>
                <div class="stat-label">Technologies / Services</div>
                <div class="stat-value"><?php echo $techCount; ?></div>
                <small>Azure-style catalog and renewals</small>
            </div>
            <div class="stat-icon">
                <i class="fas fa-microchip"></i>
            </div>
        </div>
    </div>
    
    <div class="col-xl-4 col-md-6">
        <div class="stat-card stat-card-users">
            <div>
                <div class="stat-label">Total Users</div>
                <div class="stat-value"><?php echo $userCount; ?></div>
                <small>Role-based access across six departments</small>
            </div>
            <div class="stat-icon">
                <i class="fas fa-users"></i>
            </div>
        </div>
    </div>
    
    <div class="col-xl-4 col-md-6">
        <div class="stat-card stat-card-security">
            <div>
                <div class="stat-label">Security Reports</div>
                <div class="stat-value"><?php echo $securityReports; ?></div>
                <small>Audit-backed monitoring and recovery</small>
            </div>
            <div class="stat-icon">
                <i class="fas fa-shield-alt"></i>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-xl-6">
        <div class="card chart-card">
            <div class="card-header">
                <h5>Technology Status</h5>
            </div>
            <div class="card-body">
                <canvas id="techStatusChart" height="260"></canvas>
            </div>
        </div>
    </div>
    
    <div class="col-xl-6">
        <div class="card chart-card">
            <div class="card-header">
                <h5>User Roles Distribution</h5>
            </div>
            <div class="card-body">
                <canvas id="userRolesChart" height="260"></canvas>
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
            backgroundColor: ['#2f80ed', '#56ccf2', '#93c5fd', '#1f4a7c', '#dbeafe'],
            borderWidth: 0
        }]
    },
    options: {
        plugins: {
            legend: {
                position: 'top',
                labels: {
                    usePointStyle: true,
                    padding: 18
                }
            }
        }
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
            backgroundColor: ['#0f4c81', '#1d70b8', '#3aa0db', '#86c5f4', '#5b88c4', '#224c78'],
            borderRadius: 12,
            borderSkipped: false
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: {
                display: false
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                grid: {
                    color: 'rgba(15, 76, 129, 0.08)'
                }
            },
            x: {
                grid: {
                    display: false
                }
            }
        }
    }
});
</script>

<?php
include '../../includes/footer.php';
?>
