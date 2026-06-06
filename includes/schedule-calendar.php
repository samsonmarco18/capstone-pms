<?php

if (!function_exists('normalizeScheduleDateTime')) {
    function normalizeScheduleDateTime(array $schedule): DateTime
    {
        $dateValue = trim((string)($schedule['date'] ?? ''));
        $startDate = trim((string)($schedule['start_date'] ?? ''));
        $startTime = trim((string)($schedule['start_time'] ?? ''));

        if ($dateValue !== '' && $startDate === '') {
            try {
                return new DateTime($dateValue);
            } catch (Exception $e) {
                return new DateTime();
            }
        }

        if ($startDate !== '') {
            $timeOnly = $startTime !== '' && preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $startTime);
            $value = $startDate . ($startTime !== '' && $timeOnly ? ' ' . $startTime : '');
        } else {
            $value = $startTime !== '' ? $startTime : 'now';
        }

        try {
            return new DateTime($value);
        } catch (Exception $e) {
            return new DateTime();
        }
    }
}

if (!function_exists('scheduleCalendarBuildEvent')) {
    function scheduleCalendarBuildEvent(array $schedule): array
    {
        $dateTime = normalizeScheduleDateTime($schedule);
        $type = (string)($schedule['schedule_type'] ?? $schedule['type'] ?? 'schedule');

        return [
            'title' => $schedule['title'] ?? 'Schedule',
            'description' => $schedule['description'] ?? '',
            'datetime' => $dateTime,
            'date_key' => $dateTime->format('Y-m-d'),
            'type' => $type !== '' ? $type : 'schedule',
            'url' => $schedule['url'] ?? '',
        ];
    }
}

if (!function_exists('scheduleCalendarCollectEvents')) {
    function scheduleCalendarCollectEvents($schedules, array $extraEvents = []): array
    {
        $events = [];
        if ($schedules instanceof mysqli_result && $schedules->num_rows > 0) {
            while ($schedule = $schedules->fetch_assoc()) {
                $events[] = scheduleCalendarBuildEvent($schedule);
            }
        } elseif (is_array($schedules)) {
            foreach ($schedules as $schedule) {
                if (is_array($schedule)) {
                    $events[] = scheduleCalendarBuildEvent($schedule);
                }
            }
        }

        foreach ($extraEvents as $schedule) {
            if (is_array($schedule)) {
                $events[] = scheduleCalendarBuildEvent($schedule);
            }
        }

        usort($events, static function (array $left, array $right): int {
            return $left['datetime']->getTimestamp() <=> $right['datetime']->getTimestamp();
        });

        return $events;
    }
}

if (!function_exists('renderScheduleCalendar')) {
    function renderScheduleCalendar($schedules, bool $showFullCalendarButton = true, array $extraEvents = []): void
    {
        static $stylesRendered = false;

        $events = scheduleCalendarCollectEvents($schedules, $extraEvents);

        $today = new DateTime('today');
        $baseDate = !empty($events) ? clone $events[0]['datetime'] : clone $today;
        $firstDay = new DateTime($baseDate->format('Y-m-01'));
        $daysInMonth = (int)$firstDay->format('t');
        $leadingBlanks = (int)$firstDay->format('w');
        $eventsByDate = [];

        foreach ($events as $event) {
            $eventsByDate[$event['date_key']][] = $event;
        }

        if (!$stylesRendered): ?>
            <style>
                .schedule-calendar {
                    --schedule-accent: var(--theme-primary, #1f6392);
                    --schedule-accent-dark: var(--theme-dark, #184f73);
                    --schedule-border: var(--gray-200, #e9ecef);
                    --schedule-muted: var(--gray-600, #6c757d);
                    --schedule-ink: var(--gray-900, #212529);
                    display: grid;
                    gap: 1rem;
                }

                .schedule-calendar__month {
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    gap: 1rem;
                    flex-wrap: wrap;
                    padding: 1rem;
                    border: 1px solid var(--schedule-border);
                    border-radius: 16px;
                    background: linear-gradient(135deg, rgba(31, 99, 146, 0.08), rgba(31, 99, 146, 0.02));
                }

                .schedule-calendar__month-heading {
                    display: grid;
                    gap: 0.2rem;
                }

                .schedule-calendar__month-title {
                    font-size: 1.05rem;
                    font-weight: 700;
                    color: var(--schedule-ink);
                    margin: 0;
                }

                .schedule-calendar__count {
                    display: inline-flex;
                    align-items: center;
                    gap: 0.35rem;
                    color: var(--schedule-muted);
                    font-size: 0.8rem;
                    font-weight: 600;
                }

                .schedule-calendar__actions {
                    display: inline-flex;
                    align-items: center;
                    gap: 0.6rem;
                    margin-left: auto;
                }

                .schedule-calendar__button {
                    display: inline-flex;
                    align-items: center;
                    gap: 0.45rem;
                    padding: 0.55rem 0.9rem;
                    border: 0;
                    border-radius: 999px;
                    background: linear-gradient(135deg, var(--schedule-accent), var(--schedule-accent-dark));
                    color: #ffffff;
                    text-decoration: none;
                    font-size: 0.82rem;
                    font-weight: 700;
                    box-shadow: 0 10px 20px rgba(31, 99, 146, 0.18);
                    transition: transform 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
                }

                .schedule-calendar__button:hover {
                    transform: translateY(-1px);
                    box-shadow: 0 14px 24px rgba(31, 99, 146, 0.22);
                }

                .schedule-calendar__button:focus {
                    outline: none;
                    box-shadow: 0 0 0 0.2rem rgba(31, 99, 146, 0.2);
                }

                .schedule-calendar__grid {
                    display: grid;
                    grid-template-columns: repeat(7, minmax(0, 1fr));
                    border: 1px solid var(--schedule-border);
                    border-radius: 16px;
                    overflow: hidden;
                    background: #ffffff;
                }

                .schedule-calendar__weekday {
                    min-width: 0;
                    padding: 0.6rem 0.35rem;
                    border-right: 1px solid var(--schedule-border);
                    border-bottom: 1px solid var(--schedule-border);
                    background: #f8f9fa;
                    color: var(--schedule-muted);
                    font-size: 0.72rem;
                    font-weight: 700;
                    text-align: center;
                    text-transform: uppercase;
                    letter-spacing: 0.04em;
                }

                .schedule-calendar__day,
                .schedule-calendar__blank {
                    min-height: 4.35rem;
                }

                .schedule-calendar__day {
                    position: relative;
                    padding: 0.5rem;
                    border-right: 1px solid var(--schedule-border);
                    border-bottom: 1px solid var(--schedule-border);
                    background: #ffffff;
                    color: var(--schedule-ink);
                    font-size: 0.82rem;
                    font-weight: 700;
                    transition: background 0.18s ease;
                }

                .schedule-calendar__weekday:nth-child(7n),
                .schedule-calendar__day:nth-child(7n) {
                    border-right: none;
                }

                .schedule-calendar__day:hover {
                    background: #f3faff;
                }

                .schedule-calendar__day.is-today {
                    box-shadow: inset 0 0 0 2px rgba(31, 99, 146, 0.34);
                }

                .schedule-calendar__day.has-event {
                    background: rgba(31, 99, 146, 0.07);
                }

                .schedule-calendar__dots {
                    position: absolute;
                    left: 0.4rem;
                    right: 0.4rem;
                    bottom: 0.35rem;
                    display: flex;
                    gap: 0.2rem;
                    flex-wrap: wrap;
                }

                .schedule-calendar__dot {
                    width: 0.38rem;
                    height: 0.38rem;
                    border-radius: 999px;
                    background: var(--schedule-accent);
                }

                .schedule-calendar__list {
                    display: grid;
                    gap: 0.7rem;
                }

                .schedule-calendar__event {
                    display: grid;
                    grid-template-columns: auto minmax(0, 1fr) auto;
                    gap: 0.7rem;
                    align-items: start;
                    padding: 0.8rem;
                    border: 1px solid var(--schedule-border);
                    border-radius: 14px;
                    background: #ffffff;
                    text-decoration: none;
                    transition: border-color 0.18s ease, transform 0.18s ease, box-shadow 0.18s ease;
                }

                .schedule-calendar__event:hover {
                    border-color: rgba(31, 99, 146, 0.32);
                    transform: translateY(-1px);
                    box-shadow: 0 10px 24px rgba(31, 99, 146, 0.08);
                }

                .schedule-calendar__date-badge {
                    min-width: 3.1rem;
                    padding: 0.35rem 0.45rem;
                    border-radius: 12px;
                    background: var(--schedule-accent);
                    color: #ffffff;
                    text-align: center;
                    line-height: 1.05;
                }

                .schedule-calendar__date-badge span {
                    display: block;
                    font-size: 0.68rem;
                    font-weight: 700;
                    text-transform: uppercase;
                }

                .schedule-calendar__date-badge strong {
                    display: block;
                    font-size: 1.15rem;
                }

                .schedule-calendar__event-title {
                    margin: 0 0 0.2rem;
                    color: var(--schedule-ink);
                    font-size: 0.95rem;
                    font-weight: 700;
                }

                .schedule-calendar__event-time,
                .schedule-calendar__event-desc {
                    margin: 0;
                    color: var(--schedule-muted);
                    font-size: 0.82rem;
                }

                .schedule-calendar__event-type {
                    display: inline-flex;
                    width: fit-content;
                    margin-bottom: 0.35rem;
                    padding: 0.2rem 0.55rem;
                    border-radius: 999px;
                    background: rgba(31, 99, 146, 0.09);
                    color: var(--schedule-accent);
                    font-size: 0.68rem;
                    font-weight: 800;
                    text-transform: uppercase;
                    letter-spacing: 0.04em;
                }

                .schedule-calendar__open {
                    width: 2rem;
                    height: 2rem;
                    border-radius: 10px;
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    background: #f3faff;
                    color: var(--schedule-accent);
                }

                .schedule-calendar__empty {
                    min-height: 8rem;
                    display: grid;
                    place-items: center;
                    text-align: center;
                    border: 1px dashed var(--schedule-border);
                    border-radius: 16px;
                    color: var(--schedule-muted);
                    background: #fbfcfd;
                }

                .schedule-calendar__empty i {
                    display: block;
                    color: #ced4da;
                    font-size: 2.4rem;
                    margin-bottom: 0.65rem;
                }

                @media (max-width: 575.98px) {
                    .schedule-calendar__day,
                    .schedule-calendar__blank {
                        min-height: 3.4rem;
                    }

                    .schedule-calendar__event {
                        grid-template-columns: auto minmax(0, 1fr);
                    }

                    .schedule-calendar__open {
                        display: none;
                    }
                }

            </style>
            <?php $stylesRendered = true;
        endif; ?>

        <div class="schedule-calendar">
            <div class="schedule-calendar__month">
                <div class="schedule-calendar__month-heading">
                    <h6 class="schedule-calendar__month-title"><?php echo $baseDate->format('F Y'); ?></h6>
                    <span class="schedule-calendar__count">
                        <i class="far fa-calendar-alt"></i>
                        <?php echo count($events); ?> item<?php echo count($events) === 1 ? '' : 's'; ?>
                    </span>
                </div>
                <?php if ($showFullCalendarButton): ?>
                    <div class="schedule-calendar__actions">
                        <a
                            href="<?php echo htmlspecialchars(BASE_URL . 'calendar.php'); ?>"
                            class="schedule-calendar__button"
                        >
                            <i class="fas fa-calendar-week"></i>
                            Calendar
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <div class="schedule-calendar__grid" aria-label="Schedule calendar">
                <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $weekday): ?>
                    <div class="schedule-calendar__weekday"><?php echo $weekday; ?></div>
                <?php endforeach; ?>

                <?php for ($blank = 0; $blank < $leadingBlanks; $blank++): ?>
                    <div class="schedule-calendar__blank" aria-hidden="true"></div>
                <?php endfor; ?>

                <?php for ($day = 1; $day <= $daysInMonth; $day++):
                    $dateKey = $baseDate->format('Y-m-') . str_pad((string)$day, 2, '0', STR_PAD_LEFT);
                    $dateEvents = $eventsByDate[$dateKey] ?? [];
                    $classes = ['schedule-calendar__day'];
                    if ($dateKey === $today->format('Y-m-d')) {
                        $classes[] = 'is-today';
                    }
                    if (!empty($dateEvents)) {
                        $classes[] = 'has-event';
                    }
                ?>
                    <div class="<?php echo implode(' ', $classes); ?>" title="<?php echo !empty($dateEvents) ? htmlspecialchars(count($dateEvents) . ' item(s)') : ''; ?>">
                        <?php echo $day; ?>
                        <?php if (!empty($dateEvents)): ?>
                            <div class="schedule-calendar__dots">
                                <?php foreach (array_slice($dateEvents, 0, 3) as $event): ?>
                                    <span class="schedule-calendar__dot"></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endfor; ?>
            </div>

            <?php if (!empty($events)): ?>
                <div class="schedule-calendar__list">
                    <?php foreach ($events as $event): ?>
                        <?php $eventUrl = trim((string)($event['url'] ?? '')); ?>
                        <<?php echo $eventUrl !== '' ? 'a' : 'div'; ?>
                            class="schedule-calendar__event"
                            <?php if ($eventUrl !== ''): ?>href="<?php echo htmlspecialchars($eventUrl); ?>"<?php endif; ?>
                        >
                            <div class="schedule-calendar__date-badge">
                                <span><?php echo $event['datetime']->format('M'); ?></span>
                                <strong><?php echo $event['datetime']->format('d'); ?></strong>
                            </div>
                            <div>
                                <span class="schedule-calendar__event-type"><?php echo htmlspecialchars((string)$event['type']); ?></span>
                                <h6 class="schedule-calendar__event-title"><?php echo htmlspecialchars($event['title']); ?></h6>
                                <p class="schedule-calendar__event-time">
                                    <i class="far fa-clock"></i>
                                    <?php echo $event['datetime']->format('M d, Y h:i A'); ?>
                                </p>
                                <?php if (trim((string)$event['description']) !== ''): ?>
                                    <p class="schedule-calendar__event-desc"><?php echo htmlspecialchars($event['description']); ?></p>
                                <?php endif; ?>
                            </div>
                            <?php if ($eventUrl !== ''): ?>
                                <span class="schedule-calendar__open" aria-hidden="true"><i class="fas fa-arrow-right"></i></span>
                            <?php endif; ?>
                        </<?php echo $eventUrl !== '' ? 'a' : 'div'; ?>>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="schedule-calendar__empty">
                    <div>
                        <i class="fas fa-calendar-week"></i>
                        <p>No upcoming schedules or deadlines</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}

if (!function_exists('renderScheduleAgendaPreview')) {
    function renderScheduleAgendaPreview($schedules, array $extraEvents = [], string $title = 'Calendar'): void
    {
        static $stylesRendered = false;

        $events = scheduleCalendarCollectEvents($schedules, $extraEvents);
        $nextEvent = $events[0] ?? null;

        if (!$stylesRendered): ?>
            <style>
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
                    color: var(--gray-900, #212529);
                    font-size: 1.05rem;
                    font-weight: 700;
                }

                .agenda-subtitle {
                    margin: 0.15rem 0 0;
                    color: var(--gray-500, #adb5bd);
                    font-size: 0.78rem;
                    font-weight: 500;
                }

                .agenda-open-btn {
                    width: 38px;
                    height: 38px;
                    border-radius: 8px;
                    border: 1px solid rgba(31, 99, 146, 0.18);
                    background: rgba(31, 99, 146, 0.08);
                    color: var(--theme-primary, #1f6392);
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    text-decoration: none;
                    transition: all 0.18s ease;
                }

                .agenda-open-btn:hover {
                    transform: translateY(-1px);
                    background: var(--theme-primary, #1f6392);
                    color: #ffffff;
                }

                .agenda-summary {
                    display: grid;
                    grid-template-columns: 76px minmax(0, 1fr);
                    gap: 0.9rem;
                    align-items: stretch;
                    padding: 0.85rem;
                    border: 1px solid rgba(31, 99, 146, 0.08);
                    border-radius: 8px;
                    background: #ffffff;
                }

                .agenda-today-badge {
                    display: grid;
                    place-items: center;
                    min-height: 82px;
                    border-radius: 8px;
                    background: #ffffff;
                    border: 1px solid rgba(31, 99, 146, 0.08);
                    text-align: center;
                }

                .agenda-today-badge span {
                    color: var(--gray-500, #adb5bd);
                    font-size: 0.7rem;
                    font-weight: 600;
                    text-transform: uppercase;
                }

                .agenda-today-badge strong {
                    display: block;
                    color: var(--theme-primary, #1f6392);
                    font-size: 1.8rem;
                    font-weight: 700;
                    line-height: 1;
                }

                .agenda-next {
                    min-width: 0;
                    display: grid;
                    align-content: center;
                }

                .agenda-next-label {
                    color: var(--gray-500, #adb5bd);
                    font-size: 0.72rem;
                    font-weight: 600;
                    margin-bottom: 0.25rem;
                    text-transform: uppercase;
                }

                .agenda-next-title {
                    color: var(--gray-900, #212529);
                    font-size: 0.96rem;
                    font-weight: 600;
                    line-height: 1.25;
                    overflow: hidden;
                    text-overflow: ellipsis;
                    white-space: nowrap;
                }

                .agenda-next-meta {
                    color: var(--gray-600, #6c757d);
                    font-size: 0.78rem;
                    font-weight: 500;
                    margin-top: 0.35rem;
                }

                .agenda-list {
                    display: grid;
                    gap: 0.65rem;
                }

                .agenda-date-group {
                    min-width: 0;
                    padding: 0.75rem;
                    border: 1px solid var(--gray-100, #f1f3f5);
                    border-radius: 8px;
                    background: #ffffff;
                }

                .agenda-date-row {
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    gap: 1rem;
                    margin-bottom: 0.55rem;
                }

                .agenda-date-label {
                    color: var(--gray-700, #495057);
                    font-size: 0.82rem;
                    font-weight: 600;
                }

                .agenda-more-btn {
                    border: 0;
                    background: transparent;
                    color: #9ca3af;
                    padding: 0;
                    text-decoration: none;
                }

                .agenda-item {
                    display: grid;
                    grid-template-columns: 52px 4px minmax(0, 1fr);
                    gap: 0.75rem;
                    align-items: center;
                    padding: 0.55rem 0;
                    text-decoration: none;
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
                    color: #7b8190;
                    font-size: 0.9rem;
                    font-weight: 500;
                    line-height: 1.25;
                    overflow: hidden;
                    text-overflow: ellipsis;
                    white-space: nowrap;
                }

                @media (max-width: 575.98px) {
                    .agenda-summary {
                        grid-template-columns: 64px minmax(0, 1fr);
                    }
                }
            </style>
            <?php $stylesRendered = true;
        endif; ?>

        <div class="agenda-preview">
            <div class="agenda-header">
                <div>
                    <h5 class="agenda-title"><?php echo htmlspecialchars($title); ?></h5>
                    <p class="agenda-subtitle"><?php echo count($events); ?> upcoming item<?php echo count($events) === 1 ? '' : 's'; ?></p>
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
                    <?php if ($nextEvent): ?>
                        <div class="agenda-next-title"><?php echo htmlspecialchars((string) $nextEvent['title']); ?></div>
                        <div class="agenda-next-meta">
                            <i class="far fa-clock me-1"></i>
                            <?php echo htmlspecialchars($nextEvent['datetime']->format('M d, h:i A')); ?>
                        </div>
                    <?php else: ?>
                        <div class="agenda-next-title">No upcoming schedule</div>
                        <div class="agenda-next-meta">Your next items will appear here.</div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($events): ?>
                <div class="agenda-list">
                    <?php foreach ($events as $event): ?>
                        <?php
                        $eventType = strtolower((string) ($event['type'] ?? 'schedule'));
                        $eventColor = match ($eventType) {
                            'deadline', 'project deadline' => '#dc2626',
                            'milestone', 'project start' => '#7c3aed',
                            'review', 'review estimation' => '#d97706',
                            'personal' => '#64748b',
                            'assign expert', 'assign developer' => '#059669',
                            default => '#1f6392',
                        };
                        $eventUrl = trim((string) ($event['url'] ?? ''));
                        ?>
                        <div class="agenda-date-group">
                            <div class="agenda-date-row">
                                <span class="agenda-date-label"><?php echo htmlspecialchars($event['datetime']->format('D, M d')); ?></span>
                                <a href="<?php echo htmlspecialchars($eventUrl !== '' ? $eventUrl : BASE_URL . 'calendar.php'); ?>" class="agenda-more-btn" aria-label="Open item">
                                    <i class="fas fa-ellipsis-h"></i>
                                </a>
                            </div>
                            <<?php echo $eventUrl !== '' ? 'a' : 'div'; ?>
                                class="agenda-item"
                                <?php if ($eventUrl !== ''): ?>href="<?php echo htmlspecialchars($eventUrl); ?>"<?php endif; ?>
                            >
                                <div class="agenda-time"><?php echo htmlspecialchars($event['datetime']->format('H:i')); ?></div>
                                <span class="agenda-color" style="background: <?php echo htmlspecialchars($eventColor); ?>;"></span>
                                <div>
                                    <div class="agenda-kicker"><?php echo htmlspecialchars(ucfirst($eventType)); ?></div>
                                    <div class="agenda-text"><?php echo htmlspecialchars((string) $event['title']); ?></div>
                                </div>
                            </<?php echo $eventUrl !== '' ? 'a' : 'div'; ?>>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state-modern empty-state-mini" style="padding: 2rem 1rem;">
                    <i class="fas fa-calendar-week"></i>
                    <p>No upcoming schedules</p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
