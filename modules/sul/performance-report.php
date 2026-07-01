<?php
require_once '../../includes/auth.php';
requireRole('sul');

$conn = getConnection();
$user = getCurrentUser();

// Get date filters
$dateFrom = isset($_GET['date_from']) ? (string) $_GET['date_from'] : date('Y-m-d', strtotime('-30 days'));
$dateTo = isset($_GET['date_to']) ? (string) $_GET['date_to'] : date('Y-m-d');
$reportType = isset($_GET['report_type']) ? (string) $_GET['report_type'] : 'team';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !strtotime($dateFrom)) {
    $dateFrom = date('Y-m-d', strtotime('-30 days'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) || !strtotime($dateTo)) {
    $dateTo = date('Y-m-d');
}
if ($dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}
if (!in_array($reportType, ['team', 'individual', 'project'], true)) {
    $reportType = 'team';
}

$reportLabels = [
    'team' => 'Team Performance',
    'individual' => 'Individual Performance',
    'project' => 'Project Performance',
];
$reportTitle = $reportLabels[$reportType] ?? $reportLabels['team'];
$reportContextMessage = [
    'team' => 'Quick view of overall workload, completion pace, and overdue items for the selected period.',
    'individual' => 'Compare each team member by task load, completion rate, and overdue work for the selected period.',
    'project' => 'Focus on project completion and risk so you can spot delays early in the selected period.',
][$reportType] ?? 'Performance snapshot for the selected period.';
$reportDateRangeLabel = date('M d, Y', strtotime($dateFrom)) . ' to ' . date('M d, Y', strtotime($dateTo));

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
    LEFT JOIN tasks t ON u.id = t.assigned_to AND DATE(t.created_at) BETWEEN '$dateFrom' AND '$dateTo'
    WHERE u.role IN ('expert', 'developer') AND u.status = 'active'
");

$metrics = $teamMetrics->fetch_assoc() ?: [];
$metrics = array_merge([
    'total_members' => 0,
    'total_tasks' => 0,
    'completed_tasks' => 0,
    'active_tasks' => 0,
    'overdue_tasks' => 0,
    'avg_completion_days' => 0,
    'high_priority_active' => 0,
    'critical_active' => 0,
], $metrics);

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
        COALESCE(ROUND((SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) / NULLIF(COUNT(t.id), 0)) * 100, 1), 0) as completion_rate,
        SUM(CASE WHEN t.priority = 'high' AND t.status = 'completed' THEN 1 ELSE 0 END) as high_priority_completed,
        SUM(CASE WHEN t.priority = 'critical' AND t.status = 'completed' THEN 1 ELSE 0 END) as critical_completed
    FROM users u
    LEFT JOIN tasks t ON u.id = t.assigned_to AND DATE(t.created_at) BETWEEN '$dateFrom' AND '$dateTo'
    WHERE u.role IN ('expert', 'developer') AND u.status = 'active'
    GROUP BY u.id, u.full_name, u.role
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
        COALESCE(ROUND((SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) / NULLIF(COUNT(t.id), 0)) * 100, 1), 0) as completion_rate,
        DATEDIFF(p.timeline_end, NOW()) as days_remaining
    FROM projects p
    LEFT JOIN tasks t ON p.id = t.project_id AND DATE(t.created_at) BETWEEN '$dateFrom' AND '$dateTo'
    WHERE p.status != 'completed'
    GROUP BY p.id, p.name, p.status, p.timeline_end
    ORDER BY completion_rate ASC
    LIMIT 5
");

if (isset($_GET['download'])) {
    $safeReportType = preg_replace('/[^a-z0-9_-]/i', '', $reportType);
    $filename = $safeReportType . '_performance_report_' . $dateFrom . '_to_' . $dateTo . '.xls';

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $cell = static function ($value): string {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    };

    echo "\xEF\xBB\xBF";
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8">';
    echo '<style>';
    echo 'table{border-collapse:collapse;font-family:Arial,sans-serif;font-size:12px;}';
    echo 'th{background:#1f6392;color:#fff;font-weight:bold;}';
    echo 'th,td{border:1px solid #b7c9d6;padding:6px 8px;mso-number-format:"\@";}';
    echo '.title{font-size:18px;font-weight:bold;background:#eaf3f8;color:#123;}';
    echo '.section{font-weight:bold;background:#f1f5f9;}';
    echo '</style></head><body>';
    echo '<table>';
    echo '<tr><td class="title" colspan="10">' . $cell($reportTitle . ' Report') . '</td></tr>';
    echo '<tr><td class="section">Date From</td><td>' . $cell($dateFrom) . '</td></tr>';
    echo '<tr><td class="section">Date To</td><td>' . $cell($dateTo) . '</td></tr>';
    echo '<tr><td colspan="10"></td></tr>';

    if ($reportType === 'project') {
        echo '<tr>';
        foreach (['Project', 'Status', 'Total Tasks', 'Completed', 'Completion Rate', 'Days Remaining', 'Risk Level'] as $heading) {
            echo '<th>' . $cell($heading) . '</th>';
        }
        echo '</tr>';

        while ($project = $projectPerformance->fetch_assoc()) {
            $completionRateValue = (float) ($project['completion_rate'] ?? 0);
            $daysRemainingValue = (int) ($project['days_remaining'] ?? 0);
            $riskColor = $completionRateValue < 30 && $daysRemainingValue < 30 ? 'danger' : ($completionRateValue < 60 && $daysRemainingValue < 60 ? 'warning' : ($completionRateValue < 80 ? 'info' : 'success'));
            $riskLevel = $riskColor == 'danger' ? 'Critical' : ($riskColor == 'warning' ? 'High' : ($riskColor == 'info' ? 'Medium' : 'Low'));

            echo '<tr>';
            foreach ([
                $project['name'] ?? '',
                $project['status'] ?? '',
                (int) ($project['total_tasks'] ?? 0),
                (int) ($project['completed_tasks'] ?? 0),
                $completionRateValue . '%',
                max(0, $daysRemainingValue),
                $riskLevel . ' Risk',
            ] as $value) {
                echo '<td>' . $cell($value) . '</td>';
            }
            echo '</tr>';
        }
    } else {
        $completionRate = $metrics['total_tasks'] > 0 ? round(($metrics['completed_tasks'] / $metrics['total_tasks']) * 100) : 0;
        echo '<tr><td class="section" colspan="10">Summary</td></tr>';
        foreach ([
            ['Completion Rate', $completionRate . '%'],
            ['Completed Tasks', (int) $metrics['completed_tasks']],
            ['Total Tasks', (int) $metrics['total_tasks']],
            ['Average Completion Days', round((float) ($metrics['avg_completion_days'] ?? 0), 1)],
            ['Active Tasks', (int) $metrics['active_tasks']],
            ['Overdue Tasks', (int) $metrics['overdue_tasks']],
        ] as $summaryRow) {
            echo '<tr><td>' . $cell($summaryRow[0]) . '</td><td>' . $cell($summaryRow[1]) . '</td></tr>';
        }
        echo '<tr><td colspan="10"></td></tr>';
        echo '<tr>';
        foreach (['Resource', 'Role', 'Total Tasks', 'Completed', 'Completion Rate', 'Active', 'Overdue', 'Avg Days', 'High Priority', 'Critical'] as $heading) {
            echo '<th>' . $cell($heading) . '</th>';
        }
        echo '</tr>';

        while ($member = $individualPerformance->fetch_assoc()) {
            echo '<tr>';
            foreach ([
                $member['full_name'] ?? '',
                strtoupper((string) ($member['role'] ?? '')),
                (int) ($member['total_tasks'] ?? 0),
                (int) ($member['completed_tasks'] ?? 0),
                (float) ($member['completion_rate'] ?? 0) . '%',
                (int) ($member['active_tasks'] ?? 0),
                (int) ($member['overdue_tasks'] ?? 0),
                $member['avg_completion_days'] ?? 'N/A',
                (int) ($member['high_priority_completed'] ?? 0),
                (int) ($member['critical_completed'] ?? 0),
            ] as $value) {
                echo '<td>' . $cell($value) . '</td>';
            }
            echo '</tr>';
        }
    }

    echo '</table></body></html>';
    exit();
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
    --gray-50: #f8f9fa;
    --gray-100: #f1f3f5;
    --gray-200: #e9ecef;
    --gray-300: #dee2e6;
    --gray-600: #6c757d;
    --gray-900: #212529;
    --shadow-sm: 0 2px 4px rgba(0,0,0,0.02), 0 1px 2px rgba(0,0,0,0.03);
    --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.02);
}

.page-header-modern {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    border-radius: 24px;
    padding: 2rem;
    margin-bottom: 2rem;
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
    opacity: 0.9;
    margin-bottom: 0;
    font-size: 0.9rem;
    color: rgba(255, 255, 255, 0.9);
}

/* Stats Cards */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.stat-card-modern {
    background: white;
    border-radius: 20px;
    padding: 1.5rem;
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--gray-200);
    transition: all 0.2s ease;
    position: relative;
    overflow: hidden;
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
    font-size: 2.5rem;
    font-weight: 700;
    color: var(--theme-primary);
    line-height: 1;
    margin-bottom: 0.25rem;
}

.stat-card-modern .stat-sub {
    font-size: 0.7rem;
    color: var(--gray-600);
}

/* Report Message */
.report-message-card {
    border: 1px solid rgba(31, 99, 146, 0.14);
    border-radius: 18px;
    background: linear-gradient(135deg, rgba(31, 99, 146, 0.08) 0%, rgba(58, 124, 163, 0.04) 100%);
    padding: 1rem 1.25rem;
    margin-bottom: 1.25rem;
    display: flex;
    gap: 0.85rem;
    align-items: flex-start;
}

.report-message-card .report-message-icon {
    width: 42px;
    height: 42px;
    flex: 0 0 42px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(31, 99, 146, 0.12);
    color: var(--theme-primary);
    font-size: 1rem;
}

.report-message-card h6 {
    margin: 0 0 0.25rem;
    font-size: 0.98rem;
    font-weight: 700;
    color: var(--gray-800);
}

.report-message-card p {
    margin: 0;
    color: var(--gray-600);
    line-height: 1.55;
}

/* Cards */
.card {
    background: white;
    border-radius: 20px;
    border: 1px solid var(--gray-200);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.card-header {
    padding: 1.25rem 1.5rem;
    background: white;
    border-bottom: 1px solid var(--gray-200);
}

.card-header h5 {
    font-size: 1rem;
    font-weight: 600;
    color: var(--gray-900);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.card-header h5 i {
    color: var(--theme-primary);
}

.card-body {
    padding: 1.5rem;
}

/* Filter Card */
.filter-card {
    background: white;
    border-radius: 20px;
    border: 1px solid var(--gray-200);
    overflow: hidden;
    margin-bottom: 2rem;
}

.filter-card .card-body {
    padding: 1.5rem;
}

/* Table Styles */
.table-container {
    overflow-x: auto;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table thead {
    background: var(--gray-50);
}

.data-table th {
    padding: 1rem 1.25rem;
    text-align: left;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    color: var(--gray-600);
    border-bottom: 1px solid var(--gray-200);
}

.data-table td {
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--gray-200);
    vertical-align: middle;
}

.data-table tr:hover {
    background: var(--gray-50);
}

/* Progress Bar */
.progress-modern {
    height: 8px;
    background: var(--gray-200);
    border-radius: 10px;
    overflow: hidden;
}

.progress-bar-modern {
    height: 100%;
    border-radius: 10px;
    transition: width 0.3s ease;
}

/* Badges */
.badge-priority-critical, .badge-priority-high, .badge-priority-medium, .badge-priority-low {
    padding: 0.25rem 0.6rem;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 600;
}

.badge-priority-critical { background: #fee2e2; color: #dc2626; }
.badge-priority-high { background: #fef3c7; color: #f59e0b; }
.badge-priority-medium { background: #dbeafe; color: #2563eb; }
.badge-priority-low { background: #d1fae5; color: #059669; }

/* Buttons */
.btn-primary-modern {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    color: white;
    border: none;
    padding: 0.5rem 1rem;
    border-radius: 10px;
    font-size: 0.8rem;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
}

.btn-primary-modern:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(31, 99, 146, 0.3);
    color: white;
}

.btn-secondary-modern {
    background: #e9ecef;
    color: var(--gray-700);
    border: none;
    padding: 0.5rem 1rem;
    border-radius: 10px;
    font-size: 0.8rem;
    transition: all 0.2s;
}

.btn-secondary-modern:hover {
    background: var(--gray-300);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #page-content-wrapper,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .container-fluid {
    background: #06111e;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .filter-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .stat-card-modern {
    background: #071321 !important;
    border: 1px solid rgba(31, 99, 146, 0.24) !important;
    box-shadow: 0 20px 45px rgba(0, 0, 0, 0.22);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern::before {
    background: linear-gradient(90deg, transparent, rgba(31, 99, 146, 0.16));
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card-header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .data-table thead,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .data-table tr:hover {
    background: #0a1b2c !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern h2,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern h2 i,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) h6,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .data-table td,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .fw-semibold {
    color: #d7e4f2 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern p,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .stat-card-modern .stat-label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .stat-card-modern .stat-sub,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .data-table th,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .text-muted,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) small {
    color: #9db6cf !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .data-table th,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .data-table td {
    border-color: rgba(31, 99, 146, 0.24) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-control,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-select {
    background-color: #071321;
    border-color: rgba(31, 99, 146, 0.28);
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-secondary-modern {
    background: rgba(31, 99, 146, 0.14);
    color: #9db6cf;
    border: 1px solid rgba(31, 99, 146, 0.24);
}

/* Responsive */
@media (max-width: 1200px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .page-header-modern {
        padding: 1.5rem;
    }
    
    .stats-grid {
        grid-template-columns: 1fr;
        gap: 1rem;
    }
    
    .stat-card-modern .stat-number {
        font-size: 2rem;
    }

    .report-message-card {
        padding: 0.9rem 1rem;
        gap: 0.75rem;
    }

    .report-message-card .report-message-icon {
        width: 38px;
        height: 38px;
        flex-basis: 38px;
        border-radius: 10px;
    }
}
</style>

<div class="container-fluid px-4 py-4">
    <!-- Page Header -->
    <div class="page-header-modern">
        <div>
            <h2>
                <i class="fas fa-chart-line me-2"></i> 
                Performance Report
            </h2>
            <p>Monitor team performance, task completion rates, and productivity metrics</p>
        </div>
    </div>

    <div class="report-message-card">
        <div class="report-message-icon">
            <i class="fas fa-circle-info"></i>
        </div>
        <div>
            <h6><?php echo htmlspecialchars($reportTitle); ?></h6>
            <p><?php echo htmlspecialchars($reportContextMessage); ?> Date range: <?php echo htmlspecialchars($reportDateRangeLabel); ?>.</p>
        </div>
    </div>

    <!-- Date Filter -->
    <div class="filter-card">
        <div class="card-body">
            <form method="GET" action="performance-report.php" class="row g-3">
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
                    <button type="submit" name="download" value="1" class="btn-primary-modern w-100">
                        <i class="fas fa-download"></i> Generate Report
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Key Metrics Cards -->
    <div class="stats-grid">
        <div class="stat-card-modern">
            <i class="fas fa-percent stat-icon"></i>
            <div class="stat-label">Completion Rate</div>
            <div class="stat-number"><?php 
                $completionRate = $metrics['total_tasks'] > 0 ? round(($metrics['completed_tasks'] / $metrics['total_tasks']) * 100) : 0;
                echo $completionRate; 
            ?>%</div>
            <div class="stat-sub"><?php echo $metrics['completed_tasks']; ?> / <?php echo $metrics['total_tasks']; ?> tasks</div>
        </div>
        <div class="stat-card-modern">
            <i class="fas fa-clock stat-icon"></i>
            <div class="stat-label">Avg Completion Time</div>
            <div class="stat-number"><?php echo round($metrics['avg_completion_days'] ?? 0, 1); ?></div>
            <div class="stat-sub">days per task</div>
        </div>
        <div class="stat-card-modern">
            <i class="fas fa-tasks stat-icon"></i>
            <div class="stat-label">Active Tasks</div>
            <div class="stat-number"><?php echo $metrics['active_tasks']; ?></div>
            <div class="stat-sub"><?php echo $metrics['high_priority_active']; ?> high priority</div>
        </div>
        <div class="stat-card-modern">
            <i class="fas fa-exclamation-triangle stat-icon"></i>
            <div class="stat-label">Overdue Tasks</div>
            <div class="stat-number" style="color: #dc2626;"><?php echo $metrics['overdue_tasks']; ?></div>
            <div class="stat-sub"><?php echo $metrics['critical_active']; ?> critical</div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-chart-line me-2"></i>Weekly Performance Trends</h5>
                </div>
                <div class="card-body">
                    <canvas id="trendsChart" style="height: 300px; width: 100%;"></canvas>
                </div>
            </div>
        </div>
        
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-chart-bar me-2"></i>Performance by Priority</h5>
                </div>
                <div class="card-body">
                    <canvas id="priorityChart" style="height: 200px; width: 100%;"></canvas>
                    <div class="mt-3">
                        <?php 
                        $priorityPerformance->data_seek(0);
                        while($priority = $priorityPerformance->fetch_assoc()): 
                            $priorityColor = $priority['priority'] == 'critical' ? 'danger' : ($priority['priority'] == 'high' ? 'warning' : ($priority['priority'] == 'medium' ? 'info' : 'success'));
                        ?>
                        <div class="mb-2">
                            <div class="d-flex justify-content-between mb-1">
                                <span><strong><?php echo ucfirst($priority['priority']); ?></strong></span>
                                <span><?php echo $priority['completed']; ?>/<?php echo $priority['total_tasks']; ?></span>
                            </div>
                            <div class="progress-modern">
                                <div class="progress-bar-modern" style="width: <?php echo $priority['total_tasks'] > 0 ? ($priority['completed'] / $priority['total_tasks']) * 100 : 0; ?>%; background: <?php echo $priorityColor == 'danger' ? '#dc2626' : ($priorityColor == 'warning' ? '#f59e0b' : ($priorityColor == 'info' ? '#2563eb' : '#10b981')); ?>;"></div>
                            </div>
                            <small class="text-muted">Avg: <?php echo $priority['avg_completion_days'] ?? 'N/A'; ?> days</small>
                        </div>
                        <?php endwhile; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if($reportType == 'individual' || $reportType == 'team'): ?>
    <!-- Individual Performance Table -->
    <div class="card">
        <div class="card-header">
            <h5><i class="fas fa-users me-2"></i><?php echo $reportType == 'team' ? 'Team Member Breakdown' : 'Individual Performance Report'; ?></h5>
            <div class="mt-2">
                <button class="btn-secondary-modern btn-sm" onclick="exportReport('performanceTable')">
                    <i class="fas fa-download"></i> Export to Excel
                </button>
                <button class="btn-secondary-modern btn-sm ms-2" onclick="printReport('performanceTable')">
                    <i class="fas fa-print"></i> Print Report
                </button>
            </div>
        </div>
        <div class="table-container">
            <table class="data-table" id="performanceTable">
                <thead>
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
                </thead>
                <tbody>
                    <?php while($member = $individualPerformance->fetch_assoc()): ?>
                    <tr>
                        <td><div class="fw-semibold"><?php echo htmlspecialchars($member['full_name']); ?></div></td>
                        <td><span class="badge-role-<?php echo $member['role']; ?>"><?php echo strtoupper($member['role']); ?></span></td>
                        <td><?php echo $member['total_tasks']; ?></td>
                        <td><?php echo $member['completed_tasks']; ?></td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="progress-modern flex-grow-1" style="width: 80px;">
                                    <div class="progress-bar-modern" style="width: <?php echo $member['completion_rate']; ?>%; background: <?php echo $member['completion_rate'] >= 80 ? '#10b981' : ($member['completion_rate'] >= 50 ? '#f59e0b' : '#dc2626'); ?>;"></div>
                                </div>
                                <span><?php echo $member['completion_rate']; ?>%</span>
                            </div>
                        </td>
                        <td><?php echo $member['active_tasks']; ?></td>
                        <td><?php if($member['overdue_tasks'] > 0): ?><span class="badge-priority-critical"><?php echo $member['overdue_tasks']; ?></span><?php else: ?>0<?php endif; ?></td>
                        <td><?php echo $member['avg_completion_days'] ?? 'N/A'; ?></td>
                        <td><?php echo $member['high_priority_completed']; ?></td>
                        <td><?php echo $member['critical_completed']; ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Project Performance (if report type is project) -->
    <?php if($reportType == 'project'): ?>
    <div class="card mt-4">
        <div class="card-header">
            <h5><i class="fas fa-project-diagram me-2"></i>Project Performance - At Risk Projects</h5>
            <div class="mt-2">
                <button class="btn-secondary-modern btn-sm" onclick="exportReport('projectPerformanceTable')">
                    <i class="fas fa-download"></i> Export to Excel
                </button>
                <button class="btn-secondary-modern btn-sm ms-2" onclick="printReport('projectPerformanceTable')">
                    <i class="fas fa-print"></i> Print Report
                </button>
            </div>
        </div>
        <div class="table-container">
            <table class="data-table" id="projectPerformanceTable">
                <thead>
                    <th>Project</th>
                    <th>Status</th>
                    <th>Total Tasks</th>
                    <th>Completed</th>
                    <th>Completion Rate</th>
                    <th>Days Remaining</th>
                    <th>Risk Level</th>
                </thead>
                <tbody>
                    <?php if($projectPerformance->num_rows > 0): ?>
                    <?php while($project = $projectPerformance->fetch_assoc()): 
                        $riskColor = $project['completion_rate'] < 30 && $project['days_remaining'] < 30 ? 'danger' : ($project['completion_rate'] < 60 && $project['days_remaining'] < 60 ? 'warning' : ($project['completion_rate'] < 80 ? 'info' : 'success'));
                        $riskLevel = $riskColor == 'danger' ? 'Critical' : ($riskColor == 'warning' ? 'High' : ($riskColor == 'info' ? 'Medium' : 'Low'));
                    ?>
                    <tr>
                        <td><div class="fw-semibold"><?php echo htmlspecialchars($project['name']); ?></div></td>
                        <td><span class="badge-status-<?php echo $project['status']; ?>"><?php echo ucfirst($project['status']); ?></span></td>
                        <td><?php echo $project['total_tasks']; ?></td>
                        <td><?php echo $project['completed_tasks']; ?></td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="progress-modern flex-grow-1" style="width: 80px;">
                                    <div class="progress-bar-modern" style="width: <?php echo $project['completion_rate']; ?>%; background: <?php echo $riskColor == 'danger' ? '#dc2626' : ($riskColor == 'warning' ? '#f59e0b' : ($riskColor == 'info' ? '#2563eb' : '#10b981')); ?>;"></div>
                                </div>
                                <span><?php echo $project['completion_rate']; ?>%</span>
                            </div>
                        </td>
                        <td><?php echo max(0, $project['days_remaining']); ?> days</td>
                        <td><span class="badge-status-<?php echo $riskColor; ?>"><?php echo $riskLevel; ?> Risk</span></td>
                    </tr>
                    <?php endwhile; ?>
                    <?php else: ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">No active project performance data for this date range.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Trends Chart Data
const trendsData = <?php 
    $dates = [];
    $created = [];
    $completed = [];
    while($row = $weeklyTrends->fetch_assoc()) {
        $dates[] = date('M d', strtotime($row['date']));
        $created[] = $row['tasks_created'];
        $completed[] = $row['tasks_completed'];
    }
    echo json_encode(['dates' => $dates, 'created' => $created, 'completed' => $completed]);
?>;

const trendsCanvas = document.getElementById('trendsChart');
if (window.Chart && trendsCanvas) {
    const trendsCtx = trendsCanvas.getContext('2d');
    new Chart(trendsCtx, {
        type: 'line',
        data: {
            labels: trendsData.dates,
            datasets: [
                { label: 'Tasks Created', data: trendsData.created, borderColor: '#1f6392', backgroundColor: 'rgba(31, 99, 146, 0.1)', fill: true, tension: 0.4 },
                { label: 'Tasks Completed', data: trendsData.completed, borderColor: '#10b981', backgroundColor: 'rgba(16, 185, 129, 0.1)', fill: true, tension: 0.4 }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            scales: { y: { beginAtZero: true, title: { display: true, text: 'Number of Tasks' } } },
            plugins: { legend: { position: 'top' } }
        }
    });
} else if (trendsCanvas) {
    trendsCanvas.replaceWith(Object.assign(document.createElement('div'), {
        className: 'text-muted text-center py-4',
        textContent: 'Chart preview is unavailable, but the generated report table is ready below.'
    }));
}

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

const priorityCanvas = document.getElementById('priorityChart');
if (window.Chart && priorityCanvas) {
    const priorityCtx = priorityCanvas.getContext('2d');
    new Chart(priorityCtx, {
        type: 'bar',
        data: {
            labels: priorityData.priorities,
            datasets: [{ label: 'Completion Rate (%)', data: priorityData.rates, backgroundColor: ['#dc2626', '#f59e0b', '#2563eb', '#10b981'], borderRadius: 8 }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            scales: { y: { min: 0, max: 100, title: { display: true, text: 'Completion Rate (%)' } } },
            plugins: { legend: { display: false } }
        }
    });
} else if (priorityCanvas) {
    priorityCanvas.replaceWith(Object.assign(document.createElement('div'), {
        className: 'text-muted text-center py-4',
        textContent: 'Priority chart is unavailable.'
    }));
}

function exportReport(tableId) {
    const table = document.getElementById(tableId);
    if (!table) {
        alert('No report table is available to export.');
        return;
    }
    const html = `
        <html>
            <head>
                <meta charset="UTF-8">
                <style>
                    table { border-collapse: collapse; font-family: Arial, sans-serif; font-size: 12px; }
                    th { background: #1f6392; color: #fff; font-weight: bold; }
                    th, td { border: 1px solid #b7c9d6; padding: 6px 8px; mso-number-format: "\\@"; }
                </style>
            </head>
            <body>${table.outerHTML}</body>
        </html>
    `;
    const blob = new Blob(['\ufeff', html], { type: 'application/vnd.ms-excel' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `<?php echo $reportType; ?>_performance_report_${new Date().toISOString().slice(0,19)}.xls`;
    a.click();
    window.URL.revokeObjectURL(url);
}

function printReport(tableId) {
    const table = document.getElementById(tableId);
    if (!table) {
        alert('No report table is available to print.');
        return;
    }
    const printContent = table.outerHTML;
    const originalContents = document.body.innerHTML;
    document.body.innerHTML = `<div class="container mt-4"><h2><?php echo htmlspecialchars($reportTitle, ENT_QUOTES, 'UTF-8'); ?> Report</h2><p>Generated on: ${new Date().toLocaleString()}</p><p>Period: ${document.querySelector('input[name="date_from"]').value} to ${document.querySelector('input[name="date_to"]').value}</p>${printContent}</div>`;
    window.print();
    document.body.innerHTML = originalContents;
    location.reload();
}
</script>

<style>
/* Keep the shared messenger above the report layout on desktop and mobile. */
body #chat-widget,
body #chat-widget-minimized {
    position: fixed !important;
    top: auto !important;
    right: 24px !important;
    bottom: 24px !important;
    left: auto !important;
    margin: 0 !important;
    transform: none !important;
    z-index: 2147483000 !important;
}

body #chat-widget-minimized {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 64px !important;
    height: 64px !important;
    border: 0 !important;
    border-radius: 50% !important;
    background: linear-gradient(135deg, #1f75ae, #075b96) !important;
    color: #fff !important;
    box-shadow: 0 18px 38px rgba(10, 48, 79, 0.3) !important;
    opacity: 1 !important;
    visibility: visible !important;
    pointer-events: auto !important;
}

body #chat-widget-minimized.report-chat-hidden {
    display: none !important;
}

body #chat-widget.report-chat-open {
    display: flex !important;
}

body #chat-widget:not(.report-chat-open) {
    display: none !important;
}

@media (max-width: 576px) {
    body #chat-widget,
    body #chat-widget-minimized {
        right: 12px !important;
        bottom: 12px !important;
    }

    body #chat-widget {
        width: calc(100vw - 24px) !important;
        height: calc(100dvh - 88px) !important;
        max-height: calc(100dvh - 24px) !important;
    }

    body #chat-widget-minimized {
        width: 58px !important;
        height: 58px !important;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const widget = document.getElementById('chat-widget');
    const launcher = document.getElementById('chat-widget-minimized');
    const minimizeButton = document.getElementById('toggleChatMinimize');

    if (!widget || !launcher) return;

    // Moving fixed elements directly under body avoids clipping by report containers.
    document.body.appendChild(widget);
    document.body.appendChild(launcher);

    const setReportChatOpen = function (isOpen) {
        widget.classList.toggle('report-chat-open', isOpen);
        launcher.classList.toggle('report-chat-hidden', isOpen);
    };

    setReportChatOpen(false);
    launcher.addEventListener('click', function () {
        setReportChatOpen(true);
    });

    if (minimizeButton) {
        minimizeButton.addEventListener('click', function () {
            setReportChatOpen(false);
        });
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
