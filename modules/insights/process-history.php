<?php
require_once '../../includes/auth.php';
require_once '../../includes/process-history.php';

requireRole(['admin', 'sales', 'architect', 'sul', 'qa', 'expert', 'developer']);

$conn = getConnection();
$user = getCurrentUser();
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
$sort = (string) ($_GET['sort'] ?? 'project_asc');
if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'project_asc';
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
.process-summary-card,
.process-timeline,
.task-duration-panel {
    background: #fff;
    border: 1px solid #dfe8f1;
    border-radius: 12px;
    box-shadow: 0 8px 22px rgba(15, 42, 64, 0.04);
}
.process-filter-card { padding: 1rem; margin-bottom: 1rem; }
.process-filter-card label { color: #64748b; font-size: 0.72rem; font-weight: 800; text-transform: uppercase; }
.process-summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 0.85rem; margin-bottom: 1rem; }
.process-summary-card { padding: 1rem; }
.process-summary-card span { display: block; color: #64748b; font-size: 0.72rem; font-weight: 800; text-transform: uppercase; }
.process-summary-card strong { display: block; margin-top: 0.25rem; color: #172033; font-size: 1.3rem; }
.process-summary-card small { color: #64748b; }
.process-timeline { overflow: hidden; }
.process-timeline-head {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    padding: 1rem 1.1rem;
    border-bottom: 1px solid #e5edf5;
    background: #f8fbfd;
}
.process-timeline-head h5 { margin: 0; color: #172033; font-size: 1rem; font-weight: 750; }
.process-timeline-head p { margin: 0.2rem 0 0; color: #64748b; font-size: 0.82rem; }
.process-list { display: grid; }
.process-event {
    display: grid;
    grid-template-columns: minmax(150px, 190px) 1fr minmax(130px, 160px);
    gap: 1rem;
    padding: 1rem 1.1rem;
    border-bottom: 1px solid #eef3f7;
}
.process-event:last-child { border-bottom: 0; }
.process-event-time { color: #64748b; font-size: 0.78rem; }
.process-event-time strong { display: block; color: #172033; font-size: 0.86rem; }
.process-event-main { min-width: 0; }
.process-event-title { display: flex; align-items: center; flex-wrap: wrap; gap: 0.45rem; margin-bottom: 0.4rem; }
.process-event-title strong { color: #172033; font-size: 0.95rem; }
.process-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.25rem 0.48rem;
    border-radius: 999px;
    background: #edf6fb;
    color: #1f6392;
    font-size: 0.68rem;
    font-weight: 800;
    white-space: nowrap;
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
.process-meta { display: flex; flex-wrap: wrap; gap: 0.65rem; color: #64748b; font-size: 0.78rem; }
.process-status-flow { margin-top: 0.45rem; color: #475569; font-size: 0.82rem; }
.process-note { margin-top: 0.42rem; color: #64748b; font-size: 0.8rem; overflow-wrap: anywhere; }
.process-duration { text-align: right; }
.process-duration strong { display: block; color: #172033; font-size: 0.95rem; }
.process-duration span { color: #64748b; font-size: 0.72rem; font-weight: 800; text-transform: uppercase; }
.process-duration.is-slow strong { color: #b45309; }
.process-empty { padding: 2rem; text-align: center; color: #64748b; }
.process-empty i { display: block; margin-bottom: 0.7rem; color: #1f6392; font-size: 1.8rem; }
.task-duration-panel { margin-bottom: 1rem; overflow: hidden; }
.task-duration-head {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    padding: 1rem 1.1rem;
    border-bottom: 1px solid #e5edf5;
    background: #f8fbfd;
}
.task-duration-head h5 { margin: 0; color: #172033; font-size: 1rem; font-weight: 750; }
.task-duration-head p { margin: 0.2rem 0 0; color: #64748b; font-size: 0.82rem; }
.task-duration-table-wrap { overflow-x: auto; }
.task-duration-table { width: 100%; border-collapse: separate; border-spacing: 0; min-width: 980px; }
.task-duration-table th,
.task-duration-table td {
    padding: 0.82rem 0.9rem;
    border-bottom: 1px solid #eef3f7;
    vertical-align: top;
    font-size: 0.82rem;
}
.task-duration-table th {
    background: #f8fbfd;
    color: #64748b;
    font-size: 0.68rem;
    font-weight: 850;
    text-transform: uppercase;
}
.task-duration-name strong { display: block; color: #172033; font-size: 0.9rem; }
.task-duration-name span,
.task-duration-muted { color: #64748b; font-size: 0.76rem; }
.task-duration-value strong { display: block; color: #172033; font-size: 0.92rem; }
.task-duration-value small { color: #64748b; }
.task-duration-late { color: #b91c1c; font-weight: 800; }
.task-duration-ontrack { color: #166534; font-weight: 800; }
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-filter-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-summary-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-timeline,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-duration-panel { background: #0d1b2a; border-color: #20364a; }
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-timeline-head,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-duration-head,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-duration-table th { background: #102235; border-color: #20364a; }
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-event { border-color: #20364a; }
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-duration-table td { border-color: #20364a; }
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-summary-card strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-timeline-head h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-duration-head h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-duration-name strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-duration-value strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-event-time strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-event-title strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-duration strong { color: #e7f0f8; }
@media (max-width: 900px) {
    .process-event { grid-template-columns: 1fr; }
    .process-duration { text-align: left; }
}
</style>

<div id="page-content-wrapper">
    <div class="container-fluid px-4 py-4">
        <section class="process-hero">
            <div>
                <h2><i class="fas fa-clock-rotate-left me-2"></i>Process History</h2>
                <p>Track project movements from planning to release, including task start-to-finish duration, approvals, revisions, QA decisions, and completion timing.</p>
            </div>
        </section>

        <form class="process-filter-card" method="GET">
            <div class="row g-3 align-items-end">
                <div class="col-md-6">
                    <label for="project_id" class="form-label">Project</label>
                    <select class="form-select" id="project_id" name="project_id">
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
                    <select class="form-select" id="sort" name="sort">
                        <option value="project_asc" <?php echo $sort === 'project_asc' ? 'selected' : ''; ?>>Project A-Z, oldest first</option>
                        <option value="project_desc" <?php echo $sort === 'project_desc' ? 'selected' : ''; ?>>Project Z-A, oldest first</option>
                        <option value="recent" <?php echo $sort === 'recent' ? 'selected' : ''; ?>>Most recent movement first</option>
                        <option value="longest_gap" <?php echo $sort === 'longest_gap' ? 'selected' : ''; ?>>Longest duration gap first</option>
                        <option value="longest_task_duration" <?php echo $sort === 'longest_task_duration' ? 'selected' : ''; ?>>Longest task duration first</option>
                    </select>
                </div>
                <div class="col-md-2 d-grid">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-filter me-1"></i>Apply</button>
                </div>
            </div>
        </form>

        <div class="process-summary-grid">
            <div class="process-summary-card">
                <span>Process movements</span>
                <strong><?php echo (int) $summary['event_count']; ?></strong>
                <small>Filtered timeline entries</small>
            </div>
            <div class="process-summary-card">
                <span>Projects shown</span>
                <strong><?php echo (int) $summary['project_count']; ?></strong>
                <small><?php echo $selectedProjectId > 0 ? 'Selected project only' : 'Across accessible projects'; ?></small>
            </div>
            <div class="process-summary-card">
                <span>Longest gap</span>
                <strong><?php echo $slowestEvent ? htmlspecialchars(processHistoryFormatDuration((int) $slowestEvent['gap_seconds'])) : 'None'; ?></strong>
                <small><?php echo $slowestEvent ? htmlspecialchars($projectNames[(int) $slowestEvent['project_id']] ?? 'Project') : 'No movement gap yet'; ?></small>
            </div>
            <div class="process-summary-card">
                <span>Completed tasks</span>
                <strong><?php echo (int) $taskSummary['completed_count']; ?> / <?php echo (int) $taskSummary['task_count']; ?></strong>
                <small>Average completion: <?php echo $taskSummary['average_completed_seconds'] !== null ? htmlspecialchars(processHistoryFormatDuration((int) $taskSummary['average_completed_seconds'])) : 'No completed tasks'; ?></small>
            </div>
            <div class="process-summary-card">
                <span>Longest task duration</span>
                <strong><?php echo $slowestTask ? htmlspecialchars(processHistoryFormatDuration((int) $slowestTask['workflow_seconds'])) : 'None'; ?></strong>
                <small><?php echo $slowestTask ? htmlspecialchars((string) $slowestTask['title']) : 'No tasks found'; ?></small>
            </div>
        </div>

        <section class="task-duration-panel">
            <div class="task-duration-head">
                <div>
                    <h5><i class="fas fa-list-check me-2"></i>Task Start-To-Finish Duration</h5>
                    <p>Shows every task in the workflow, including completed duration, open duration, start date, completion date, and lateness against deadline.</p>
                </div>
            </div>
            <?php if (!$taskDurations): ?>
                <div class="process-empty">
                    <i class="fas fa-list"></i>
                    <strong>No tasks found.</strong>
                    <div>Choose another project or create workflow tasks first.</div>
                </div>
            <?php else: ?>
                <div class="task-duration-table-wrap">
                    <table class="task-duration-table">
                        <thead>
                            <tr>
                                <th>Task</th>
                                <th>Project</th>
                                <th>Status</th>
                                <th>First Movement</th>
                                <th>Known Start</th>
                                <th>Completed / Current</th>
                                <th>Total Workflow Duration</th>
                                <th>Work Duration</th>
                                <th>Deadline Result</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($taskDurations as $task): ?>
                                <?php
                                    $projectName = $projectNames[(int) $task['project_id']] ?? 'Project';
                                    $firstMovementTs = processHistoryTimestamp($task['first_movement_at'] ?? null) ?? processHistoryTimestamp($task['created_at'] ?? null);
                                    $startedTs = processHistoryTimestamp($task['started_at'] ?? null);
                                    $completedTs = processHistoryTimestamp($task['completed_at'] ?? null);
                                    $deadlineTs = processHistoryTimestamp($task['deadline'] ?? null);
                                    $displayEndTs = $completedTs ?? time();
                                    $lateSeconds = (int) ($task['late_seconds'] ?? 0);
                                    $isCompleted = !empty($task['is_completed']);
                                ?>
                                <tr>
                                    <td class="task-duration-name">
                                        <strong><?php echo htmlspecialchars((string) $task['title']); ?></strong>
                                        <span><?php echo htmlspecialchars((string) $task['assigned_to']); ?></span>
                                    </td>
                                    <td><?php echo htmlspecialchars($projectName); ?></td>
                                    <td><span class="process-pill <?php echo $isCompleted ? 'approval' : 'task'; ?>"><?php echo htmlspecialchars((string) $task['status_label']); ?></span></td>
                                    <td class="task-duration-muted"><?php echo $firstMovementTs ? htmlspecialchars(date('M d, Y h:i A', $firstMovementTs)) : 'No movement yet'; ?></td>
                                    <td class="task-duration-muted"><?php echo $startedTs ? htmlspecialchars(date('M d, Y', $startedTs)) : 'Not set'; ?></td>
                                    <td class="task-duration-muted">
                                        <?php echo $completedTs ? htmlspecialchars(date('M d, Y h:i A', $completedTs)) : 'Open as of ' . htmlspecialchars(date('M d, Y h:i A', $displayEndTs)); ?>
                                    </td>
                                    <td class="task-duration-value">
                                        <strong><?php echo htmlspecialchars(processHistoryFormatDuration($task['workflow_seconds'])); ?></strong>
                                        <small><?php echo $isCompleted ? 'start to completed' : 'open so far'; ?></small>
                                    </td>
                                    <td class="task-duration-value">
                                        <strong><?php echo htmlspecialchars(processHistoryFormatDuration($task['work_seconds'])); ?></strong>
                                        <small><?php echo $startedTs ? 'known start to end' : 'using first movement'; ?></small>
                                    </td>
                                    <td>
                                        <?php if (!$deadlineTs): ?>
                                            <span class="task-duration-muted">No deadline</span>
                                        <?php elseif ($lateSeconds > 0): ?>
                                            <span class="task-duration-late"><?php echo htmlspecialchars(processHistoryFormatDuration($lateSeconds)); ?> late</span>
                                        <?php elseif ($isCompleted): ?>
                                            <span class="task-duration-ontrack">On time</span>
                                        <?php else: ?>
                                            <span class="task-duration-muted">Due <?php echo htmlspecialchars(date('M d, Y', $deadlineTs)); ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="process-timeline">
            <div class="process-timeline-head">
                <div>
                    <h5><i class="fas fa-route me-2"></i>Movement Timeline</h5>
                    <p>Every process movement from planning through completion, with the waiting time since the previous movement.</p>
                </div>
            </div>
            <?php if (!$events): ?>
                <div class="process-empty">
                    <i class="fas fa-folder-open"></i>
                    <strong>No process movements found.</strong>
                    <div>Try another project or complete a workflow action first.</div>
                </div>
            <?php else: ?>
                <div class="process-list">
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
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
