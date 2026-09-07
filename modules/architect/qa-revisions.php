<?php
require_once '../../includes/auth.php';
require_once '../../includes/project-workflow.php';
requireRole('architect');

$conn = getConnection();
$user = getCurrentUser();
projectWorkflowEnsureSchema($conn);

$selectedProjectId = max(0, (int) ($_GET['project_id'] ?? 0));
$architectId = (int) ($user['id'] ?? 0);
$projectFilterSql = $selectedProjectId > 0 ? ' AND p.id = ' . $selectedProjectId : '';

$revisionProjects = $conn->query("
    SELECT DISTINCT p.id, p.name, c.name AS client_name
    FROM projects p
    JOIN clients c ON c.id = p.client_id
    JOIN tasks t ON t.project_id = p.id
    WHERE (p.architect_id = {$architectId} OR p.architect_id IS NULL)
      AND p.status != 'cancelled'
      AND (
          t.status = 'qa_failed'
          OR EXISTS (
              SELECT 1
              FROM methodology_task_departments mtd_project
              LEFT JOIN methodology_tasks mt_project ON mt_project.id = mtd_project.methodology_task_id
              WHERE COALESCE(mtd_project.workflow_task_id, mt_project.task_id) = t.id
                AND mtd_project.status IN ('for_revision', 'planned')
                AND COALESCE(mtd_project.revision_notes, '') <> ''
          )
      )
    ORDER BY p.name ASC
");

$revisionTasks = $conn->query("
    SELECT t.id,
           t.title,
           t.status,
           t.priority,
           t.deadline,
           t.updated_at,
           p.id AS project_id,
           p.name AS project_name,
           c.name AS client_name,
           developer.full_name AS developer_name,
           (
               SELECT mtd_current.department
               FROM methodology_task_departments mtd_current
               LEFT JOIN methodology_tasks mt_current ON mt_current.id = mtd_current.methodology_task_id
               WHERE COALESCE(mtd_current.workflow_task_id, mt_current.task_id) = t.id
                 AND mtd_current.status = 'for_revision'
                 AND COALESCE(mtd_current.revision_notes, '') <> ''
               ORDER BY mtd_current.updated_at DESC, mtd_current.id DESC
               LIMIT 1
           ) AS current_revision_department,
           (
               SELECT mtd_current.revision_notes
               FROM methodology_task_departments mtd_current
               LEFT JOIN methodology_tasks mt_current ON mt_current.id = mtd_current.methodology_task_id
               WHERE COALESCE(mtd_current.workflow_task_id, mt_current.task_id) = t.id
                 AND mtd_current.status = 'for_revision'
                 AND COALESCE(mtd_current.revision_notes, '') <> ''
               ORDER BY mtd_current.updated_at DESC, mtd_current.id DESC
               LIMIT 1
           ) AS current_revision_notes,
           (
               SELECT mtd_latest.department
               FROM methodology_task_departments mtd_latest
               LEFT JOIN methodology_tasks mt_latest ON mt_latest.id = mtd_latest.methodology_task_id
               WHERE COALESCE(mtd_latest.workflow_task_id, mt_latest.task_id) = t.id
                 AND COALESCE(mtd_latest.revision_notes, '') <> ''
               ORDER BY
                 CASE WHEN mtd_latest.status = 'for_revision' THEN 0 WHEN mtd_latest.status = 'planned' THEN 1 ELSE 2 END,
                 mtd_latest.updated_at DESC,
                 mtd_latest.id DESC
               LIMIT 1
           ) AS latest_revision_department,
           (
               SELECT mtd_latest.revision_notes
               FROM methodology_task_departments mtd_latest
               LEFT JOIN methodology_tasks mt_latest ON mt_latest.id = mtd_latest.methodology_task_id
               WHERE COALESCE(mtd_latest.workflow_task_id, mt_latest.task_id) = t.id
                 AND COALESCE(mtd_latest.revision_notes, '') <> ''
               ORDER BY
                 CASE WHEN mtd_latest.status = 'for_revision' THEN 0 WHEN mtd_latest.status = 'planned' THEN 1 ELSE 2 END,
                 mtd_latest.updated_at DESC,
                 mtd_latest.id DESC
               LIMIT 1
           ) AS latest_revision_notes,
           (
               SELECT COUNT(DISTINCT mtd_queue.id)
               FROM methodology_task_departments mtd_queue
               LEFT JOIN methodology_tasks mt_queue ON mt_queue.id = mtd_queue.methodology_task_id
               WHERE COALESCE(mtd_queue.workflow_task_id, mt_queue.task_id) = t.id
                 AND mtd_queue.status = 'planned'
                 AND COALESCE(mtd_queue.revision_notes, '') <> ''
           ) AS queued_department_count
    FROM tasks t
    JOIN projects p ON p.id = t.project_id
    JOIN clients c ON c.id = p.client_id
    LEFT JOIN users developer ON developer.id = t.assigned_to
    WHERE (p.architect_id = {$architectId} OR p.architect_id IS NULL)
      AND p.status != 'cancelled'
      {$projectFilterSql}
      AND (
          t.status = 'qa_failed'
          OR EXISTS (
              SELECT 1
              FROM methodology_task_departments mtd_revision
              LEFT JOIN methodology_tasks mt_revision ON mt_revision.id = mtd_revision.methodology_task_id
              WHERE COALESCE(mtd_revision.workflow_task_id, mt_revision.task_id) = t.id
                AND mtd_revision.status IN ('for_revision', 'planned')
                AND COALESCE(mtd_revision.revision_notes, '') <> ''
          )
      )
    ORDER BY
      CASE
        WHEN t.status = 'qa_failed' THEN 0
        WHEN t.status = 'sul_revision' THEN 1
        WHEN t.status = 'in_progress' THEN 2
        ELSE 3
      END,
      t.updated_at DESC,
      t.deadline ASC
");
$revisionTaskRows = [];
if ($revisionTasks) {
    while ($task = $revisionTasks->fetch_assoc()) {
        $revisionTaskRows[] = $task;
    }
}
$revisionTaskCount = count($revisionTaskRows);

$revisionTabs = [
    'all' => [
        'label' => 'All',
        'icon' => 'table-list',
        'title' => 'All QA Revision Work',
        'empty' => 'No QA revision work is currently visible to Architect.',
        'rows' => $revisionTaskRows,
    ],
    'routed' => [
        'label' => 'QA Routed',
        'icon' => 'route',
        'title' => 'QA Routed Revisions',
        'empty' => 'No tasks are currently routed from QA failure.',
        'rows' => [],
    ],
    'active' => [
        'label' => 'Active Revision',
        'icon' => 'spinner',
        'title' => 'Active Department Revisions',
        'empty' => 'No department revision is actively assigned right now.',
        'rows' => [],
    ],
    'queued' => [
        'label' => 'Queued Next',
        'icon' => 'hourglass-half',
        'title' => 'Queued Revision Departments',
        'empty' => 'No downstream revision departments are queued.',
        'rows' => [],
    ],
    'review' => [
        'label' => 'Review Loop',
        'icon' => 'clipboard-check',
        'title' => 'Revision Work Back In Review',
        'empty' => 'No revision work is waiting for SUL or QA review.',
        'rows' => [],
    ],
];

foreach ($revisionTaskRows as $task) {
    $status = (string) ($task['status'] ?? '');
    $hasCurrentRevision = trim((string) ($task['current_revision_department'] ?? '')) !== ''
        || trim((string) ($task['current_revision_notes'] ?? '')) !== '';
    $queuedCount = max(0, (int) ($task['queued_department_count'] ?? 0));

    if ($status === 'qa_failed') {
        $revisionTabs['routed']['rows'][] = $task;
    }

    if ($hasCurrentRevision || in_array($status, ['sul_revision', 'in_progress'], true)) {
        $revisionTabs['active']['rows'][] = $task;
    }

    if ($queuedCount > 0) {
        $revisionTabs['queued']['rows'][] = $task;
    }

    if (in_array($status, ['for_review', 'qa_testing', 'qa_passed'], true)) {
        $revisionTabs['review']['rows'][] = $task;
    }
}

function architectQaRevisionStatusLabel(string $status): string
{
    return match ($status) {
        'qa_failed' => 'QA revision routed',
        'sul_revision' => 'SUL revision',
        'in_progress' => 'Revision in progress',
        'for_review' => 'Waiting for SUL review',
        default => ucwords(str_replace('_', ' ', $status)),
    };
}

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<style>
:root {
    --qa-revision-primary: #1f6392;
    --qa-revision-text: #1f2f3f;
    --qa-revision-muted: #6b7b8c;
    --qa-revision-border: #dce8f1;
    --qa-revision-soft: #f5fbff;
}

.qa-revisions-page {
    color: var(--qa-revision-text);
}

.qa-revision-header,
.qa-revision-toolbar,
.qa-revision-card,
.qa-revision-empty {
    background: #fff;
    border: 1px solid var(--qa-revision-border);
    border-radius: 14px;
    box-shadow: 0 10px 25px rgba(31, 99, 146, 0.08);
}

.qa-revision-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 1rem 1.1rem;
    margin-bottom: 1rem;
}

.qa-revision-title {
    display: flex;
    align-items: center;
    gap: 0.85rem;
}

.qa-revision-title-icon {
    display: inline-grid;
    place-items: center;
    width: 42px;
    height: 42px;
    border-radius: 12px;
    background: linear-gradient(135deg, #e8f7ff, #dff4ee);
    color: var(--qa-revision-primary);
}

.qa-revision-title h2 {
    margin: 0;
    font-size: 1.2rem;
    font-weight: 700;
}

.qa-revision-title p {
    margin: 0.15rem 0 0;
    color: var(--qa-revision-muted);
    font-size: 0.78rem;
}

.qa-revision-count {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.45rem 0.7rem;
    border-radius: 999px;
    background: #fff5df;
    color: #8a5a00;
    font-size: 0.75rem;
    font-weight: 700;
}

.qa-revision-toolbar {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.85rem;
    margin-bottom: 1rem;
}

.qa-revision-toolbar .form-select {
    max-width: 420px;
    border-color: var(--qa-revision-border);
    font-size: 0.82rem;
}

.qa-revision-card {
    padding: 1rem;
}

.qa-revision-preview {
    background: #fff;
    border: 1px solid var(--qa-revision-border);
    border-radius: 14px;
    box-shadow: 0 10px 25px rgba(31, 99, 146, 0.08);
    overflow: hidden;
}

.qa-revision-tabs {
    border: 0;
    border-bottom: 1px solid var(--qa-revision-border);
    display: flex;
    flex-wrap: nowrap;
    gap: 0.15rem;
    overflow-x: auto;
    padding: 0 0.85rem;
}

.qa-revision-tabs .nav-item {
    display: flex;
    flex: 0 0 auto;
}

.qa-revision-tabs .nav-link {
    align-items: center;
    background: transparent;
    border: 0;
    border-radius: 0;
    color: var(--qa-revision-muted);
    display: inline-flex;
    font-size: 0.78rem;
    font-weight: 700;
    gap: 0.42rem;
    min-height: 48px;
    padding: 0.7rem 0.45rem;
    position: relative;
    white-space: nowrap;
}

.qa-revision-tabs .nav-link:hover,
.qa-revision-tabs .nav-link.active {
    color: var(--qa-revision-primary);
}

.qa-revision-tabs .nav-link.active::after {
    background: var(--qa-revision-primary);
    border-radius: 999px;
    bottom: -1px;
    content: "";
    height: 3px;
    left: 0.45rem;
    position: absolute;
    right: 0.45rem;
}

.qa-revision-tabs .badge-count {
    background: #e8f3fa;
    border-radius: 999px;
    color: #426780;
    font-size: 0.68rem;
    font-weight: 800;
    line-height: 1;
    padding: 0.25rem 0.48rem;
}

.qa-revision-panel {
    padding: 1rem;
}

.qa-revision-panel-header {
    align-items: center;
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 0.85rem;
}

.qa-revision-panel-header h5 {
    align-items: center;
    color: var(--qa-revision-text);
    display: flex;
    font-size: 0.92rem;
    font-weight: 700;
    gap: 0.45rem;
    margin: 0;
}

.qa-revision-panel-list {
    display: grid;
    gap: 0.85rem;
}

.qa-revision-card-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
}

.qa-revision-card h5 {
    margin: 0;
    font-size: 0.98rem;
    font-weight: 700;
}

.qa-revision-meta {
    margin-top: 0.25rem;
    color: var(--qa-revision-muted);
    font-size: 0.75rem;
}

.qa-revision-status {
    white-space: nowrap;
    border-radius: 999px;
    padding: 0.35rem 0.6rem;
    background: #eaf6ff;
    color: var(--qa-revision-primary);
    font-size: 0.72rem;
    font-weight: 700;
}

.qa-revision-notes {
    margin-top: 0.8rem;
    padding: 0.8rem;
    border-left: 3px solid #f5a400;
    background: var(--qa-revision-soft);
    color: #425466;
    font-size: 0.8rem;
    line-height: 1.45;
}

.qa-revision-details {
    display: flex;
    flex-wrap: wrap;
    gap: 0.45rem;
    margin-top: 0.85rem;
}

.qa-revision-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.36rem 0.55rem;
    border-radius: 999px;
    background: #f2f7fb;
    color: #506171;
    font-size: 0.72rem;
    font-weight: 600;
}

.qa-revision-empty {
    display: flex;
    align-items: center;
    gap: 0.85rem;
    padding: 1.2rem;
}

.qa-revision-empty-icon {
    display: inline-grid;
    place-items: center;
    width: 42px;
    height: 42px;
    border-radius: 12px;
    background: #ecfdf3;
    color: #16834b;
}

.qa-revision-empty strong {
    display: block;
    font-size: 0.95rem;
}

.qa-revision-empty p {
    margin: 0.15rem 0 0;
    color: var(--qa-revision-muted);
    font-size: 0.78rem;
}

@media (max-width: 768px) {
    .qa-revision-header,
    .qa-revision-toolbar,
    .qa-revision-card-head {
        align-items: stretch;
        flex-direction: column;
    }

    .qa-revision-toolbar .form-select {
        max-width: none;
    }

    .qa-revision-status {
        width: fit-content;
        white-space: normal;
    }
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-revision-header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-revision-toolbar,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-revision-preview,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-revision-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-revision-empty {
    background: #0b1b2b;
    border-color: #20384d;
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-revision-title h2,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-revision-panel-header h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-revision-card h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-revision-empty strong {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-revision-title p,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-revision-meta,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-revision-empty p {
    color: #9fb7cb;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-revision-notes,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-revision-chip {
    background: #10263a;
    color: #c9d9e7;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-revision-tabs {
    border-color: #20384d;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-revision-tabs .nav-link {
    color: #9fb7cb;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-revision-tabs .nav-link.active,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-revision-tabs .nav-link:hover {
    color: #7cc0ee;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .qa-revision-tabs .badge-count {
    background: #10263a;
    color: #c9d9e7;
}
</style>

<div class="container-fluid px-4 py-4 qa-revisions-page">
    <header class="qa-revision-header">
        <div class="qa-revision-title">
            <span class="qa-revision-title-icon" aria-hidden="true"><i class="fas fa-bell"></i></span>
            <div>
                <h2>QA Revisions</h2>
                <p>Evortix alerts for QA-routed revision work that needs architecture visibility.</p>
            </div>
        </div>
        <span class="qa-revision-count"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i><?php echo $revisionTaskCount; ?> active</span>
    </header>

    <form method="GET" class="qa-revision-toolbar" id="qaRevisionProjectForm">
        <label class="visually-hidden" for="qaRevisionProjectId">Project</label>
        <select class="form-select" id="qaRevisionProjectId" name="project_id">
            <option value="0">All projects with QA revisions</option>
            <?php if ($revisionProjects): while ($project = $revisionProjects->fetch_assoc()): ?>
                <option value="<?php echo (int) $project['id']; ?>" <?php echo (int) $project['id'] === $selectedProjectId ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars((string) $project['name'] . ' - ' . (string) $project['client_name']); ?>
                </option>
            <?php endwhile; endif; ?>
        </select>
    </form>

    <section class="qa-revision-preview">
        <ul class="nav qa-revision-tabs" id="qaRevisionTabs" role="tablist">
            <?php $tabIndex = 0; ?>
            <?php foreach ($revisionTabs as $tabKey => $tab): ?>
                <?php $isActiveTab = $tabIndex === 0; ?>
                <li class="nav-item" role="presentation">
                    <button
                        class="nav-link <?php echo $isActiveTab ? 'active' : ''; ?>"
                        id="qa-<?php echo htmlspecialchars($tabKey); ?>-tab"
                        data-bs-toggle="tab"
                        data-bs-target="#qa-<?php echo htmlspecialchars($tabKey); ?>-panel"
                        type="button"
                        role="tab"
                        aria-controls="qa-<?php echo htmlspecialchars($tabKey); ?>-panel"
                        aria-selected="<?php echo $isActiveTab ? 'true' : 'false'; ?>"
                    >
                        <i class="fas fa-<?php echo htmlspecialchars((string) $tab['icon']); ?>" aria-hidden="true"></i>
                        <?php echo htmlspecialchars((string) $tab['label']); ?>
                        <span class="badge-count"><?php echo count($tab['rows']); ?></span>
                    </button>
                </li>
                <?php $tabIndex++; ?>
            <?php endforeach; ?>
        </ul>

        <div class="tab-content" id="qaRevisionTabContent">
            <?php $tabIndex = 0; ?>
            <?php foreach ($revisionTabs as $tabKey => $tab): ?>
                <?php
                    $isActiveTab = $tabIndex === 0;
                    $tabRows = is_array($tab['rows'] ?? null) ? $tab['rows'] : [];
                ?>
                <div
                    class="tab-pane fade <?php echo $isActiveTab ? 'show active' : ''; ?>"
                    id="qa-<?php echo htmlspecialchars($tabKey); ?>-panel"
                    role="tabpanel"
                    aria-labelledby="qa-<?php echo htmlspecialchars($tabKey); ?>-tab"
                    tabindex="0"
                >
                    <div class="qa-revision-panel">
                        <div class="qa-revision-panel-header">
                            <h5>
                                <i class="fas fa-<?php echo htmlspecialchars((string) $tab['icon']); ?>" aria-hidden="true"></i>
                                <?php echo htmlspecialchars((string) $tab['title']); ?>
                            </h5>
                            <span class="qa-revision-count"><?php echo count($tabRows); ?> <?php echo count($tabRows) === 1 ? 'item' : 'items'; ?></span>
                        </div>

                        <?php if ($tabRows !== []): ?>
                            <div class="qa-revision-panel-list">
                                <?php foreach ($tabRows as $task): ?>
                                    <?php
                                        $deadline = trim((string) ($task['deadline'] ?? ''));
                                        $revisionNotes = trim((string) ($task['current_revision_notes'] ?? ''));
                                        if ($revisionNotes === '') {
                                            $revisionNotes = trim((string) ($task['latest_revision_notes'] ?? ''));
                                        }
                                        $currentDepartment = trim((string) ($task['current_revision_department'] ?? ''));
                                        if ($currentDepartment === '') {
                                            $currentDepartment = trim((string) ($task['latest_revision_department'] ?? ''));
                                        }
                                        $queuedCount = max(0, (int) ($task['queued_department_count'] ?? 0));
                                    ?>
                                    <article class="qa-revision-card">
                                        <div class="qa-revision-card-head">
                                            <div>
                                                <h5><?php echo htmlspecialchars((string) ($task['title'] ?? 'Task')); ?></h5>
                                                <div class="qa-revision-meta">
                                                    <?php echo htmlspecialchars((string) ($task['project_name'] ?? 'Project')); ?> &middot;
                                                    <?php echo htmlspecialchars((string) ($task['client_name'] ?? 'Client')); ?> &middot;
                                                    Developer: <?php echo htmlspecialchars((string) ($task['developer_name'] ?? 'Unassigned')); ?>
                                                </div>
                                            </div>
                                            <span class="qa-revision-status"><?php echo htmlspecialchars(architectQaRevisionStatusLabel((string) ($task['status'] ?? ''))); ?></span>
                                        </div>

                                        <div class="qa-revision-notes">
                                            <?php if ($revisionNotes !== ''): ?>
                                                <?php echo nl2br(htmlspecialchars($revisionNotes)); ?>
                                            <?php else: ?>
                                                QA marked this task for revision. Department-specific notes will appear when the revision lane is assigned.
                                            <?php endif; ?>
                                        </div>

                                        <div class="qa-revision-details">
                                            <span class="qa-revision-chip"><i class="fas fa-folder-tree" aria-hidden="true"></i>Project #<?php echo (int) ($task['project_id'] ?? 0); ?></span>
                                            <span class="qa-revision-chip"><i class="fas fa-layer-group" aria-hidden="true"></i><?php echo htmlspecialchars($currentDepartment !== '' ? $currentDepartment : 'Department pending'); ?></span>
                                            <span class="qa-revision-chip"><i class="fas fa-list-check" aria-hidden="true"></i><?php echo $queuedCount; ?> queued next</span>
                                            <span class="qa-revision-chip"><i class="fas fa-flag" aria-hidden="true"></i><?php echo htmlspecialchars(ucfirst((string) ($task['priority'] ?? 'normal'))); ?></span>
                                            <span class="qa-revision-chip"><i class="fas fa-calendar-day" aria-hidden="true"></i><?php echo htmlspecialchars($deadline !== '' ? date('M d, Y', strtotime($deadline)) : 'No deadline'); ?></span>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="qa-revision-empty">
                                <span class="qa-revision-empty-icon" aria-hidden="true"><i class="fas fa-check"></i></span>
                                <div>
                                    <strong><?php echo htmlspecialchars((string) $tab['empty']); ?></strong>
                                    <p>Evortix will place matching project and task alerts in this tab automatically.</p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php $tabIndex++; ?>
            <?php endforeach; ?>
        </div>
    </section>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const projectSelect = document.getElementById('qaRevisionProjectId');
    if (!projectSelect) {
        return;
    }

    projectSelect.addEventListener('change', function () {
        if (Number(projectSelect.value) <= 0) {
            window.location.assign('qa-revisions.php');
            return;
        }

        projectSelect.form.submit();
    });
});
</script>

<?php include '../../includes/footer.php'; ?>
