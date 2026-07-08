<?php

if (!function_exists('dashboardPlannerHandleRequest')) {
    function dashboardPlannerHandleRequest(mysqli|PgsqlMysqliCompatConnection $conn, int $userId, string $redirectPath = 'dashboard.php'): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        if (isset($_POST['dashboard_create_plan'])) {
            $title = trim((string) ($_POST['plan_title'] ?? ''));
            $date = trim((string) ($_POST['plan_date'] ?? ''));
            $time = trim((string) ($_POST['plan_time'] ?? '09:00'));
            $type = trim((string) ($_POST['plan_type'] ?? 'meeting'));
            $location = trim((string) ($_POST['plan_location'] ?? ''));
            $description = trim((string) ($_POST['plan_description'] ?? ''));
            $validTypes = ['meeting', 'deadline', 'milestone', 'review', 'holiday', 'personal'];

            if ($title !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && in_array($type, $validTypes, true)) {
                $colorMap = [
                    'meeting' => '#1f6392',
                    'deadline' => '#dc2626',
                    'milestone' => '#7c3aed',
                    'review' => '#d97706',
                    'holiday' => '#059669',
                    'personal' => '#64748b',
                ];
                $status = 'scheduled';
                $reminderDays = 1;
                $colorCode = $colorMap[$type] ?? '#1f6392';

                $stmt = $conn->prepare(
                    'INSERT INTO schedules (user_id, title, description, schedule_type, start_date, start_time, end_date, end_time, location, color_code, status, reminder_days)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );

                if ($stmt !== false) {
                    $stmt->bind_param(
                        'issssssssssi',
                        $userId,
                        $title,
                        $description,
                        $type,
                        $date,
                        $time,
                        $date,
                        $time,
                        $location,
                        $colorCode,
                        $status,
                        $reminderDays
                    );
                    $stmt->execute();
                    $stmt->close();
                    $_SESSION['success'] = 'Plan added to your calendar.';
                }
            } else {
                $_SESSION['error'] = 'Please enter a title, date, and valid plan type.';
            }

            header('Location: ' . $redirectPath . '?calendar_month=' . urlencode(substr($date !== '' ? $date : date('Y-m-d'), 0, 7)));
            exit();
        }

        if (isset($_POST['dashboard_delete_plan'])) {
            $planId = (int) ($_POST['plan_id'] ?? 0);
            if ($planId > 0) {
                $stmt = $conn->prepare('DELETE FROM schedules WHERE id = ? AND user_id = ?');
                if ($stmt !== false) {
                    $stmt->bind_param('ii', $planId, $userId);
                    $stmt->execute();
                    $stmt->close();
                    $_SESSION['success'] = 'Plan removed.';
                }
            }

            header('Location: ' . $redirectPath . '?calendar_month=' . urlencode((string) ($_POST['calendar_month'] ?? date('Y-m'))));
            exit();
        }
    }
}

if (!function_exists('dashboardPlannerBuild')) {
    function dashboardPlannerBuild(mysqli|PgsqlMysqliCompatConnection $conn, int $userId): array
    {
        $calendarMonthInput = trim((string) ($_GET['calendar_month'] ?? date('Y-m')));
        if (!preg_match('/^\d{4}-\d{2}$/', $calendarMonthInput)) {
            $calendarMonthInput = date('Y-m');
        }

        $calendarStart = DateTime::createFromFormat('Y-m-d', $calendarMonthInput . '-01') ?: new DateTime('first day of this month');
        $calendarStart->setTime(0, 0, 0);
        $calendarEnd = (clone $calendarStart)->modify('last day of this month');
        $calendarMonth = $calendarStart->format('Y-m');
        $firstCalendarDay = (clone $calendarStart)->modify('-' . (int) $calendarStart->format('w') . ' days');
        $lastCalendarDay = (clone $calendarEnd)->modify('+' . (6 - (int) $calendarEnd->format('w')) . ' days');

        $plansByDate = [];
        $monthStartString = $calendarStart->format('Y-m-d');
        $monthEndString = $calendarEnd->format('Y-m-d');
        $planStmt = $conn->prepare(
            'SELECT *
             FROM schedules
             WHERE user_id = ? AND start_date BETWEEN ? AND ? AND status <> ?
             ORDER BY start_date ASC, start_time ASC'
        );

        if ($planStmt !== false) {
            $cancelledStatus = 'cancelled';
            $planStmt->bind_param('isss', $userId, $monthStartString, $monthEndString, $cancelledStatus);
            $planStmt->execute();
            $planResult = $planStmt->get_result();
            if ($planResult) {
                while ($plan = $planResult->fetch_assoc()) {
                    $planDate = (string) ($plan['start_date'] ?? '');
                    $plansByDate[$planDate][] = $plan;
                }
            }
            $planStmt->close();
        }

        $upcomingPlans = [];
        $upcomingStmt = $conn->prepare(
            'SELECT *
             FROM schedules
             WHERE user_id = ? AND start_date >= ? AND status = ?
             ORDER BY start_date ASC, start_time ASC
             LIMIT 5'
        );

        if ($upcomingStmt !== false) {
            $today = date('Y-m-d');
            $scheduledStatus = 'scheduled';
            $upcomingStmt->bind_param('iss', $userId, $today, $scheduledStatus);
            $upcomingStmt->execute();
            $upcomingResult = $upcomingStmt->get_result();
            if ($upcomingResult) {
                while ($plan = $upcomingResult->fetch_assoc()) {
                    $upcomingPlans[] = $plan;
                }
            }
            $upcomingStmt->close();
        }

        return [
            'calendar_start' => $calendarStart,
            'calendar_month' => $calendarMonth,
            'previous_month' => (clone $calendarStart)->modify('-1 month')->format('Y-m'),
            'next_month' => (clone $calendarStart)->modify('+1 month')->format('Y-m'),
            'first_calendar_day' => $firstCalendarDay,
            'last_calendar_day' => $lastCalendarDay,
            'plans_by_date' => $plansByDate,
            'upcoming_plans' => $upcomingPlans,
        ];
    }
}

if (!function_exists('dashboardPlannerRender')) {
    function dashboardPlannerRender(array $planner, string $title = 'Calendar', string $addLabel = 'Add Plan'): void
    {
        static $stylesRendered = false;
        if (!$stylesRendered): ?>
            <style>
                .dashboard-planner-grid {
                    display: grid;
                    grid-template-columns: minmax(0, 1.5fr) minmax(280px, 0.8fr);
                    gap: 1rem;
                }
                .dashboard-planner-toolbar {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    gap: 1rem;
                    margin-bottom: 1rem;
                }
                .dashboard-planner-month {
                    margin: 0;
                    color: #1f6392;
                    font-size: 1rem;
                    font-weight: 700;
                }
                .dashboard-planner-nav {
                    display: inline-flex;
                    gap: 0.35rem;
                }
                .dashboard-planner-nav a {
                    width: 34px;
                    height: 34px;
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    border: 1px solid #e9ecef;
                    border-radius: 8px;
                    color: #1f6392;
                    background: #fff;
                    text-decoration: none;
                }
                .dashboard-calendar-grid {
                    display: grid;
                    grid-template-columns: repeat(7, minmax(0, 1fr));
                    border: 1px solid #e9ecef;
                    border-radius: 12px;
                    overflow: hidden;
                }
                .dashboard-calendar-weekday,
                .dashboard-calendar-day {
                    min-width: 0;
                    border-right: 1px solid #e9ecef;
                    border-bottom: 1px solid #e9ecef;
                }
                .dashboard-calendar-weekday:nth-child(7n),
                .dashboard-calendar-day:nth-child(7n) {
                    border-right: none;
                }
                .dashboard-calendar-day:nth-last-child(-n + 7) {
                    border-bottom: none;
                }
                .dashboard-calendar-weekday {
                    padding: 0.55rem 0.35rem;
                    background: #f8f9fa;
                    color: #6c757d;
                    text-align: center;
                    font-size: 0.68rem;
                    font-weight: 700;
                    text-transform: uppercase;
                }
                .dashboard-calendar-day {
                    min-height: 86px;
                    padding: 0.45rem;
                    background: #fff;
                    display: flex;
                    flex-direction: column;
                    gap: 0.3rem;
                }
                .dashboard-calendar-day.is-muted {
                    background: #fbfcfd;
                }
                .dashboard-calendar-day.is-today {
                    box-shadow: inset 0 0 0 2px rgba(31, 99, 146, 0.35);
                }
                .dashboard-calendar-date {
                    color: #212529;
                    font-size: 0.76rem;
                    font-weight: 700;
                }
                .dashboard-calendar-day.is-muted .dashboard-calendar-date {
                    color: #adb5bd;
                }
                .dashboard-calendar-chip {
                    border: 0;
                    border-radius: 8px;
                    padding: 0.22rem 0.35rem;
                    color: #fff;
                    font-size: 0.66rem;
                    text-align: left;
                    white-space: nowrap;
                    overflow: hidden;
                    text-overflow: ellipsis;
                }
                .dashboard-calendar-more {
                    color: #6c757d;
                    font-size: 0.66rem;
                    font-weight: 700;
                }
                .dashboard-planner-side {
                    display: grid;
                    gap: 1rem;
                }
                .dashboard-planner-panel {
                    border: 1px solid #e9ecef;
                    border-radius: 12px;
                    padding: 1rem;
                    background: #f8f9fa;
                }
                .dashboard-planner-panel h6 {
                    margin: 0 0 0.75rem;
                    color: #212529;
                    font-size: 0.9rem;
                    font-weight: 700;
                }
                .dashboard-planner-form {
                    display: grid;
                    gap: 0.65rem;
                }
                .dashboard-planner-form .form-control,
                .dashboard-planner-form .form-select {
                    border-radius: 8px;
                    font-size: 0.85rem;
                }
                .dashboard-plan-list {
                    display: grid;
                    gap: 0.6rem;
                }
                .dashboard-plan-item {
                    display: grid;
                    grid-template-columns: 4px minmax(0, 1fr) auto;
                    gap: 0.65rem;
                    align-items: start;
                    background: #fff;
                    border: 1px solid #e9ecef;
                    border-radius: 10px;
                    padding: 0.7rem;
                }
                .dashboard-plan-color {
                    width: 4px;
                    min-height: 40px;
                    height: 100%;
                    border-radius: 999px;
                }
                .dashboard-plan-title {
                    margin-bottom: 0.2rem;
                    color: #212529;
                    font-size: 0.84rem;
                    font-weight: 700;
                }
                .dashboard-plan-meta {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 0.5rem;
                    color: #6c757d;
                    font-size: 0.72rem;
                }
                .dashboard-plan-delete {
                    width: 30px;
                    height: 30px;
                    border: 0;
                    border-radius: 8px;
                    color: #dc2626;
                    background: #fee2e2;
                }
                @media (max-width: 1200px) {
                    .dashboard-planner-grid {
                        grid-template-columns: 1fr;
                    }
                }
                @media (max-width: 768px) {
                    .dashboard-calendar-day {
                        min-height: 70px;
                        padding: 0.35rem;
                    }
                    .dashboard-calendar-weekday {
                        font-size: 0.62rem;
                    }
                }
            </style>
            <?php $stylesRendered = true;
        endif;

        $calendarStart = $planner['calendar_start'];
        $calendarMonth = (string) $planner['calendar_month'];
        $cursorDate = clone $planner['first_calendar_day'];
        $lastCalendarDay = clone $planner['last_calendar_day'];
        $plansByDate = $planner['plans_by_date'];
        $upcomingPlans = $planner['upcoming_plans'];
        ?>
        <div class="dashboard-planner-toolbar">
            <h5 class="dashboard-planner-month"><?php echo htmlspecialchars($title . ' - ' . $calendarStart->format('F Y')); ?></h5>
            <div class="dashboard-planner-nav">
                <a href="dashboard.php?calendar_month=<?php echo urlencode((string) $planner['previous_month']); ?>" aria-label="Previous month"><i class="fas fa-chevron-left"></i></a>
                <a href="dashboard.php?calendar_month=<?php echo urlencode(date('Y-m')); ?>" aria-label="Current month"><i class="fas fa-circle"></i></a>
                <a href="dashboard.php?calendar_month=<?php echo urlencode((string) $planner['next_month']); ?>" aria-label="Next month"><i class="fas fa-chevron-right"></i></a>
            </div>
        </div>
        <div class="dashboard-planner-grid">
            <div class="dashboard-calendar-grid">
                <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $weekday): ?>
                    <div class="dashboard-calendar-weekday"><?php echo $weekday; ?></div>
                <?php endforeach; ?>
                <?php while ($cursorDate <= $lastCalendarDay):
                    $dateString = $cursorDate->format('Y-m-d');
                    $datePlans = $plansByDate[$dateString] ?? [];
                    $isCurrentMonth = $cursorDate->format('Y-m') === $calendarMonth;
                    $isToday = $dateString === date('Y-m-d');
                ?>
                    <div class="dashboard-calendar-day <?php echo !$isCurrentMonth ? 'is-muted' : ''; ?> <?php echo $isToday ? 'is-today' : ''; ?>">
                        <span class="dashboard-calendar-date"><?php echo htmlspecialchars($cursorDate->format('j')); ?></span>
                        <?php foreach (array_slice($datePlans, 0, 2) as $plan): ?>
                            <span
                                class="dashboard-calendar-chip"
                                style="background: <?php echo htmlspecialchars((string) ($plan['color_code'] ?? '#1f6392')); ?>;"
                                title="<?php echo htmlspecialchars((string) ($plan['title'] ?? 'Plan')); ?>"
                            >
                                <?php echo htmlspecialchars((string) ($plan['title'] ?? 'Plan')); ?>
                            </span>
                        <?php endforeach; ?>
                        <?php if (count($datePlans) > 2): ?>
                            <span class="dashboard-calendar-more">+<?php echo count($datePlans) - 2; ?> more</span>
                        <?php endif; ?>
                    </div>
                <?php $cursorDate->modify('+1 day'); endwhile; ?>
            </div>
            <div class="dashboard-planner-side">
                <div class="dashboard-planner-panel">
                    <h6><i class="fas fa-plus me-1" style="color: #1f6392;"></i><?php echo htmlspecialchars($addLabel); ?></h6>
                    <form method="POST" class="dashboard-planner-form">
                        <input type="hidden" name="calendar_month" value="<?php echo htmlspecialchars($calendarMonth); ?>">
                        <input type="date" name="plan_date" class="form-control" value="<?php echo htmlspecialchars(date('Y-m-d')); ?>" required>
                        <input type="time" name="plan_time" class="form-control" value="09:00" required>
                        <input type="text" name="plan_title" class="form-control" placeholder="Plan title" required>
                        <select name="plan_type" class="form-select">
                            <option value="meeting">Meeting</option>
                            <option value="deadline">Deadline</option>
                            <option value="milestone">Milestone</option>
                            <option value="review">Review</option>
                            <option value="personal">Personal</option>
                        </select>
                        <input type="text" name="plan_location" class="form-control" placeholder="Location or meeting link">
                        <textarea name="plan_description" class="form-control" rows="2" placeholder="Notes or details"></textarea>
                        <button type="submit" name="dashboard_create_plan" class="btn btn-primary w-100">
                            <i class="fas fa-calendar-plus me-1"></i> Add to Calendar
                        </button>
                    </form>
                </div>
                <div class="dashboard-planner-panel">
                    <h6><i class="fas fa-list me-1" style="color: #1f6392;"></i>Upcoming Plans</h6>
                    <?php if ($upcomingPlans): ?>
                        <div class="dashboard-plan-list">
                            <?php foreach ($upcomingPlans as $plan): ?>
                                <div class="dashboard-plan-item">
                                    <span class="dashboard-plan-color" style="background: <?php echo htmlspecialchars((string) ($plan['color_code'] ?? '#1f6392')); ?>;"></span>
                                    <div>
                                        <div class="dashboard-plan-title"><?php echo htmlspecialchars((string) ($plan['title'] ?? 'Plan')); ?></div>
                                        <div class="dashboard-plan-meta">
                                            <span><i class="far fa-calendar"></i> <?php echo htmlspecialchars(date('M d, Y', strtotime((string) ($plan['start_date'] ?? date('Y-m-d'))))); ?></span>
                                            <span><i class="far fa-clock"></i> <?php echo htmlspecialchars(date('h:i A', strtotime((string) ($plan['start_time'] ?? '09:00')))); ?></span>
                                        </div>
                                    </div>
                                    <form method="POST" class="js-dashboard-delete-plan-form" data-confirm-skip="1">
                                        <input type="hidden" name="plan_id" value="<?php echo (int) ($plan['id'] ?? 0); ?>">
                                        <input type="hidden" name="calendar_month" value="<?php echo htmlspecialchars($calendarMonth); ?>">
                                        <button type="submit" name="dashboard_delete_plan" class="dashboard-plan-delete" aria-label="Delete plan">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center text-muted py-3">
                            <i class="fas fa-calendar-week d-block mb-2"></i>
                            <span>No upcoming plans</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <script>
        (function () {
            if (window.dashboardPlannerConfirmBound) {
                return;
            }
            window.dashboardPlannerConfirmBound = true;

            document.addEventListener('submit', async function (event) {
                const form = event.target.closest('.js-dashboard-delete-plan-form');
                if (!form) {
                    return;
                }

                if (form.dataset.confirmedSubmit === '1') {
                    form.dataset.confirmedSubmit = '0';
                    return;
                }

                event.preventDefault();

                const confirmed = typeof window.appConfirm === 'function'
                    ? await window.appConfirm({
                        title: 'Remove Plan',
                        subject: 'Remove this plan?',
                        message: 'This calendar plan will be removed from your dashboard.',
                        icon: 'fas fa-calendar-xmark fa-lg',
                        confirmIcon: 'fas fa-trash-alt',
                        confirmText: 'Remove',
                        variant: 'danger'
                    })
                    : false;

                if (!confirmed) {
                    return;
                }

                form.dataset.confirmedSubmit = '1';
                form.requestSubmit();
            });
        })();
        </script>
        <?php
    }
}
