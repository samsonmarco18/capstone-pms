<?php
require_once '../../includes/auth.php';
require_once '../../includes/process-history.php';

requireRole(['admin', 'sales', 'architect', 'sul', 'qa', 'expert', 'developer']);

$conn = getConnection();
$user = getCurrentUser();
methodologyRoadmapEnsureSchema($conn);
$projects = di_load_accessible_projects($conn, $user);
$projectNames = [];
foreach ($projects as $project) {
    $projectNames[(int) ($project['id'] ?? 0)] = (string) ($project['name'] ?? 'Project');
}

$accessibleProjectIds = array_keys($projectNames);
$selectedProjectId = (int) ($_GET['project_id'] ?? 0);
if ($selectedProjectId > 0 && !isset($projectNames[$selectedProjectId])) {
    $selectedProjectId = 0;
}

$allowedSorts = ['project_asc', 'project_desc', 'recent', 'longest_gap', 'longest_task_duration'];
$sort = (string) ($_GET['sort'] ?? 'recent');
if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'recent';
}
$activeTab = (string) ($_GET['tab'] ?? 'movements');
if (!in_array($activeTab, ['movements', 'durations'], true)) {
    $activeTab = 'movements';
}

$events = processHistoryLoadEvents($conn, $accessibleProjectIds, $selectedProjectId);
$events = processHistorySortEvents($events, $projectNames, $sort);
$taskDurations = processHistoryLoadTaskDurations($conn, $accessibleProjectIds, $selectedProjectId);
$taskDurations = processHistorySortTasks($taskDurations, $projectNames, $sort);
$summary = processHistorySummary($events);
$taskSummary = processHistoryTaskSummary($taskDurations);
$slowestEvent = $summary['slowest_event'];
$slowestTask = $taskSummary['slowest_task'];

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<style>
.process-hero {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1rem;
    padding: 1.25rem;
    border-radius: 14px;
    background: linear-gradient(135deg, #1f6392 0%, #174d72 100%);
    color: #fff;
}
.process-hero h2 { margin: 0; font-size: 1.35rem; font-weight: 750; }
.process-hero p { margin: 0.35rem 0 0; max-width: 780px; color: rgba(255, 255, 255, 0.86); font-size: 0.9rem; }
.process-filter-card,
.process-tab-shell {
    background: #fff;
    border: 1px solid #dfe8f1;
    border-radius: 12px;
    box-shadow: 0 8px 22px rgba(15, 42, 64, 0.04);
}
.process-filter-card { padding: 1rem; margin-bottom: 1rem; }
.process-filter-card .row { min-width: 0; }
.process-filter-card .form-select { min-width: 0; text-overflow: ellipsis; }
.process-filter-card label { color: #64748b; font-size: 0.72rem; font-weight: 800; text-transform: uppercase; }
.process-tabs {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1rem;
    overflow-x: auto;
}
.process-tabs .nav-link {
    border: 1px solid #dfe8f1;
    border-radius: 10px;
    color: #475569;
    font-weight: 800;
    white-space: nowrap;
}
.process-tabs .nav-link.active {
    background: #1f6392;
    border-color: #1f6392;
    color: #fff;
}
.process-tab-shell { overflow: hidden; }
.process-tab-head {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    padding: 1rem 1.1rem;
    border-bottom: 1px solid #e5edf5;
    background: #f8fbfd;
}
.process-tab-head > div { min-width: 0; }
.process-tab-head h5 { margin: 0; color: #172033; font-size: 1rem; font-weight: 750; }
.process-tab-head p { margin: 0.2rem 0 0; color: #64748b; font-size: 0.82rem; overflow-wrap: anywhere; }
.process-metric-strip {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 0.75rem;
    padding: 1rem 1.1rem;
    border-bottom: 1px solid #eef3f7;
}
.process-metric {
    min-width: 0;
    padding: 0.85rem;
    border: 1px solid #e5edf5;
    border-radius: 10px;
    background: #fbfdff;
}
.process-metric span { display: block; color: #64748b; font-size: 0.68rem; font-weight: 850; text-transform: uppercase; }
.process-metric strong { display: block; margin-top: 0.2rem; color: #172033; font-size: 1.12rem; overflow-wrap: anywhere; }
.process-metric small { display: block; margin-top: 0.2rem; color: #64748b; overflow-wrap: anywhere; }
.process-scroll-list {
    display: grid;
    gap: 0.85rem;
    max-height: min(680px, 64vh);
    overflow-y: auto;
    overflow-x: hidden;
    padding: 1rem 1.1rem;
    scrollbar-gutter: stable;
}
.process-event {
    display: grid;
    grid-template-columns: minmax(110px, 0.75fr) minmax(0, 2fr) minmax(105px, 0.75fr);
    gap: 1rem;
    min-width: 0;
    padding: 1rem;
    border: 1px solid #e5edf5;
    border-radius: 10px;
    background: #fff;
}
.process-event-time { min-width: 0; color: #64748b; font-size: 0.78rem; overflow-wrap: anywhere; }
.process-event-time strong { display: block; color: #172033; font-size: 0.86rem; }
.process-event-main { min-width: 0; }
.process-event-title { display: flex; align-items: center; flex-wrap: wrap; gap: 0.45rem; margin-bottom: 0.4rem; }
.process-event-title strong { min-width: 0; color: #172033; font-size: 0.95rem; overflow-wrap: anywhere; }
.process-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    max-width: 100%;
    padding: 0.25rem 0.48rem;
    border-radius: 999px;
    background: #edf6fb;
    color: #1f6392;
    font-size: 0.68rem;
    font-weight: 800;
    white-space: normal;
    overflow-wrap: anywhere;
    line-height: 1.25;
}
.process-pill.planning { background: #e0f2fe; color: #0369a1; }
.process-pill.roadmap { background: #ede9fe; color: #6d28d9; }
.process-pill.estimation { background: #fef3c7; color: #92400e; }
.process-pill.handoff,
.process-pill.task { background: #dbeafe; color: #1d4ed8; }
.process-pill.approval { background: #dcfce7; color: #166534; }
.process-pill.revision { background: #fee2e2; color: #b91c1c; }
.process-pill.version { background: #e2e8f0; color: #334155; }
.process-pill.release { background: #cffafe; color: #0e7490; }
.process-meta { display: flex; flex-wrap: wrap; gap: 0.45rem; color: #64748b; font-size: 0.78rem; }
.process-meta span {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    min-width: 0;
    max-width: 100%;
    padding: 0.28rem 0.5rem;
    border: 1px solid #edf2f7;
    border-radius: 8px;
    background: #f8fbfd;
    overflow-wrap: anywhere;
    word-break: break-word;
}
.process-status-flow { margin-top: 0.45rem; color: #475569; font-size: 0.82rem; overflow-wrap: anywhere; }
.process-note {
    margin-top: 0.55rem;
    padding: 0.55rem 0.65rem;
    border-left: 3px solid #cfe0ee;
    border-radius: 8px;
    background: #f8fbfd;
    color: #64748b;
    font-size: 0.8rem;
    overflow-wrap: anywhere;
}
.process-duration { min-width: 0; text-align: right; overflow-wrap: anywhere; }
.process-duration strong { display: block; color: #172033; font-size: 0.95rem; }
.process-duration span { color: #64748b; font-size: 0.72rem; font-weight: 800; text-transform: uppercase; overflow-wrap: anywhere; }
.process-duration small { display: block; overflow-wrap: anywhere; }
.process-duration.is-slow strong { color: #b45309; }
.process-empty { padding: 2rem; text-align: center; color: #64748b; }
.process-empty i { display: block; margin-bottom: 0.7rem; color: #1f6392; font-size: 1.8rem; }
.developer-duration-list { display: grid; }
.developer-duration-card {
    min-width: 0;
    padding: 1rem;
    border: 1px solid #e5edf5;
    border-radius: 10px;
    background: #fff;
}
.developer-duration-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 1rem;
    margin-bottom: 0.75rem;
}
.task-duration-name { min-width: 0; }
.task-duration-name strong { display: block; color: #172033; font-size: 0.95rem; overflow-wrap: anywhere; }
.task-duration-name span,
.task-duration-muted { color: #64748b; font-size: 0.76rem; overflow-wrap: anywhere; }
.developer-duration-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(155px, 100%), 1fr));
    gap: 0.65rem;
}
.task-duration-value {
    min-width: 0;
    padding: 0.72rem;
    border: 1px solid #edf2f7;
    border-radius: 9px;
    background: #f8fbfd;
}
.task-duration-value strong { display: block; color: #172033; font-size: 0.92rem; overflow-wrap: anywhere; }
.task-duration-value small { color: #64748b; overflow-wrap: anywhere; }
.task-duration-late { color: #b91c1c; font-weight: 800; }
.task-duration-ontrack { color: #166534; font-weight: 800; }
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-filter-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-tab-shell,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-metric,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-event,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .developer-duration-card { background: #0d1b2a; border-color: #20364a; }
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-tab-head { background: #102235; border-color: #20364a; }
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-metric-strip { border-color: #20364a; }
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-meta span,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-note,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-duration-value { background: #102235; border-color: #20364a; }
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-metric strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-tab-head h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-duration-name strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-duration-value strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-event-time strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-event-title strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-duration strong { color: #e7f0f8; }
@media (max-width: 1200px) {
    .process-event { grid-template-columns: minmax(0, 1fr); }
    .process-duration { text-align: left; }
}
@media (max-width: 900px) {
    .process-event { grid-template-columns: 1fr; }
    .developer-duration-top { flex-direction: column; }
    .process-duration { text-align: left; }
}
</style>

<div class="container-fluid px-4 py-4">
        <section class="process-hero">
            <div>
                <h2><i class="fas fa-clock-rotate-left me-2"></i>Process History</h2>
                <p>Track project movements from planning to release, including task start-to-finish duration, approvals, revisions, QA decisions, and completion timing.</p>
            </div>
        </section>

        <form class="process-filter-card" method="GET" id="processHistoryFilter">
            <input type="hidden" name="tab" id="processHistoryActiveTab" value="<?php echo htmlspecialchars($activeTab); ?>">
            <div class="row g-3 align-items-end">
                <div class="col-md-6">
                    <label for="project_id" class="form-label">Project</label>
                    <select class="form-select" id="project_id" name="project_id" onchange="this.form.submit()">
                        <option value="0">All accessible projects</option>
                        <?php foreach ($projects as $project): ?>
                            <?php $projectId = (int) ($project['id'] ?? 0); ?>
                            <option value="<?php echo $projectId; ?>" <?php echo $selectedProjectId === $projectId ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars((string) ($project['name'] ?? 'Project')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="sort" class="form-label">Sort</label>
                    <select class="form-select" id="sort" name="sort" onchange="this.form.submit()">
                        <option value="recent" <?php echo $sort === 'recent' ? 'selected' : ''; ?>>Most recent movement first</option>
                        <option value="longest_gap" <?php echo $sort === 'longest_gap' ? 'selected' : ''; ?>>Longest duration gap first</option>
                        <option value="longest_task_duration" <?php echo $sort === 'longest_task_duration' ? 'selected' : ''; ?>>Longest developer duration first</option>
                        <option value="project_asc" <?php echo $sort === 'project_asc' ? 'selected' : ''; ?>>Project A-Z, latest first</option>
                        <option value="project_desc" <?php echo $sort === 'project_desc' ? 'selected' : ''; ?>>Project Z-A, latest first</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <div class="text-muted small pb-2">Changes apply automatically.</div>
                </div>
            </div>
        </form>

        <ul class="nav process-tabs" id="processHistoryTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo $activeTab === 'movements' ? 'active' : ''; ?>" id="movements-tab" data-bs-toggle="tab" data-bs-target="#movements-panel" type="button" role="tab" aria-controls="movements-panel" aria-selected="<?php echo $activeTab === 'movements' ? 'true' : 'false'; ?>">
                    <i class="fas fa-route me-1"></i> Latest Movements
                    <span class="badge bg-light text-dark ms-1"><?php echo (int) $summary['event_count']; ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo $activeTab === 'durations' ? 'active' : ''; ?>" id="developer-duration-tab" data-bs-toggle="tab" data-bs-target="#developer-duration-panel" type="button" role="tab" aria-controls="developer-duration-panel" aria-selected="<?php echo $activeTab === 'durations' ? 'true' : 'false'; ?>">
                    <i class="fas fa-users-gear me-1"></i> Developer Durations
                    <span class="badge bg-light text-dark ms-1"><?php echo (int) $taskSummary['task_count']; ?></span>
                </button>
            </li>
        </ul>

        <div class="tab-content">
            <section class="tab-pane fade <?php echo $activeTab === 'movements' ? 'show active' : ''; ?> process-tab-shell" id="movements-panel" role="tabpanel" aria-labelledby="movements-tab">
                <div class="process-tab-head">
                    <div>
                        <h5><i class="fas fa-route me-2"></i>Latest Process Movements</h5>
                        <p>Newest actions are shown first. Each card includes the project, actor, item, status movement, and waiting time.</p>
                    </div>
                </div>
                <div class="process-metric-strip">
                    <div class="process-metric">
                        <span>Movements</span>
                        <strong><?php echo (int) $summary['event_count']; ?></strong>
                        <small>Filtered timeline entries</small>
                    </div>
                    <div class="process-metric">
                        <span>Projects shown</span>
                        <strong><?php echo (int) $summary['project_count']; ?></strong>
                        <small><?php echo $selectedProjectId > 0 ? 'Selected project only' : 'Across accessible projects'; ?></small>
                    </div>
                    <div class="process-metric">
                        <span>Longest gap</span>
                        <strong><?php echo $slowestEvent ? htmlspecialchars(processHistoryFormatDuration((int) $slowestEvent['gap_seconds'])) : 'None'; ?></strong>
                        <small><?php echo $slowestEvent ? htmlspecialchars($projectNames[(int) $slowestEvent['project_id']] ?? 'Project') : 'No movement gap yet'; ?></small>
                    </div>
                </div>
                <?php if (!$events): ?>
                    <div class="process-empty">
                        <i class="fas fa-folder-open"></i>
                        <strong>No process movements found.</strong>
                        <div>Try another project or complete a workflow action first.</div>
                    </div>
                <?php else: ?>
                    <div class="process-scroll-list">
                        <?php foreach ($events as $event): ?>
                        <?php
                            $projectName = $projectNames[(int) $event['project_id']] ?? 'Project';
                            $gapSeconds = $event['gap_seconds'];
                            $isSlow = $gapSeconds !== null && (int) $gapSeconds >= 172800;
                            $createdAt = !empty($event['created_at']) ? strtotime((string) $event['created_at']) : false;
                        ?>
                        <article class="process-event">
                            <div class="process-event-time">
                                <strong><?php echo $createdAt ? htmlspecialchars(date('M d, Y', $createdAt)) : 'No date'; ?></strong>
                                <span><?php echo $createdAt ? htmlspecialchars(date('h:i A', $createdAt)) : 'No time'; ?></span>
                            </div>
                            <div class="process-event-main">
                                <div class="process-event-title">
                                    <span class="process-pill <?php echo htmlspecialchars((string) $event['tone']); ?>"><?php echo htmlspecialchars((string) $event['category']); ?></span>
                                    <strong><?php echo htmlspecialchars((string) $event['label']); ?></strong>
                                </div>
                                <div class="process-meta">
                                    <span><i class="fas fa-diagram-project me-1"></i><?php echo htmlspecialchars($projectName); ?></span>
                                    <span><i class="fas fa-user me-1"></i><?php echo htmlspecialchars((string) $event['actor']); ?><?php echo !empty($event['role']) ? ' (' . htmlspecialchars(ucfirst((string) $event['role'])) . ')' : ''; ?></span>
                                    <span><i class="fas fa-tag me-1"></i><?php echo htmlspecialchars((string) $event['item']); ?></span>
                                </div>
                                <?php if ($event['from_status'] !== '' || $event['to_status'] !== ''): ?>
                                    <div class="process-status-flow">
                                        <i class="fas fa-arrow-right-arrow-left me-1"></i>
                                        <?php echo htmlspecialchars($event['from_status'] !== '' ? $event['from_status'] : 'Status'); ?>
                                        <i class="fas fa-arrow-right mx-1"></i>
                                        <?php echo htmlspecialchars($event['to_status'] !== '' ? $event['to_status'] : 'Updated'); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($event['description'] !== ''): ?>
                                    <div class="process-note"><?php echo htmlspecialchars((string) $event['description']); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="process-duration <?php echo $isSlow ? 'is-slow' : ''; ?>">
                                <span>Since previous</span>
                                <strong><?php echo htmlspecialchars(processHistoryFormatDuration($gapSeconds)); ?></strong>
                                <small><?php echo htmlspecialchars(processHistoryFormatDuration((int) $event['since_start_seconds'])); ?> since first step</small>
                            </div>
                        </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="tab-pane fade <?php echo $activeTab === 'durations' ? 'show active' : ''; ?> process-tab-shell" id="developer-duration-panel" role="tabpanel" aria-labelledby="developer-duration-tab">
                <div class="process-tab-head">
                    <div>
                        <h5><i class="fas fa-users-gear me-2"></i>Developer Task Start-To-Finish Duration</h5>
                        <p>One row per developer assignment. Total duration includes planning time; developer duration starts from the first known developer movement.</p>
                    </div>
                </div>
                <div class="process-metric-strip">
                    <div class="process-metric">
                        <span>Developer task rows</span>
                        <strong><?php echo (int) $taskSummary['task_count']; ?></strong>
                        <small>Developer-specific assignments</small>
                    </div>
                    <div class="process-metric">
                        <span>Completed developer work</span>
                        <strong><?php echo (int) $taskSummary['completed_count']; ?></strong>
                        <small>Average: <?php echo $taskSummary['average_completed_seconds'] !== null ? htmlspecialchars(processHistoryFormatDuration((int) $taskSummary['average_completed_seconds'])) : 'No completed work yet'; ?></small>
                    </div>
                    <div class="process-metric">
                        <span>Longest developer duration</span>
                        <strong><?php echo $slowestTask ? htmlspecialchars(processHistoryFormatDuration((int) $slowestTask['workflow_seconds'])) : 'None'; ?></strong>
                        <small><?php echo $slowestTask ? htmlspecialchars((string) $slowestTask['assigned_to'] . ' - ' . (string) $slowestTask['title']) : 'No tasks found'; ?></small>
                    </div>
                </div>
                <?php if (!$taskDurations): ?>
                    <div class="process-empty">
                        <i class="fas fa-list"></i>
                        <strong>No developer task durations found.</strong>
                        <div>Choose another project or assign developers to workflow tasks first.</div>
                    </div>
                <?php else: ?>
                    <div class="process-scroll-list developer-duration-list">
                        <?php foreach ($taskDurations as $task): ?>
                            <?php
                                $projectName = $projectNames[(int) $task['project_id']] ?? 'Project';
                                $planningStartTs = processHistoryTimestamp($task['planning_start_at'] ?? null);
                                $developerStartTs = processHistoryTimestamp($task['developer_started_at'] ?? null);
                                $completedTs = processHistoryTimestamp($task['completed_at'] ?? null);
                                $deadlineTs = processHistoryTimestamp($task['deadline'] ?? null);
                                $displayEndTs = $completedTs ?? time();
                                $lateSeconds = (int) ($task['late_seconds'] ?? 0);
                                $isCompleted = !empty($task['is_completed']);
                            ?>
                            <article class="developer-duration-card">
                                <div class="developer-duration-top">
                                    <div class="task-duration-name">
                                        <strong><?php echo htmlspecialchars((string) $task['title']); ?></strong>
                                        <span><i class="fas fa-user me-1"></i><?php echo htmlspecialchars((string) $task['assigned_to']); ?></span>
                                        <span class="ms-2"><i class="fas fa-diagram-project me-1"></i><?php echo htmlspecialchars($projectName); ?></span>
                                    </div>
                                    <span class="process-pill <?php echo $isCompleted ? 'approval' : 'task'; ?>"><?php echo htmlspecialchars((string) $task['status_label']); ?></span>
                                </div>
                                <div class="developer-duration-grid">
                                    <div class="task-duration-value">
                                        <small>Planning start</small>
                                        <strong><?php echo $planningStartTs ? htmlspecialchars(date('M d, Y h:i A', $planningStartTs)) : 'No movement yet'; ?></strong>
                                    </div>
                                    <div class="task-duration-value">
                                        <small>Developer start</small>
                                        <strong><?php echo $developerStartTs ? htmlspecialchars(date('M d, Y h:i A', $developerStartTs)) : 'Not started'; ?></strong>
                                    </div>
                                    <div class="task-duration-value">
                                        <small><?php echo $isCompleted ? 'Completed' : 'Current'; ?></small>
                                        <strong><?php echo $completedTs ? htmlspecialchars(date('M d, Y h:i A', $completedTs)) : htmlspecialchars(date('M d, Y h:i A', $displayEndTs)); ?></strong>
                                    </div>
                                    <div class="task-duration-value">
                                        <small>Total including planning</small>
                                        <strong><?php echo htmlspecialchars(processHistoryFormatDuration($task['workflow_seconds'])); ?></strong>
                                    </div>
                                    <div class="task-duration-value">
                                        <small>Developer work duration</small>
                                        <strong><?php echo htmlspecialchars(processHistoryFormatDuration($task['work_seconds'])); ?></strong>
                                    </div>
                                    <div class="task-duration-value">
                                        <small>Deadline result</small>
                                        <strong>
                                            <?php if (!$deadlineTs): ?>
                                                <span class="task-duration-muted">No deadline</span>
                                            <?php elseif ($lateSeconds > 0): ?>
                                                <span class="task-duration-late"><?php echo htmlspecialchars(processHistoryFormatDuration($lateSeconds)); ?> late</span>
                                            <?php elseif ($isCompleted): ?>
                                                <span class="task-duration-ontrack">On time</span>
                                            <?php else: ?>
                                                <span class="task-duration-muted">Due <?php echo htmlspecialchars(date('M d, Y', $deadlineTs)); ?></span>
                                            <?php endif; ?>
                                        </strong>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
</div>

<script>
document.querySelectorAll('#processHistoryTabs [data-bs-toggle="tab"]').forEach((tab) => {
    tab.addEventListener('shown.bs.tab', (event) => {
        const activeInput = document.getElementById('processHistoryActiveTab');
        if (!activeInput) {
            return;
        }
        activeInput.value = event.target.id === 'developer-duration-tab' ? 'durations' : 'movements';
    });
});
</script>

<?php include '../../includes/footer.php'; ?>
