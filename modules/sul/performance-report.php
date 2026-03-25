<?php
require_once '../../includes/auth.php';
requireRole('sul');

$conn = getConnection();
$user = getCurrentUser();

// Get date filters
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d', strtotime('-30 days'));
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$reportType = isset($_GET['report_type']) ? $_GET['report_type'] : 'team';

// Get team performance metrics
$teamMetrics = $conn->query("
    SELECT 
        COUNT(DISTINCT u.id) as total_members,
        COUNT(DISTINCT t.id) as total_tasks,
        SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) as completed_tasks,
        SUM(CASE WHEN t.status != 'completed' THEN 1 ELSE 0 END) as active_tasks,
        SUM(CASE WHEN t.status != 'completed' AND t.deadline < NOW() THEN 1 ELSE 0 END) as overdue_tasks,
        AVG(CASE WHEN t.status = 'completed' AND t.completed_at IS NOT NULL 
            THEN TIMESTAMPDIFF(DAY, t.created_at, t.completed_at) 
            ELSE NULL END) as avg_completion_days,
        SUM(CASE WHEN t.priority = 'high' AND t.status != 'completed' THEN 1 ELSE 0 END) as high_priority_active,
        SUM(CASE WHEN t.priority = 'critical' AND t.status != 'completed' THEN 1 ELSE 0 END) as critical_active
    FROM users u
    LEFT JOIN tasks t ON u.id = t.assigned_to
    WHERE u.role IN ('expert', 'developer') AND u.status = 'active'
    AND DATE(t.created_at) BETWEEN '$dateFrom' AND '$dateTo'
");

$metrics = $teamMetrics->fetch_assoc();

// Get individual performance
$individualPerformance = $conn->query("
    SELECT 
        u.id,
        u.full_name,
        u.role,
        COUNT(t.id) as total_tasks,
        SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) as completed_tasks,
        SUM(CASE WHEN t.status != 'completed' THEN 1 ELSE 0 END) as active_tasks,
        SUM(CASE WHEN t.status != 'completed' AND t.deadline < NOW() THEN 1 ELSE 0 END) as overdue_tasks,
        ROUND(AVG(CASE WHEN t.status = 'completed' AND t.completed_at IS NOT NULL 
            THEN TIMESTAMPDIFF(DAY, t.created_at, t.completed_at) 
            ELSE NULL END), 1) as avg_completion_days,
        ROUND((SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) / NULLIF(COUNT(t.id), 0)) * 100, 1) as completion_rate,
        SUM(CASE WHEN t.priority = 'high' AND t.status = 'completed' THEN 1 ELSE 0 END) as high_priority_completed,
        SUM(CASE WHEN t.priority = 'critical' AND t.status = 'completed' THEN 1 ELSE 0 END) as critical_completed
    FROM users u
    LEFT JOIN tasks t ON u.id = t.assigned_to
    WHERE u.role IN ('expert', 'developer') AND u.status = 'active'
    AND DATE(t.created_at) BETWEEN '$dateFrom' AND '$dateTo'
    GROUP BY u.id
    ORDER BY completion_rate DESC, completed_tasks DESC
");

// Get weekly performance trends
$weeklyTrends = $conn->query("
    SELECT 
        DATE_FORMAT(t.created_at, '%Y-%m-%d') as date,
        COUNT(t.id) as tasks_created,
        SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) as tasks_completed,
        ROUND(AVG(CASE WHEN t.status = 'completed' AND t.completed_at IS NOT NULL 
            THEN TIMESTAMPDIFF(HOUR, t.created_at, t.completed_at) / 24 
            ELSE NULL END), 1) as avg_completion_days
    FROM tasks t
    WHERE DATE(t.created_at) BETWEEN '$dateFrom' AND '$dateTo'
    GROUP BY DATE(t.created_at)
    ORDER BY date ASC
");

// Get performance by priority
$priorityPerformance = $conn->query("
    SELECT 
        priority,
        COUNT(*) as total_tasks,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        ROUND(AVG(CASE WHEN status = 'completed' AND completed_at IS NOT NULL 
            THEN TIMESTAMPDIFF(DAY, created_at, completed_at) 
            ELSE NULL END), 1) as avg_completion_days
    FROM tasks
    WHERE DATE(created_at) BETWEEN '$dateFrom' AND '$dateTo'
    GROUP BY priority
");

// Get project performance
$projectPerformance = $conn->query("
    SELECT 
        p.name,
        p.status,
        COUNT(t.id) as total_tasks,
        SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) as completed_tasks,
        ROUND((SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) / NULLIF(COUNT(t.id), 0)) * 100, 1) as completion_rate,
        DATEDIFF(p.timeline_end, NOW()) as days_remaining
    FROM projects p
    LEFT JOIN tasks t ON p.id = t.project_id
    WHERE p.status != 'completed'
    GROUP BY p.id
    ORDER BY completion_rate ASC
    LIMIT 5
");

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<div class="row">
    <div class="col-12 mb-4">
        <h2>Performance Report</h2>
        <p class="text-muted">Monitor team performance, task completion rates, and productivity metrics</p>
    </div>
</div>

<!-- Date Filter -->
<div class="row mb-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Date From</label>
                        <input type="date" name="date_from" class="form-control" value="<?php echo $dateFrom; ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Date To</label>
                        <input type="date" name="date_to" class="form-control" value="<?php echo $dateTo; ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Report Type</label>
                        <select name="report_type" class="form-select">
                            <option value="team" <?php echo $reportType == 'team' ? 'selected' : ''; ?>>Team Performance</option>
                            <option value="individual" <?php echo $reportType == 'individual' ? 'selected' : ''; ?>>Individual Performance</option>
                            <option value="project" <?php echo $reportType == 'project' ? 'selected' : ''; ?>>Project Performance</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-chart-line"></i> Generate Report
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Key Metrics Cards -->
<div class="row">
    <div class="col-md-3 mb-4">
        <div class="stat-card" style="background: linear-gradient(135deg, #0d6efd 0%, #0b5ed7 100%);">
            <p>Completion Rate</p>
            <h3><?php 
                $completionRate = $metrics['total_tasks'] > 0 ? round(($metrics['completed_tasks'] / $metrics['total_tasks']) * 100) : 0;
                echo $completionRate; 
            ?>%</h3>
            <small><?php echo $metrics['completed_tasks']; ?> / <?php echo $metrics['total_tasks']; ?> tasks completed</small>
        </div>
    </div>
    <div class="col-md-3 mb-4">
        <div class="stat-card" style="background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%);">
            <p>Avg Completion Time</p>
            <h3><?php echo round($metrics['avg_completion_days'] ?? 0, 1); ?></h3>
            <small>days per task</small>
        </div>
    </div>
    <div class="col-md-3 mb-4">
        <div class="stat-card" style="background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%);">
            <p>Active Tasks</p>
            <h3><?php echo $metrics['active_tasks']; ?></h3>
            <small><?php echo $metrics['high_priority_active']; ?> high priority</small>
        </div>
    </div>
    <div class="col-md-3 mb-4">
        <div class="stat-card" style="background: linear-gradient(135deg, #dc3545 0%, #bb2d3b 100%);">
            <p>Overdue Tasks</p>
            <h3><?php echo $metrics['overdue_tasks']; ?></h3>
            <small><?php echo $metrics['critical_active']; ?> critical</small>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-8 mb-4">
        <div class="card">
            <div class="card-header">
                <h5>Weekly Performance Trends</h5>
            </div>
            <div class="card-body">
                <canvas id="trendsChart" height="300"></canvas>
            </div>
        </div>
    </div>
    
    <div class="col-md-4 mb-4">
        <div class="card">
            <div class="card-header">
                <h5>Performance by Priority</h5>
            </div>
            <div class="card-body">
                <canvas id="priorityChart" height="250"></canvas>
                <div class="mt-3">
                    <?php 
                    $priorityPerformance->data_seek(0);
                    while($priority = $priorityPerformance->fetch_assoc()): 
                    ?>
                    <div class="mb-2">
                        <div class="d-flex justify-content-between">
                            <span><strong><?php echo ucfirst($priority['priority']); ?></strong></span>
                            <span><?php echo $priority['completed']; ?>/<?php echo $priority['total_tasks']; ?></span>
                        </div>
                        <div class="progress">
                            <div class="progress-bar bg-<?php 
                                echo $priority['priority'] == 'critical' ? 'danger' : 
                                    ($priority['priority'] == 'high' ? 'warning' : 
                                    ($priority['priority'] == 'medium' ? 'info' : 'success'));
                            ?>" style="width: <?php echo $priority['total_tasks'] > 0 ? ($priority['completed'] / $priority['total_tasks']) * 100 : 0; ?>%">
                            </div>
                        </div>
                        <small>Avg: <?php echo $priority['avg_completion_days'] ?? 'N/A'; ?> days</small>
                    </div>
                    <?php endwhile; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Individual Performance Table -->
<div class="row">
    <div class="col-12 mb-4">
        <div class="card">
            <div class="card-header">
                <h5>Individual Performance Report</h5>
                <div class="mt-2">
                    <button class="btn btn-sm btn-success" onclick="exportReport()">
                        <i class="fas fa-download"></i> Export to CSV
                    </button>
                    <button class="btn btn-sm btn-info" onclick="printReport()">
                        <i class="fas fa-print"></i> Print Report
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="performanceTable">
                        <thead>
                            32
                                <th>Resource</th>
                                <th>Role</th>
                                <th>Total Tasks</th>
                                <th>Completed</th>
                                <th>Completion Rate</th>
                                <th>Active</th>
                                <th>Overdue</th>
                                <th>Avg Days</th>
                                <th>High Priority</th>
                                <th>Critical</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($member = $individualPerformance->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($member['full_name']); ?></strong>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $member['role'] == 'expert' ? 'warning' : 'info'; ?>">
                                        <?php echo strtoupper($member['role']); ?>
                                    </span>
                                </td>
                                <td><?php echo $member['total_tasks']; ?></td>
                                <td><?php echo $member['completed_tasks']; ?></td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="progress flex-grow-1 me-2" style="height: 20px;">
                                            <div class="progress-bar bg-<?php 
                                                echo $member['completion_rate'] >= 80 ? 'success' : 
                                                    ($member['completion_rate'] >= 50 ? 'warning' : 'danger');
                                            ?>" style="width: <?php echo $member['completion_rate']; ?>%">
                                                <?php echo $member['completion_rate']; ?>%
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td><?php echo $member['active_tasks']; ?></td>
                                <td>
                                    <?php if($member['overdue_tasks'] > 0): ?>
                                        <span class="badge bg-danger"><?php echo $member['overdue_tasks']; ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">0</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $member['avg_completion_days'] ?? 'N/A'; ?></td>
                                <td><?php echo $member['high_priority_completed']; ?></td>
                                <td><?php echo $member['critical_completed']; ?></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Project Performance (if report type is project) -->
<?php if($reportType == 'project'): ?>
<div class="row">
    <div class="col-12 mb-4">
        <div class="card">
            <div class="card-header">
                <h5>Project Performance - At Risk Projects</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Project</th>
                                <th>Status</th>
                                <th>Total Tasks</th>
                                <th>Completed</th>
                                <th>Completion Rate</th>
                                <th>Days Remaining</th>
                                <th>Risk Level</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($project = $projectPerformance->fetch_assoc()): 
                                $riskLevel = '';
                                $riskColor = '';
                                if($project['completion_rate'] < 30 && $project['days_remaining'] < 30) {
                                    $riskLevel = 'Critical';
                                    $riskColor = 'danger';
                                } elseif($project['completion_rate'] < 60 && $project['days_remaining'] < 60) {
                                    $riskLevel = 'High';
                                    $riskColor = 'warning';
                                } elseif($project['completion_rate'] < 80) {
                                    $riskLevel = 'Medium';
                                    $riskColor = 'info';
                                } else {
                                    $riskLevel = 'Low';
                                    $riskColor = 'success';
                                }
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($project['name']); ?></strong></td>
                                <td>
                                    <span class="badge bg-<?php echo $project['status'] == 'in_progress' ? 'info' : 'warning'; ?>">
                                        <?php echo ucfirst($project['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo $project['total_tasks']; ?></td>
                                <td><?php echo $project['completed_tasks']; ?></td>
                                <td>
                                    <div class="progress" style="height: 20px;">
                                        <div class="progress-bar bg-<?php echo $riskColor; ?>" style="width: <?php echo $project['completion_rate']; ?>%">
                                            <?php echo $project['completion_rate']; ?>%
                                        </div>
                                    </div>
                                </td>
                                <td><?php echo max(0, $project['days_remaining']); ?> days</td>
                                <td>
                                    <span class="badge bg-<?php echo $riskColor; ?>">
                                        <?php echo $riskLevel; ?> Risk
                                    </span>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
// Trends Chart Data
const trendsData = <?php 
    $dates = [];
    $created = [];
    $completed = [];
    $avgDays = [];
    while($row = $weeklyTrends->fetch_assoc()) {
        $dates[] = date('M d', strtotime($row['date']));
        $created[] = $row['tasks_created'];
        $completed[] = $row['tasks_completed'];
        $avgDays[] = $row['avg_completion_days'] ?? 0;
    }
    echo json_encode(['dates' => $dates, 'created' => $created, 'completed' => $completed, 'avgDays' => $avgDays]);
?>;

const trendsCtx = document.getElementById('trendsChart').getContext('2d');
new Chart(trendsCtx, {
    type: 'line',
    data: {
        labels: trendsData.dates,
        datasets: [
            {
                label: 'Tasks Created',
                data: trendsData.created,
                borderColor: '#0d6efd',
                backgroundColor: 'rgba(13, 110, 253, 0.1)',
                fill: true,
                tension: 0.4
            },
            {
                label: 'Tasks Completed',
                data: trendsData.completed,
                borderColor: '#28a745',
                backgroundColor: 'rgba(40, 167, 69, 0.1)',
                fill: true,
                tension: 0.4
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            tooltip: {
                mode: 'index',
                intersect: false
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                title: {
                    display: true,
                    text: 'Number of Tasks'
                }
            }
        }
    }
});

// Priority Performance Chart
const priorityData = <?php 
    $priorities = [];
    $completionRates = [];
    $priorityPerformance->data_seek(0);
    while($row = $priorityPerformance->fetch_assoc()) {
        $priorities[] = ucfirst($row['priority']);
        $completionRates[] = $row['total_tasks'] > 0 ? round(($row['completed'] / $row['total_tasks']) * 100) : 0;
    }
    echo json_encode(['priorities' => $priorities, 'rates' => $completionRates]);
?>;

const priorityCtx = document.getElementById('priorityChart').getContext('2d');
new Chart(priorityCtx, {
    type: 'bar',
    data: {
        labels: priorityData.priorities,
        datasets: [{
            label: 'Completion Rate (%)',
            data: priorityData.rates,
            backgroundColor: ['#dc3545', '#ffc107', '#0d6efd', '#28a745'],
            borderRadius: 4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        scales: {
            y: {
                min: 0,
                max: 100,
                title: {
                    display: true,
                    text: 'Completion Rate (%)'
                }
            }
        },
        plugins: {
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return `Completion Rate: ${context.raw}%`;
                    }
                }
            }
        }
    }
});

function exportReport() {
    const table = document.getElementById('performanceTable');
    let csv = [];
    
    // Get headers
    let headers = [];
    table.querySelectorAll('thead th').forEach(th => {
        headers.push(th.textContent.trim());
    });
    csv.push(headers.join(','));
    
    // Get rows
    table.querySelectorAll('tbody tr').forEach(row => {
        let rowData = [];
        row.querySelectorAll('td').forEach(td => {
            // Handle progress bar content
            const progressText = td.querySelector('.progress-bar');
            if(progressText) {
                rowData.push('"' + progressText.textContent.trim() + '"');
            } else {
                rowData.push('"' + td.textContent.trim().replace(/"/g, '""') + '"');
            }
        });
        csv.push(rowData.join(','));
    });
    
    // Download
    const blob = new Blob([csv.join('\n')], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `performance_report_${new Date().toISOString().slice(0,19)}.csv`;
    a.click();
    window.URL.revokeObjectURL(url);
}

function printReport() {
    const printContent = document.getElementById('performanceTable').outerHTML;
    const originalContents = document.body.innerHTML;
    document.body.innerHTML = `
        <div class="container mt-4">
            <h2>Performance Report</h2>
            <p>Generated on: ${new Date().toLocaleString()}</p>
            <p>Period: ${document.querySelector('input[name="date_from"]').value} to ${document.querySelector('input[name="date_to"]').value}</p>
            ${printContent}
        </div>
    `;
    window.print();
    document.body.innerHTML = originalContents;
    location.reload();
}
</script>

<?php include '../../includes/footer.php'; ?>