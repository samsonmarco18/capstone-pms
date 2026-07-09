<?php
require_once '../../includes/auth.php';
require_once '../../includes/password-reset.php';
require_once '../../includes/project-delay-insights.php';
requireRole('admin');

$user = getCurrentUser();
$conn = getConnection();

// Get statistics
$techCount = $conn->query("SELECT COUNT(*) as count FROM technologies")->fetch_assoc()['count'];
$userCount = $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'];
$securityReports = $conn->query("SELECT COUNT(*) as count FROM audit_logs WHERE action LIKE '%security%'")->fetch_assoc()['count'];
$passwordResetCounts = getPasswordResetRequestCounts();
$passwordResetPending = (int) ($passwordResetCounts['pending'] ?? 0);

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

<style>
:root {
    --theme-primary: #1f6392;
    --theme-dark: #184f73;
    --theme-light: #3a7ca3;
    --dashboard-surface: #ffffff;
    --dashboard-surface-alt: #f8fbff;
    --dashboard-border: #e4edf5;
    --dashboard-heading: #1f6392;
    --dashboard-text: #212529;
    --dashboard-muted: #6c757d;
    --dashboard-chart-grid: rgba(31, 99, 146, 0.08);
    --dashboard-chart-axis: #36566f;
    --dashboard-shadow: 0 10px 28px rgba(15, 74, 110, 0.08);
}

html[data-theme="dark"] {
    --dashboard-surface: rgba(10, 22, 35, 0.88);
    --dashboard-surface-alt: rgba(12, 31, 49, 0.94);
    --dashboard-border: rgba(138, 210, 255, 0.10);
    --dashboard-heading: #d8e6f3;
    --dashboard-text: #d8e6f3;
    --dashboard-muted: #90aeca;
    --dashboard-chart-grid: rgba(144, 174, 202, 0.14);
    --dashboard-chart-axis: #b4cde0;
    --dashboard-shadow: 0 14px 32px rgba(0, 0, 0, 0.32);
}

html:not([data-theme="dark"]) body,
html:not([data-theme="dark"]) #page-content-wrapper {
    background: #ffffff;
}

.card {
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.card:hover {
    transform: translateY(-2px);
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.08) !important;
}

.bg-opacity-10 {
    --bs-bg-opacity: 0.1;
}

.text-primary-custom {
    color: var(--theme-primary) !important;
}

.bg-primary-custom {
    background-color: var(--theme-primary) !important;
}

.btn-primary-custom {
    background-color: var(--theme-primary);
    border-color: var(--theme-primary);
}

.btn-primary-custom:hover {
    background-color: var(--theme-dark);
    border-color: var(--theme-dark);
}

.admin-overview-badge {
    background-color: var(--theme-primary) !important;
    color: #fff !important;
}

.dashboard-mini-stat {
    flex: 1;
    background: var(--dashboard-surface) !important;
    border: 1px solid var(--dashboard-border) !important;
    box-shadow: var(--dashboard-shadow);
}

.dashboard-mini-stat .text-muted {
    color: var(--dashboard-muted) !important;
}

.dashboard-mini-stat-value {
    color: var(--theme-primary) !important;
}

.dashboard-chart-card .card-header {
    background: var(--dashboard-surface) !important;
    border-bottom: 1px solid var(--dashboard-border) !important;
}

.dashboard-chart-title {
    color: var(--dashboard-heading) !important;
}
</style>

<div class="container-fluid px-4 py-3">
    <!-- Welcome Section -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <div class="row align-items-center">
                        <div class="col-md-7 mb-3 mb-md-0">
                            <span class="badge admin-overview-badge mb-2 px-3 py-2">Administration Overview</span>
                            <h1 class="h3 mb-1">Welcome back, <?php echo htmlspecialchars($user['full_name']); ?>!</h1>
                            <p class="text-muted mb-0 small">Track technologies, user activity, and governance signals from one clean control center.</p>
                        </div>
                        <div class="col-md-5">
                            <div class="d-flex gap-2 justify-content-md-end">
                                <div class="card dashboard-mini-stat">
                                    <div class="card-body p-3">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <div class="text-muted small text-uppercase fw-semibold">Password Requests</div>
                                            <i class="fas fa-heartbeat" style="font-size: 20px; color: var(--theme-primary); opacity: 0.65;"></i>
                                        </div>
                                        <div class="fw-bold fs-2 dashboard-mini-stat-value"><?php echo $passwordResetPending; ?></div>
                                    </div>
                                </div>
                                <div class="card dashboard-mini-stat">
                                    <div class="card-body p-3">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <div class="text-muted small text-uppercase fw-semibold">Active Services</div>
                                            <i class="fas fa-microchip" style="font-size: 20px; color: var(--theme-primary); opacity: 0.65;"></i>
                                        </div>
                                        <div class="fw-bold fs-4 dashboard-mini-stat-value"><?php echo $techCount; ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-xl-4 col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="flex-grow-1">
                            <div class="text-muted text-uppercase small fw-semibold mb-2">Technologies / Services</div>
                            <div class="h1 fw-bold mb-2 dashboard-mini-stat-value"><?php echo $techCount; ?></div>
                            <small class="text-muted">Azure-style catalog and renewals</small>
                        </div>
                        <div class="ms-3">
                            <i class="fas fa-microchip" style="font-size: 2.5rem; color: var(--theme-primary); opacity: 0.65;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-4 col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="flex-grow-1">
                            <div class="text-muted text-uppercase small fw-semibold mb-2">Total Users</div>
                            <div class="h1 fw-bold mb-2 dashboard-mini-stat-value"><?php echo $userCount; ?></div>
                            <small class="text-muted">Role-based access across six departments</small>
                        </div>
                        <div class="ms-3">
                            <i class="fas fa-users" style="font-size: 2.5rem; color: var(--theme-primary); opacity: 0.65;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-xl-4 col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="flex-grow-1">
                            <div class="text-muted text-uppercase small fw-semibold mb-2">Security Reports</div>
                            <div class="h1 fw-bold mb-2 dashboard-mini-stat-value"><?php echo $securityReports; ?></div>
                            <small class="text-muted">Audit-backed monitoring and recovery</small>
                        </div>
                        <div class="ms-3">
                            <i class="fas fa-shield-alt" style="font-size: 2.5rem; color: var(--theme-primary); opacity: 0.65;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php
    delayInsightsRenderCompactList($conn, $user, [
        'limit' => 4,
        'title' => 'Project Insight List',
        'subtitle' => 'Small one-line project signals with direct links to the full insight page.',
        'button_label' => 'View Project',
    ]);
    ?>

    <!-- Charts Section -->
    <div class="row g-3">
        <div class="col-xl-6">
            <div class="card border-0 shadow-sm h-100 dashboard-chart-card">
                <div class="card-header border-0 pt-4 px-4">
                    <h5 class="mb-0 fw-semibold dashboard-chart-title">Technology Status</h5>
                </div>
                <div class="card-body p-4 pt-2">
                    <canvas id="techStatusChart" style="max-height: 300px; width: 100%;"></canvas>
                </div>
            </div>
        </div>
        
        <div class="col-xl-6">
            <div class="card border-0 shadow-sm h-100 dashboard-chart-card">
                <div class="card-header border-0 pt-4 px-4">
                    <h5 class="mb-0 fw-semibold dashboard-chart-title">User Roles Distribution</h5>
                </div>
                <div class="card-body p-4 pt-2">
                    <canvas id="userRolesChart" style="max-height: 300px; width: 100%;"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const rootStyles = () => getComputedStyle(document.documentElement);
    const getChartTheme = () => {
        const styles = rootStyles();
        return {
            axis: styles.getPropertyValue('--dashboard-chart-axis').trim() || '#36566f',
            grid: styles.getPropertyValue('--dashboard-chart-grid').trim() || 'rgba(31, 99, 146, 0.08)',
            legend: styles.getPropertyValue('--dashboard-chart-axis').trim() || '#36566f'
        };
    };

    let techStatusChart = null;
    let userRolesChart = null;

    function destroyCharts() {
        if (techStatusChart) {
            techStatusChart.destroy();
            techStatusChart = null;
        }

        if (userRolesChart) {
            userRolesChart.destroy();
            userRolesChart = null;
        }
    }

    function renderCharts() {
        destroyCharts();

        const techCtx = document.getElementById('techStatusChart').getContext('2d');
        const chartTheme = getChartTheme();

        // Color palette based on #1f6392
    const themePalette = [
        '#1f6392',  // Primary theme color
        '#3a7ca3',  // Lighter shade
        '#0f4a6e',  // Darker shade
        '#5b8fb2',  // Even lighter
        '#0a3a55',  // Even darker
        '#74a0c0',  // Very light
        '#06273a',  // Very dark
        '#8db1cd'   // Lightest
    ];
    
    // Get status labels and values
    const statusLabels = <?php echo json_encode(array_keys($techStatusData)); ?>;
    const statusValues = <?php echo json_encode(array_values($techStatusData)); ?>;
    
    // Generate shades based on number of statuses
    const chartColors = statusLabels.map((label, index) => {
        return themePalette[index % themePalette.length];
    });
    
        techStatusChart = new Chart(techCtx, {
        type: 'pie',
        data: {
            labels: statusLabels,
            datasets: [{
                data: statusValues,
                backgroundColor: chartColors,
                borderWidth: 0,
                hoverOffset: 10
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        usePointStyle: true,
                        padding: 15,
                        color: chartTheme.legend,
                        font: {
                            size: 12,
                            weight: '500'
                        },
                        generateLabels: function(chart) {
                            const data = chart.data;
                            if (data.labels.length && data.datasets.length) {
                                return data.labels.map((label, i) => {
                                    const value = data.datasets[0].data[i];
                                    const total = data.datasets[0].data.reduce((a, b) => a + b, 0);
                                    const percentage = ((value / total) * 100).toFixed(1);
                                    return {
                                        text: `${label} (${percentage}%)`,
                                        fillStyle: data.datasets[0].backgroundColor[i],
                                        hidden: false,
                                        index: i
                                    };
                                });
                            }
                            return [];
                        }
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const label = context.label || '';
                            const value = context.raw || 0;
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = ((value / total) * 100).toFixed(1);
                            return `${label}: ${value} (${percentage}%)`;
                        }
                    }
                }
            }
        }
    });

        const rolesCtx = document.getElementById('userRolesChart').getContext('2d');
    const roleThemeColors = [
        '#1f6392', '#3a7ca3', '#0f4a6e', '#5b8fb2', '#0a3a55', '#74a0c0'
    ];
    
        userRolesChart = new Chart(rolesCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_keys($userRolesData)); ?>,
            datasets: [{
                label: 'Number of Users',
                data: <?php echo json_encode(array_values($userRolesData)); ?>,
                backgroundColor: roleThemeColors.slice(0, <?php echo count($userRolesData); ?>),
                borderRadius: 8,
                barPercentage: 0.65,
                categoryPercentage: 0.8,
                hoverBackgroundColor: function(context) {
                    return context.dataset.backgroundColor[context.dataIndex];
                }
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return `Users: ${context.raw}`;
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        color: chartTheme.grid,
                        drawBorder: false
                    },
                    ticks: {
                        stepSize: 1,
                        color: chartTheme.axis,
                        font: {
                            size: 11
                        },
                        callback: function(value) {
                            return value;
                        }
                    },
                    title: {
                        display: true,
                        text: 'Number of Users',
                        color: chartTheme.axis,
                        font: {
                            size: 12,
                            weight: '500'
                        }
                    }
                },
                x: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        color: chartTheme.axis,
                        font: {
                            size: 12,
                            weight: '500'
                        }
                    },
                    title: {
                        display: true,
                        text: 'User Roles',
                        color: chartTheme.axis,
                        font: {
                            size: 12,
                            weight: '500'
                        }
                    }
                }
            }
        }
    });
    }

    renderCharts();

    const themeObserver = new MutationObserver(function(mutations) {
        if (mutations.some(function(mutation) {
            return mutation.type === 'attributes' && mutation.attributeName === 'data-theme';
        })) {
            renderCharts();
        }
    });

    themeObserver.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['data-theme']
    });
});
</script>

<?php
include '../../includes/footer.php';
?>
