<?php
require_once __DIR__ . '/includes/auth.php';

requireLogin();

$conn = getConnection();
$user = getCurrentUser();

if (!$user) {
    header('Location: ' . BASE_URL . 'login.php');
    exit();
}

function calendarPageParseDateTime(string $dateValue, string $timeValue = ''): DateTime
{
    $dateValue = trim($dateValue);
    $timeValue = trim($timeValue);
    $candidate = $dateValue !== ''
        ? $dateValue . ($timeValue !== '' ? ' ' . $timeValue : '')
        : ($timeValue !== '' ? $timeValue : 'now');

    try {
        return new DateTime($candidate);
    } catch (Throwable $exception) {
        return new DateTime();
    }
}

function calendarPageAddEvent(array &$events, array $event): void
{
    $dateValue = trim((string) ($event['date'] ?? ''));
    $timeValue = trim((string) ($event['time'] ?? ''));
    if ($dateValue === '') {
        return;
    }

    $dateTime = calendarPageParseDateTime($dateValue, $timeValue);

    $events[] = [
        'title' => trim((string) ($event['title'] ?? 'Schedule')) ?: 'Schedule',
        'description' => trim((string) ($event['description'] ?? '')),
        'datetime' => $dateTime,
        'date_key' => $dateTime->format('Y-m-d'),
        'time_label' => $dateTime->format('h:i A'),
        'category' => trim((string) ($event['category'] ?? 'Calendar')) ?: 'Calendar',
        'color' => trim((string) ($event['color'] ?? '')) ?: '#1f6392',
        'url' => trim((string) ($event['url'] ?? '')),
        'status' => trim((string) ($event['status'] ?? '')),
    ];
}

$role = (string) ($user['role'] ?? '');
$userId = (int) $user['id'];

$calendarMonthInput = trim((string) ($_GET['calendar_month'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $calendarMonthInput)) {
    $calendarMonthInput = date('Y-m');
}

$calendarStart = DateTime::createFromFormat('Y-m-d', $calendarMonthInput . '-01') ?: new DateTime('first day of this month');
$calendarStart->setTime(0, 0, 0);
$calendarEnd = (clone $calendarStart)->modify('last day of this month');
$calendarMonth = $calendarStart->format('Y-m');
$monthStartString = $calendarStart->format('Y-m-d');
$monthEndString = $calendarEnd->format('Y-m-d');
$previousMonth = (clone $calendarStart)->modify('-1 month')->format('Y-m');
$nextMonth = (clone $calendarStart)->modify('+1 month')->format('Y-m');
$firstCalendarDay = (clone $calendarStart)->modify('-' . (int) $calendarStart->format('w') . ' days');
$lastCalendarDay = (clone $calendarEnd)->modify('+' . (6 - (int) $calendarEnd->format('w')) . ' days');
$leadingBlanks = (int) $calendarStart->format('w');
$trailingBlanks = 6 - (int) $calendarEnd->format('w');
$hasProjectStartTime = dbColumnExists($conn, 'projects', 'timeline_start_time');
$hasProjectEndTime = dbColumnExists($conn, 'projects', 'timeline_end_time');

$events = [];

$scheduleStatement = $conn->prepare("
    SELECT title, description, schedule_type, start_date, start_time, color_code, status
    FROM schedules
    WHERE user_id = ?
      AND status <> 'cancelled'
      AND start_date BETWEEN ? AND ?
    ORDER BY start_date ASC, start_time ASC, id ASC
");
$scheduleStatement->bind_param('iss', $userId, $monthStartString, $monthEndString);
$scheduleStatement->execute();
$scheduleRows = $scheduleStatement->get_result();

while ($row = $scheduleRows->fetch_assoc()) {
    calendarPageAddEvent($events, [
        'title' => (string) ($row['title'] ?? 'Schedule'),
        'description' => (string) ($row['description'] ?? ''),
        'date' => (string) ($row['start_date'] ?? ''),
        'time' => (string) ($row['start_time'] ?? '09:00:00'),
        'category' => ucfirst((string) ($row['schedule_type'] ?? 'Schedule')),
        'color' => trim((string) ($row['color_code'] ?? '')) ?: '#1f6392',
        'status' => (string) ($row['status'] ?? ''),
    ]);
}

if (in_array($role, ['architect', 'developer', 'expert', 'sul'], true)) {
    $taskStatement = $conn->prepare("
        SELECT
            t.title,
            t.description,
            t.deadline,
            t.status,
            t.priority,
            p.name AS project_name
        FROM tasks t
        LEFT JOIN projects p ON p.id = t.project_id
        WHERE t.assigned_to = ?
          AND t.deadline BETWEEN ? AND ?
          AND t.status <> 'completed'
        ORDER BY t.deadline ASC, t.id ASC
    ");
    $taskStatement->bind_param('iss', $userId, $monthStartString, $monthEndString);
    $taskStatement->execute();
    $taskRows = $taskStatement->get_result();

    while ($row = $taskRows->fetch_assoc()) {
        $status = (string) ($row['status'] ?? '');
        $priority = (string) ($row['priority'] ?? '');
        $color = '#1f6392';
        if ($status === 'blocked') {
            $color = '#dc3545';
        } elseif ($priority === 'high') {
            $color = '#fd7e14';
        } elseif ($status === 'review' || $status === 'in_review') {
            $color = '#0d6efd';
        }

        calendarPageAddEvent($events, [
            'title' => (string) ($row['title'] ?? 'Task'),
            'description' => trim((string) ($row['project_name'] ?? '')) !== ''
                ? 'Project: ' . (string) ($row['project_name'] ?? '')
                : '',
            'date' => (string) ($row['deadline'] ?? ''),
            'time' => '17:00:00',
            'category' => 'Task',
            'color' => $color,
            'status' => $status,
        ]);
    }
    $taskStatement->close();
}

if (in_array($role, ['admin', 'sales', 'architect', 'sul'], true)) {
    $projectStartTimeSelect = $hasProjectStartTime ? 'p.timeline_start_time' : 'NULL AS timeline_start_time';
    $projectEndTimeSelect = $hasProjectEndTime ? 'p.timeline_end_time' : 'NULL AS timeline_end_time';
    $projectAccessSql = '';

    if ($role === 'sales') {
        $projectAccessSql = 'AND p.created_by = ?';
    } elseif ($role === 'architect') {
        $projectAccessSql = 'AND (p.architect_id = ? OR p.architect_id IS NULL)';
    } elseif ($role === 'sul') {
        $projectAccessSql = 'AND p.sul_id = ?';
    }

    $projectStatement = $conn->prepare("
        SELECT p.id, p.client_id, p.name, p.timeline_start, {$projectStartTimeSelect}, p.timeline_end, {$projectEndTimeSelect}, p.status, c.name AS client_name
        FROM projects p
        INNER JOIN clients c ON c.id = p.client_id
        WHERE p.status NOT IN ('completed', 'cancelled')
          AND (
              p.timeline_start BETWEEN ? AND ?
              OR p.timeline_end BETWEEN ? AND ?
          )
          {$projectAccessSql}
        ORDER BY COALESCE(p.timeline_end, p.timeline_start) ASC, p.id ASC
    ");
    if ($projectAccessSql !== '') {
        $projectStatement->bind_param('ssssi', $monthStartString, $monthEndString, $monthStartString, $monthEndString, $userId);
    } else {
        $projectStatement->bind_param('ssss', $monthStartString, $monthEndString, $monthStartString, $monthEndString);
    }
    $projectStatement->execute();
    $projectRows = $projectStatement->get_result();

    while ($row = $projectRows->fetch_assoc()) {
        $description = trim((string) ($row['client_name'] ?? '')) !== ''
                ? 'Client: ' . (string) ($row['client_name'] ?? '')
                : '';
        $projectUrl = '';
        if ($role === 'architect') {
            $projectUrl = BASE_URL . 'modules/architect/project-review.php?id=' . (int) ($row['id'] ?? 0);
        } elseif ($role === 'sales') {
            $projectUrl = BASE_URL . 'modules/sales/report.php?client_id=' . (int) ($row['client_id'] ?? 0);
        }

        calendarPageAddEvent($events, [
            'title' => 'Start: ' . (string) ($row['name'] ?? 'Project'),
            'description' => $description,
            'date' => (string) ($row['timeline_start'] ?? ''),
            'time' => (string) ($row['timeline_start_time'] ?? '09:00:00'),
            'category' => 'Project Start',
            'color' => '#198754',
            'url' => $projectUrl,
            'status' => (string) ($row['status'] ?? ''),
        ]);

        calendarPageAddEvent($events, [
            'title' => 'Deadline: ' . (string) ($row['name'] ?? 'Project'),
            'description' => $description,
            'date' => (string) ($row['timeline_end'] ?? ''),
            'time' => (string) ($row['timeline_end_time'] ?? '17:00:00'),
            'category' => 'Project Deadline',
            'color' => '#dc3545',
            'url' => $projectUrl,
            'status' => (string) ($row['status'] ?? ''),
        ]);
    }
    $projectStatement->close();
}

usort($events, static function (array $left, array $right): int {
    return $left['datetime']->getTimestamp() <=> $right['datetime']->getTimestamp();
});

$eventsByDate = [];
foreach ($events as $event) {
    $eventsByDate[$event['date_key']][] = $event;
}

$today = new DateTime('today');
$now = new DateTime();
$selectedDayInput = trim((string) ($_GET['day'] ?? ''));
$selectedDay = null;
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDayInput)) {
    $candidate = DateTime::createFromFormat('Y-m-d', $selectedDayInput);
    if ($candidate instanceof DateTime && $candidate->format('Y-m') === $calendarMonth) {
        $selectedDay = $candidate;
    }
}
if (!$selectedDay) {
    if ($today->format('Y-m') === $calendarMonth) {
        $selectedDay = clone $today;
    } elseif (!empty($events)) {
        $selectedDay = clone $events[0]['datetime'];
    } else {
        $selectedDay = clone $calendarStart;
    }
}

$selectedDayKey = $selectedDay->format('Y-m-d');
$selectedEvents = $eventsByDate[$selectedDayKey] ?? [];

$currentMonthEventCount = count($events);
$todayEventCount = isset($eventsByDate[$today->format('Y-m-d')]) ? count($eventsByDate[$today->format('Y-m-d')]) : 0;
$weekEnd = (clone $today)->modify('+6 days')->format('Y-m-d');
$weekEventCount = 0;
foreach ($events as $event) {
    $dateKey = $event['date_key'];
    if ($dateKey >= $today->format('Y-m-d') && $dateKey <= $weekEnd) {
        $weekEventCount++;
    }
}
$upcomingEvents = array_values(array_filter($events, static function (array $event) use ($now): bool {
    return $event['datetime']->getTimestamp() >= $now->getTimestamp();
}));
$deadlineEvents = array_values(array_filter($upcomingEvents, static function (array $event): bool {
    return stripos((string) $event['category'], 'deadline') !== false || (string) $event['category'] === 'Task';
}));

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
include __DIR__ . '/includes/navbar.php';
?>

<style>
.calendar-shell {
    display: grid;
    gap: 1.1rem;
    min-width: 0;
    color: #172033;
    --calendar-primary: #1f6392;
    --calendar-primary-dark: #184f73;
    --calendar-surface: #ffffff;
    --calendar-head: #ffffff;
    --calendar-border: #e4eaf0;
    --calendar-border-strong: #ccd9e4;
    --calendar-shadow: 0 12px 32px rgba(23, 50, 77, 0.07);
    --calendar-shadow-hover: 0 14px 34px rgba(23, 50, 77, 0.09);
    --calendar-card-bg: var(--calendar-surface);
    --calendar-day-bg: #ffffff;
    --calendar-grid-bg: #ffffff;
}

.calendar-shell > *,
.calendar-card,
.calendar-side {
    min-width: 0;
    max-width: 100%;
}

.calendar-hero {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 1.45rem 1.55rem;
    border-radius: 18px;
    background: linear-gradient(135deg, #19567f 0%, #1f6392 55%, #2f83b9 100%);
    color: #fff;
    box-shadow: 0 20px 45px rgba(31, 99, 146, 0.2);
}

.calendar-hero h1 {
    margin: 0;
    font-size: 1.65rem;
    font-weight: 800;
}

.calendar-hero p {
    margin: 0.35rem 0 0;
    color: rgba(255, 255, 255, 0.78);
    font-size: 0.9rem;
}

.calendar-hero__meta {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    flex-wrap: wrap;
}

.calendar-nav {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.calendar-nav a {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2.6rem;
    height: 2.6rem;
    border-radius: 8px;
    border: 1px solid rgba(255, 255, 255, 0.16);
    background: rgba(255, 255, 255, 0.12);
    color: #fff;
    text-decoration: none;
    transition: background 0.18s ease, border-color 0.18s ease;
}

.calendar-hero .page-header-help-btn {
    border-color: rgba(255, 255, 255, 0.88);
    background: linear-gradient(145deg, #ffffff 0%, #e8f5fb 100%);
    color: #15567f;
    box-shadow: 0 8px 20px rgba(5, 32, 55, 0.24);
}

.calendar-hero .page-header-help-btn:hover,
.calendar-hero .page-header-help-btn:focus-visible {
    background: #ffffff;
    color: #0f496e;
}

.calendar-nav a:hover {
    background: rgba(255, 255, 255, 0.18);
    border-color: rgba(255, 255, 255, 0.28);
}

.calendar-stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 0.9rem;
}

.calendar-stat {
    position: relative;
    padding: 1rem 1.1rem;
    padding-right: 4.5rem;
    border: 1px solid var(--calendar-border);
    border-radius: 12px;
    background: linear-gradient(145deg, #ffffff 0%, #ffffff 68%, #eef9fd 100%);
    box-shadow: var(--calendar-shadow), 0 0 18px rgba(34, 211, 238, 0.05);
}

.calendar-stat__label {
    display: block;
    color: #6c757d;
    font-size: 0.74rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.calendar-stat__icon {
    position: absolute;
    top: 0.9rem;
    right: 0.9rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2.65rem;
    height: 2.65rem;
    border-radius: 11px;
    background: linear-gradient(145deg, #f4fbff 0%, #dfeff7 100%);
    color: #1f6392;
    font-size: 1rem;
}

.calendar-stat strong {
    display: block;
    margin-top: 0.35rem;
    color: #184f73;
    font-size: 1.6rem;
    font-weight: 800;
}

.calendar-card {
    border: 1px solid var(--calendar-border);
    border-radius: 20px;
    background: var(--calendar-card-bg);
    overflow: hidden;
    box-shadow: var(--calendar-shadow);
}

.calendar-card__header {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
    align-items: center;
    gap: 1rem;
    padding: 1.15rem 1.3rem 1rem;
    background: var(--calendar-head);
}

.calendar-card__header h2,
.calendar-card__header h3 {
    margin: 0;
    color: #184f73;
    font-size: 1rem;
    font-weight: 800;
}

.calendar-card__month-nav {
    align-items: center;
    display: flex;
    gap: .65rem;
    grid-column: 2;
}

.calendar-month-button {
    align-items: center;
    background: transparent;
    border: 1px solid transparent;
    border-radius: 9px;
    color: #6d7b8a;
    display: inline-flex;
    height: 34px;
    justify-content: center;
    text-decoration: none;
    transition: background .18s ease, border-color .18s ease, color .18s ease;
    width: 34px;
}

.calendar-month-button:hover {
    background: #f3f7fa;
    border-color: var(--calendar-border);
    color: var(--calendar-primary);
}

.calendar-card__tools {
    align-items: center;
    display: flex;
    gap: .55rem;
    grid-column: 3;
    justify-self: end;
}

.calendar-mode-pill {
    background: #edf5fa;
    border: 1px solid #d8e7f0;
    border-radius: 999px;
    color: var(--calendar-primary-dark);
    display: inline-flex;
    font-size: .72rem;
    font-weight: 800;
    padding: .38rem .72rem;
}

.calendar-scroll-hint {
    display: none;
}

.calendar-scroll {
    max-width: 100%;
    overflow-x: clip;
}

.calendar-grid {
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
    gap: 0;
    padding: 0 1.3rem 1.3rem;
    background: var(--calendar-grid-bg);
}

.calendar-weekday {
    border-bottom: 1px solid var(--calendar-border);
    border-right: 1px solid var(--calendar-border);
    color: #667484;
    font-size: 0.73rem;
    font-weight: 650;
    padding: .75rem .6rem .85rem;
    text-align: center;
}

.calendar-day,
.calendar-blank {
    min-height: 8rem;
    border-bottom: 1px solid var(--calendar-border);
    border-right: 1px solid var(--calendar-border);
    border-radius: 0;
}

.calendar-blank {
    background: #fbfcfd;
}

.calendar-grid > :nth-child(7n + 1) {
    border-left: 1px solid var(--calendar-border);
}

.calendar-day {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: 0.45rem;
    padding: 0.65rem 0.7rem;
    border-top: 0;
    background: var(--calendar-day-bg);
    color: #263648;
    text-decoration: none;
    transition: background 0.16s ease, box-shadow 0.16s ease;
}

.calendar-day:hover {
    background: #f7fafc;
    box-shadow: inset 0 0 0 1px rgba(31, 99, 146, .12);
    z-index: 1;
}

.calendar-day.is-muted {
    color: #9ca8b4;
}

.calendar-day.is-today {
    box-shadow: inset 0 3px 0 var(--calendar-primary);
}

.calendar-day.is-selected {
    background: #f4f9fc;
    box-shadow: inset 0 3px 0 #4ea4dc;
}

.calendar-date {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.65rem;
    height: 1.65rem;
    border-radius: 50%;
    font-size: 0.86rem;
    font-weight: 700;
}

.calendar-day.is-today .calendar-date {
    background: #1f6392;
    color: #fff;
}

.calendar-day.is-selected .calendar-date {
    background: transparent;
    color: #2d8bc8;
}

.calendar-day.is-today.is-selected .calendar-date {
    background: #1f6392;
    color: #fff;
}

.calendar-dots {
    display: none;
    flex-wrap: wrap;
    gap: 0.25rem;
}

.calendar-dot {
    width: 0.42rem;
    height: 0.42rem;
    border-radius: 999px;
    background: #1f6392;
}

.calendar-day__top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.35rem;
}

.calendar-event-count {
    display: none;
    align-items: center;
    justify-content: center;
    min-width: 1.45rem;
    height: 1.45rem;
    padding: 0 0.35rem;
    border-radius: 8px;
    background: #edf6fb;
    color: #184f73;
    font-size: 0.72rem;
    font-weight: 800;
}

.calendar-day__events {
    display: grid;
    gap: 0.3rem;
    min-width: 0;
}

.calendar-mini-event {
    align-items: center;
    display: block;
    overflow: hidden;
    padding: 0.2rem 0.2rem 0.2rem 0.85rem;
    border-radius: 0;
    background: transparent;
    color: #38495b;
    font-size: 0.68rem;
    font-weight: 650;
    line-height: 1.28;
    position: relative;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.calendar-mini-event::before {
    background: var(--event-color, #1f6392);
    border-radius: 50%;
    content: "";
    height: .38rem;
    left: .18rem;
    position: absolute;
    top: .48rem;
    width: .38rem;
}

.calendar-more {
    color: #8793a0;
    font-size: 0.65rem;
    font-weight: 650;
    padding-left: .85rem;
}

.calendar-side {
    display: grid;
    gap: 1rem;
    align-content: start;
}

.calendar-panel {
    border-radius: 18px;
    border: 1px solid var(--calendar-border);
    background: var(--calendar-card-bg);
    box-shadow: var(--calendar-shadow);
    overflow: hidden;
}

.calendar-panel__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 1.1rem 1.25rem;
    border-bottom: 1px solid rgba(31, 99, 146, 0.10);
    background: var(--calendar-head);
}

.calendar-panel__header h3 {
    margin: 0;
    color: #184f73;
    font-size: 0.98rem;
    font-weight: 800;
}

.calendar-count-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 1.8rem;
    min-height: 1.8rem;
    padding: 0.25rem 0.55rem !important;
    border: 1px solid rgba(31, 99, 146, 0.14);
    border-radius: 999px !important;
    background: #e8f3f9 !important;
    color: #184f73 !important;
    font-size: 0.72rem;
    font-weight: 800;
}

.calendar-panel__body {
    padding: 0.35rem 1rem 0.55rem;
    overflow-y: auto;
    overscroll-behavior: contain;
    scrollbar-color: rgba(31, 99, 146, 0.28) transparent;
    scrollbar-width: thin;
}

.calendar-panel--selected .calendar-panel__body {
    max-height: 19rem;
}

.calendar-panel--deadlines .calendar-panel__body {
    max-height: 25rem;
}

.calendar-panel__body::-webkit-scrollbar {
    width: 6px;
}

.calendar-panel__body::-webkit-scrollbar-track {
    background: transparent;
}

.calendar-panel__body::-webkit-scrollbar-thumb {
    border-radius: 999px;
    background: rgba(31, 99, 146, 0.25);
}

.calendar-event {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 0.65rem;
    padding: 0.72rem 0;
}

.calendar-event + .calendar-event {
    border-top: 1px solid #eef2f6;
}

.calendar-event__marker {
    width: 0.62rem;
    height: 0.62rem;
    margin-top: 0.35rem;
    border-radius: 999px;
    background: var(--event-color, #1f6392);
    box-shadow: 0 0 0 4px rgba(31, 99, 146, 0.12);
}

.calendar-event__title {
    display: -webkit-box;
    overflow: hidden;
    margin: 0;
    color: #184f73;
    font-size: 0.86rem;
    font-weight: 800;
    line-height: 1.35;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
}

.calendar-event__title-row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.55rem;
}

.calendar-event__link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.75rem;
    height: 1.75rem;
    flex: 0 0 auto;
    border-radius: 8px;
    color: #1f6392;
    background: #edf6fb;
    text-decoration: none;
}

.calendar-event__link:hover {
    color: #184f73;
    background: #dff0f7;
}

.calendar-event__meta {
    margin-top: 0.2rem;
    color: #6c757d;
    font-size: 0.74rem;
    line-height: 1.4;
}

.calendar-empty {
    padding: 1.1rem 0.1rem;
    color: #6c757d;
    font-size: 0.92rem;
}

@media (max-width: 1199.98px) {
    .calendar-stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 991.98px) {
    .calendar-hero {
        flex-direction: column;
        align-items: flex-start;
    }

    .calendar-grid {
        gap: 0;
    }
}

@media (max-width: 575.98px) {
    .calendar-stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.7rem;
    }

    .calendar-hero {
        padding: 1.15rem;
        border-radius: 15px;
    }

    .calendar-hero h1 {
        font-size: 1.4rem;
    }

    .calendar-stat {
        padding: 0.85rem 0.9rem;
    }

    .calendar-stat strong {
        font-size: 1.35rem;
    }

    .calendar-card__header {
        grid-template-columns: 1fr;
        padding: 1rem;
    }

    .calendar-card__month-nav,
    .calendar-card__tools {
        grid-column: 1;
        justify-self: center;
    }

    .calendar-panel__header,
    .calendar-panel__body {
        padding-left: 1rem;
        padding-right: 1rem;
    }
}

@media (max-width: 767.98px) {
    .calendar-scroll-hint {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.45rem;
        padding: 0.55rem 1rem 0;
        color: #6c757d;
        font-size: 0.72rem;
        font-weight: 700;
    }

    .calendar-scroll {
        width: 100%;
        overflow-x: auto;
        overflow-y: hidden;
        overscroll-behavior-x: contain;
        scroll-behavior: smooth;
        scrollbar-color: rgba(31, 99, 146, 0.45) rgba(31, 99, 146, 0.08);
        scrollbar-width: thin;
        -webkit-overflow-scrolling: touch;
    }

    .calendar-scroll::-webkit-scrollbar {
        height: 8px;
    }

    .calendar-scroll::-webkit-scrollbar-track {
        background: rgba(31, 99, 146, 0.08);
    }

    .calendar-scroll::-webkit-scrollbar-thumb {
        border: 2px solid transparent;
        border-radius: 999px;
        background: rgba(31, 99, 146, 0.45);
        background-clip: padding-box;
    }

    .calendar-scroll .calendar-grid {
        width: 46rem;
        min-width: 46rem;
        padding: 0 1rem 1rem;
    }

    .calendar-day,
    .calendar-blank {
        min-height: 5.75rem;
    }

    .calendar-mini-event {
        font-size: 0.66rem;
    }
}

html[data-theme="dark"] .calendar-shell {
    color: #d8e6f3;
    --calendar-surface: #0b1d2e;
    --calendar-head: #0b1d2e;
    --calendar-border: rgba(138, 210, 255, 0.12);
    --calendar-border-strong: rgba(138, 210, 255, 0.22);
    --calendar-shadow: 0 14px 32px rgba(0, 0, 0, 0.30);
    --calendar-shadow-hover: 0 18px 40px rgba(0, 0, 0, 0.36);
    --calendar-card-bg: #0b1d2e;
    --calendar-day-bg: #0b1d2e;
    --calendar-grid-bg: #0b1d2e;
}

html[data-theme="dark"] .calendar-hero {
    background: linear-gradient(135deg, rgba(31, 99, 146, 0.26) 0%, rgba(12, 31, 49, 0.96) 100%);
    box-shadow: 0 20px 45px rgba(0, 0, 0, 0.34);
}

html[data-theme="dark"] .calendar-hero p,
html[data-theme="dark"] .calendar-stat span,
html[data-theme="dark"] .calendar-weekday,
html[data-theme="dark"] .calendar-more,
html[data-theme="dark"] .calendar-event__meta,
html[data-theme="dark"] .calendar-empty {
    color: #90aeca;
}

html[data-theme="dark"] .calendar-stat,
html[data-theme="dark"] .calendar-card__header,
html[data-theme="dark"] .calendar-day,
html[data-theme="dark"] .calendar-panel {
    border-color: rgba(138, 210, 255, 0.10);
}

html[data-theme="dark"] .calendar-stat,
html[data-theme="dark"] .calendar-card,
html[data-theme="dark"] .calendar-panel {
    background: var(--calendar-card-bg);
}

html[data-theme="dark"] .calendar-stat__icon {
    background: linear-gradient(145deg, rgba(31, 99, 146, 0.3), rgba(31, 99, 146, 0.16));
    color: #8cc7ff;
}

html[data-theme="dark"] .calendar-card__header,
html[data-theme="dark"] .calendar-panel__header {
    background: var(--calendar-head);
}

html[data-theme="dark"] .calendar-day {
    background: var(--calendar-day-bg);
}

html[data-theme="dark"] .calendar-blank {
    background: #091827;
}

html[data-theme="dark"] .calendar-stat strong {
    color: #8cc7ff;
}

html[data-theme="dark"] .calendar-card,
html[data-theme="dark"] .calendar-panel {
    box-shadow: 0 18px 36px rgba(0, 0, 0, 0.28);
}

html[data-theme="dark"] .calendar-grid {
    background: var(--calendar-grid-bg);
}

html[data-theme="dark"] .calendar-day:hover {
    background: #10263a;
    box-shadow: inset 0 0 0 1px rgba(138, 210, 255, 0.14);
}

html[data-theme="dark"] .calendar-day.is-today {
    border-color: rgba(140, 199, 255, 0.65);
    box-shadow: inset 0 0 0 1px rgba(140, 199, 255, 0.65);
}

html[data-theme="dark"] .calendar-day.is-today .calendar-date {
    background: rgba(140, 199, 255, 0.16);
    color: #d8e6f3;
}

html[data-theme="dark"] .calendar-day.is-selected {
    background: linear-gradient(180deg, rgba(31, 99, 146, 0.18), rgba(31, 99, 146, 0.08));
    border-color: rgba(140, 199, 255, 0.20);
}

html[data-theme="dark"] .calendar-date {
    color: #d8e6f3;
}

html[data-theme="dark"] .calendar-event-count {
    background: rgba(31, 99, 146, 0.18);
    color: #8cc7ff;
}

html[data-theme="dark"] .calendar-mini-event {
    background: transparent;
    color: #d8e6f3;
}

html[data-theme="dark"] .calendar-day.is-muted .calendar-date {
    color: #70869b;
}

html[data-theme="dark"] .calendar-month-button {
    color: #90aeca;
}

html[data-theme="dark"] .calendar-month-button:hover {
    background: rgba(138, 210, 255, .08);
    border-color: rgba(138, 210, 255, .14);
    color: #d8e6f3;
}

html[data-theme="dark"] .calendar-mode-pill {
    background: rgba(31, 99, 146, .2);
    border-color: rgba(138, 210, 255, .16);
    color: #8cc7ff;
}

html[data-theme="dark"] .calendar-dot {
    opacity: 0.92;
}

html[data-theme="dark"] .calendar-event__title,
html[data-theme="dark"] .calendar-card__header h2,
html[data-theme="dark"] .calendar-card__header h3,
html[data-theme="dark"] .calendar-panel__header h3 {
    color: #d8e6f3;
}

html[data-theme="dark"] .calendar-event {
    border-top-color: rgba(138, 210, 255, 0.10);
}

html[data-theme="dark"] .calendar-event__marker {
    box-shadow: 0 0 0 4px rgba(31, 99, 146, 0.18);
}

html[data-theme="dark"] .calendar-panel__body {
    scrollbar-color: rgba(140, 199, 255, 0.28) transparent;
}

html[data-theme="dark"] .calendar-panel__body::-webkit-scrollbar-thumb {
    background: rgba(140, 199, 255, 0.24);
}

html[data-theme="dark"] .calendar-event__link {
    color: #8cc7ff;
    background: rgba(31, 99, 146, 0.18);
}

html[data-theme="dark"] .calendar-event__link:hover {
    color: #d8e6f3;
    background: rgba(31, 99, 146, 0.28);
}

html[data-theme="dark"] .calendar-panel .badge.text-bg-primary {
    background-color: rgba(31, 99, 146, 0.22) !important;
    color: #8cc7ff !important;
}

html[data-theme="dark"] .calendar-panel .badge.text-bg-light {
    background-color: rgba(12, 31, 49, 0.92) !important;
    color: #d8e6f3 !important;
    border: 1px solid rgba(138, 210, 255, 0.10);
}

html[data-theme="dark"] .calendar-count-badge {
    border-color: rgba(138, 210, 255, 0.15);
    background: rgba(31, 99, 146, 0.22) !important;
    color: #8cc7ff !important;
}

html[data-theme="dark"] .btn-outline-primary.calendar-today-btn {
    color: #8cc7ff;
    border-color: rgba(140, 199, 255, 0.35);
}

html[data-theme="dark"] .btn-outline-primary.calendar-today-btn:hover {
    background: rgba(31, 99, 146, 0.22);
    color: #d8e6f3;
}
</style>

<div class="container-fluid calendar-shell">
    <div class="calendar-hero" data-page-header>
        <div>
            <h1>Calendar</h1>
            <p><?php echo htmlspecialchars($selectedDay->format('l, F j')); ?> selected</p>
        </div>
    </div>

    <div class="calendar-stats">
        <div class="calendar-stat">
            <span class="calendar-stat__label">Today</span>
            <strong><?php echo $todayEventCount; ?></strong>
            <span class="calendar-stat__icon" aria-hidden="true"><i class="fas fa-calendar-day"></i></span>
        </div>
        <div class="calendar-stat">
            <span class="calendar-stat__label">This Week</span>
            <strong><?php echo $weekEventCount; ?></strong>
            <span class="calendar-stat__icon" aria-hidden="true"><i class="fas fa-calendar-week"></i></span>
        </div>
        <div class="calendar-stat">
            <span class="calendar-stat__label">This Month</span>
            <strong><?php echo $currentMonthEventCount; ?></strong>
            <span class="calendar-stat__icon" aria-hidden="true"><i class="far fa-calendar-alt"></i></span>
        </div>
        <div class="calendar-stat">
            <span class="calendar-stat__label">Deadlines</span>
            <strong><?php echo count($deadlineEvents); ?></strong>
            <span class="calendar-stat__icon" aria-hidden="true"><i class="fas fa-hourglass-half"></i></span>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-xl-9">
            <div class="calendar-card">
                <div class="calendar-card__header">
                    <div class="calendar-card__month-nav" aria-label="Calendar navigation">
                        <a class="calendar-month-button" href="calendar.php?calendar_month=<?php echo urlencode($previousMonth); ?>" aria-label="Previous month">
                            <i class="fas fa-chevron-left" aria-hidden="true"></i>
                        </a>
                        <h2><?php echo htmlspecialchars($calendarStart->format('F Y')); ?></h2>
                        <a class="calendar-month-button" href="calendar.php?calendar_month=<?php echo urlencode($nextMonth); ?>" aria-label="Next month">
                            <i class="fas fa-chevron-right" aria-hidden="true"></i>
                        </a>
                    </div>
                    <div class="calendar-card__tools">
                        <span class="calendar-mode-pill">Month</span>
                        <a class="btn btn-sm btn-outline-primary calendar-today-btn" href="calendar.php?calendar_month=<?php echo urlencode(date('Y-m')); ?>&day=<?php echo urlencode(date('Y-m-d')); ?>">Today</a>
                    </div>
                </div>

                <div class="calendar-scroll-hint" aria-hidden="true">
                    <i class="fas fa-arrows-left-right"></i>
                    <span>Swipe sideways to view the full week</span>
                </div>

                <div class="calendar-scroll" role="region" aria-label="Monthly calendar. Scroll horizontally to view all days on smaller screens." tabindex="0">
                    <div class="calendar-grid">
                        <?php foreach (['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $weekday): ?>
                            <div class="calendar-weekday"><?php echo $weekday; ?></div>
                        <?php endforeach; ?>

                        <?php for ($blank = 0; $blank < $leadingBlanks; $blank++): ?>
                            <div class="calendar-blank" aria-hidden="true"></div>
                        <?php endfor; ?>

                        <?php
                        for ($day = 1; $day <= (int) $calendarStart->format('t'); $day++):
                            $dateKey = $calendarStart->format('Y-m-') . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
                            $dayEvents = $eventsByDate[$dateKey] ?? [];
                            $classes = ['calendar-day'];
                            if ($dateKey < $today->format('Y-m-d') && $calendarMonth === $today->format('Y-m') && empty($dayEvents)) {
                                $classes[] = 'is-muted';
                            }
                            if ($dateKey === $today->format('Y-m-d')) {
                                $classes[] = 'is-today';
                            }
                            if ($dateKey === $selectedDayKey) {
                                $classes[] = 'is-selected';
                            }
                        ?>
                            <a class="<?php echo implode(' ', $classes); ?>" href="calendar.php?calendar_month=<?php echo urlencode($calendarMonth); ?>&day=<?php echo urlencode($dateKey); ?>">
                                <span class="calendar-day__top">
                                    <span class="calendar-date"><?php echo $day; ?></span>
                                    <?php if (!empty($dayEvents)): ?>
                                        <span class="calendar-event-count"><?php echo count($dayEvents); ?></span>
                                    <?php endif; ?>
                                </span>
                                <?php if (!empty($dayEvents)): ?>
                                    <span class="calendar-day__events" aria-hidden="true">
                                        <?php foreach (array_slice($dayEvents, 0, 2) as $event): ?>
                                            <span class="calendar-mini-event" style="--event-color: <?php echo htmlspecialchars($event['color']); ?>;">
                                                <?php echo htmlspecialchars($event['time_label'] . ' ' . $event['title']); ?>
                                            </span>
                                        <?php endforeach; ?>
                                        <?php if (count($dayEvents) > 2): ?>
                                            <span class="calendar-more">+<?php echo count($dayEvents) - 2; ?> more</span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="calendar-dots" aria-hidden="true">
                                        <?php foreach (array_slice($dayEvents, 0, 4) as $event): ?>
                                            <span class="calendar-dot" style="background: <?php echo htmlspecialchars($event['color']); ?>;"></span>
                                        <?php endforeach; ?>
                                    </span>
                                <?php endif; ?>
                            </a>
                        <?php endfor; ?>

                        <?php for ($blank = 0; $blank < $trailingBlanks; $blank++): ?>
                            <div class="calendar-blank" aria-hidden="true"></div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3">
            <div class="calendar-side">
                <div class="calendar-panel calendar-panel--selected">
                    <div class="calendar-panel__header">
                        <h3><?php echo htmlspecialchars($selectedDay->format('F j, Y')); ?></h3>
                        <span class="badge calendar-count-badge"><?php echo count($selectedEvents); ?></span>
                    </div>
                    <div class="calendar-panel__body">
                        <?php if (!empty($selectedEvents)): ?>
                            <?php foreach ($selectedEvents as $event): ?>
                                <div class="calendar-event">
                                    <span class="calendar-event__marker" style="--event-color: <?php echo htmlspecialchars($event['color']); ?>;"></span>
                                    <div>
                                        <div class="calendar-event__title-row">
                                            <p class="calendar-event__title"><?php echo htmlspecialchars($event['title']); ?></p>
                                            <?php if ($event['url'] !== ''): ?>
                                                <a class="calendar-event__link" href="<?php echo htmlspecialchars($event['url']); ?>" aria-label="Open item">
                                                    <i class="fas fa-arrow-right"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                        <div class="calendar-event__meta">
                                            <i class="far fa-clock me-1"></i><?php echo htmlspecialchars($event['time_label']); ?>
                                            <span class="ms-2 badge text-bg-light"><?php echo htmlspecialchars($event['category']); ?></span>
                                        </div>
                                        <?php if ($event['description'] !== ''): ?>
                                            <div class="calendar-event__meta mt-1"><?php echo htmlspecialchars($event['description']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="calendar-empty">No items for this day.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="calendar-panel calendar-panel--deadlines">
                    <div class="calendar-panel__header">
                        <h3>Upcoming Deadlines</h3>
                        <span class="badge calendar-count-badge"><?php echo count($deadlineEvents); ?></span>
                    </div>
                    <div class="calendar-panel__body">
                        <?php if (!empty($deadlineEvents)): ?>
                            <?php foreach (array_slice($deadlineEvents, 0, 6) as $event): ?>
                                <div class="calendar-event">
                                    <span class="calendar-event__marker" style="--event-color: <?php echo htmlspecialchars($event['color']); ?>;"></span>
                                    <div>
                                        <div class="calendar-event__title-row">
                                            <p class="calendar-event__title"><?php echo htmlspecialchars($event['title']); ?></p>
                                            <?php if ($event['url'] !== ''): ?>
                                                <a class="calendar-event__link" href="<?php echo htmlspecialchars($event['url']); ?>" aria-label="Open item">
                                                    <i class="fas fa-arrow-right"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                        <div class="calendar-event__meta">
                                            <?php echo htmlspecialchars($event['datetime']->format('M j, h:i A')); ?>
                                            <span class="ms-2 badge text-bg-light"><?php echo htmlspecialchars($event['category']); ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="calendar-empty">No upcoming deadlines this month.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const calendarScroll = document.querySelector('.calendar-scroll');
    const selectedDay = calendarScroll?.querySelector('.calendar-day.is-selected');

    if (!calendarScroll || !selectedDay || !window.matchMedia('(max-width: 767.98px)').matches) {
        return;
    }

    window.requestAnimationFrame(function () {
        const selectedStart = selectedDay.offsetLeft;
        const selectedEnd = selectedStart + selectedDay.offsetWidth;
        const visibleEnd = calendarScroll.scrollLeft + calendarScroll.clientWidth;

        if (selectedStart < calendarScroll.scrollLeft || selectedEnd > visibleEnd) {
            calendarScroll.scrollLeft = Math.max(
                0,
                selectedStart - ((calendarScroll.clientWidth - selectedDay.offsetWidth) / 2)
            );
        }
    });
});
</script>

<?php
$scheduleStatement->close();
include __DIR__ . '/includes/footer.php';
