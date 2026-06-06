<?php
require_once '../../includes/auth.php';
require_once '../../includes/project-delay-insights.php';
require_once '../../includes/schedule-calendar.php';
requireRole('sales');

$conn = getConnection();
$user = getCurrentUser();
$currentUserId = (int) $user['id'];
$hasProjectStartTime = dbColumnExists($conn, 'projects', 'timeline_start_time');
$hasProjectEndTime = dbColumnExists($conn, 'projects', 'timeline_end_time');
$projectStartTimeSql = $hasProjectStartTime ? "COALESCE(p.timeline_start_time, '09:00:00')" : "'09:00:00'";
$projectEndTimeSql = $hasProjectEndTime ? "COALESCE(p.timeline_end_time, '17:00:00')" : "'17:00:00'";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_plan'])) {
        $planTitle = trim((string) ($_POST['plan_title'] ?? ''));
        $planDate = trim((string) ($_POST['plan_date'] ?? ''));
        $planTime = trim((string) ($_POST['plan_time'] ?? '09:00'));
        $planType = trim((string) ($_POST['plan_type'] ?? 'meeting'));
        $planLocation = trim((string) ($_POST['plan_location'] ?? ''));
        $planDescription = trim((string) ($_POST['plan_description'] ?? ''));
        $validTypes = ['meeting', 'deadline', 'milestone', 'review', 'holiday', 'personal'];

        if ($planTitle !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $planDate) && in_array($planType, $validTypes, true)) {
            $colorMap = [
                'meeting' => '#1f6392',
                'deadline' => '#dc2626',
                'milestone' => '#7c3aed',
                'review' => '#d97706',
                'holiday' => '#059669',
                'personal' => '#64748b',
            ];
            $colorCode = $colorMap[$planType] ?? '#1f6392';
            $status = 'scheduled';
            $reminderDays = 1;

            $stmt = $conn->prepare(
                'INSERT INTO schedules (user_id, title, description, schedule_type, start_date, start_time, end_date, end_time, location, color_code, status, reminder_days)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            if ($stmt !== false) {
                $stmt->bind_param(
                    'issssssssssi',
                    $currentUserId,
                    $planTitle,
                    $planDescription,
                    $planType,
                    $planDate,
                    $planTime,
                    $planDate,
                    $planTime,
                    $planLocation,
                    $colorCode,
                    $status,
                    $reminderDays
                );
                $stmt->execute();
                $stmt->close();
                logActivity($currentUserId, 'create_sales_plan', 'schedules', 0, null, [
                    'title' => $planTitle,
                    'date' => $planDate,
                    'time' => $planTime,
                    'type' => $planType,
                ]);
                $_SESSION['success'] = 'Sales plan added to your calendar.';
            } else {
                $_SESSION['error'] = 'Unable to prepare the sales plan.';
            }
        } else {
            $_SESSION['error'] = 'Please enter a title, date, and valid plan type.';
        }

        header('Location: dashboard.php?calendar_month=' . urlencode(substr($planDate !== '' ? $planDate : date('Y-m-d'), 0, 7)));
        exit();
    }

    if (isset($_POST['delete_plan'])) {
        $planId = (int) ($_POST['plan_id'] ?? 0);
        if ($planId > 0) {
            $stmt = $conn->prepare('DELETE FROM schedules WHERE id = ? AND user_id = ?');
            if ($stmt !== false) {
                $stmt->bind_param('ii', $planId, $currentUserId);
                $stmt->execute();
                $stmt->close();
                logActivity($currentUserId, 'delete_sales_plan', 'schedules', $planId);
                $_SESSION['success'] = 'Sales plan removed.';
            }
        }

        header('Location: dashboard.php?calendar_month=' . urlencode((string) ($_POST['calendar_month'] ?? date('Y-m'))));
        exit();
    }
}

// Get statistics
$totalClients = $conn->query("SELECT COUNT(*) as count FROM clients")->fetch_assoc()['count'];
$activeClients = $conn->query("SELECT COUNT(*) as count FROM clients WHERE status = 'active'")->fetch_assoc()['count'];
$totalProjects = $conn->query("SELECT COUNT(*) as count FROM projects")->fetch_assoc()['count'];
$activeProjects = $conn->query("SELECT COUNT(*) as count FROM projects WHERE status != 'completed'")->fetch_assoc()['count'];
$completedProjects = $conn->query("SELECT COUNT(*) as count FROM projects WHERE status = 'completed'")->fetch_assoc()['count'];

// Get recent clients
$recentClients = $conn->query("SELECT * FROM clients ORDER BY created_at DESC LIMIT 5");

// Get projects by status
$projectStatus = $conn->query("
    SELECT status, COUNT(*) as count 
    FROM projects 
    GROUP BY status
");

// Get user's schedule and related project/client deadlines
$schedules = $conn->query("
    SELECT title, description, schedule_type, start_date, start_time, status, project_id
    FROM (
        SELECT
            s.title,
            s.description,
            s.schedule_type,
            s.start_date,
            COALESCE(s.start_time, '00:00:00') AS start_time,
            s.status,
            s.project_id
        FROM schedules s
        WHERE s.user_id = {$user['id']}
            AND s.status <> 'cancelled'
            AND (
                s.start_date > CURDATE()
                OR (s.start_date = CURDATE() AND COALESCE(s.start_time, '00:00:00') >= CURTIME())
            )
        UNION ALL
        SELECT
            CONCAT('Project start: ', p.name) AS title,
            CONCAT('Client: ', c.name) AS description,
            'milestone' AS schedule_type,
            p.timeline_start AS start_date,
            {$projectStartTimeSql} AS start_time,
            p.status,
            p.id AS project_id
        FROM projects p
        JOIN clients c ON c.id = p.client_id
        WHERE p.timeline_start IS NOT NULL
            AND (
                p.timeline_start > CURDATE()
                OR (p.timeline_start = CURDATE() AND {$projectStartTimeSql} >= CURTIME())
            )
            AND p.status NOT IN ('completed', 'cancelled')
            AND (p.created_by = {$user['id']} OR c.created_by = {$user['id']})
        UNION ALL
        SELECT
            CONCAT('Project deadline: ', p.name) AS title,
            CONCAT('Client: ', c.name) AS description,
            'deadline' AS schedule_type,
            p.timeline_end AS start_date,
            {$projectEndTimeSql} AS start_time,
            p.status,
            p.id AS project_id
        FROM projects p
        JOIN clients c ON c.id = p.client_id
        WHERE p.timeline_end IS NOT NULL
            AND (
                p.timeline_end > CURDATE()
                OR (p.timeline_end = CURDATE() AND {$projectEndTimeSql} >= CURTIME())
            )
            AND p.status NOT IN ('completed', 'cancelled')
            AND (p.created_by = {$user['id']} OR c.created_by = {$user['id']})
        UNION ALL
        SELECT
            CONCAT('Client deadline: ', c.name) AS title,
            c.contact_person AS description,
            'deadline' AS schedule_type,
            c.deadline AS start_date,
            '17:00:00' AS start_time,
            c.status,
            NULL AS project_id
        FROM clients c
        WHERE c.deadline IS NOT NULL
            AND c.deadline >= CURDATE()
            AND c.status <> 'inactive'
            AND c.created_by = {$user['id']}
    ) upcoming
    ORDER BY start_date, start_time
    LIMIT 5
");
$calendarMonthInput = trim((string) ($_GET['calendar_month'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $calendarMonthInput)) {
    $calendarMonthInput = date('Y-m');
}

$calendarStart = DateTime::createFromFormat('Y-m-d', $calendarMonthInput . '-01') ?: new DateTime('first day of this month');
$calendarStart->setTime(0, 0, 0);
$calendarEnd = (clone $calendarStart)->modify('last day of this month');
$calendarMonth = $calendarStart->format('Y-m');
$previousMonth = (clone $calendarStart)->modify('-1 month')->format('Y-m');
$nextMonth = (clone $calendarStart)->modify('+1 month')->format('Y-m');
$firstCalendarDay = (clone $calendarStart)->modify('-' . (int) $calendarStart->format('w') . ' days');
$lastCalendarDay = (clone $calendarEnd)->modify('+' . (6 - (int) $calendarEnd->format('w')) . ' days');

$plansByDate = [];
$monthlyPlans = [];
$monthStartString = $calendarStart->format('Y-m-d');
$monthEndString = $calendarEnd->format('Y-m-d');
$planStmt = $conn->prepare(
    'SELECT *
     FROM schedules
     WHERE user_id = ? AND start_date BETWEEN ? AND ?
     ORDER BY start_date ASC, start_time ASC'
);

if ($planStmt !== false) {
    $planStmt->bind_param('iss', $currentUserId, $monthStartString, $monthEndString);
    $planStmt->execute();
    $planResult = $planStmt->get_result();
    if ($planResult) {
        while ($plan = $planResult->fetch_assoc()) {
            $planDate = (string) ($plan['start_date'] ?? '');
            $monthlyPlans[] = $plan;
            $plansByDate[$planDate][] = $plan;
        }
    }
    $planStmt->close();
}

$upcomingPlans = [];
$todayString = date('Y-m-d');
$upcomingStmt = $conn->prepare(
    'SELECT *
     FROM schedules
     WHERE user_id = ? AND start_date >= ? AND status = ?
     ORDER BY start_date ASC, start_time ASC
     LIMIT 5'
);

if ($upcomingStmt !== false) {
    $scheduledStatus = 'scheduled';
    $upcomingStmt->bind_param('iss', $currentUserId, $todayString, $scheduledStatus);
    $upcomingStmt->execute();
    $upcomingResult = $upcomingStmt->get_result();
    if ($upcomingResult) {
        while ($plan = $upcomingResult->fetch_assoc()) {
            $upcomingPlans[] = $plan;
        }
    }
    $upcomingStmt->close();
}

// Get monthly sales data
$monthlySales = $conn->query("
    SELECT DATE_FORMAT(created_at, '%Y-%m') as month, 
           COUNT(*) as project_count,
           SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count
    FROM projects 
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month ASC
");

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

/* Page Header */
.page-header-modern {
    background: #ffffff;
    border-radius: 24px;
    padding: 2rem;
    margin-bottom: 2rem;
    position: relative;
    overflow: hidden;
    border: 1px solid var(--gray-200);
}

.page-header-modern::before {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 300px;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(31, 99, 146, 0.06));
    pointer-events: none;
}

.page-header-modern h2 {
    font-size: 1.75rem;
    font-weight: 600;
    margin-bottom: 0.5rem;
    color: var(--gray-900);
}

.page-header-modern h2 i {
    color: var(--theme-primary);
}

.page-header-modern p {
    opacity: 0.9;
    margin-bottom: 0;
    font-size: 0.9rem;
    color: var(--gray-600);
}

.sales-overview-badge {
    background-color: var(--theme-primary) !important;
    color: #fff !important;
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
    opacity: 0.7;
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

/* Chart Cards */
.chart-card {
    background: white;
    border-radius: 20px;
    border: 1px solid var(--gray-200);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    height: 100%;
}

.chart-card .card-header {
    padding: 1.25rem 1.5rem;
    background: white;
    border-bottom: 1px solid var(--gray-200);
}

.chart-card .card-header h5 {
    font-size: 1rem;
    font-weight: 600;
    color: var(--gray-900);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.chart-card .card-header h5 i {
    color: var(--theme-primary);
}

.chart-card .card-body {
    padding: 1.5rem;
}

/* List Cards */
.list-card {
    background: white;
    border-radius: 20px;
    border: 1px solid var(--gray-200);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    height: 100%;
}

.list-card .card-header {
    padding: 1.25rem 1.5rem;
    background: white;
    border-bottom: 1px solid var(--gray-200);
}

.list-card .card-header h5 {
    font-size: 1rem;
    font-weight: 600;
    color: var(--gray-900);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.list-card .card-header h5 i {
    color: var(--theme-primary);
}

.list-card .card-body {
    padding: 0;
}

/* List Items */
.list-item-modern {
    padding: 1rem 1.5rem;
    border-bottom: 1px solid var(--gray-200);
    transition: all 0.2s ease;
}

.list-item-modern:last-child {
    border-bottom: none;
}

.list-item-modern:hover {
    background: var(--gray-50);
}

.client-info h6 {
    font-size: 0.95rem;
    font-weight: 600;
    color: var(--gray-900);
    margin-bottom: 0.25rem;
}

.client-info .client-details {
    font-size: 0.75rem;
    color: var(--gray-600);
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
}

.client-info .client-details i {
    margin-right: 0.25rem;
    color: var(--theme-primary);
}

/* Timeline Items */
.timeline-item-modern {
    padding: 1rem 1.5rem;
    border-bottom: 1px solid var(--gray-200);
    position: relative;
    padding-left: 3rem;
}

.timeline-item-modern:last-child {
    border-bottom: none;
}

.timeline-item-modern::before {
    content: '';
    position: absolute;
    left: 1.5rem;
    top: 1.5rem;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--theme-primary);
}

.timeline-item-modern::after {
    content: '';
    position: absolute;
    left: 1.5rem;
    top: 2rem;
    width: 2px;
    height: calc(100% - 1rem);
    background: var(--gray-200);
}

.timeline-item-modern:last-child::after {
    display: none;
}

.timeline-title {
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--gray-900);
    margin-bottom: 0.25rem;
}

.timeline-time {
    font-size: 0.7rem;
    color: var(--gray-600);
    margin-bottom: 0.25rem;
    display: flex;
    align-items: center;
    gap: 0.25rem;
}

.timeline-time i {
    font-size: 0.65rem;
}

.timeline-desc {
    font-size: 0.8rem;
    color: var(--gray-600);
    margin-top: 0.25rem;
}

/* Badge */
.badge-modern {
    display: inline-block;
    padding: 0.2rem 0.6rem;
    border-radius: 20px;
    font-size: 0.65rem;
    font-weight: 600;
}

.badge-modern.active {
    background: #d1fae5;
    color: #059669;
}

.badge-modern.inactive {
    background: #fef3c7;
    color: #d97706;
}

/* View Button */
.btn-view {
    background: transparent;
    border: 1px solid var(--theme-primary);
    color: var(--theme-primary);
    padding: 0.3rem 0.8rem;
    border-radius: 8px;
    font-size: 0.7rem;
    transition: all 0.2s;
}

.btn-view:hover {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-primary-dark) 100%);
    color: white;
    transform: translateY(-1px);
}

/* Empty State */
.empty-state-modern {
    text-align: center;
    padding: 2rem 1.5rem;
}

.empty-state-modern i {
    font-size: 2.5rem;
    color: var(--gray-300);
    margin-bottom: 0.5rem;
}

.empty-state-modern p {
    color: var(--gray-600);
    font-size: 0.85rem;
    margin: 0;
}

/* Sales Planner */
.agenda-preview {
    padding: 1.15rem 1.25rem;
}

.agenda-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1.25rem;
}

.agenda-title {
    margin: 0;
    color: var(--gray-900);
    font-size: 1.35rem;
    font-weight: 600;
}

.agenda-open-btn {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    border: 0;
    background: var(--theme-primary);
    color: white;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 12px 24px rgba(31, 99, 146, 0.22);
    transition: all 0.18s ease;
}

.agenda-open-btn:hover {
    transform: translateY(-1px);
    background: var(--theme-primary-dark);
}

.agenda-date-group {
    min-width: 0;
    margin-bottom: 1rem;
    padding: 0.75rem 0.85rem;
    border: 1px solid var(--gray-100);
    border-radius: 14px;
    background: #ffffff;
}

.agenda-date-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 0.85rem;
}

.agenda-date-label {
    color: #9ca3af;
    font-size: 0.98rem;
    font-weight: 500;
}

.agenda-more-btn {
    border: 0;
    background: transparent;
    color: #9ca3af;
    padding: 0;
}

.agenda-item {
    display: grid;
    grid-template-columns: 66px 6px minmax(0, 1fr);
    gap: 1rem;
    align-items: center;
    padding: 0.55rem 0;
}

.agenda-time {
    color: #111827;
    font-size: 1.2rem;
    font-weight: 600;
    line-height: 1;
}

.agenda-color {
    width: 6px;
    height: 48px;
    border-radius: 999px;
}

.agenda-kicker {
    color: #9ca3af;
    font-size: 0.78rem;
    font-weight: 500;
    line-height: 1.2;
    margin-bottom: 0.2rem;
}

.agenda-text {
    color: #7b8190;
    font-size: 0.98rem;
    font-weight: 600;
    line-height: 1.25;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.planner-modal .modal-dialog {
    max-width: 1040px;
}

.planner-modal .modal-content {
    border: 0;
    border-radius: 22px;
    overflow: hidden;
}

.planner-modal .modal-header {
    background: #ffffff;
    border-bottom: 1px solid var(--gray-200);
    padding: 1.25rem 1.5rem;
}

.planner-modal .modal-title {
    color: var(--gray-900);
    font-weight: 800;
}

.planner-modal .planner-side {
    grid-template-columns: minmax(0, 1fr) 360px;
    align-items: start;
}

.planner-shell {
    padding: 1.25rem;
}

.planner-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1rem;
}

.planner-month {
    margin: 0;
    color: var(--gray-900);
    font-size: 1.05rem;
    font-weight: 700;
}

.planner-nav {
    display: flex;
    gap: 0.5rem;
}

.planner-nav a {
    width: 34px;
    height: 34px;
    border-radius: 10px;
    border: 1px solid var(--gray-300);
    color: var(--theme-primary);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    transition: all 0.18s ease;
}

.planner-nav a:hover {
    background: var(--theme-primary);
    color: white;
    border-color: var(--theme-primary);
}

.calendar-grid {
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
    border: 1px solid var(--gray-200);
    border-radius: 16px;
    overflow: hidden;
}

.calendar-weekday,
.calendar-day {
    min-width: 0;
    border-right: 1px solid var(--gray-200);
    border-bottom: 1px solid var(--gray-200);
}

.calendar-weekday:nth-child(7n),
.calendar-day:nth-child(7n) {
    border-right: none;
}

.calendar-day:nth-last-child(-n + 7) {
    border-bottom: none;
}

.calendar-weekday {
    background: var(--gray-50);
    color: var(--gray-600);
    padding: 0.65rem 0.4rem;
    font-size: 0.7rem;
    font-weight: 700;
    text-align: center;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.calendar-day {
    min-height: 96px;
    background: white;
    padding: 0.55rem;
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
    cursor: pointer;
    transition: all 0.18s ease;
}

.calendar-day:hover {
    background: #f3faff;
}

.calendar-day.is-muted {
    background: #fbfcfd;
    color: #adb5bd;
}

.calendar-day.is-today {
    box-shadow: inset 0 0 0 2px rgba(31, 99, 146, 0.35);
}

.calendar-day.is-selected {
    background: #eef8ff;
}

.calendar-date {
    font-size: 0.78rem;
    font-weight: 700;
    color: var(--gray-900);
}

.calendar-day.is-muted .calendar-date {
    color: #adb5bd;
}

.calendar-plan-chip {
    width: 100%;
    border: 0;
    border-radius: 8px;
    padding: 0.25rem 0.4rem;
    color: white;
    font-size: 0.68rem;
    text-align: left;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.calendar-more {
    color: var(--gray-600);
    font-size: 0.68rem;
    font-weight: 700;
}

.planner-side {
    display: grid;
    gap: 1rem;
}

.planner-panel {
    border: 1px solid var(--gray-200);
    border-radius: 16px;
    padding: 1rem;
    background: var(--gray-50);
}

.planner-panel h6 {
    margin: 0 0 0.8rem;
    color: var(--gray-900);
    font-size: 0.9rem;
    font-weight: 700;
}

.planner-form-grid {
    display: grid;
    gap: 0.75rem;
}

.planner-form-grid .form-control,
.planner-form-grid .form-select {
    border-radius: 12px;
    font-size: 0.85rem;
}

.plan-list {
    display: grid;
    gap: 0.65rem;
}

.plan-item {
    display: grid;
    grid-template-columns: 4px minmax(0, 1fr) auto;
    gap: 0.75rem;
    align-items: start;
    background: white;
    border: 1px solid var(--gray-200);
    border-radius: 12px;
    padding: 0.75rem;
}

.plan-color {
    width: 4px;
    height: 100%;
    min-height: 42px;
    border-radius: 999px;
}

.plan-title {
    font-size: 0.86rem;
    font-weight: 700;
    color: var(--gray-900);
    margin-bottom: 0.2rem;
}

.plan-meta {
    color: var(--gray-600);
    font-size: 0.72rem;
    display: flex;
    flex-wrap: wrap;
    gap: 0.55rem;
}

.plan-delete {
    width: 30px;
    height: 30px;
    border: 0;
    border-radius: 8px;
    color: #dc2626;
    background: #fee2e2;
}

.plan-delete:hover {
    background: #dc2626;
    color: white;
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

    .calendar-weekday {
        font-size: 0.62rem;
    }

    .calendar-day {
        min-height: 78px;
        padding: 0.4rem;
    }

    .planner-modal .planner-side {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="container-fluid px-4 py-4">
    <!-- Welcome Section -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <div class="row align-items-center">
                        <div class="col-12">
                            <span class="badge sales-overview-badge mb-2 px-3 py-2">Sales Overview</span>
                            <h1 class="h3 mb-1">Welcome back, <?php echo htmlspecialchars($user['full_name']); ?>!</h1>
                            <p class="text-muted mb-0 small">Sales Dashboard - Track clients, manage projects, and monitor performance metrics for business growth.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card-modern stat-clients">
            <i class="fas fa-users stat-icon"></i>
            <div class="stat-label">Total Clients</div>
            <div class="stat-number"><?php echo $totalClients; ?></div>
            <div class="stat-sub"><?php echo $activeClients; ?> active clients</div>
        </div>
        
        <div class="stat-card-modern stat-projects">
            <i class="fas fa-project-diagram stat-icon"></i>
            <div class="stat-label">Total Projects</div>
            <div class="stat-number"><?php echo $totalProjects; ?></div>
            <div class="stat-sub"><?php echo $completedProjects; ?> completed</div>
        </div>
        
        <div class="stat-card-modern stat-active">
            <i class="fas fa-play-circle stat-icon"></i>
            <div class="stat-label">Active Projects</div>
            <div class="stat-number"><?php echo $activeProjects; ?></div>
            <div class="stat-sub">Currently ongoing</div>
        </div>
        
        <div class="stat-card-modern stat-completion">
            <i class="fas fa-trophy stat-icon"></i>
            <div class="stat-label">Completion Rate</div>
            <div class="stat-number"><?php echo $totalProjects > 0 ? round(($completedProjects / $totalProjects) * 100) : 0; ?>%</div>
            <div class="stat-sub">Project success rate</div>
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

    <!-- Charts Row -->
    <div class="row g-4 mb-4">
        <div class="col-lg-8">
            <div class="chart-card">
                <div class="card-header">
                    <h5>
                        <i class="fas fa-chart-line"></i>
                        Project Trends (Last 6 Months)
                    </h5>
                </div>
                <div class="card-body">
                    <canvas id="projectTrendsChart" style="height: 300px; width: 100%;"></canvas>
                </div>
            </div>
        </div>
        
        <div class="col-lg-4">
            <div class="chart-card">
                <div class="card-header">
                    <h5>
                        <i class="fas fa-chart-pie"></i>
                        Project Status Distribution
                    </h5>
                </div>
                <div class="card-body">
                    <canvas id="statusChart" style="height: 280px; width: 100%;"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Lists Row -->
    <div class="row g-4">
        <div class="col-lg-7">
            <div class="list-card">
                <div class="card-header">
                    <h5>
                        <i class="fas fa-user-plus"></i>
                        Recent Clients
                    </h5>
                </div>
                <div class="card-body">
                    <?php if($recentClients->num_rows > 0): ?>
                        <?php while($client = $recentClients->fetch_assoc()): ?>
                        <div class="list-item-modern">
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="client-info">
                                    <h6><?php echo htmlspecialchars($client['name']); ?></h6>
                                    <div class="client-details">
                                        <span><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($client['email']); ?></span>
                                        <span>
                                            <i class="fas fa-circle"></i> 
                                            <span class="badge-modern <?php echo $client['status']; ?>">
                                                <?php echo ucfirst($client['status']); ?>
                                            </span>
                                        </span>
                                    </div>
                                </div>
                                <a href="clients.php?view=<?php echo $client['id']; ?>" class="btn-view">
                                    <i class="fas fa-eye"></i> View
                                </a>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state-modern">
                            <i class="fas fa-user-friends"></i>
                            <p>No clients found</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="list-card">
                <div class="card-body">
                    <?php renderScheduleCalendar($schedules); ?>
                    <div class="agenda-preview">
                        <div class="agenda-header">
                            <h5 class="agenda-title">Calendar</h5>
                            <button type="button" class="agenda-open-btn" data-bs-toggle="modal" data-bs-target="#salesPlannerModal" aria-label="Open sales planner">
                                <i class="far fa-calendar-alt"></i>
                            </button>
                        </div>

                        <?php if ($upcomingPlans): ?>
                            <?php
                            $agendaGroups = [];
                            foreach ($upcomingPlans as $plan) {
                                $groupDate = (string) ($plan['start_date'] ?? date('Y-m-d'));
                                $agendaGroups[$groupDate][] = $plan;
                            }
                            ?>
                            <?php foreach ($agendaGroups as $groupDate => $plans): ?>
                                <div class="agenda-date-group">
                                    <div class="agenda-date-row">
                                        <span class="agenda-date-label"><?php echo htmlspecialchars(date('d F', strtotime($groupDate))); ?></span>
                                        <button type="button" class="agenda-more-btn" data-bs-toggle="modal" data-bs-target="#salesPlannerModal" aria-label="View date plans">
                                            <i class="fas fa-ellipsis-h"></i>
                                        </button>
                                    </div>
                                    <?php foreach ($plans as $plan): ?>
                                        <div class="agenda-item">
                                            <div class="agenda-time"><?php echo htmlspecialchars(date('H:i', strtotime((string) ($plan['start_time'] ?? '09:00')))); ?></div>
                                            <span class="agenda-color" style="background: <?php echo htmlspecialchars((string) ($plan['color_code'] ?? '#1f6392')); ?>;"></span>
                                            <div>
                                                <div class="agenda-kicker"><?php echo htmlspecialchars(ucfirst((string) ($plan['schedule_type'] ?? 'Plan'))); ?></div>
                                                <div class="agenda-text"><?php echo htmlspecialchars((string) ($plan['title'] ?? 'Plan')); ?></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state-modern" style="padding: 2rem 1rem;">
                                <i class="fas fa-calendar-week"></i>
                                <p>No upcoming plans</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade planner-modal" id="salesPlannerModal" tabindex="-1" aria-labelledby="salesPlannerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="salesPlannerModalLabel">
                    <i class="fas fa-calendar-alt me-2" style="color: var(--theme-primary);"></i>
                    Sales Planner
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div class="planner-shell">
                    <div class="planner-toolbar">
                        <h5 class="planner-month"><?php echo htmlspecialchars($calendarStart->format('F Y')); ?></h5>
                        <div class="planner-nav">
                            <a href="dashboard.php?calendar_month=<?php echo urlencode($previousMonth); ?>" aria-label="Previous month">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                            <a href="dashboard.php?calendar_month=<?php echo urlencode(date('Y-m')); ?>" aria-label="Current month">
                                <i class="fas fa-circle"></i>
                            </a>
                            <a href="dashboard.php?calendar_month=<?php echo urlencode($nextMonth); ?>" aria-label="Next month">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        </div>
                    </div>

                    <div class="calendar-grid mb-3">
                        <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $weekday): ?>
                            <div class="calendar-weekday"><?php echo $weekday; ?></div>
                        <?php endforeach; ?>

                        <?php
                        $cursorDate = clone $firstCalendarDay;
                        while ($cursorDate <= $lastCalendarDay):
                            $dateString = $cursorDate->format('Y-m-d');
                            $datePlans = $plansByDate[$dateString] ?? [];
                            $isCurrentMonth = $cursorDate->format('Y-m') === $calendarMonth;
                            $isToday = $dateString === date('Y-m-d');
                        ?>
                            <div
                                class="calendar-day <?php echo !$isCurrentMonth ? 'is-muted' : ''; ?> <?php echo $isToday ? 'is-today' : ''; ?>"
                                data-plan-date="<?php echo htmlspecialchars($dateString); ?>"
                            >
                                <span class="calendar-date"><?php echo htmlspecialchars($cursorDate->format('j')); ?></span>
                                <?php foreach (array_slice($datePlans, 0, 2) as $plan): ?>
                                    <button
                                        type="button"
                                        class="calendar-plan-chip"
                                        style="background: <?php echo htmlspecialchars((string) ($plan['color_code'] ?? '#1f6392')); ?>;"
                                        title="<?php echo htmlspecialchars((string) ($plan['title'] ?? 'Plan')); ?>"
                                    >
                                        <?php echo htmlspecialchars((string) ($plan['title'] ?? 'Plan')); ?>
                                    </button>
                                <?php endforeach; ?>
                                <?php if (count($datePlans) > 2): ?>
                                    <span class="calendar-more">+<?php echo count($datePlans) - 2; ?> more</span>
                                <?php endif; ?>
                            </div>
                        <?php
                            $cursorDate->modify('+1 day');
                        endwhile;
                        ?>
                    </div>

                    <div class="planner-side">
                        <div class="planner-panel">
                            <h6><i class="fas fa-plus me-1" style="color: var(--theme-primary);"></i>Add Sales Plan</h6>
                            <form method="POST" class="planner-form-grid">
                                <input type="hidden" name="calendar_month" value="<?php echo htmlspecialchars($calendarMonth); ?>">
                                <input type="date" name="plan_date" id="planDate" class="form-control" value="<?php echo htmlspecialchars(date('Y-m-d')); ?>" required>
                                <input type="time" name="plan_time" class="form-control" value="09:00" required>
                                <input type="text" name="plan_title" class="form-control" placeholder="Plan title, e.g. Client follow-up" required>
                                <select name="plan_type" class="form-select">
                                    <option value="meeting">Meeting</option>
                                    <option value="deadline">Deadline</option>
                                    <option value="milestone">Milestone</option>
                                    <option value="review">Review</option>
                                    <option value="personal">Personal</option>
                                </select>
                                <input type="text" name="plan_location" class="form-control" placeholder="Location or meeting link">
                                <textarea name="plan_description" class="form-control" rows="2" placeholder="Notes or plan details"></textarea>
                                <button type="submit" name="create_plan" class="btn btn-primary w-100">
                                    <i class="fas fa-calendar-plus me-1"></i> Add to Calendar
                                </button>
                            </form>
                        </div>

                        <div class="planner-panel">
                            <h6><i class="fas fa-list me-1" style="color: var(--theme-primary);"></i>Upcoming Plans</h6>
                            <?php if ($upcomingPlans): ?>
                                <div class="plan-list">
                                    <?php foreach ($upcomingPlans as $plan): ?>
                                        <div class="plan-item">
                                            <span class="plan-color" style="background: <?php echo htmlspecialchars((string) ($plan['color_code'] ?? '#1f6392')); ?>;"></span>
                                            <div>
                                                <div class="plan-title"><?php echo htmlspecialchars((string) ($plan['title'] ?? 'Plan')); ?></div>
                                                <div class="plan-meta">
                                                    <span><i class="far fa-calendar"></i> <?php echo htmlspecialchars(date('M d, Y', strtotime((string) ($plan['start_date'] ?? date('Y-m-d'))))); ?></span>
                                                    <span><i class="far fa-clock"></i> <?php echo htmlspecialchars(date('h:i A', strtotime((string) ($plan['start_time'] ?? '09:00')))); ?></span>
                                                    <?php if (!empty($plan['location'])): ?>
                                                        <span><i class="fas fa-location-dot"></i> <?php echo htmlspecialchars((string) $plan['location']); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <form method="POST" onsubmit="return confirm('Remove this plan?');">
                                                <input type="hidden" name="plan_id" value="<?php echo (int) ($plan['id'] ?? 0); ?>">
                                                <input type="hidden" name="calendar_month" value="<?php echo htmlspecialchars($calendarMonth); ?>">
                                                <button type="submit" name="delete_plan" class="plan-delete" aria-label="Delete plan">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="empty-state-modern" style="padding: 1rem;">
                                    <i class="fas fa-calendar-week"></i>
                                    <p>No upcoming plans</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Project Trends Chart
const trendsData = <?php 
    $months = [];
    $counts = [];
    $completed = [];
    while($row = $monthlySales->fetch_assoc()) {
        $months[] = date('M Y', strtotime($row['month'] . '-01'));
        $counts[] = $row['project_count'];
        $completed[] = $row['completed_count'];
    }
    echo json_encode(['months' => $months, 'counts' => $counts, 'completed' => $completed]);
?>;

const trendsCtx = document.getElementById('projectTrendsChart').getContext('2d');
new Chart(trendsCtx, {
    type: 'line',
    data: {
        labels: trendsData.months,
        datasets: [
            {
                label: 'Total Projects',
                data: trendsData.counts,
                borderColor: '#1f6392',
                backgroundColor: 'rgba(31, 99, 146, 0.1)',
                fill: true,
                tension: 0.4,
                pointBackgroundColor: '#1f6392',
                pointBorderColor: '#fff',
                pointRadius: 4,
                pointHoverRadius: 6
            },
            {
                label: 'Completed Projects',
                data: trendsData.completed,
                borderColor: '#10b981',
                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                fill: true,
                tension: 0.4,
                pointBackgroundColor: '#10b981',
                pointBorderColor: '#fff',
                pointRadius: 4,
                pointHoverRadius: 6
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: {
                position: 'top',
                labels: {
                    usePointStyle: true,
                    boxWidth: 8,
                    font: {
                        size: 11
                    }
                }
            },
            tooltip: {
                mode: 'index',
                intersect: false
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                grid: {
                    color: 'rgba(0, 0, 0, 0.05)'
                },
                ticks: {
                    stepSize: 1,
                    font: {
                        size: 11
                    }
                }
            },
            x: {
                grid: {
                    display: false
                },
                ticks: {
                    font: {
                        size: 11
                    }
                }
            }
        }
    }
});

// Status Chart
const statusData = <?php 
    $statuses = [];
    $statusCounts = [];
    $statusColors = [];
    while($row = $projectStatus->fetch_assoc()) {
        $statuses[] = ucfirst($row['status']);
        $statusCounts[] = $row['count'];
        // Color mapping
        switch($row['status']) {
            case 'planning':
                $statusColors[] = '#f59e0b';
                break;
            case 'in_progress':
                $statusColors[] = '#1f6392';
                break;
            case 'review':
                $statusColors[] = '#8b5cf6';
                break;
            case 'completed':
                $statusColors[] = '#10b981';
                break;
            case 'cancelled':
                $statusColors[] = '#ef4444';
                break;
            default:
                $statusColors[] = '#6c757d';
        }
    }
    echo json_encode(['labels' => $statuses, 'data' => $statusCounts, 'colors' => $statusColors]);
?>;

const statusCtx = document.getElementById('statusChart').getContext('2d');
new Chart(statusCtx, {
    type: 'doughnut',
    data: {
        labels: statusData.labels,
        datasets: [{
            data: statusData.data,
            backgroundColor: statusData.colors,
            borderWidth: 0,
            hoverOffset: 10
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        cutout: '60%',
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    usePointStyle: true,
                    boxWidth: 8,
                    font: {
                        size: 11
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

document.querySelectorAll('.calendar-day').forEach((day) => {
    day.addEventListener('click', () => {
        const selectedDate = day.dataset.planDate;
        const planDateInput = document.getElementById('planDate');

        if (!selectedDate || !planDateInput) {
            return;
        }

        document.querySelectorAll('.calendar-day.is-selected').forEach((selectedDay) => {
            selectedDay.classList.remove('is-selected');
        });

        day.classList.add('is-selected');
        planDateInput.value = selectedDate;
        planDateInput.focus();
    });
});
</script>

<?php include '../../includes/footer.php'; ?>
