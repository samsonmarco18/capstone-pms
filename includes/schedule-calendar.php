<?php

if (!function_exists('normalizeScheduleDateTime')) {
    function normalizeScheduleDateTime(array $schedule): DateTime
    {
        $startDate = trim((string)($schedule['start_date'] ?? ''));
        $startTime = trim((string)($schedule['start_time'] ?? ''));

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

if (!function_exists('renderScheduleCalendar')) {
    function renderScheduleCalendar($schedules, bool $showFullCalendarButton = true): void
    {
        static $stylesRendered = false;

        $events = [];
        if ($schedules && $schedules->num_rows > 0) {
            while ($schedule = $schedules->fetch_assoc()) {
                $dateTime = normalizeScheduleDateTime($schedule);
                $events[] = [
                    'title' => $schedule['title'] ?? 'Schedule',
                    'description' => $schedule['description'] ?? '',
                    'datetime' => $dateTime,
                    'date_key' => $dateTime->format('Y-m-d'),
                    'type' => $schedule['schedule_type'] ?? 'schedule',
                ];
            }
        }

        usort($events, static function (array $left, array $right): int {
            return $left['datetime']->getTimestamp() <=> $right['datetime']->getTimestamp();
        });

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
                    display: grid;
                    gap: 1rem;
                }

                .schedule-calendar__month {
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    gap: 1rem;
                    flex-wrap: wrap;
                }

                .schedule-calendar__month-heading {
                    display: grid;
                    gap: 0.2rem;
                }

                .schedule-calendar__month-title {
                    font-size: 1rem;
                    font-weight: 700;
                    color: #1f6392;
                    margin: 0;
                }

                .schedule-calendar__count {
                    display: inline-flex;
                    align-items: center;
                    gap: 0.35rem;
                    color: #6c757d;
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
                    background: linear-gradient(135deg, #1f6392, #184f73);
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
                    gap: 0.35rem;
                }

                .schedule-calendar__weekday {
                    color: #6c757d;
                    font-size: 0.72rem;
                    font-weight: 700;
                    text-align: center;
                    text-transform: uppercase;
                }

                .schedule-calendar__day,
                .schedule-calendar__blank {
                    min-height: 3.2rem;
                    border-radius: 8px;
                }

                .schedule-calendar__day {
                    position: relative;
                    padding: 0.4rem;
                    border: 1px solid #e9ecef;
                    background: #ffffff;
                    color: #212529;
                    font-size: 0.82rem;
                    font-weight: 700;
                }

                .schedule-calendar__day.is-today {
                    border-color: #1f6392;
                    box-shadow: inset 0 0 0 1px #1f6392;
                }

                .schedule-calendar__day.has-event {
                    background: rgba(31, 99, 146, 0.08);
                    border-color: rgba(31, 99, 146, 0.32);
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
                    background: #1f6392;
                }

                .schedule-calendar__list {
                    display: grid;
                    gap: 0.65rem;
                }

                .schedule-calendar__event {
                    display: grid;
                    grid-template-columns: auto 1fr;
                    gap: 0.7rem;
                    align-items: start;
                    padding: 0.75rem;
                    border: 1px solid #e9ecef;
                    border-radius: 8px;
                    background: #ffffff;
                }

                .schedule-calendar__date-badge {
                    min-width: 3.1rem;
                    padding: 0.35rem 0.45rem;
                    border-radius: 8px;
                    background: #1f6392;
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
                    color: #212529;
                    font-size: 0.95rem;
                    font-weight: 700;
                }

                .schedule-calendar__event-time,
                .schedule-calendar__event-desc {
                    margin: 0;
                    color: #6c757d;
                    font-size: 0.82rem;
                }

                .schedule-calendar__empty {
                    min-height: 8rem;
                    display: grid;
                    place-items: center;
                    text-align: center;
                    color: #6c757d;
                }

                .schedule-calendar__empty i {
                    display: block;
                    color: #ced4da;
                    font-size: 2.4rem;
                    margin-bottom: 0.65rem;
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
                        <div class="schedule-calendar__event">
                            <div class="schedule-calendar__date-badge">
                                <span><?php echo $event['datetime']->format('M'); ?></span>
                                <strong><?php echo $event['datetime']->format('d'); ?></strong>
                            </div>
                            <div>
                                <h6 class="schedule-calendar__event-title"><?php echo htmlspecialchars($event['title']); ?></h6>
                                <p class="schedule-calendar__event-time">
                                    <i class="far fa-clock"></i>
                                    <?php echo $event['datetime']->format('M d, Y h:i A'); ?>
                                </p>
                                <?php if (trim((string)$event['description']) !== ''): ?>
                                    <p class="schedule-calendar__event-desc"><?php echo htmlspecialchars($event['description']); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
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
