<?php
declare(strict_types=1);

require_once __DIR__ . '/workflow.php';
require_once __DIR__ . '/decision-insights.php';
require_once __DIR__ . '/methodology-roadmap.php';

function processHistoryActions(): array
{
    return [
        'create_project_requirements' => ['label' => 'Sales created project request', 'category' => 'Planning', 'tone' => 'planning'],
        'define_features' => ['label' => 'Architect defined features', 'category' => 'Planning', 'tone' => 'planning'],
        'tech_recommendation' => ['label' => 'Architect selected tech stack', 'category' => 'Planning', 'tone' => 'planning'],
        'developer_allocation' => ['label' => 'Architect allocated departments', 'category' => 'Planning', 'tone' => 'planning'],
        'apply_methodology_template' => ['label' => 'Methodology template applied', 'category' => 'Roadmap', 'tone' => 'roadmap'],
        'save_methodology_phase' => ['label' => 'Roadmap phase saved', 'category' => 'Roadmap', 'tone' => 'roadmap'],
        'save_methodology_task' => ['label' => 'Roadmap task planned', 'category' => 'Roadmap', 'tone' => 'roadmap'],
        'upload_diagram' => ['label' => 'Architecture document uploaded', 'category' => 'Planning', 'tone' => 'planning'],
        'submit_architecture' => ['label' => 'Architect submitted project package', 'category' => 'Handoff', 'tone' => 'handoff'],
        'submit_project_estimation' => ['label' => 'Expert submitted project cost estimate', 'category' => 'Estimation', 'tone' => 'estimation'],
        'submit_estimation' => ['label' => 'Expert submitted feature estimate', 'category' => 'Estimation', 'tone' => 'estimation'],
        'sul_approve_project_estimation' => ['label' => 'SUL approved project estimate', 'category' => 'Approval', 'tone' => 'approval'],
        'sul_reject_project_estimation' => ['label' => 'SUL returned project estimate', 'category' => 'Revision', 'tone' => 'revision'],
        'sales_approve_project_estimation' => ['label' => 'Sales approved project estimate', 'category' => 'Approval', 'tone' => 'approval'],
        'sales_reject_project_estimation' => ['label' => 'Sales returned project estimate', 'category' => 'Revision', 'tone' => 'revision'],
        'sales_approve_estimation' => ['label' => 'Sales approved feature estimate', 'category' => 'Approval', 'tone' => 'approval'],
        'sales_reject_estimation' => ['label' => 'Sales returned feature estimate', 'category' => 'Revision', 'tone' => 'revision'],
        'uiux_submit_design' => ['label' => 'UI/UX submitted design', 'category' => 'Task Movement', 'tone' => 'task'],
        'developer_update_task_status' => ['label' => 'Developer moved task status', 'category' => 'Task Movement', 'tone' => 'task'],
        'sul_approve_department_work' => ['label' => 'SUL approved department work', 'category' => 'Task Movement', 'tone' => 'approval'],
        'sul_request_pull_request_revision' => ['label' => 'SUL requested task revision', 'category' => 'Revision', 'tone' => 'revision'],
        'qa_pass_feature' => ['label' => 'QA passed feature', 'category' => 'QA', 'tone' => 'approval'],
        'qa_reject_feature' => ['label' => 'QA failed feature', 'category' => 'QA', 'tone' => 'revision'],
        'architect_merge_to_main' => ['label' => 'Architect gave final approval', 'category' => 'Final Approval', 'tone' => 'approval'],
        'create_repository' => ['label' => 'Repository created', 'category' => 'Version Control', 'tone' => 'version'],
        'create_branch' => ['label' => 'Branch created', 'category' => 'Version Control', 'tone' => 'version'],
        'create_commit' => ['label' => 'Commit recorded', 'category' => 'Version Control', 'tone' => 'version'],
        'merge_branches' => ['label' => 'Branch merge recorded', 'category' => 'Version Control', 'tone' => 'approval'],
        'create_report' => ['label' => 'Sales report created', 'category' => 'Release', 'tone' => 'release'],
        'create_receipt' => ['label' => 'Receipt created', 'category' => 'Release', 'tone' => 'release'],
        'auto_finalize_sales_report' => ['label' => 'Final sales report prepared', 'category' => 'Release', 'tone' => 'release'],
        'auto_finalize_receipt' => ['label' => 'Final receipt prepared', 'category' => 'Release', 'tone' => 'release'],
        'verify_payment_receipt' => ['label' => 'Payment receipt verified', 'category' => 'Release', 'tone' => 'approval'],
        'submit_payment_proof' => ['label' => 'Client submitted payment proof', 'category' => 'Release', 'tone' => 'release'],
        'reject_payment_proof' => ['label' => 'Payment proof rejected', 'category' => 'Release', 'tone' => 'revision'],
    ];
}

function processHistoryFormatDuration(?int $seconds): string
{
    if ($seconds === null) {
        return 'First step';
    }

    $seconds = max(0, $seconds);
    if ($seconds < 60) {
        return $seconds . 's';
    }

    $days = intdiv($seconds, 86400);
    $seconds %= 86400;
    $hours = intdiv($seconds, 3600);
    $seconds %= 3600;
    $minutes = intdiv($seconds, 60);

    $parts = [];
    if ($days > 0) {
        $parts[] = $days . 'd';
    }
    if ($hours > 0) {
        $parts[] = $hours . 'h';
    }
    if ($minutes > 0 && $days === 0) {
        $parts[] = $minutes . 'm';
    }

    return implode(' ', array_slice($parts, 0, 2)) ?: '0m';
}

function processHistoryTimestamp(?string $value): ?int
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $timestamp = strtotime($value);
    return $timestamp === false ? null : $timestamp;
}

function processHistoryDecodeJson(?string $value): array
{
    $decoded = json_decode((string) $value, true);
    return is_array($decoded) ? $decoded : [];
}

function processHistoryStatusLabel(?string $status): string
{
    $status = trim((string) $status);
    return $status !== '' ? workflowStatusLabel($status) : '';
}

function processHistoryEventItem(array $row): string
{
    $table = (string) ($row['table_name'] ?? '');
    if ($table === 'projects') {
        return trim((string) ($row['project_name'] ?? '')) ?: 'Project #' . (int) ($row['record_id'] ?? 0);
    }
    if ($table === 'tasks') {
        return trim((string) ($row['task_title'] ?? '')) ?: 'Task #' . (int) ($row['record_id'] ?? 0);
    }
    if ($table === 'methodology_tasks') {
        return trim((string) ($row['roadmap_task_title'] ?? '')) ?: 'Roadmap task #' . (int) ($row['record_id'] ?? 0);
    }
    if ($table === 'methodology_phases') {
        return trim((string) ($row['phase_name'] ?? '')) ?: 'Roadmap phase #' . (int) ($row['record_id'] ?? 0);
    }
    if ($table === 'expert_project_estimations') {
        return 'Project cost estimate';
    }
    if ($table === 'sales_reports') {
        return 'Sales report #' . (int) ($row['record_id'] ?? 0);
    }
    if ($table === 'receipts') {
        return 'Receipt #' . (int) ($row['record_id'] ?? 0);
    }
    if ($table === 'project_repositories') {
        return 'Project repository';
    }
    if ($table === 'repository_branches') {
        return trim((string) ($row['branch_name'] ?? '')) ?: 'Repository branch #' . (int) ($row['record_id'] ?? 0);
    }
    if ($table === 'repository_commits') {
        return trim((string) ($row['commit_title'] ?? '')) ?: 'Repository commit #' . (int) ($row['record_id'] ?? 0);
    }
    if ($table === 'repository_merges') {
        return trim((string) ($row['merge_summary'] ?? '')) ?: 'Repository merge #' . (int) ($row['record_id'] ?? 0);
    }

    return 'Project process';
}

function processHistoryLoadEvents(mysqli|PgsqlMysqliCompatConnection $conn, array $projectIds, int $selectedProjectId = 0): array
{
    $projectIds = array_values(array_unique(array_filter(array_map('intval', $projectIds))));
    if ($projectIds === []) {
        return [];
    }
    if ($selectedProjectId > 0 && in_array($selectedProjectId, $projectIds, true)) {
        $projectIds = [$selectedProjectId];
    }

    $actions = array_keys(processHistoryActions());
    $quotedActions = array_map(static fn(string $action): string => "'" . $conn->real_escape_string($action) . "'", $actions);
    $projectIdList = implode(',', $projectIds);
    $projectExpr = "
        COALESCE(
            CASE WHEN al.table_name = 'projects' THEN al.record_id END,
            t.project_id,
            mp.project_id,
            mt.project_id,
            epe.project_id,
            sr.project_id,
            receipt_sr.project_id,
            pr.project_id,
            branch_repo.project_id,
            commit_repo.project_id,
            merge_repo.project_id
        )
    ";

    $result = $conn->query("
        SELECT
            al.id,
            al.user_id,
            al.action,
            al.table_name,
            al.record_id,
            al.old_data,
            al.new_data,
            al.description,
            al.status,
            al.created_at,
            u.full_name,
            u.role,
            {$projectExpr} AS project_id,
            p.name AS project_name,
            t.title AS task_title,
            mp.name AS phase_name,
            mt.title AS roadmap_task_title,
            rb.name AS branch_name,
            rc.commit_title,
            rm.summary AS merge_summary
        FROM audit_logs al
        LEFT JOIN users u ON u.id = al.user_id
        LEFT JOIN projects p ON al.table_name = 'projects' AND p.id = al.record_id
        LEFT JOIN tasks t ON al.table_name = 'tasks' AND t.id = al.record_id
        LEFT JOIN methodology_phases mp ON al.table_name = 'methodology_phases' AND mp.id = al.record_id
        LEFT JOIN methodology_tasks mt ON al.table_name = 'methodology_tasks' AND mt.id = al.record_id
        LEFT JOIN expert_project_estimations epe ON al.table_name = 'expert_project_estimations' AND epe.id = al.record_id
        LEFT JOIN sales_reports sr ON al.table_name = 'sales_reports' AND sr.id = al.record_id
        LEFT JOIN receipts receipt ON al.table_name = 'receipts' AND receipt.id = al.record_id
        LEFT JOIN sales_reports receipt_sr ON receipt_sr.id = receipt.report_id
        LEFT JOIN project_repositories pr ON al.table_name = 'project_repositories' AND pr.id = al.record_id
        LEFT JOIN repository_branches rb ON al.table_name = 'repository_branches' AND rb.id = al.record_id
        LEFT JOIN project_repositories branch_repo ON branch_repo.id = rb.repository_id
        LEFT JOIN repository_commits rc ON al.table_name = 'repository_commits' AND rc.id = al.record_id
        LEFT JOIN project_repositories commit_repo ON commit_repo.id = rc.repository_id
        LEFT JOIN repository_merges rm ON al.table_name = 'repository_merges' AND rm.id = al.record_id
        LEFT JOIN project_repositories merge_repo ON merge_repo.id = rm.repository_id
        WHERE al.action IN (" . implode(',', $quotedActions) . ")
          AND {$projectExpr} IN ({$projectIdList})
        ORDER BY al.created_at DESC, al.id DESC
        LIMIT 2000
    ");

    if (!$result) {
        return [];
    }

    $catalog = processHistoryActions();
    $events = [];
    $projectEvents = [];

    while ($row = $result->fetch_assoc()) {
        $projectId = (int) ($row['project_id'] ?? 0);
        if ($projectId <= 0) {
            continue;
        }

        $createdAt = (string) ($row['created_at'] ?? '');
        $timestamp = strtotime($createdAt) ?: 0;
        $oldData = processHistoryDecodeJson($row['old_data'] ?? null);
        $newData = processHistoryDecodeJson($row['new_data'] ?? null);
        $action = (string) ($row['action'] ?? '');
        $meta = $catalog[$action] ?? ['label' => ucwords(str_replace('_', ' ', $action)), 'category' => 'Process', 'tone' => 'task'];
        $fromStatus = processHistoryStatusLabel($oldData['status'] ?? null);
        $toStatus = processHistoryStatusLabel($newData['status'] ?? null);

        $projectEvents[$projectId][] = [
            'id' => (int) ($row['id'] ?? 0),
            'project_id' => $projectId,
            'action' => $action,
            'label' => $meta['label'],
            'category' => $meta['category'],
            'tone' => $meta['tone'],
            'item' => processHistoryEventItem($row),
            'actor' => trim((string) ($row['full_name'] ?? '')) ?: 'System',
            'role' => (string) ($row['role'] ?? ''),
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'description' => trim((string) ($row['description'] ?? '')),
            'created_at' => $createdAt,
            'timestamp' => $timestamp,
            'gap_seconds' => null,
            'since_start_seconds' => 0,
        ];
    }

    foreach ($projectEvents as $projectId => $rows) {
        usort($rows, static fn(array $a, array $b): int => [$a['timestamp'], $a['id']] <=> [$b['timestamp'], $b['id']]);
        $previousTimestamp = null;
        $firstTimestamp = isset($rows[0]) ? (int) $rows[0]['timestamp'] : null;
        foreach ($rows as $row) {
            $row['gap_seconds'] = $previousTimestamp === null ? null : max(0, (int) $row['timestamp'] - $previousTimestamp);
            $row['since_start_seconds'] = $firstTimestamp === null ? 0 : max(0, (int) $row['timestamp'] - $firstTimestamp);
            $previousTimestamp = (int) $row['timestamp'];
            $events[] = $row;
        }
    }

    return $events;
}

function processHistoryEventsFromTaskDurations(array $tasks): array
{
    $eventsByProject = [];
    $eventId = 1000000;

    foreach ($tasks as $task) {
        $projectId = (int) ($task['project_id'] ?? 0);
        $taskId = (int) ($task['id'] ?? 0);
        if ($projectId <= 0 || $taskId <= 0) {
            continue;
        }

        $title = (string) ($task['title'] ?? 'Task');
        $actor = (string) ($task['assigned_to'] ?? 'Assigned developer');
        $statusLabel = (string) ($task['status_label'] ?? processHistoryStatusLabel((string) ($task['status'] ?? '')));
        $planningStart = processHistoryTimestamp($task['planning_start_at'] ?? null);
        $developerStart = processHistoryTimestamp($task['developer_started_at'] ?? null);
        $completedAt = processHistoryTimestamp($task['completed_at'] ?? null);
        $latestAt = (int) ($task['latest_timestamp'] ?? 0);

        $pushEvent = static function (
            int $timestamp,
            string $label,
            string $category,
            string $tone,
            string $description,
            string $fromStatus = '',
            string $toStatus = ''
        ) use (&$eventsByProject, &$eventId, $projectId, $taskId, $title, $actor): void {
            if ($timestamp <= 0) {
                return;
            }

            $eventsByProject[$projectId][] = [
                'id' => $eventId++,
                'project_id' => $projectId,
                'action' => 'derived_task_movement',
                'label' => $label,
                'category' => $category,
                'tone' => $tone,
                'item' => $title,
                'actor' => $actor,
                'role' => 'developer',
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'description' => $description,
                'created_at' => date('Y-m-d H:i:s', $timestamp),
                'timestamp' => $timestamp,
                'gap_seconds' => null,
                'since_start_seconds' => 0,
                'source_task_id' => $taskId,
            ];
        };

        $pushEvent(
            $planningStart ?? $latestAt,
            'Feature entered workflow',
            'Planning',
            'planning',
            'Derived from the feature task or roadmap schedule because no audit movement entry was found.',
            '',
            'Planned'
        );

        if ($developerStart !== null && $developerStart !== $planningStart) {
            $pushEvent(
                $developerStart,
                'Developer work started',
                'Task Movement',
                'task',
                'Derived from the assigned developer start date or roadmap schedule.',
                'Planned',
                $statusLabel !== '' ? $statusLabel : 'In Progress'
            );
        }

        if ($completedAt !== null) {
            $pushEvent(
                $completedAt,
                'Developer work completed',
                'Task Movement',
                'approval',
                'Derived from the task completion or approved department work timestamp.',
                $statusLabel !== '' ? $statusLabel : 'In Progress',
                'Completed'
            );
        } elseif ($latestAt > 0 && $latestAt !== $planningStart && $latestAt !== $developerStart) {
            $pushEvent(
                $latestAt,
                'Task status updated',
                'Task Movement',
                'task',
                'Derived from the latest known task or roadmap update timestamp.',
                '',
                $statusLabel
            );
        }
    }

    $events = [];
    foreach ($eventsByProject as $projectId => $rows) {
        usort($rows, static fn(array $a, array $b): int => [$a['timestamp'], $a['id']] <=> [$b['timestamp'], $b['id']]);
        $previousTimestamp = null;
        $firstTimestamp = isset($rows[0]) ? (int) $rows[0]['timestamp'] : null;
        foreach ($rows as $row) {
            $row['gap_seconds'] = $previousTimestamp === null ? null : max(0, (int) $row['timestamp'] - $previousTimestamp);
            $row['since_start_seconds'] = $firstTimestamp === null ? 0 : max(0, (int) $row['timestamp'] - $firstTimestamp);
            $previousTimestamp = (int) $row['timestamp'];
            $events[] = $row;
        }
    }

    return $events;
}

function processHistoryLoadProjectLifecycleEvents(
    mysqli|PgsqlMysqliCompatConnection $conn,
    array $projectIds,
    int $selectedProjectId = 0,
    array $existingEvents = []
): array {
    $projectIds = array_values(array_unique(array_filter(array_map('intval', $projectIds))));
    if ($projectIds === []) {
        return [];
    }
    if ($selectedProjectId > 0 && in_array($selectedProjectId, $projectIds, true)) {
        $projectIds = [$selectedProjectId];
    }

    $existingCreationProjects = [];
    $existingTimelineStartProjects = [];
    foreach ($existingEvents as $event) {
        $projectId = (int) ($event['project_id'] ?? 0);
        $action = (string) ($event['action'] ?? '');
        if ($projectId <= 0) {
            continue;
        }
        if (in_array($action, ['create_project_requirements', 'project_created'], true)) {
            $existingCreationProjects[$projectId] = true;
        }
        if ($action === 'project_timeline_started') {
            $existingTimelineStartProjects[$projectId] = true;
        }
    }

    $projectIdList = implode(',', $projectIds);
    $hasTimelineStartTime = dbColumnExists($conn, 'projects', 'timeline_start_time');
    $timelineStartTimeSelect = $hasTimelineStartTime ? 'p.timeline_start_time' : "NULL AS timeline_start_time";
    $result = $conn->query("
        SELECT
            p.id,
            p.name,
            p.status,
            p.timeline_start,
            {$timelineStartTimeSelect},
            p.created_at,
            u.full_name AS creator_name,
            u.role AS creator_role
        FROM projects p
        LEFT JOIN users u ON u.id = p.created_by
        WHERE p.id IN ({$projectIdList})
    ");

    if (!$result) {
        return [];
    }

    $eventsByProject = [];
    $eventId = 2000000;
    while ($project = $result->fetch_assoc()) {
        $projectId = (int) ($project['id'] ?? 0);
        if ($projectId <= 0) {
            continue;
        }

        $projectName = trim((string) ($project['name'] ?? '')) ?: 'Project #' . $projectId;
        $actor = trim((string) ($project['creator_name'] ?? '')) ?: 'System';
        $role = (string) ($project['creator_role'] ?? '');
        $createdAt = processHistoryTimestamp($project['created_at'] ?? null);
        if ($createdAt !== null && !isset($existingCreationProjects[$projectId])) {
            $eventsByProject[$projectId][] = [
                'id' => $eventId++,
                'project_id' => $projectId,
                'action' => 'project_created',
                'label' => 'Project created',
                'category' => 'Planning',
                'tone' => 'planning',
                'item' => $projectName,
                'actor' => $actor,
                'role' => $role,
                'from_status' => '',
                'to_status' => processHistoryStatusLabel((string) ($project['status'] ?? 'pending')),
                'description' => 'Project record created and added to the planning workflow.',
                'created_at' => date('Y-m-d H:i:s', $createdAt),
                'timestamp' => $createdAt,
                'gap_seconds' => null,
                'since_start_seconds' => 0,
            ];
        }

        $timelineStartDate = trim((string) ($project['timeline_start'] ?? ''));
        if ($timelineStartDate !== '' && !isset($existingTimelineStartProjects[$projectId])) {
            $timelineStartTime = trim((string) ($project['timeline_start_time'] ?? ''));
            $timelineStartValue = $timelineStartDate . ' ' . ($timelineStartTime !== '' ? $timelineStartTime : '00:00:00');
            $timelineStartAt = processHistoryTimestamp($timelineStartValue);
            if ($timelineStartAt !== null && ($createdAt === null || abs($timelineStartAt - $createdAt) > 60)) {
                $eventsByProject[$projectId][] = [
                    'id' => $eventId++,
                    'project_id' => $projectId,
                    'action' => 'project_timeline_started',
                    'label' => 'Project timeline started',
                    'category' => 'Planning',
                    'tone' => 'roadmap',
                    'item' => $projectName,
                    'actor' => $actor,
                    'role' => $role,
                    'from_status' => '',
                    'to_status' => processHistoryStatusLabel((string) ($project['status'] ?? 'pending')),
                    'description' => 'Derived from the project timeline start date set during intake.',
                    'created_at' => date('Y-m-d H:i:s', $timelineStartAt),
                    'timestamp' => $timelineStartAt,
                    'gap_seconds' => null,
                    'since_start_seconds' => 0,
                ];
            }
        }
    }

    $events = [];
    foreach ($eventsByProject as $projectId => $rows) {
        usort($rows, static fn(array $a, array $b): int => [$a['timestamp'], $a['id']] <=> [$b['timestamp'], $b['id']]);
        foreach ($rows as $row) {
            $events[] = $row;
        }
    }

    return $events;
}

function processHistoryRecalculateEventGaps(array $events): array
{
    $eventsByProject = [];
    foreach ($events as $event) {
        $projectId = (int) ($event['project_id'] ?? 0);
        if ($projectId <= 0) {
            continue;
        }
        $eventsByProject[$projectId][] = $event;
    }

    $recalculated = [];
    foreach ($eventsByProject as $projectId => $rows) {
        usort($rows, static fn(array $a, array $b): int => [(int) ($a['timestamp'] ?? 0), (int) ($a['id'] ?? 0)] <=> [(int) ($b['timestamp'] ?? 0), (int) ($b['id'] ?? 0)]);
        $previousTimestamp = null;
        $firstTimestamp = isset($rows[0]) ? (int) ($rows[0]['timestamp'] ?? 0) : null;
        foreach ($rows as $row) {
            $timestamp = (int) ($row['timestamp'] ?? 0);
            $row['gap_seconds'] = $previousTimestamp === null ? null : max(0, $timestamp - $previousTimestamp);
            $row['since_start_seconds'] = $firstTimestamp === null ? 0 : max(0, $timestamp - $firstTimestamp);
            $previousTimestamp = $timestamp;
            $recalculated[] = $row;
        }
    }

    return $recalculated;
}

function processHistoryLoadTaskDurations(mysqli|PgsqlMysqliCompatConnection $conn, array $projectIds, int $selectedProjectId = 0): array
{
    $projectIds = array_values(array_unique(array_filter(array_map('intval', $projectIds))));
    if ($projectIds === []) {
        return [];
    }
    if ($selectedProjectId > 0 && in_array($selectedProjectId, $projectIds, true)) {
        $projectIds = [$selectedProjectId];
    }

    $actions = array_keys(processHistoryActions());
    $quotedActions = array_map(static fn(string $action): string => "'" . $conn->real_escape_string($action) . "'", $actions);
    $actionList = implode(',', $quotedActions);
    $projectIdList = implode(',', $projectIds);
    $now = time();

    $result = $conn->query("
        SELECT
            developer_tasks.developer_id,
            t.id,
            t.project_id,
            t.title,
            t.status,
            t.assigned_to,
            t.created_at,
            t.started_at,
            t.deadline,
            t.completed_at,
            u.full_name AS developer_name,
            (
                SELECT MIN(al.created_at)
                FROM audit_logs al
                WHERE al.table_name = 'tasks'
                  AND al.record_id = t.id
                  AND al.action IN ({$actionList})
            ) AS first_movement_at,
            (
                SELECT MAX(al.created_at)
                FROM audit_logs al
                WHERE al.table_name = 'tasks'
                  AND al.record_id = t.id
                  AND al.action IN ({$actionList})
            ) AS last_movement_at,
            (
                SELECT MIN(al.created_at)
                FROM audit_logs al
                WHERE al.table_name = 'tasks'
                  AND al.record_id = t.id
                  AND al.user_id = developer_tasks.developer_id
                  AND al.action IN ({$actionList})
            ) AS first_developer_movement_at,
            (
                SELECT MAX(al.created_at)
                FROM audit_logs al
                WHERE al.table_name = 'tasks'
                  AND al.record_id = t.id
                  AND al.user_id = developer_tasks.developer_id
                  AND al.action IN ({$actionList})
            ) AS last_developer_movement_at,
            (
                SELECT MIN(mt.start_date)
                FROM methodology_tasks mt
                WHERE mt.task_id = t.id
                  AND mt.assigned_developer_id = developer_tasks.developer_id
            ) AS roadmap_start_at,
            (
                SELECT MAX(mt.updated_at)
                FROM methodology_tasks mt
                WHERE mt.task_id = t.id
                  AND mt.assigned_developer_id = developer_tasks.developer_id
                  AND mt.status = 'completed'
            ) AS roadmap_completed_at,
            (
                SELECT MAX(COALESCE(mtd.approved_at, mtd.updated_at))
                FROM methodology_task_departments mtd
                LEFT JOIN methodology_tasks mt ON mt.id = mtd.methodology_task_id
                WHERE mtd.assigned_developer_id = developer_tasks.developer_id
                  AND (mtd.workflow_task_id = t.id OR mt.task_id = t.id)
                  AND mtd.status = 'completed'
            ) AS department_completed_at
        FROM (
            SELECT DISTINCT task_id, developer_id
            FROM (
                SELECT id AS task_id, assigned_to AS developer_id
                FROM tasks
                WHERE project_id IN ({$projectIdList})
                  AND assigned_to IS NOT NULL
                  AND assigned_to > 0

                UNION

                SELECT task_id, assigned_developer_id AS developer_id
                FROM methodology_tasks
                WHERE project_id IN ({$projectIdList})
                  AND task_id IS NOT NULL
                  AND task_id > 0
                  AND assigned_developer_id IS NOT NULL
                  AND assigned_developer_id > 0

                UNION

                SELECT COALESCE(mtd.workflow_task_id, mt.task_id) AS task_id, mtd.assigned_developer_id AS developer_id
                FROM methodology_task_departments mtd
                LEFT JOIN methodology_tasks mt ON mt.id = mtd.methodology_task_id
                WHERE mtd.project_id IN ({$projectIdList})
                  AND COALESCE(mtd.workflow_task_id, mt.task_id) IS NOT NULL
                  AND COALESCE(mtd.workflow_task_id, mt.task_id) > 0
                  AND mtd.assigned_developer_id IS NOT NULL
                  AND mtd.assigned_developer_id > 0
            ) assigned_developers
        ) developer_tasks
        JOIN tasks t ON t.id = developer_tasks.task_id
        LEFT JOIN users u ON u.id = developer_tasks.developer_id
        WHERE t.project_id IN ({$projectIdList})
        ORDER BY COALESCE(t.completed_at, t.updated_at, t.created_at) DESC, t.id DESC, developer_tasks.developer_id ASC
    ");

    if (!$result) {
        return [];
    }

    $rows = [];
    while ($task = $result->fetch_assoc()) {
        $status = strtolower((string) ($task['status'] ?? 'todo'));
        $createdAt = processHistoryTimestamp($task['created_at'] ?? null);
        $startedAt = processHistoryTimestamp($task['started_at'] ?? null);
        $completedAt = processHistoryTimestamp($task['completed_at'] ?? null);
        $deadlineAt = processHistoryTimestamp($task['deadline'] ?? null);
        $firstMovementAt = processHistoryTimestamp($task['first_movement_at'] ?? null);
        $lastMovementAt = processHistoryTimestamp($task['last_movement_at'] ?? null);
        $firstDeveloperMovementAt = processHistoryTimestamp($task['first_developer_movement_at'] ?? null);
        $lastDeveloperMovementAt = processHistoryTimestamp($task['last_developer_movement_at'] ?? null);
        $roadmapStartAt = processHistoryTimestamp($task['roadmap_start_at'] ?? null);
        $roadmapCompletedAt = processHistoryTimestamp($task['roadmap_completed_at'] ?? null);
        $departmentCompletedAt = processHistoryTimestamp($task['department_completed_at'] ?? null);
        $developerId = (int) ($task['developer_id'] ?? 0);
        $directTaskDeveloper = $developerId > 0 && $developerId === (int) ($task['assigned_to'] ?? 0);
        $completionCandidates = array_filter([
            $directTaskDeveloper && $status === 'completed' ? $completedAt : null,
            $roadmapCompletedAt,
            $departmentCompletedAt,
            $status === 'completed' ? $lastDeveloperMovementAt : null,
            $status === 'completed' ? $lastMovementAt : null,
        ], static fn($value): bool => $value !== null);
        $developerEndAt = $completionCandidates ? max($completionCandidates) : null;
        $planningStartAt = $firstMovementAt ?? $createdAt ?? $roadmapStartAt;
        $developerStartAt = $firstDeveloperMovementAt ?? ($directTaskDeveloper ? $startedAt : null) ?? $roadmapStartAt ?? $planningStartAt;
        $isCompleted = $developerEndAt !== null;
        $currentOrEndAt = $developerEndAt ?? $now;
        $deadlineEndAt = $developerEndAt ?? $now;
        $lateSeconds = ($deadlineAt !== null && $deadlineEndAt !== null && $deadlineEndAt > $deadlineAt)
            ? $deadlineEndAt - $deadlineAt
            : 0;
        $latestTimestamp = max(array_filter([
            $developerEndAt,
            $lastDeveloperMovementAt,
            $lastMovementAt,
            $startedAt,
            $createdAt,
        ], static fn($value): bool => $value !== null) ?: [0]);

        $rows[] = [
            'id' => (int) ($task['id'] ?? 0),
            'developer_id' => $developerId,
            'project_id' => (int) ($task['project_id'] ?? 0),
            'title' => (string) ($task['title'] ?? 'Task'),
            'status' => $status,
            'status_label' => workflowStatusLabel($status),
            'assigned_to' => trim((string) ($task['developer_name'] ?? '')) ?: 'Developer #' . $developerId,
            'created_at' => $task['created_at'] ?? '',
            'started_at' => $task['started_at'] ?? '',
            'deadline' => $task['deadline'] ?? '',
            'completed_at' => $developerEndAt !== null ? date('Y-m-d H:i:s', $developerEndAt) : '',
            'first_movement_at' => $task['first_movement_at'] ?? '',
            'last_movement_at' => $task['last_movement_at'] ?? '',
            'developer_started_at' => $developerStartAt !== null ? date('Y-m-d H:i:s', $developerStartAt) : '',
            'planning_start_at' => $planningStartAt !== null ? date('Y-m-d H:i:s', $planningStartAt) : '',
            'workflow_start_at' => $planningStartAt,
            'workflow_end_at' => $developerEndAt,
            'workflow_seconds' => $planningStartAt !== null ? max(0, $currentOrEndAt - $planningStartAt) : null,
            'work_seconds' => $developerStartAt !== null ? max(0, $currentOrEndAt - $developerStartAt) : null,
            'late_seconds' => $lateSeconds,
            'is_completed' => $isCompleted,
            'latest_timestamp' => $latestTimestamp,
        ];
    }

    return $rows;
}

function processHistorySortTasks(array $tasks, array $projectNames, string $sort): array
{
    usort($tasks, static function (array $a, array $b) use ($projectNames, $sort): int {
        if ($sort === 'longest_task_duration') {
            return [(int) ($b['workflow_seconds'] ?? -1), $b['id']] <=> [(int) ($a['workflow_seconds'] ?? -1), $a['id']];
        }
        if ($sort === 'recent' || $sort === 'longest_gap') {
            return [(int) ($b['latest_timestamp'] ?? 0), $b['id'], $b['developer_id']] <=> [(int) ($a['latest_timestamp'] ?? 0), $a['id'], $a['developer_id']];
        }

        $projectCompare = strcasecmp($projectNames[$a['project_id']] ?? '', $projectNames[$b['project_id']] ?? '');
        if ($sort === 'project_desc') {
            $projectCompare *= -1;
        }
        if ($projectCompare !== 0) {
            return $projectCompare;
        }

        return [(int) ($b['latest_timestamp'] ?? 0), $b['id'], $b['developer_id']] <=> [(int) ($a['latest_timestamp'] ?? 0), $a['id'], $a['developer_id']];
    });

    return $tasks;
}

function processHistoryTaskSummary(array $tasks): array
{
    $completed = [];
    $slowest = null;
    foreach ($tasks as $task) {
        if (!empty($task['is_completed']) && $task['workflow_seconds'] !== null) {
            $completed[] = (int) $task['workflow_seconds'];
        }
        if ($task['workflow_seconds'] !== null && ($slowest === null || (int) $task['workflow_seconds'] > (int) $slowest['workflow_seconds'])) {
            $slowest = $task;
        }
    }

    $average = $completed ? (int) round(array_sum($completed) / count($completed)) : null;

    return [
        'task_count' => count($tasks),
        'completed_count' => count($completed),
        'average_completed_seconds' => $average,
        'slowest_task' => $slowest,
    ];
}

function processHistorySortEvents(array $events, array $projectNames, string $sort): array
{
    usort($events, static function (array $a, array $b) use ($projectNames, $sort): int {
        if ($sort === 'recent') {
            return [$b['timestamp'], $b['id']] <=> [$a['timestamp'], $a['id']];
        }
        if ($sort === 'longest_gap') {
            return [(int) ($b['gap_seconds'] ?? -1), $b['timestamp']] <=> [(int) ($a['gap_seconds'] ?? -1), $a['timestamp']];
        }

        $projectCompare = strcasecmp($projectNames[$a['project_id']] ?? '', $projectNames[$b['project_id']] ?? '');
        if ($sort === 'project_desc') {
            $projectCompare *= -1;
        }
        if ($projectCompare !== 0) {
            return $projectCompare;
        }

        return [$a['timestamp'], $a['id']] <=> [$b['timestamp'], $b['id']];
    });

    return $events;
}

function processHistorySummary(array $events): array
{
    $projectIds = [];
    $slowest = null;
    foreach ($events as $event) {
        $projectIds[(int) $event['project_id']] = true;
        if ($event['gap_seconds'] !== null && ($slowest === null || (int) $event['gap_seconds'] > (int) $slowest['gap_seconds'])) {
            $slowest = $event;
        }
    }

    return [
        'event_count' => count($events),
        'project_count' => count($projectIds),
        'slowest_event' => $slowest,
    ];
}
?>
