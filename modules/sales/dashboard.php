<?php
require_once '../../includes/auth.php';
require_once '../../includes/project-delay-insights.php';
require_once '../../includes/project-progress.php';
require_once '../../includes/schedule-calendar.php';
requireRole('sales');

$conn = getConnection();
$user = getCurrentUser();
$currentUserId = (int) $user['id'];
$showEvortixLoginWelcome = true;
unset($_SESSION['evortix_sales_dashboard_welcome_pending']);
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
$overdueProjects = $conn->query("SELECT COUNT(*) as count FROM projects WHERE timeline_end IS NOT NULL AND timeline_end < CURDATE() AND status NOT IN ('completed', 'cancelled')")->fetch_assoc()['count'];
$insightProjectCount = $conn->query("SELECT COUNT(*) as count FROM projects WHERE status != 'cancelled'")->fetch_assoc()['count'];
$fetchEvortixRows = static function ($result): array {
    $rows = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    return $rows;
};
$evortixPendingProjects = $fetchEvortixRows($conn->query("
    SELECT p.id, p.client_id, p.name AS project_name, c.name AS client_name
    FROM projects p
    INNER JOIN clients c ON c.id = p.client_id
    WHERE p.status = 'pending'
    ORDER BY p.created_at ASC, p.id ASC
"));
$evortixInactiveClients = $fetchEvortixRows($conn->query("
    SELECT id, name AS client_name FROM clients
    WHERE status = 'inactive'
    ORDER BY name ASC
"));
$evortixOverdueProjects = $fetchEvortixRows($conn->query("
    SELECT p.id, p.client_id, p.name AS project_name, p.timeline_end, c.name AS client_name
    FROM projects p
    INNER JOIN clients c ON c.id = p.client_id
    WHERE p.timeline_end IS NOT NULL
      AND p.timeline_end < CURDATE()
      AND p.status NOT IN ('completed', 'cancelled')
    ORDER BY p.timeline_end ASC, p.id ASC
"));

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

$dashboardScheduleEvents = [];
if ($schedules && $schedules->num_rows > 0) {
    while ($schedule = $schedules->fetch_assoc()) {
        $dashboardScheduleEvents[] = [
            'title' => $schedule['title'] ?? 'Schedule',
            'description' => $schedule['description'] ?? '',
            'type' => $schedule['schedule_type'] ?? 'schedule',
            'datetime' => normalizeScheduleDateTime($schedule),
        ];
    }
}
$dashboardScheduleEvents = array_slice($dashboardScheduleEvents, 0, 3);

$nextDashboardEvent = $dashboardScheduleEvents[0] ?? null;

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

$projectChecklists = fetchSalesProjectChecklists($conn, 5);
$releaseMergedTotal = 0;
$releaseReviewTotal = 0;
$releaseWaitingTotal = 0;
$releaseFeatureTotal = 0;
foreach ($projectChecklists as $projectChecklistSummary) {
    $mergedCount = (int) ($projectChecklistSummary['merged_features'] ?? 0);
    $totalCount = (int) ($projectChecklistSummary['total_features'] ?? 0);
    $reviewCount = 0;
    foreach (array_merge($projectChecklistSummary['features'] ?? [], $projectChecklistSummary['additional_features'] ?? []) as $checklistFeature) {
        if (empty($checklistFeature['is_merged']) && in_array((string) ($checklistFeature['status'] ?? ''), ['in_review', 'for_review', 'qa_testing', 'qa_passed', 'architect_review'], true)) {
            $reviewCount++;
        }
    }
    $releaseMergedTotal += $mergedCount;
    $releaseReviewTotal += $reviewCount;
    $releaseFeatureTotal += $totalCount;
    $releaseWaitingTotal += max(0, $totalCount - $mergedCount - $reviewCount);
}
$releaseCompletionRate = $releaseFeatureTotal > 0 ? (int) round(($releaseMergedTotal / $releaseFeatureTotal) * 100) : 0;

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
    --gray-500: #8a94a3;
    --gray-600: #6c757d;
    --gray-700: #495057;
    --gray-900: #212529;
    --tech-border: rgba(31, 99, 146, 0.14);
    --tech-panel-tint: rgba(31, 99, 146, 0.055);
    --tech-grid-line: rgba(31, 99, 146, 0.055);
    --tech-node: rgba(34, 211, 238, 0.12);
    --card-sheen: rgba(255, 255, 255, 0.82);
    --card-sheen-soft: rgba(255, 255, 255, 0.42);
    --card-corner-glow: rgba(34, 211, 238, 0.12);
    --shadow-sm: 0 12px 28px rgba(15, 76, 129, 0.08);
    --shadow-md: 0 20px 44px rgba(15, 76, 129, 0.13);
    --shadow-lg: 0 28px 64px rgba(15, 76, 129, 0.18);
}

.sales-dashboard-shell {
    position: relative;
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
    display: none;
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
    background: var(--theme-primary) !important;
    color: #fff !important;
    border-radius: 999px;
    box-shadow: 0 12px 26px rgba(31, 99, 146, 0.22), 0 0 0 1px rgba(255, 255, 255, 0.22) inset;
    font-size: 0.72rem;
    font-weight: 600;
    letter-spacing: 0.06em;
}

.sales-dashboard-hero {
    position: relative;
    background: rgba(255, 255, 255, 0.94);
    border: 1px solid var(--tech-border) !important;
    border-radius: 24px;
    box-shadow: var(--shadow-md), 0 0 38px rgba(34, 211, 238, 0.09) !important;
    overflow: hidden;
    backdrop-filter: blur(14px);
    isolation: isolate;
}

.sales-dashboard-hero::before {
    content: "";
    position: absolute;
    inset: 0;
    pointer-events: none;
    background:
        radial-gradient(circle at 100% 0%, var(--card-corner-glow), transparent 34%),
        linear-gradient(135deg, var(--card-sheen) 0%, rgba(255, 255, 255, 0) 34%, rgba(31, 99, 146, 0.06) 100%),
        linear-gradient(180deg, var(--card-sheen-soft), transparent 58%);
    opacity: 0.78;
    z-index: 0;
}

.sales-dashboard-hero .card-body {
    position: relative;
    padding: 1.5rem !important;
    z-index: 1;
}

.sales-dashboard-hero h1 {
    color: #163b5c;
    font-weight: 600;
}

/* Stats Cards */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.stat-card-modern {
    --stat-color: var(--theme-primary);
    --stat-foreground: var(--theme-primary-dark);
    --stat-muted: var(--text-muted);
    --stat-border: var(--tech-border);
    --stat-border-hover: rgba(34, 211, 238, 0.28);
    --stat-glow: rgba(34, 211, 238, 0.07);
    --stat-glow-hover: rgba(34, 211, 238, 0.13);
    --stat-grid-line: var(--tech-grid-line);
    --stat-node: var(--tech-node);
    background:
        radial-gradient(circle at 100% 0%, var(--card-corner-glow), transparent 34%),
        linear-gradient(135deg, var(--card-sheen) 0%, rgba(255, 255, 255, 0) 38%, var(--tech-panel-tint) 100%),
        linear-gradient(180deg, var(--card-sheen-soft), transparent 56%),
        #ffffff;
    border-radius: 20px;
    padding: 1.5rem;
    box-shadow: var(--shadow-sm), 0 0 26px var(--stat-glow);
    border: 1px solid var(--stat-border);
    transition: box-shadow 0.22s ease, border-color 0.22s ease;
    position: relative;
    overflow: hidden;
    backdrop-filter: blur(12px);
    isolation: isolate;
}

.stat-clients {
    --stat-border: rgba(31, 99, 146, 0.14);
    --stat-border-hover: rgba(31, 99, 146, 0.28);
    --stat-glow: rgba(31, 99, 146, 0.10);
    --stat-glow-hover: rgba(31, 99, 146, 0.18);
    --stat-grid-line: rgba(31, 99, 146, 0.055);
}

.stat-projects {
    --stat-border: rgba(47, 128, 200, 0.14);
    --stat-border-hover: rgba(47, 128, 200, 0.28);
    --stat-glow: rgba(47, 128, 200, 0.10);
    --stat-glow-hover: rgba(47, 128, 200, 0.18);
    --stat-grid-line: rgba(47, 128, 200, 0.05);
}

.stat-active {
    --stat-border: rgba(13, 103, 134, 0.14);
    --stat-border-hover: rgba(13, 103, 134, 0.28);
    --stat-glow: rgba(13, 103, 134, 0.10);
    --stat-glow-hover: rgba(13, 103, 134, 0.18);
    --stat-grid-line: rgba(31, 114, 168, 0.05);
}

.stat-overdue {
    --stat-color: #dc2626;
    --stat-foreground: #b91c1c;
    --stat-border: rgba(220, 38, 38, 0.24);
    --stat-border-hover: rgba(220, 38, 38, 0.45);
    --stat-glow: rgba(220, 38, 38, 0.10);
    --stat-glow-hover: rgba(220, 38, 38, 0.18);
    --stat-grid-line: rgba(220, 38, 38, 0.055);
    border-color: var(--stat-border);
}

.stat-overdue.has-alert {
    animation: overdueHeartbeat 1.8s ease-in-out infinite;
}

.stat-overdue:not(.has-alert) {
    --stat-border: rgba(31, 99, 146, 0.14);
    --stat-border-hover: rgba(31, 99, 146, 0.28);
    --stat-glow: rgba(31, 99, 146, 0.10);
    --stat-glow-hover: rgba(31, 99, 146, 0.18);
    --stat-grid-line: rgba(31, 99, 146, 0.055);
}

.stat-overdue .stat-icon {
    color: #dc2626;
}

@keyframes overdueHeartbeat {
    0%, 100% {
        box-shadow: var(--shadow-sm), 0 0 0 rgba(220, 38, 38, 0);
    }
    28% {
        box-shadow: var(--shadow-md), 0 0 0 4px rgba(220, 38, 38, 0.12), 0 0 30px rgba(220, 38, 38, 0.18);
    }
    44% {
        box-shadow: var(--shadow-sm), 0 0 0 1px rgba(220, 38, 38, 0.08);
    }
    64% {
        box-shadow: var(--shadow-md), 0 0 0 5px rgba(220, 38, 38, 0.10), 0 0 26px rgba(220, 38, 38, 0.16);
    }
}

.stat-card-modern:hover {
    box-shadow: var(--shadow-lg), 0 0 32px var(--stat-glow-hover);
    border-color: var(--stat-border-hover);
}

.stat-card-modern::before {
    content: "";
    position: absolute;
    inset: 0;
    pointer-events: none;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.10));
    opacity: 1;
    z-index: 0;
}

.stat-card-modern > * {
    position: relative;
    z-index: 1;
}

.stat-card-modern .stat-icon {
    position: absolute;
    right: 1rem;
    top: 1rem;
    color: var(--theme-primary);
    font-size: 1.6rem;
    opacity: 0.78;
}

.stat-card-modern .stat-label {
    position: relative;
    font-size: 0.75rem;
    text-transform: uppercase;
    font-weight: 600;
    color: var(--stat-muted);
    letter-spacing: 0.5px;
    margin-bottom: 0.5rem;
}

.stat-card-modern .stat-number {
    position: relative;
    font-size: 2.5rem;
    font-weight: 600;
    color: var(--stat-foreground);
    line-height: 1;
    margin-bottom: 0.25rem;
}

.stat-card-modern .stat-sub {
    position: relative;
    font-size: 0.7rem;
    color: var(--stat-muted);
    font-weight: 500;
}

/* Chart Cards */
.chart-card {
    background: rgba(255, 255, 255, 0.94);
    border-radius: 20px;
    border: 1px solid var(--tech-border);
    overflow: hidden;
    box-shadow: var(--shadow-sm), 0 0 28px rgba(34, 211, 238, 0.07);
    height: 100%;
    position: relative;
    transition: box-shadow 0.22s ease, border-color 0.22s ease;
    backdrop-filter: blur(12px);
    isolation: isolate;
}

.chart-card:hover,
.list-card:hover {
    box-shadow: var(--shadow-md), 0 0 32px rgba(34, 211, 238, 0.1);
    border-color: rgba(34, 211, 238, 0.24);
}

.chart-card::before {
    content: "";
    position: absolute;
    inset: 0;
    pointer-events: none;
    background:
        radial-gradient(circle at 84% 18%, var(--tech-node) 0 2px, transparent 2.5px),
        radial-gradient(circle at 18% 82%, rgba(31, 99, 146, 0.045), transparent 34%),
        linear-gradient(120deg, rgba(31, 99, 146, 0.025), transparent 46%, var(--tech-panel-tint)),
        linear-gradient(var(--tech-grid-line) 1px, transparent 1px),
        linear-gradient(90deg, var(--tech-grid-line) 1px, transparent 1px);
    background-size: 100% 100%, 100% 100%, 100% 100%, 18px 18px, 18px 18px;
    opacity: 0.72;
}

.list-card::before {
    content: "";
    position: absolute;
    inset: 0;
    pointer-events: none;
    background:
        radial-gradient(circle at 100% 0%, var(--card-corner-glow), transparent 34%),
        linear-gradient(135deg, var(--card-sheen) 0%, rgba(255, 255, 255, 0) 36%, rgba(31, 99, 146, 0.05) 100%),
        linear-gradient(180deg, var(--card-sheen-soft), transparent 58%);
    opacity: 0.74;
    z-index: 0;
}

.chart-card > *,
.list-card > * {
    position: relative;
    z-index: 1;
}

.chart-card .card-header {
    padding: 1.25rem 1.5rem;
    background: rgba(248, 252, 255, 0.96);
    border-bottom: 1px solid rgba(31, 99, 146, 0.1);
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
    background: rgba(255, 255, 255, 0.94);
    border-radius: 20px;
    border: 1px solid var(--tech-border);
    overflow: hidden;
    box-shadow: var(--shadow-sm), 0 0 28px rgba(34, 211, 238, 0.07);
    height: 100%;
    position: relative;
    transition: box-shadow 0.22s ease, border-color 0.22s ease;
    backdrop-filter: blur(12px);
    isolation: isolate;
}

.list-card .card-header {
    padding: 1.25rem 1.5rem;
    background: rgba(248, 252, 255, 0.96);
    border-bottom: 1px solid rgba(31, 99, 146, 0.1);
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
    border-bottom: 1px solid rgba(31, 99, 146, 0.08);
    transition: background 0.2s ease;
}

.list-item-modern:last-child {
    border-bottom: none;
}

.list-item-modern:hover {
    background: var(--gray-50);
}

.client-info h6 {
    font-size: 0.98rem;
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
    padding: 0.25rem 0.65rem;
    border-radius: 20px;
    font-size: 0.67rem;
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
    background: rgba(31, 99, 146, 0.08);
    border: 1px solid rgba(31, 99, 146, 0.18);
    color: var(--theme-primary);
    padding: 0.38rem 0.85rem;
    border-radius: 10px;
    font-size: 0.72rem;
    font-weight: 600;
    text-decoration: none;
    transition: background 0.2s ease, color 0.2s ease;
}

.btn-view:hover {
    background: var(--theme-primary);
    color: white;
}

/* Empty State */
.empty-state-modern {
    text-align: center;
    padding: 2rem 1.5rem;
}

.empty-state-modern i {
    font-size: 2.5rem;
    color: rgba(31, 99, 146, 0.22);
    margin-bottom: 0.65rem;
}

.empty-state-modern p {
    color: var(--gray-600);
    font-size: 0.85rem;
    margin: 0;
}

/* Sales Planner */
.agenda-preview {
    display: grid;
    gap: 1rem;
    padding: 1.15rem 1.25rem;
}

.agenda-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}

.agenda-title {
    margin: 0;
    color: var(--gray-900);
    font-size: 1.05rem;
    font-weight: 600;
}

.agenda-subtitle {
    margin: 0.15rem 0 0;
    color: var(--gray-500);
    font-size: 0.78rem;
    font-weight: 500;
}

.agenda-open-btn {
    width: 40px;
    height: 40px;
    border-radius: 12px;
    border: 1px solid rgba(31, 99, 146, 0.18);
    background: rgba(31, 99, 146, 0.08);
    color: var(--theme-primary);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: background 0.18s ease, color 0.18s ease;
}

.agenda-open-btn:hover {
    background: var(--theme-primary);
    color: #ffffff;
}

.agenda-summary {
    display: grid;
    grid-template-columns: 76px minmax(0, 1fr);
    gap: 0.9rem;
    align-items: stretch;
    padding: 0.85rem;
    border: 1px solid var(--tech-border);
    border-radius: 14px;
    background: #ffffff;
    box-shadow: 0 12px 26px rgba(31, 99, 146, 0.08), 0 0 24px rgba(34, 211, 238, 0.07);
}

.agenda-today-badge {
    display: grid;
    place-items: center;
    min-height: 82px;
    border-radius: 12px;
    background: #ffffff;
    border: 1px solid rgba(34, 211, 238, 0.18);
    text-align: center;
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.7);
}

.agenda-today-badge span {
    color: var(--gray-500);
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
}

.agenda-today-badge strong {
    display: block;
    color: var(--theme-primary);
    font-size: 1.8rem;
    font-weight: 600;
    line-height: 1;
}

.agenda-next {
    min-width: 0;
    display: grid;
    align-content: center;
}

.agenda-next-label {
    color: var(--gray-500);
    font-size: 0.72rem;
    font-weight: 600;
    margin-bottom: 0.25rem;
    text-transform: uppercase;
}

.agenda-next-title {
    color: var(--gray-900);
    font-size: 0.96rem;
    font-weight: 600;
    line-height: 1.25;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.agenda-next-meta {
    color: var(--gray-600);
    font-size: 0.78rem;
    font-weight: 500;
    margin-top: 0.35rem;
}

.agenda-date-group {
    min-width: 0;
    padding: 0.75rem;
    border: 1px solid rgba(31, 99, 146, 0.11);
    border-radius: 12px;
    background: #ffffff;
    box-shadow: 0 10px 22px rgba(15, 76, 129, 0.06);
}

.agenda-date-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 0.55rem;
}

.agenda-date-label {
    color: var(--gray-700);
    font-size: 0.82rem;
    font-weight: 600;
}

.agenda-more-btn {
    border: 0;
    background: rgba(31, 99, 146, 0.06);
    color: var(--theme-primary);
    padding: 0;
    width: 28px;
    height: 28px;
    border-radius: 9px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
}

.agenda-item {
    display: grid;
    grid-template-columns: 52px 4px minmax(0, 1fr);
    gap: 0.75rem;
    align-items: center;
    padding: 0.55rem 0;
}

.agenda-time {
    color: #111827;
    font-size: 0.86rem;
    font-weight: 600;
    line-height: 1;
}

.agenda-color {
    width: 4px;
    height: 38px;
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
    color: var(--gray-700);
    font-size: 0.9rem;
    font-weight: 600;
    line-height: 1.25;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.agenda-list {
    display: grid;
    gap: 0.65rem;
}

.planner-modal .modal-dialog {
    max-width: 1040px;
}

.planner-modal .modal-content {
    border: 1px solid rgba(31, 99, 146, 0.12);
    border-radius: 22px;
    overflow: hidden;
    box-shadow: 0 28px 70px rgba(15, 76, 129, 0.2), 0 0 36px rgba(34, 211, 238, 0.08);
}

.planner-modal .modal-header {
    background: #ffffff;
    border-bottom: 1px solid rgba(31, 99, 146, 0.1);
    padding: 1.25rem 1.5rem;
}

.planner-modal .modal-title {
    color: var(--gray-900);
    font-weight: 600;
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
    font-weight: 600;
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

.calendar-scroll {
    width: 100%;
    overflow-x: visible;
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
    font-weight: 600;
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
    font-weight: 600;
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
    font-weight: 600;
}

.planner-side {
    display: grid;
    gap: 1rem;
}

.planner-panel {
    border: 1px solid var(--tech-border);
    border-radius: 16px;
    padding: 1rem;
    background: var(--gray-50);
    box-shadow: 0 10px 24px rgba(31, 99, 146, 0.07);
}

.planner-panel h6 {
    margin: 0 0 0.8rem;
    color: var(--gray-900);
    font-size: 0.9rem;
    font-weight: 600;
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
    background: #ffffff;
    border: 1px solid var(--tech-border);
    border-radius: 12px;
    padding: 0.75rem;
    box-shadow: 0 10px 22px rgba(15, 76, 129, 0.06);
}

.plan-color {
    width: 4px;
    height: 100%;
    min-height: 42px;
    border-radius: 999px;
}

.plan-title {
    font-size: 0.86rem;
    font-weight: 600;
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

/* Project Release Checklist */
.release-graph-layout {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1.25rem;
    padding: 0;
    align-items: stretch;
}

.release-chart-panel {
    min-height: 250px;
    padding: 20px 0 0 0;
    border: none;
    border-radius: 8px;
    background: none;
    background-size: 28px 28px;
    box-shadow: none;
    display: flex;
    flex-direction: column;
    gap: 0.7rem;
    align-items: center;
    justify-content: center;
}

.release-chart-panel canvas {
    align-self: center;
    height: 175px !important;
    width: 100% !important;
}

.release-chart-summary {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: center;
    gap: 0.65rem 1.25rem;
    width: 100%;
}

.release-summary-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    min-width: max-content;
    padding: 0.45rem 0.65rem;
    border: 1px solid transparent;
    border-radius: 999px;
    background: transparent;
    box-shadow: none;
    cursor: pointer;
    transition: opacity 0.2s ease, background-color 0.2s ease, border-color 0.2s ease, transform 0.2s ease;
}

.release-summary-pill:hover,
.release-summary-pill:focus-visible {
    background: rgba(31, 99, 146, 0.06);
    border-color: rgba(31, 99, 146, 0.14);
    outline: none;
    transform: translateY(-1px);
}

.release-summary-pill.is-hidden {
    opacity: 0.42;
    text-decoration: line-through;
}

.release-summary-pill::before {
    content: "";
    width: 14px;
    height: 14px;
    flex: 0 0 14px;
    border-radius: 50%;
    background: #1f6392;
}

.release-summary-pill.merged::before {
    background: #10b981;
}

.release-summary-pill.review::before {
    background: #f59e0b;
}

.release-summary-pill.waiting::before {
    background: rgba(31, 99, 146, 0.35);
}

.release-summary-pill span {
    color: var(--gray-600);
    font-size: 0.8rem;
    font-weight: 500;
    line-height: 1;
    text-transform: none;
    letter-spacing: 0;
}

.release-checklist {
    display: none;
    gap: 0.85rem;
    align-content: start;
    max-height: 560px;
    overflow: auto;
    padding-right: 0.2rem;
}

.release-project {
    border: 1px solid var(--tech-border);
    border-radius: 8px;
    padding: 0.95rem;
    background: rgba(255, 255, 255, 0.94);
    box-shadow: 0 10px 24px rgba(15, 76, 129, 0.06);
    transition: box-shadow 0.2s ease, border-color 0.2s ease;
}

.release-project:hover {
    border-color: rgba(34, 211, 238, 0.24);
    box-shadow: 0 18px 38px rgba(15, 76, 129, 0.12), 0 0 30px rgba(34, 211, 238, 0.1);
}

.release-project-header {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    align-items: flex-start;
    margin-bottom: 0.85rem;
}

.release-project-title {
    margin: 0 0 0.2rem;
    color: var(--gray-900);
    font-size: 1rem;
    font-weight: 600;
}

.release-project-client {
    color: var(--gray-600);
    font-size: 0.78rem;
    font-weight: 600;
}

.release-progress {
    width: min(100%, 390px);
    min-width: 350px;
}

.release-progress-legend {
    display: flex;
    width: 100%;
    gap: 0.75rem;
    align-items: stretch;
    justify-content: center;
    margin-bottom: 0.65rem;
}

.release-progress-metric {
    display: flex;
    width: 104px;
    min-width: 104px;
    flex: 0 0 104px;
    min-height: 58px;
    padding: 0.55rem 0.45rem;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    text-align: center;
    border: 1px solid rgba(31, 99, 146, 0.14);
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.76);
}

.release-progress-metric-label {
    width: 100%;
    color: var(--gray-600);
    font-size: 0.65rem;
    font-weight: 700;
    line-height: 1.2;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    white-space: nowrap;
    overflow: visible;
}

.release-progress-metric-value {
    margin-top: 0.2rem;
    color: var(--gray-900);
    font-size: 1.05rem;
    font-weight: 700;
    line-height: 1;
}

.release-progress-metric:first-child .release-progress-metric-value {
    color: #059669;
}

.release-progress-metric:nth-child(2) .release-progress-metric-value {
    color: var(--theme-primary);
}

.release-progress-track {
    width: 100%;
    height: 10px;
    overflow: hidden;
    border-radius: 999px;
    background: rgba(31, 99, 146, 0.1);
    box-shadow: inset 0 0 0 1px rgba(31, 99, 146, 0.06);
}

.release-progress-bar {
    height: 100%;
    border-radius: inherit;
    background: #10b981;
    box-shadow: 0 0 12px rgba(34, 211, 238, 0.26);
}

.release-download-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.4rem;
    margin-top: 0.55rem;
    padding: 0.42rem 0.65rem;
    border-radius: 8px;
    background: rgba(31, 99, 146, 0.1);
    border: 1px solid rgba(31, 99, 146, 0.18);
    color: var(--theme-primary);
    font-size: 0.72rem;
    font-weight: 600;
    text-decoration: none;
    white-space: nowrap;
}

.release-download-btn:hover {
    background: var(--theme-primary);
    color: #fff;
}

.release-section-title {
    margin: 0.75rem 0 0.45rem;
    color: var(--theme-primary);
    font-size: 0.72rem;
    font-weight: 600;
    text-transform: uppercase;
}

.release-feature-list {
    display: grid;
    gap: 0.45rem;
    max-height: 104px;
    overflow: auto;
    padding-right: 0.2rem;
}

.release-feature {
    display: grid;
    grid-template-columns: 18px minmax(0, 1fr) auto;
    gap: 0.55rem;
    align-items: center;
    min-width: 0;
    color: var(--gray-900);
    font-size: 0.84rem;
    background: rgba(248, 250, 252, 0.72);
    border: 1px solid rgba(31, 99, 146, 0.08);
    border-radius: 8px;
    padding: 0.42rem 0.5rem;
}

.release-feature input {
    width: 16px;
    height: 16px;
    accent-color: #10b981;
    pointer-events: none;
}

.release-feature span {
    min-width: 0;
    overflow-wrap: anywhere;
}

.release-feature small {
    color: var(--gray-600);
    font-size: 0.7rem;
    font-weight: 600;
    padding: 0.2rem 0.55rem;
    border-radius: 999px;
    background: #eef2f6;
    white-space: nowrap;
}

.release-feature input:checked ~ small {
    color: #0f766e;
    background: #d1fae5;
}

.sales-insights-column .delay-insights-wrap,
.sales-insights-column .delay-compact-card {
    height: 100%;
}

.sales-insights-column .delay-insights-wrap {
    margin-bottom: 0;
}

/* Animated 3D illustration in the Sales dashboard banner. */
.evortix-3d-scene {
    position: relative;
    top: 10px;
    width: min(100%, 300px);
    height: 138px;
    margin-left: auto;
    perspective: 900px;
    transform-style: preserve-3d;
}

.evortix-3d-platform {
    position: absolute;
    width: 220px;
    height: 112px;
    left: 50%;
    top: 50%;
    border: 1px solid rgba(31, 99, 146, 0.24);
    border-radius: 28px;
    background:
        linear-gradient(rgba(31, 99, 146, 0.08) 1px, transparent 1px),
        linear-gradient(90deg, rgba(31, 99, 146, 0.08) 1px, transparent 1px),
        linear-gradient(145deg, rgba(255, 255, 255, 0.96), rgba(219, 240, 252, 0.72));
    background-size: 22px 22px, 22px 22px, 100% 100%;
    box-shadow:
        -9px 12px 0 rgba(15, 74, 110, 0.1),
        -18px 25px 38px rgba(15, 74, 110, 0.18),
        0 0 30px rgba(34, 211, 238, 0.12);
    transform: translate(-50%, -50%) rotateX(61deg) rotateZ(-25deg);
    transform-style: preserve-3d;
    animation: evortixPlatformFloat 5s ease-in-out infinite;
}

.evortix-3d-core {
    position: absolute;
    left: 50%;
    top: 50%;
    width: 68px;
    height: 68px;
    display: grid;
    place-items: center;
    color: #ffffff;
    font-size: 1.45rem;
    border: 1px solid rgba(255, 255, 255, 0.7);
    border-radius: 22px;
    background: linear-gradient(145deg, #41a7d8, #15557f);
    box-shadow:
        inset 4px 4px 8px rgba(255, 255, 255, 0.3),
        inset -5px -6px 10px rgba(7, 48, 78, 0.26),
        -8px 13px 0 #0f4a6e,
        -14px 24px 26px rgba(15, 74, 110, 0.28),
        0 0 28px rgba(34, 211, 238, 0.28);
    transform: translate(-50%, -68%) translateZ(58px) rotateZ(25deg) rotateX(-12deg);
}

.evortix-3d-pillar {
    position: absolute;
    bottom: 28px;
    width: 22px;
    border-radius: 7px 7px 4px 4px;
    background: linear-gradient(90deg, #0f4a6e, #3a9acc 72%, #8ed9f4);
    box-shadow: -5px 7px 0 rgba(15, 74, 110, 0.3), -8px 12px 14px rgba(15, 74, 110, 0.2);
    transform: translateZ(24px) rotateZ(25deg) rotateX(-8deg);
    transform-origin: bottom;
}

.evortix-3d-pillar.pillar-one {
    left: 38px;
    height: 34px;
}

.evortix-3d-pillar.pillar-two {
    right: 38px;
    height: 54px;
}

.evortix-3d-pillar.pillar-three {
    right: 68px;
    bottom: 16px;
    height: 27px;
}

.evortix-3d-orbit {
    position: absolute;
    left: 50%;
    top: 50%;
    width: 152px;
    height: 58px;
    border: 2px solid rgba(34, 211, 238, 0.38);
    border-left-color: rgba(31, 99, 146, 0.08);
    border-radius: 50%;
    transform: translate(-50%, -72%) translateZ(74px) rotateZ(18deg);
    animation: evortixOrbit 7s linear infinite;
}

.evortix-3d-orbit::after {
    content: "";
    position: absolute;
    width: 10px;
    height: 10px;
    right: 13px;
    top: 2px;
    border-radius: 50%;
    background: #22d3ee;
    box-shadow: 0 0 0 5px rgba(34, 211, 238, 0.12), 0 0 18px #22d3ee;
}

@keyframes evortixPlatformFloat {
    0%, 100% { transform: translate(-50%, -50%) rotateX(61deg) rotateZ(-25deg) translateZ(0); }
    50% { transform: translate(-50%, -54%) rotateX(61deg) rotateZ(-25deg) translateZ(8px); }
}

@keyframes evortixOrbit {
    to { transform: translate(-50%, -72%) translateZ(74px) rotateZ(378deg); }
}

html[data-theme="dark"] .sales-dashboard-hero,
body[data-theme="dark"] .sales-dashboard-hero,
body.dark-mode .sales-dashboard-hero,
html[data-theme="dark"] .chart-card,
body[data-theme="dark"] .chart-card,
body.dark-mode .chart-card,
html[data-theme="dark"] .list-card,
body[data-theme="dark"] .list-card,
body.dark-mode .list-card,
html[data-theme="dark"] .planner-modal .modal-content,
body[data-theme="dark"] .planner-modal .modal-content,
body.dark-mode .planner-modal .modal-content,
html[data-theme="dark"] .release-chart-panel,
body[data-theme="dark"] .release-chart-panel,
body.dark-mode .release-chart-panel,
html[data-theme="dark"] .release-project,
body[data-theme="dark"] .release-project,
body.dark-mode .release-project {
    background: #071321;
    border-color: rgba(31, 99, 146, 0.24) !important;
    box-shadow: 0 16px 36px rgba(3, 14, 25, 0.26);
    color: #d7e4f2;
}

html[data-theme="dark"] .sales-dashboard-hero h1,
body[data-theme="dark"] .sales-dashboard-hero h1,
body.dark-mode .sales-dashboard-hero h1,
html[data-theme="dark"] .chart-card .card-header h5,
body[data-theme="dark"] .chart-card .card-header h5,
body.dark-mode .chart-card .card-header h5,
html[data-theme="dark"] .list-card .card-header h5,
body[data-theme="dark"] .list-card .card-header h5,
body.dark-mode .list-card .card-header h5,
html[data-theme="dark"] .client-info h6,
body[data-theme="dark"] .client-info h6,
body.dark-mode .client-info h6,
html[data-theme="dark"] .timeline-title,
body[data-theme="dark"] .timeline-title,
body.dark-mode .timeline-title,
html[data-theme="dark"] .agenda-title,
body[data-theme="dark"] .agenda-title,
body.dark-mode .agenda-title,
html[data-theme="dark"] .agenda-next-title,
body[data-theme="dark"] .agenda-next-title,
body.dark-mode .agenda-next-title,
html[data-theme="dark"] .agenda-date-label,
body[data-theme="dark"] .agenda-date-label,
body.dark-mode .agenda-date-label,
html[data-theme="dark"] .planner-modal .modal-title,
body[data-theme="dark"] .planner-modal .modal-title,
body.dark-mode .planner-modal .modal-title,
html[data-theme="dark"] .planner-month,
body[data-theme="dark"] .planner-month,
body.dark-mode .planner-month,
html[data-theme="dark"] .planner-panel h6,
body[data-theme="dark"] .planner-panel h6,
body.dark-mode .planner-panel h6,
html[data-theme="dark"] .plan-title,
body[data-theme="dark"] .plan-title,
body.dark-mode .plan-title,
html[data-theme="dark"] .calendar-date,
body[data-theme="dark"] .calendar-date,
body.dark-mode .calendar-date,
html[data-theme="dark"] .release-project-title,
body[data-theme="dark"] .release-project-title,
body.dark-mode .release-project-title,
html[data-theme="dark"] .release-feature,
body[data-theme="dark"] .release-feature,
body.dark-mode .release-feature {
    color: #d7e4f2;
}

html[data-theme="dark"] .sales-dashboard-shell,
body[data-theme="dark"] .sales-dashboard-shell,
body.dark-mode .sales-dashboard-shell {
    --tech-panel-tint: rgba(76, 184, 255, 0.09);
    --tech-grid-line: rgba(138, 210, 255, 0.075);
    --tech-node: rgba(34, 211, 238, 0.16);
    --card-sheen: rgba(255, 255, 255, 0.035);
    --card-sheen-soft: rgba(138, 210, 255, 0.055);
    --card-corner-glow: rgba(76, 184, 255, 0.14);
}

html[data-theme="dark"] .stat-card-modern,
body[data-theme="dark"] .stat-card-modern,
body.dark-mode .stat-card-modern {
    --stat-foreground: #eaf6ff;
    --stat-muted: rgba(234, 246, 255, 0.68);
    --stat-border: rgba(78, 155, 199, 0.22);
    --stat-border-hover: rgba(78, 155, 199, 0.34);
    --stat-grid-line: rgba(138, 210, 255, 0.07);
    --stat-node: rgba(34, 211, 238, 0.16);
    background:
        radial-gradient(circle at 100% 0%, var(--card-corner-glow), transparent 34%),
        linear-gradient(135deg, var(--card-sheen) 0%, rgba(255, 255, 255, 0) 38%, var(--tech-panel-tint) 100%),
        linear-gradient(180deg, var(--card-sheen-soft), transparent 56%),
        rgba(12, 31, 49, 0.96);
}

html[data-theme="dark"] .stat-card-modern::before,
body[data-theme="dark"] .stat-card-modern::before,
body.dark-mode .stat-card-modern::before {
    inset: 0;
    border-radius: 0;
    background: linear-gradient(90deg, transparent, rgba(138, 210, 255, 0.055));
}

html[data-theme="dark"] .sales-dashboard-hero .text-muted,
body[data-theme="dark"] .sales-dashboard-hero .text-muted,
body.dark-mode .sales-dashboard-hero .text-muted,
html[data-theme="dark"] .client-info .client-details,
body[data-theme="dark"] .client-info .client-details,
body.dark-mode .client-info .client-details,
html[data-theme="dark"] .timeline-time,
body[data-theme="dark"] .timeline-time,
body.dark-mode .timeline-time,
html[data-theme="dark"] .timeline-desc,
body[data-theme="dark"] .timeline-desc,
body.dark-mode .timeline-desc,
html[data-theme="dark"] .empty-state-modern p,
body[data-theme="dark"] .empty-state-modern p,
body.dark-mode .empty-state-modern p,
html[data-theme="dark"] .agenda-subtitle,
body[data-theme="dark"] .agenda-subtitle,
body.dark-mode .agenda-subtitle,
html[data-theme="dark"] .agenda-today-badge span,
body[data-theme="dark"] .agenda-today-badge span,
body.dark-mode .agenda-today-badge span,
html[data-theme="dark"] .agenda-next-label,
body[data-theme="dark"] .agenda-next-label,
body.dark-mode .agenda-next-label,
html[data-theme="dark"] .agenda-next-meta,
body[data-theme="dark"] .agenda-next-meta,
body.dark-mode .agenda-next-meta,
html[data-theme="dark"] .calendar-weekday,
body[data-theme="dark"] .calendar-weekday,
body.dark-mode .calendar-weekday,
html[data-theme="dark"] .calendar-more,
body[data-theme="dark"] .calendar-more,
body.dark-mode .calendar-more,
html[data-theme="dark"] .plan-meta,
body[data-theme="dark"] .plan-meta,
body.dark-mode .plan-meta,
html[data-theme="dark"] .release-project-client,
body[data-theme="dark"] .release-project-client,
body.dark-mode .release-project-client,
html[data-theme="dark"] .release-progress-label,
body[data-theme="dark"] .release-progress-label,
body.dark-mode .release-progress-label,
html[data-theme="dark"] .release-section-title,
body[data-theme="dark"] .release-section-title,
body.dark-mode .release-section-title,
html[data-theme="dark"] .release-feature small,
body[data-theme="dark"] .release-feature small,
body.dark-mode .release-feature small {
    color: #9db6cf !important;
}

html[data-theme="dark"] .chart-card .card-header,
body[data-theme="dark"] .chart-card .card-header,
body.dark-mode .chart-card .card-header,
html[data-theme="dark"] .list-card .card-header,
body[data-theme="dark"] .list-card .card-header,
body.dark-mode .list-card .card-header,
html[data-theme="dark"] .planner-modal .modal-header,
body[data-theme="dark"] .planner-modal .modal-header,
body.dark-mode .planner-modal .modal-header {
    background: #071321;
    border-color: rgba(31, 99, 146, 0.24);
}

html[data-theme="dark"] .list-item-modern,
body[data-theme="dark"] .list-item-modern,
body.dark-mode .list-item-modern,
html[data-theme="dark"] .timeline-item-modern::after,
body[data-theme="dark"] .timeline-item-modern::after,
body.dark-mode .timeline-item-modern::after,
html[data-theme="dark"] .calendar-grid,
body[data-theme="dark"] .calendar-grid,
body.dark-mode .calendar-grid,
html[data-theme="dark"] .calendar-weekday,
body[data-theme="dark"] .calendar-weekday,
body.dark-mode .calendar-weekday,
html[data-theme="dark"] .calendar-day,
body[data-theme="dark"] .calendar-day,
body.dark-mode .calendar-day,
html[data-theme="dark"] .planner-nav a,
body[data-theme="dark"] .planner-nav a,
body.dark-mode .planner-nav a {
    border-color: rgba(31, 99, 146, 0.24);
}

html[data-theme="dark"] .list-item-modern:hover,
body[data-theme="dark"] .list-item-modern:hover,
body.dark-mode .list-item-modern:hover,
html[data-theme="dark"] .agenda-summary,
body[data-theme="dark"] .agenda-summary,
body.dark-mode .agenda-summary,
html[data-theme="dark"] .agenda-date-group,
body[data-theme="dark"] .agenda-date-group,
body.dark-mode .agenda-date-group,
html[data-theme="dark"] .planner-panel,
body[data-theme="dark"] .planner-panel,
body.dark-mode .planner-panel,
html[data-theme="dark"] .calendar-weekday,
body[data-theme="dark"] .calendar-weekday,
body.dark-mode .calendar-weekday,
html[data-theme="dark"] .calendar-day:hover,
body[data-theme="dark"] .calendar-day:hover,
body.dark-mode .calendar-day:hover,
html[data-theme="dark"] .calendar-day.is-selected,
body[data-theme="dark"] .calendar-day.is-selected,
body.dark-mode .calendar-day.is-selected,
html[data-theme="dark"] .release-project,
body[data-theme="dark"] .release-project,
body.dark-mode .release-project {
    background: #0a1b2c;
    border-color: rgba(31, 99, 146, 0.24);
}

html[data-theme="dark"] .agenda-today-badge,
body[data-theme="dark"] .agenda-today-badge,
body.dark-mode .agenda-today-badge,
html[data-theme="dark"] .calendar-day,
body[data-theme="dark"] .calendar-day,
body.dark-mode .calendar-day,
html[data-theme="dark"] .plan-item,
body[data-theme="dark"] .plan-item,
body.dark-mode .plan-item {
    background: #071321;
    border-color: rgba(31, 99, 146, 0.24);
}

html[data-theme="dark"] .calendar-day.is-muted,
body[data-theme="dark"] .calendar-day.is-muted,
body.dark-mode .calendar-day.is-muted {
    background: #06101b;
    color: #637f9a;
}

html[data-theme="dark"] .release-progress-track,
body[data-theme="dark"] .release-progress-track,
body.dark-mode .release-progress-track {
    background: rgba(157, 182, 207, 0.18);
}

html[data-theme="dark"] .release-download-btn,
body[data-theme="dark"] .release-download-btn,
body.dark-mode .release-download-btn {
    background: rgba(31, 99, 146, 0.14);
    border-color: rgba(31, 99, 146, 0.3);
    color: #bfe6ff;
}

html[data-theme="dark"] .agenda-open-btn,
body[data-theme="dark"] .agenda-open-btn,
body.dark-mode .agenda-open-btn,
html[data-theme="dark"] .agenda-more-btn,
body[data-theme="dark"] .agenda-more-btn,
body.dark-mode .agenda-more-btn {
    background: rgba(31, 99, 146, 0.16);
    border-color: rgba(31, 99, 146, 0.3);
    box-shadow: none;
}

html[data-theme="dark"] .release-feature,
body[data-theme="dark"] .release-feature,
body.dark-mode .release-feature {
    background: rgba(10, 27, 44, 0.78);
    border-color: rgba(31, 99, 146, 0.2);
}

html[data-theme="dark"] .release-feature small,
body[data-theme="dark"] .release-feature small,
body.dark-mode .release-feature small {
    background: rgba(157, 182, 207, 0.14);
    color: #9db6cf;
}

html[data-theme="dark"] .release-feature input:checked ~ small,
body[data-theme="dark"] .release-feature input:checked ~ small,
body.dark-mode .release-feature input:checked ~ small {
    background: rgba(16, 185, 129, 0.16);
    color: #7ee3bd;
}

html[data-theme="dark"] .sales-dashboard-hero,
body[data-theme="dark"] .sales-dashboard-hero,
body.dark-mode .sales-dashboard-hero,
html[data-theme="dark"] .chart-card,
body[data-theme="dark"] .chart-card,
body.dark-mode .chart-card,
html[data-theme="dark"] .list-card,
body[data-theme="dark"] .list-card,
body.dark-mode .list-card {
    --surface-highlight: rgba(138, 210, 255, 0.08);
    box-shadow:
        inset 0 1px 0 var(--surface-highlight),
        0 7px 0 rgba(2, 11, 20, 0.66),
        0 24px 44px rgba(2, 11, 20, 0.34),
        0 0 34px rgba(34, 211, 238, 0.07) !important;
}

html[data-theme="dark"] .stat-card-modern,
body[data-theme="dark"] .stat-card-modern,
body.dark-mode .stat-card-modern {
    --surface-highlight: rgba(138, 210, 255, 0.1);
    box-shadow:
        inset 0 1px 0 var(--surface-highlight),
        0 6px 0 rgba(2, 11, 20, 0.7),
        0 20px 36px rgba(2, 11, 20, 0.34);
}

html[data-theme="dark"] .stat-card-modern .stat-icon,
body[data-theme="dark"] .stat-card-modern .stat-icon,
body.dark-mode .stat-card-modern .stat-icon {
    background: linear-gradient(145deg, rgba(23, 63, 91, 0.98), rgba(7, 25, 41, 0.98));
    border-color: rgba(138, 210, 255, 0.18);
    box-shadow:
        inset 2px 2px 0 rgba(138, 210, 255, 0.1),
        0 5px 0 rgba(2, 11, 20, 0.72),
        0 14px 22px rgba(2, 11, 20, 0.36);
}

html[data-theme="dark"] .evortix-3d-platform,
body[data-theme="dark"] .evortix-3d-platform,
body.dark-mode .evortix-3d-platform {
    background:
        linear-gradient(rgba(138, 210, 255, 0.1) 1px, transparent 1px),
        linear-gradient(90deg, rgba(138, 210, 255, 0.1) 1px, transparent 1px),
        linear-gradient(145deg, rgba(18, 49, 72, 0.98), rgba(5, 21, 35, 0.98));
    background-size: 22px 22px, 22px 22px, 100% 100%;
    box-shadow: -9px 12px 0 rgba(2, 11, 20, 0.72), -18px 25px 38px rgba(2, 11, 20, 0.42), 0 0 30px rgba(34, 211, 238, 0.11);
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

    .release-project-header {
        flex-direction: column;
    }

    .release-progress {
        width: 100%;
        min-width: 0;
    }

    .release-progress-legend {
        gap: 0.45rem;
    }

    .release-progress-metric {
        width: 96px;
        min-width: 96px;
        flex: 0 0 96px;
        padding-inline: 0.25rem;
    }

    .release-progress-metric-label {
        font-size: 0.58rem;
    }

    .release-download-btn {
        width: 100%;
    }

    .release-graph-layout {
        grid-template-columns: 1fr;
        padding: 1rem;
    }

    .release-chart-panel {
        min-height: auto;
    }

    .release-chart-summary {
        gap: 0.55rem 0.9rem;
    }

    .release-checklist {
        max-height: none;
        overflow: visible;
    }

    .release-feature {
        grid-template-columns: 18px minmax(0, 1fr);
    }

    .release-feature small {
        grid-column: 2;
        white-space: normal;
    }

    .calendar-scroll {
        overflow-x: auto;
        padding-bottom: 0.35rem;
        -webkit-overflow-scrolling: touch;
    }

    .calendar-scroll .calendar-grid {
        min-width: 720px;
    }
}

@media (hover: none), (pointer: coarse), (prefers-reduced-motion: reduce) {
    .sales-dashboard-hero,
    .stat-card-modern,
    .chart-card,
    .list-card {
        --tilt-x: 0deg !important;
        --tilt-y: 0deg !important;
        --card-lift: 0px !important;
        transform: none !important;
        transition-duration: 0.01ms !important;
    }

    .evortix-3d-platform,
    .evortix-3d-orbit {
        animation: none !important;
    }

    .stat-overdue.has-alert {
        animation: none !important;
    }
}
/* Evortix login flight and live dashboard briefing. */
.chat-bot-float.is-dashboard-welcome-tour {
    z-index: 1096;
    pointer-events: none;
    will-change: transform, opacity;
}

.chat-bot-float.is-dashboard-welcome-tour .chat-bot-speech {
    opacity: 0 !important;
    visibility: hidden !important;
    pointer-events: none !important;
}

.chat-bot-speech.evortix-dashboard-summary {
    top: 42%;
    right: calc(100% + 18px);
    width: min(390px, calc(100vw - 190px));
    min-width: 340px;
    padding: 1rem 1.05rem 1.05rem;
    border-radius: 22px 22px 8px 22px;
    pointer-events: auto;
    animation: evortixDashboardSummaryReveal .55s cubic-bezier(.16, .86, .22, 1) both;
}

.evortix-dashboard-summary-head {
    position: relative;
    z-index: 2;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: .75rem;
    padding-left: .75rem;
}

.evortix-dashboard-summary-eyebrow {
    color: #157baa;
    font-size: .65rem;
    font-weight: 800;
    letter-spacing: .1em;
    text-transform: uppercase;
}

.chat-bot-speech .evortix-dashboard-summary-title {
    min-height: 0;
    padding: 0;
    color: #083a5d;
    font-size: .98rem;
    line-height: 1.3;
}

.evortix-dashboard-summary-close {
    display: grid;
    flex: 0 0 30px;
    width: 30px;
    height: 30px;
    place-items: center;
    border: 0;
    border-radius: 50%;
    background: rgba(255, 255, 255, .7);
    color: #0d6f9f;
    cursor: pointer;
    box-shadow: 0 5px 14px rgba(15, 119, 183, .15);
}

.evortix-dashboard-summary-intro {
    position: relative;
    z-index: 2;
    margin: .5rem 0 .7rem;
    color: #175f8f;
    font-size: .74rem;
    font-weight: 700;
}

.evortix-live-actions {
    position: relative;
    z-index: 2;
    display: grid;
    gap: .45rem;
    max-height: 285px;
    overflow-y: auto;
}

.evortix-live-action {
    display: flex;
    align-items: center;
    gap: .7rem;
    min-width: 0;
    padding: .65rem .75rem;
    border: 1px solid rgba(8, 123, 185, .2);
    border-radius: 13px;
    background: rgba(255, 255, 255, .82);
    color: #0b466d;
    text-decoration: none;
    box-shadow: 0 5px 14px rgba(15, 119, 183, .08);
}

.evortix-live-action:hover,
.evortix-live-action:focus-visible {
    border-color: rgba(8, 123, 185, .45);
    background: #fff;
    color: #083a5d;
}

.evortix-live-action > i {
    flex: 0 0 auto;
    color: #138ac1;
}

.evortix-live-action span {
    display: grid;
    min-width: 0;
}

.evortix-live-action strong,
.evortix-live-action small {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.evortix-live-action strong {
    font-size: .75rem;
}

.evortix-live-action small {
    color: #5b7f97;
    font-size: .64rem;
    font-weight: 700;
}

.evortix-dashboard-summary-grid {
    position: relative;
    z-index: 2;
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: .48rem;
}

.evortix-dashboard-summary-metric {
    min-width: 0;
    padding: .62rem .68rem;
    border: 1px solid rgba(8, 123, 185, .17);
    border-radius: 12px;
    background: rgba(255, 255, 255, .78);
    box-shadow: 0 6px 16px rgba(15, 119, 183, .09);
}

.evortix-dashboard-summary-metric-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .5rem;
}

.evortix-dashboard-summary-metric i {
    color: #138ac1;
    font-size: .78rem;
}

.chat-bot-speech .evortix-dashboard-summary-value {
    min-height: 0;
    padding: 0;
    color: #083a5d;
    font-size: 1.1rem;
    line-height: 1;
}

.evortix-dashboard-summary-label {
    margin-top: .36rem;
    overflow: hidden;
    color: #0b466d;
    font-size: .71rem;
    font-weight: 850;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.evortix-dashboard-summary-meta {
    margin-top: .16rem;
    color: #5b7f97;
    font-size: .63rem;
    font-weight: 700;
}

.evortix-dashboard-summary-metric.is-overdue .evortix-dashboard-summary-value,
.evortix-dashboard-summary-metric.is-overdue i {
    color: #c2413b;
}

.stats-grid.is-evortix-highlight .stat-card-modern {
    animation: evortixDashboardCardBrief 1.5s ease-in-out 2;
}

.stats-grid.is-evortix-highlight .stat-card-modern:nth-child(2) { animation-delay: .1s; }
.stats-grid.is-evortix-highlight .stat-card-modern:nth-child(3) { animation-delay: .2s; }
.stats-grid.is-evortix-highlight .stat-card-modern:nth-child(4) { animation-delay: .3s; }

@keyframes evortixDashboardSummaryReveal {
    from { opacity: 0; transform: translateY(-50%) translateX(18px) scale(.94); }
    to { opacity: 1; transform: translateY(-50%) translateX(0) scale(1); }
}

@keyframes evortixDashboardCardBrief {
    0%, 100% { border-color: var(--stat-border); box-shadow: var(--shadow-sm), 0 0 26px var(--stat-glow); }
    50% { border-color: rgba(34, 190, 238, .5); box-shadow: var(--shadow-md), 0 0 34px rgba(34, 211, 238, .24); }
}

@media (max-width: 640px) {
    .chat-bot-speech.evortix-dashboard-summary {
        right: calc(100% + 9px);
        width: min(270px, calc(100vw - 145px));
        min-width: 220px;
        padding: .85rem;
    }

    .evortix-dashboard-summary-grid {
        grid-template-columns: 1fr;
        max-height: 238px;
        overflow-y: auto;
    }
}

@media (prefers-reduced-motion: reduce) {
    .chat-bot-speech.evortix-dashboard-summary,
    .stats-grid.is-evortix-highlight .stat-card-modern {
        animation: none !important;
    }
}
</style>

<div class="container-fluid px-4 py-4 sales-dashboard-shell role-dashboard-shell">
    <!-- Welcome Section -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm sales-dashboard-hero role-dashboard-hero">
                <div class="card-body p-4">
                    <div class="row align-items-center g-3">
                        <div class="col-lg-8 role-dashboard-hero-copy">
                            <span class="badge sales-overview-badge mb-2 px-3 py-2">Sales Overview</span>
                            <h1 class="h3 mb-1">Welcome back, <?php echo htmlspecialchars($user['full_name']); ?>!</h1>
                            <p class="text-muted mb-0 small">Sales Dashboard - Track clients, manage projects, and monitor performance metrics for business growth.</p>
                        </div>
                        <div class="col-lg-4 d-none d-lg-flex justify-content-end">
                            <div class="role-3d-scene role-3d-scene--sales" aria-hidden="true">
                                <div class="role-3d-platform">
                                    <span class="role-3d-node role-3d-node--one"><i class="fas fa-handshake"></i></span>
                                    <span class="role-3d-node role-3d-node--two"><i class="fas fa-chart-column"></i></span>
                                    <span class="role-3d-orbit"></span>
                                    <span class="role-3d-core"><i class="fas fa-chart-line"></i></span>
                                </div>
                            </div>
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
        
        <div class="stat-card-modern stat-overdue <?php echo (int) $overdueProjects > 0 ? 'has-alert' : ''; ?>">
            <i class="fas fa-heart-pulse stat-icon"></i>
            <div class="stat-label">Overdue</div>
            <div class="stat-number"><?php echo (int) $overdueProjects; ?></div>
            <div class="stat-sub"><?php echo (int) $overdueProjects > 0 ? 'Needs immediate follow-up' : 'No overdue projects'; ?></div>
        </div>
    </div>

    <!-- Trends and Calendar Row -->
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
            <div class="list-card">
                <div class="card-body">
                    <div class="agenda-preview">
                        <div class="agenda-header">
                            <div>
                                <h5 class="agenda-title">Calendar</h5>
                                <p class="agenda-subtitle"><?php echo count($dashboardScheduleEvents); ?> upcoming item<?php echo count($dashboardScheduleEvents) === 1 ? '' : 's'; ?></p>
                            </div>
                            <a href="<?php echo htmlspecialchars(BASE_URL . 'calendar.php'); ?>" class="agenda-open-btn" aria-label="Open calendar page">
                                <i class="far fa-calendar-alt"></i>
                            </a>
                        </div>

                        <div class="agenda-summary">
                            <div class="agenda-today-badge">
                                <div>
                                    <span><?php echo htmlspecialchars(date('M')); ?></span>
                                    <strong><?php echo htmlspecialchars(date('d')); ?></strong>
                                </div>
                            </div>
                            <div class="agenda-next">
                                <div class="agenda-next-label">Next Schedule</div>
                                <?php if ($nextDashboardEvent): ?>
                                    <div class="agenda-next-title"><?php echo htmlspecialchars((string) $nextDashboardEvent['title']); ?></div>
                                    <div class="agenda-next-meta">
                                        <i class="far fa-clock me-1"></i>
                                        <?php echo htmlspecialchars($nextDashboardEvent['datetime']->format('M d, h:i A')); ?>
                                    </div>
                                <?php else: ?>
                                    <div class="agenda-next-title">No upcoming schedule</div>
                                    <div class="agenda-next-meta">Your next items will appear here.</div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($dashboardScheduleEvents): ?>
                            <div class="agenda-list">
                                <?php foreach ($dashboardScheduleEvents as $event): ?>
                                    <?php
                                    $eventType = strtolower((string) ($event['type'] ?? 'schedule'));
                                    $eventColor = match ($eventType) {
                                        'deadline' => '#dc2626',
                                        'milestone' => '#7c3aed',
                                        'review' => '#d97706',
                                        'personal' => '#64748b',
                                        default => '#1f6392',
                                    };
                                    ?>
                                    <div class="agenda-date-group">
                                        <div class="agenda-date-row">
                                            <span class="agenda-date-label"><?php echo htmlspecialchars($event['datetime']->format('D, M d')); ?></span>
                                            <a href="<?php echo htmlspecialchars(BASE_URL . 'calendar.php'); ?>" class="agenda-more-btn" aria-label="Open calendar page">
                                                <i class="fas fa-ellipsis-h"></i>
                                            </a>
                                        </div>
                                        <div class="agenda-item">
                                            <div class="agenda-time"><?php echo htmlspecialchars($event['datetime']->format('H:i')); ?></div>
                                            <span class="agenda-color" style="background: <?php echo htmlspecialchars($eventColor); ?>;"></span>
                                            <div>
                                                <div class="agenda-kicker"><?php echo htmlspecialchars(ucfirst($eventType)); ?></div>
                                                <div class="agenda-text"><?php echo htmlspecialchars((string) $event['title']); ?></div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state-modern" style="padding: 2rem 1rem;">
                                <i class="fas fa-calendar-week"></i>
                                <p>No upcoming schedules</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Status and Insights Row -->
    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="chart-card">
                <div class="card-header">
                    <h5>
                        <i class="fas fa-list-check"></i>
                        Project Release Checklist
                    </h5>
                </div>
                <div class="card-body">
                    <?php if ($projectChecklists): ?>
                        <div class="release-graph-layout">
                            <div class="release-chart-panel">
                                <div class="release-chart-summary">
                                    <button class="release-summary-pill merged" type="button" data-release-legend-index="0" aria-pressed="true" title="Show or hide Merged">
                                        <span>Merged</span>
                                    </button>
                                    <button class="release-summary-pill review" type="button" data-release-legend-index="1" aria-pressed="true" title="Show or hide In Review">
                                        <span>In Review</span>
                                    </button>
                                    <button class="release-summary-pill waiting" type="button" data-release-legend-index="2" aria-pressed="true" title="Show or hide Waiting">
                                        <span>Waiting</span>
                                    </button>
                                </div>
                                <canvas id="releaseChecklistChart"></canvas>
                            </div>
                            <div class="release-checklist">
                                <?php foreach ($projectChecklists as $projectChecklist): ?>
                                    <div class="release-project">
                                        <div class="release-project-header">
                                            <div>
                                                <h6 class="release-project-title"><?php echo htmlspecialchars((string) $projectChecklist['project_name']); ?></h6>
                                                <div class="release-project-client"><?php echo htmlspecialchars((string) $projectChecklist['client_name']); ?></div>
                                            </div>
                                            <div class="release-progress">
                                                <div class="release-progress-label">
                                                    <?php echo (int) $projectChecklist['merged_features']; ?> / <?php echo (int) $projectChecklist['total_features']; ?> merged
                                                </div>
                                                <div class="release-progress-track">
                                                    <div class="release-progress-bar" style="width: <?php echo (int) $projectChecklist['progress']; ?>%;"></div>
                                                </div>
                                                <?php
                                                $canDownloadChecklistRepository = (string) ($projectChecklist['project_status'] ?? '') === 'completed'
                                                    && (int) ($projectChecklist['repository_id'] ?? 0) > 0
                                                    && (
                                                        (int) ($projectChecklist['project_created_by'] ?? 0) === $currentUserId
                                                        || (int) ($projectChecklist['client_created_by'] ?? 0) === $currentUserId
                                                    );
                                                ?>
                                                <?php if ($canDownloadChecklistRepository): ?>
                                                    <a
                                                        class="release-download-btn"
                                                        href="<?php echo htmlspecialchars(BASE_URL . 'api/version-control/download-repository.php?repository_id=' . (int) $projectChecklist['repository_id']); ?>"
                                                        title="Download <?php echo htmlspecialchars((string) (($projectChecklist['repository_branch_name'] ?? '') ?: 'main')); ?> branch code"
                                                    >
                                                        <i class="fas fa-download"></i>
                                                        Main Branch
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <?php
                                        $renderFeatureList = static function (array $features): void {
                                            echo '<div class="release-feature-list">';
                                            foreach ($features as $feature) {
                                                $isMerged = !empty($feature['is_merged']);
                                                $isInReview = !$isMerged && in_array((string) ($feature['status'] ?? ''), ['in_review', 'for_review', 'qa_testing', 'qa_passed', 'architect_review'], true);
                                                $mergedBy = trim((string) ($feature['architect_name'] ?? ''));
                                                echo '<label class="release-feature">';
                                                echo '<input type="checkbox" disabled ' . ($isMerged ? 'checked' : '') . '>';
                                                echo '<span>' . htmlspecialchars((string) ($feature['title'] ?? 'Feature')) . '</span>';
                                                echo '<small>' . ($isMerged ? 'Merged' . ($mergedBy !== '' ? ' by ' . htmlspecialchars($mergedBy) : '') : ($isInReview ? 'In review' : 'Waiting')) . '</small>';
                                                echo '</label>';
                                            }
                                            echo '</div>';
                                        };
                                        ?>

                                        <?php if (!empty($projectChecklist['features'])): ?>
                                            <div class="release-section-title">Features</div>
                                            <?php $renderFeatureList($projectChecklist['features']); ?>
                                        <?php endif; ?>

                                        <?php if (!empty($projectChecklist['additional_features'])): ?>
                                            <div class="release-section-title">Additional Features</div>
                                            <?php $renderFeatureList($projectChecklist['additional_features']); ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="empty-state-modern">
                            <i class="fas fa-list-check"></i>
                            <p>No feature checklist items yet</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="chart-card">
                <div class="card-header">
                    <h5>
                        <i class="fas fa-chart-pie"></i>
                        Project Status Distribution
                    </h5>
                </div>
                <div class="card-body">
                    <canvas id="statusChart" style="height: 20px; width: 100%;"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Insights and Recent Clients Row -->
    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="list-card h-100">
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
        <div class="col-lg-6 sales-insights-column">
            <?php
            delayInsightsRenderCompactList($conn, $user, [
                'limit' => max(1, (int) $insightProjectCount),
                'display' => 'portfolio_chart',
                'title' => 'Overall Project Risk',
                'subtitle' => 'Portfolio-wide delivery risk across all monitored client projects.',
                'show_empty' => true,
                'empty_title' => 'No project insights yet',
                'empty_message' => 'Project insight signals will appear here when they become available.',
            ]);
            ?>
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

                    <div class="calendar-scroll mb-3">
                        <div class="calendar-grid">
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
                                            <form method="POST" class="delete-plan-form" data-plan-title="<?php echo htmlspecialchars((string) ($plan['title'] ?? 'Plan'), ENT_QUOTES, 'UTF-8'); ?>" data-generic-draft-disabled="true" data-confirm-skip="true">
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

<script>
const evortixDashboardWelcomeData = <?php echo json_encode([
    'enabled' => $showEvortixLoginWelcome,
    'actions' => [
        'pending_projects' => $evortixPendingProjects,
        'inactive_clients' => $evortixInactiveClients,
        'overdue_projects' => $evortixOverdueProjects,
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
window.evortixDashboardWelcomeActive = Boolean(evortixDashboardWelcomeData.enabled);

document.addEventListener('DOMContentLoaded', async () => {
    if (!evortixDashboardWelcomeData.enabled) return;

    let completionSignalled = false;
    const signalWelcomeComplete = () => {
        if (completionSignalled) return;
        completionSignalled = true;
        window.evortixDashboardWelcomeActive = false;
        document.dispatchEvent(new CustomEvent('evortix:dashboard-welcome-complete'));
    };
    const waitForFloatingRobot = () => new Promise(resolve => {
        if (window.chatBotFloatRobot) {
            resolve(window.chatBotFloatRobot);
            return;
        }

        const ready = event => {
            if (event.detail?.name !== 'chatBotFloatRobot') return;
            document.removeEventListener('chatbot:controllable-robot-ready', ready);
            resolve(event.detail.controller || window.chatBotFloatRobot || null);
        };
        document.addEventListener('chatbot:controllable-robot-ready', ready);
        window.setTimeout(() => {
            document.removeEventListener('chatbot:controllable-robot-ready', ready);
            resolve(window.chatBotFloatRobot || null);
        }, 1800);
    });
    const escapeSummaryHtml = value => String(value ?? '').replace(/[&<>'"]/g, character => ({
        '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#39;', '"':'&quot;'
    })[character]);

    try {
        const botFloat = document.getElementById('chat-bot-float');
        const speech = botFloat?.querySelector('.chat-bot-speech');
        const botWidget = document.getElementById('chat-bot-widget');
        const statsGrid = document.querySelector('.stats-grid');
        if (!botFloat || !speech) {
            signalWelcomeComplete();
            return;
        }

        const robot = await waitForFloatingRobot();
        await new Promise(resolve => window.setTimeout(resolve, 180));

        const restoreDefaultSpeech = () => {
            speech.hidden = false;
            speech.className = 'chat-bot-speech';
            speech.removeAttribute('role');
            speech.removeAttribute('data-dashboard-summary-dismissed');
            speech.style.pointerEvents = '';
            speech.innerHTML = '<strong data-chat-bot-type="title" data-chat-bot-text="Hi, good day!"></strong>';
        };
        botWidget?.addEventListener('click', () => {
            if (botFloat.hidden && speech.hasAttribute('data-dashboard-summary-dismissed')) {
                restoreDefaultSpeech();
            }
        }, true);

        botFloat.classList.remove('is-leaving-bed', 'is-returning-bed', 'is-preparing-speech', 'is-speaking');
        botFloat.classList.add('is-dashboard-welcome-tour');
        botFloat.hidden = false;
        botFloat.setAttribute('aria-hidden', 'false');
        speech.hidden = true;
        robot?.wake?.();
        robot?.setEmotion?.('', 0);

        const botRect = botFloat.getBoundingClientRect();
        const statsRect = statsGrid?.getBoundingClientRect();
        const edgeGap = window.innerWidth <= 640 ? 12 : 32;
        const finalX = Math.max(10, window.innerWidth - botRect.width - edgeGap);
        const desiredY = statsRect
            ? statsRect.top + Math.min(statsRect.height * .5, 92) - botRect.height * .38
            : window.innerHeight * .3;
        const finalY = Math.min(
            Math.max(82, desiredY),
            Math.max(82, window.innerHeight - botRect.height - 18)
        );
        botFloat.style.left = `${finalX}px`;
        botFloat.style.top = `${finalY}px`;
        botFloat.style.right = 'auto';
        botFloat.style.bottom = 'auto';

        const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (!reduceMotion && typeof botFloat.animate === 'function') {
            const finalCenterX = finalX + botRect.width / 2;
            const finalCenterY = finalY + botRect.height / 2;
            const radiusX = Math.min(640, Math.max(170, (window.innerWidth - botRect.width) * .43));
            const radiusY = Math.max(70, Math.min(
                220,
                finalCenterY - 24,
                window.innerHeight - finalCenterY - 24
            ));
            const orbitCenterX = finalCenterX - radiusX;
            const flightFrames = [];
            for (let step = 0; step <= 8; step++) {
                const angle = -(Math.PI * 2 * step / 8);
                const pointX = orbitCenterX + Math.cos(angle) * radiusX;
                const pointY = finalCenterY + Math.sin(angle) * radiusY;
                const tilt = step === 0 || step === 8 ? 0 : Math.sin(angle) * -7;
                flightFrames.push({
                    offset: step * .105,
                    opacity: step === 0 ? 0 : 1,
                    transform: `translate(${(pointX - finalCenterX).toFixed(1)}px, ${(pointY - finalCenterY).toFixed(1)}px) rotate(${tilt.toFixed(1)}deg) scale(${step === 0 ? '.72' : '1'})`
                });
            }
            flightFrames.push({ offset:.94, opacity:1, transform:'translate(-12px, -5px) rotate(-2deg) scale(1.04)' });
            flightFrames.push({ offset:1, opacity:1, transform:'translate(0, 0) rotate(0) scale(1)' });
            const flight = botFloat.animate(flightFrames, {
                duration:4400,
                easing:'linear',
                fill:'both'
            });
            await flight.finished.catch(() => undefined);
            flight.cancel();
        }

        botFloat.classList.remove('is-dashboard-welcome-tour');
        speech.className = 'chat-bot-speech evortix-dashboard-summary';
        speech.hidden = false;
        speech.setAttribute('aria-hidden', 'false');
        speech.setAttribute('role', 'status');
        speech.style.pointerEvents = 'auto';
        const actions = evortixDashboardWelcomeData.actions || {};
        const pending = Array.isArray(actions.pending_projects) ? actions.pending_projects : [];
        const inactive = Array.isArray(actions.inactive_clients) ? actions.inactive_clients : [];
        const overdue = Array.isArray(actions.overdue_projects) ? actions.overdue_projects : [];
        const actionItems = [
            ...pending.map(item => ({
                label: `Follow up: ${item.project_name}`,
                meta: `${item.client_name} · Pending project`,
                icon: 'fa-clock',
                url: `clients.php?view=${encodeURIComponent(item.client_id)}&project=${encodeURIComponent(item.id)}`
            })),
            ...inactive.map(item => ({
                label: `Send proposal: ${item.client_name}`,
                meta: 'Inactive client',
                icon: 'fa-paper-plane',
                url: `clients.php?view=${encodeURIComponent(item.id)}`
            })),
            ...overdue.map(item => ({
                label: `Review: ${item.project_name}`,
                meta: `${item.client_name} · Due ${item.timeline_end}`,
                icon: 'fa-triangle-exclamation',
                url: `clients.php?view=${encodeURIComponent(item.client_id)}&project=${encodeURIComponent(item.id)}`
            }))
        ];
        const actionsMarkup = actionItems.length
            ? actionItems.slice(0, 8).map(item => `
                <a class="evortix-live-action" href="${escapeSummaryHtml(item.url)}">
                    <i class="fas ${escapeSummaryHtml(item.icon)}" aria-hidden="true"></i>
                    <span><strong>${escapeSummaryHtml(item.label)}</strong><small>${escapeSummaryHtml(item.meta)}</small></span>
                </a>`).join('')
            : '<div class="evortix-dashboard-summary-intro">You are all caught up. No items need attention.</div>';
        speech.innerHTML = `
            <div class="evortix-dashboard-summary-head">
                <div>
                    <strong class="evortix-dashboard-summary-title">Live Overview</strong>
                </div>
                <button type="button" class="evortix-dashboard-summary-close" aria-label="Dismiss dashboard overview">
                    <i class="fas fa-xmark" aria-hidden="true"></i>
                </button>
            </div>
            <div class="evortix-dashboard-summary-intro">${pending.length} pending, ${inactive.length} inactive, and ${overdue.length} overdue. Select an item to open it.</div>
            <div class="evortix-live-actions">${actionsMarkup}</div>`;

        statsGrid?.classList.add('is-evortix-highlight');
        window.setTimeout(() => statsGrid?.classList.remove('is-evortix-highlight'), 3800);
        if (typeof robot?.point === 'function') robot.point('left', 7000);
        else robot?.setEmotion?.('happy', 2600);

        let summaryTimer = null;
        const dismissSummary = () => {
            window.clearTimeout(summaryTimer);
            speech.hidden = true;
            speech.setAttribute('aria-hidden', 'true');
            speech.setAttribute('data-dashboard-summary-dismissed', 'true');
            robot?.setEmotion?.('', 0);
            signalWelcomeComplete();
        };
        speech.querySelector('.evortix-dashboard-summary-close')?.addEventListener('click', event => {
            event.stopPropagation();
            dismissSummary();
        });
        summaryTimer = window.setTimeout(dismissSummary, 15000);
    } catch (error) {
        console.warn('Evortix dashboard welcome could not complete:', error);
        document.getElementById('chat-bot-float')?.classList.remove('is-dashboard-welcome-tour');
        signalWelcomeComplete();
    }
});

(() => {
    const canTilt = window.matchMedia('(hover: hover) and (pointer: fine)').matches
        && !window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (!canTilt) {
        return;
    }

    document.querySelectorAll('.stat-card-modern').forEach((card) => {
        let animationFrame = null;

        card.addEventListener('pointermove', (event) => {
            if (animationFrame !== null) {
                cancelAnimationFrame(animationFrame);
            }

            animationFrame = requestAnimationFrame(() => {
                const bounds = card.getBoundingClientRect();
                const pointerX = Math.min(1, Math.max(0, (event.clientX - bounds.left) / bounds.width));
                const pointerY = Math.min(1, Math.max(0, (event.clientY - bounds.top) / bounds.height));
                const intensity = card.classList.contains('sales-dashboard-hero') ? 2.4 : 4.5;

                card.style.setProperty('--tilt-x', `${((0.5 - pointerY) * intensity).toFixed(2)}deg`);
                card.style.setProperty('--tilt-y', `${((pointerX - 0.5) * intensity).toFixed(2)}deg`);
                card.style.setProperty('--pointer-x', `${(pointerX * 100).toFixed(1)}%`);
                card.style.setProperty('--pointer-y', `${(pointerY * 100).toFixed(1)}%`);
                card.classList.add('is-tilting');
                animationFrame = null;
            });
        });

        card.addEventListener('pointerleave', () => {
            if (animationFrame !== null) {
                cancelAnimationFrame(animationFrame);
                animationFrame = null;
            }

            card.style.setProperty('--tilt-x', '0deg');
            card.style.setProperty('--tilt-y', '0deg');
            card.style.setProperty('--pointer-x', '50%');
            card.style.setProperty('--pointer-y', '50%');
            card.classList.remove('is-tilting');
        });
    });
})();

document.querySelectorAll('.delete-plan-form').forEach(function (form) {
    form.addEventListener('submit', async function (event) {
        if (form.dataset.confirmedSubmit === '1') {
            delete form.dataset.confirmedSubmit;
            return;
        }

        event.preventDefault();
        const submitter = event.submitter || form.querySelector('button[name="delete_plan"]');
        const planTitle = form.dataset.planTitle || 'this plan';
        const confirmed = typeof window.appConfirm === 'function'
            ? await window.appConfirm({
                title: 'Remove Plan',
                titleIcon: 'fas fa-calendar-xmark',
                subject: `Remove "${planTitle}"?`,
                message: 'This plan will be removed from the upcoming schedule.',
                icon: 'fas fa-trash-can fa-lg',
                confirmIcon: 'fas fa-trash',
                confirmText: 'Remove Plan',
                variant: 'danger'
            })
            : false;

        if (confirmed) {
            form.dataset.confirmedSubmit = '1';
            form.requestSubmit(submitter);
        }
    });
});

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
                backgroundColor: 'rgba(31, 99, 146, 0.08)',
                fill: false,
                borderWidth: 3,
                tension: 0.4,
                pointBackgroundColor: '#1f6392',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointRadius: 3,
                pointHoverRadius: 5
            },
            {
                label: 'Completed Projects',
                data: trendsData.completed,
                borderColor: '#10b981',
                backgroundColor: 'rgba(16, 185, 129, 0.08)',
                fill: false,
                borderWidth: 3,
                tension: 0.4,
                pointBackgroundColor: '#10b981',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointRadius: 3,
                pointHoverRadius: 5
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        interaction: {
            mode: 'index',
            intersect: false
        },
        plugins: {
            legend: {
                position: 'top',
                labels: {
                    usePointStyle: true,
                    boxWidth: 8,
                    padding: 18,
                    color: '#495057',
                    font: {
                        size: 11
                    }
                }
            },
            tooltip: {
                mode: 'index',
                intersect: false,
                backgroundColor: '#ffffff',
                borderColor: 'rgba(31, 99, 146, 0.16)',
                borderWidth: 1,
                titleColor: '#212529',
                bodyColor: '#495057',
                padding: 12,
                displayColors: true
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                border: {
                    display: false
                },
                grid: {
                    color: 'rgba(31, 99, 146, 0.08)'
                },
                ticks: {
                    stepSize: 1,
                    color: '#6c757d',
                    font: {
                        size: 11
                    }
                }
            },
            x: {
                border: {
                    display: false
                },
                grid: {
                    display: false
                },
                ticks: {
                    color: '#6c757d',
                    font: {
                        size: 11
                    }
                }
            }
        }
    }
});

// Project Release Checklist Chart
const releaseChecklistData = <?php
    echo json_encode([
        'labels' => ['Merged', 'In Review', 'Waiting'],
        'data' => [$releaseMergedTotal, $releaseReviewTotal, $releaseWaitingTotal],
        'colors' => ['#10b981', '#f59e0b', 'rgba(31, 99, 146, 0.22)'],
        'hoverColors' => ['#059669', '#d97706', 'rgba(31, 99, 146, 0.34)'],
    ]);
?>;

const releaseChecklistCanvas = document.getElementById('releaseChecklistChart');
if (releaseChecklistCanvas) {
    let releaseChecklistChart = null;
    const releaseLegendButtons = document.querySelectorAll('[data-release-legend-index]');
    const releaseVisibleData = [...releaseChecklistData.data];

    const bindReleaseLegendControls = () => {
        releaseLegendButtons.forEach(button => {
            button.addEventListener('click', () => {
                if (!releaseChecklistChart) return;

                const index = Number(button.dataset.releaseLegendIndex);
                const isVisible = button.getAttribute('aria-pressed') === 'true';
                button.setAttribute('aria-pressed', isVisible ? 'false' : 'true');
                button.classList.toggle('is-hidden', isVisible);
                releaseChecklistChart.data.datasets[0].data[index] = isVisible ? null : releaseVisibleData[index];
                releaseChecklistChart.update();
            });
        });
    };

    bindReleaseLegendControls();

    const renderReleaseChecklistChart = () => {
        releaseChecklistChart = new Chart(releaseChecklistCanvas.getContext('2d'), {
        type: 'bar',
        data: {
            labels: releaseChecklistData.labels,
            datasets: [{
                label: 'Count',
                data: releaseChecklistData.data,
                backgroundColor: releaseChecklistData.colors,
                hoverBackgroundColor: releaseChecklistData.hoverColors,
                borderRadius: 6,
                borderSkipped: false,
                barThickness: 'flex',
                maxBarThickness: 50
            }]
        },
        options: {
            indexAxis: 'x',
            responsive: true,
            maintainAspectRatio: false,
            animation: {
                duration: 1100,
                easing: 'easeOutQuart',
                delay: context => context.type === 'data' ? context.dataIndex * 180 : 0
            },
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    backgroundColor: '#ffffff',
                    borderColor: 'rgba(31, 99, 146, 0.16)',
                    borderWidth: 1,
                    titleColor: '#212529',
                    bodyColor: '#495057',
                    padding: 12,
                    callbacks: {
                        label: function(context) {
                            const value = context.raw || 0;
                            const total = releaseChecklistData.data.reduce((a, b) => a + b, 0);
                            const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : '0.0';
                            return `${value} (${percentage}%)`;
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        drawBorder: false,
                        color: 'rgba(31, 99, 146, 0.06)'
                    },
                    ticks: {
                        color: '#6b7280',
                        font: {
                            size: 10
                        }
                    }
                },
                x: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        color: '#6b7280',
                        font: {
                            size: 11,
                            weight: 600
                        }
                    }
                }
            }
        }
        });
        return releaseChecklistChart;
    };

    if ('IntersectionObserver' in window) {
        const releaseChartObserver = new IntersectionObserver(entries => {
            if (!entries.some(entry => entry.isIntersecting)) return;
            releaseChartObserver.disconnect();
            renderReleaseChecklistChart();
        }, { threshold: 0.25 });
        releaseChartObserver.observe(releaseChecklistCanvas);
    } else {
        renderReleaseChecklistChart();
    }
}

// Status Chart
const statusData = <?php 
    $statuses = [];
    $statusCounts = [];
    $statusColors = [];
    $statusHoverColors = [];
    $statusColorMap = [
        'pending' => ['#f59e0b', '#d97706'],
        'planning' => ['#f59e0b', '#d97706'],
        'requirements_gathering' => ['#2563eb', '#1d4ed8'],
        'requirement_gathering' => ['#2563eb', '#1d4ed8'],
        'in_progress' => ['#0e7490', '#0f766e'],
        'active' => ['#0e7490', '#0f766e'],
        'review' => ['#8b5cf6', '#7c3aed'],
        'completed' => ['#10b981', '#059669'],
        'cancelled' => ['#ef4444', '#dc2626'],
        'on_hold' => ['#64748b', '#475569'],
    ];
    $fallbackStatusPalette = [
        ['#1f6392', '#0f4a6e'],
        ['#0e7490', '#0f766e'],
        ['#8b5cf6', '#7c3aed'],
        ['#f59e0b', '#d97706'],
        ['#10b981', '#059669'],
        ['#ef4444', '#dc2626'],
    ];
    $fallbackIndex = 0;

    while($row = $projectStatus->fetch_assoc()) {
        $rawStatus = strtolower((string) $row['status']);
        $statuses[] = ucwords(str_replace('_', ' ', $rawStatus));
        $statusCounts[] = $row['count'];

        $statusPalette = $statusColorMap[$rawStatus] ?? $fallbackStatusPalette[$fallbackIndex % count($fallbackStatusPalette)];
        $statusColors[] = $statusPalette[0];
        $statusHoverColors[] = $statusPalette[1];

        if (!isset($statusColorMap[$rawStatus])) {
            $fallbackIndex++;
        }
    }
    echo json_encode(['labels' => $statuses, 'data' => $statusCounts, 'colors' => $statusColors, 'hoverColors' => $statusHoverColors]);
?>;

const statusCtx = document.getElementById('statusChart').getContext('2d');
new Chart(statusCtx, {
    type: 'doughnut',
    data: {
        labels: statusData.labels,
        datasets: [{
            data: statusData.data,
            backgroundColor: statusData.colors,
            hoverBackgroundColor: statusData.hoverColors,
            borderColor: '#ffffff',
            borderWidth: 4,
            hoverOffset: 4,
            spacing: 3
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '66%',
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    usePointStyle: true,
                    boxWidth: 8,
                    padding: 16,
                    color: '#495057',
                    font: {
                        size: 11
                    }
                }
            },
            tooltip: {
                backgroundColor: '#ffffff',
                borderColor: 'rgba(31, 99, 146, 0.16)',
                borderWidth: 1,
                titleColor: '#212529',
                bodyColor: '#495057',
                padding: 12,
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
