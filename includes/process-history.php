<?php
declare(strict_types=1);

require_once __DIR__ . '/workflow.php';
require_once __DIR__ . '/decision-insights.php';

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
            t.title AS task_title,
            mp.name AS phase_name,
            mt.title AS roadmap_task_title,
            rb.name AS branch_name,
            rc.commit_title,
            rm.summary AS merge_summary
        FROM audit_logs al
        LEFT JOIN users u ON u.id = al.user_id
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
        ORDER BY {$projectExpr} ASC, al.created_at ASC, al.id ASC
        LIMIT 2000
    ");

    if (!$result) {
        return [];
    }

    $catalog = processHistoryActions();
    $events = [];
    $previousByProject = [];
    $firstByProject = [];

    while ($row = $result->fetch_assoc()) {
        $projectId = (int) ($row['project_id'] ?? 0);
        if ($projectId <= 0) {
            continue;
        }

        $createdAt = (string) ($row['created_at'] ?? '');
        $timestamp = strtotime($createdAt) ?: 0;
        $oldData = processHistoryDecodeJson($row['old_data'] ?? null);
        $newData = processHistoryDecodeJson($row['new_data'] ?? null);
        $previousTimestamp = $previousByProject[$projectId] ?? null;
        $firstTimestamp = $firstByProject[$projectId] ?? $timestamp;
        $previousByProject[$projectId] = $timestamp;
        $firstByProject[$projectId] = $firstTimestamp;

        $action = (string) ($row['action'] ?? '');
        $meta = $catalog[$action] ?? ['label' => ucwords(str_replace('_', ' ', $action)), 'category' => 'Process', 'tone' => 'task'];
        $fromStatus = processHistoryStatusLabel($oldData['status'] ?? null);
        $toStatus = processHistoryStatusLabel($newData['status'] ?? null);

        $events[] = [
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
            'gap_seconds' => $previousTimestamp === null ? null : max(0, $timestamp - $previousTimestamp),
            'since_start_seconds' => max(0, $timestamp - $firstTimestamp),
        ];
    }

    return $events;
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
