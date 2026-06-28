<?php
declare(strict_types=1);

require_once __DIR__ . '/project-progress.php';

function resolveFolderProjectId(mysqli|PgsqlMysqliCompatConnection $conn, array $folder): int
{
    $projectId = (int) ($folder['project_id'] ?? 0);
    if ($projectId > 0) {
        return $projectId;
    }

    $currentId = (int) ($folder['parent_id'] ?? 0);
    $stmt = $conn->prepare('SELECT parent_id, project_id FROM folders WHERE id = ? LIMIT 1');
    if ($stmt === false) {
        error_log('resolveFolderProjectId prepare failed: ' . ($conn->error ?? 'unknown error'));
        return 0;
    }

    while ($currentId > 0) {
        $stmt->bind_param('i', $currentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) {
            break;
        }

        $projectId = (int) ($row['project_id'] ?? 0);
        if ($projectId > 0) {
            $stmt->close();
            return $projectId;
        }

        $currentId = (int) ($row['parent_id'] ?? 0);
    }

    $stmt->close();

    return 0;
}

function projectDateString(?string $value): ?string
{
    if (!$value) {
        return null;
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('Y-m-d', $timestamp) : null;
}

function buildApprovedTimeline(mysqli|PgsqlMysqliCompatConnection $conn, array $project): array
{
    $projectId = (int) $project['id'];
    $timelineRows = [];

    if (!empty($project['project_phases'])) {
        $phases = json_decode((string) $project['project_phases'], true);
        if (is_array($phases)) {
            foreach ($phases as $index => $phase) {
                $start = projectDateString($phase['start_date'] ?? null);
                $end = projectDateString($phase['end_date'] ?? null);
                if (!$start || !$end) {
                    continue;
                }

                $timelineRows[] = [
                    'id' => 'phase-' . $projectId . '-' . $index,
                    'name' => $phase['name'] ?? 'Project Phase',
                    'start' => $start,
                    'end' => $end,
                    'type' => 'phase',
                    'progress' => 0,
                ];
            }
        }
    }

    $hasSalesApproval = dbColumnExists($conn, 'tasks', 'sales_approved');
    $hasSalesChoice = dbColumnExists($conn, 'tasks', 'sales_estimate_days');
    if (!$hasSalesApproval) {
        return [
            'approved' => false,
            'timeline_start' => projectDateString($project['timeline_start'] ?? null),
            'timeline_end' => projectDateString($project['timeline_end'] ?? null),
            'tasks' => $timelineRows,
        ];
    }

    $taskSql = 'SELECT id, title, started_at, deadline, estimated_days_min, estimated_days_likely,
                       estimated_days_max, sales_estimate_choice, sales_estimate_days
                FROM tasks
                WHERE project_id = ? AND sales_approved = 1';
    if ($hasSalesChoice) {
        $taskSql .= ' AND sales_estimate_days IS NOT NULL';
    }
    $taskSql .= ' ORDER BY COALESCE(deadline, updated_at) ASC';

    $taskStmt = $conn->prepare($taskSql);
    $taskStmt->bind_param('i', $projectId);
    $taskStmt->execute();
    $approvedTasks = $taskStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $taskStmt->close();

    foreach ($approvedTasks as $task) {
        $start = projectDateString($task['started_at'] ?? null)
            ?: projectDateString($project['timeline_start'] ?? null)
            ?: date('Y-m-d');
        $end = projectDateString($task['deadline'] ?? null);

        if (!$end) {
            $duration = (int) ($task['sales_estimate_days'] ?? 0);
            if ($duration <= 0) {
                $duration = (int) ($task['estimated_days_likely'] ?? 0);
            }
            if ($duration <= 0) {
                $duration = (int) ($task['estimated_days_min'] ?? 0);
            }
            if ($duration <= 0) {
                $duration = (int) ($task['estimated_days_max'] ?? 0);
            }
            if ($duration <= 0) {
                $duration = 1;
            }

            $endDate = new DateTime($start);
            $endDate->modify('+' . max(1, $duration) . ' days');
            $end = $endDate->format('Y-m-d');
        }

        $timelineRows[] = [
            'id' => 'task-' . (int) $task['id'],
            'name' => $task['title'],
            'start' => $start,
            'end' => $end,
            'type' => 'task',
            'progress' => 0,
            'min_days' => (int) ($task['estimated_days_min'] ?? 0),
            'likely_days' => (int) ($task['estimated_days_likely'] ?? 0),
            'max_days' => (int) ($task['estimated_days_max'] ?? 0),
            'sales_estimate_choice' => $task['sales_estimate_choice'] ?? null,
            'sales_estimate_days' => (int) ($task['sales_estimate_days'] ?? 0),
        ];
    }

    return [
        'approved' => count($approvedTasks) > 0,
        'timeline_start' => projectDateString($project['timeline_start'] ?? null),
        'timeline_end' => projectDateString($project['timeline_end'] ?? null),
        'tasks' => $timelineRows,
    ];
}

function fetchProjectContext(mysqli|PgsqlMysqliCompatConnection $conn, int $projectId, array $user): ?array
{
    try {
    $projectStmt = $conn->prepare(
        'SELECT p.*, c.name AS client_name, c.contact_person, c.email AS client_email,
                c.phone AS client_phone, c.address AS client_address, c.industry AS client_industry,
                creator.full_name AS created_by_name,
                architect.full_name AS architect_name,
                sul.full_name AS sul_name
         FROM projects p
         LEFT JOIN clients c ON p.client_id = c.id
         LEFT JOIN users creator ON creator.id = p.created_by
         LEFT JOIN users architect ON architect.id = p.architect_id
         LEFT JOIN users sul ON sul.id = p.sul_id
         WHERE p.id = ?
         LIMIT 1'
    );
    $projectStmt->bind_param('i', $projectId);
    $projectStmt->execute();
    $project = $projectStmt->get_result()->fetch_assoc();
    $projectStmt->close();

    if (!$project) {
        return null;
    }

    $statusStmt = $conn->prepare(
        'SELECT status, COUNT(*) AS total
         FROM tasks
         WHERE project_id = ?
         GROUP BY status'
    );
    $statusStmt->bind_param('i', $projectId);
    $statusStmt->execute();
    $taskStatusRows = $statusStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $statusStmt->close();

    $taskCounts = [
        'total' => 0,
        'completed' => 0,
        'active' => 0,
        'blocked' => 0,
        'overdue' => 0,
        'unassigned' => 0,
        'by_status' => [],
    ];
    foreach ($taskStatusRows as $row) {
        $status = (string) ($row['status'] ?? 'unknown');
        $count = (int) ($row['total'] ?? 0);
        $taskCounts['total'] += $count;
        $taskCounts['by_status'][$status] = $count;

        if ($status === 'completed') {
            $taskCounts['completed'] += $count;
        }
        if (in_array($status, ['todo', 'in_progress', 'in_review', 'review'], true)) {
            $taskCounts['active'] += $count;
        }
        if ($status === 'blocked') {
            $taskCounts['blocked'] += $count;
        }
        if ($status === 'overdue') {
            $taskCounts['overdue'] += $count;
        }
    }

    $unassignedStmt = $conn->prepare(
        'SELECT COUNT(*) AS total
         FROM tasks
         WHERE project_id = ? AND (assigned_to IS NULL OR assigned_to = 0)'
    );
    $unassignedStmt->bind_param('i', $projectId);
    $unassignedStmt->execute();
    $taskCounts['unassigned'] = (int) ($unassignedStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $unassignedStmt->close();

    $featureProgressStmt = $conn->prepare(
        'SELECT
            COUNT(*) AS total_features,
            SUM(CASE WHEN ' . projectProgressMergedCondition('t') . ' THEN 1 ELSE 0 END) AS merged_features
         FROM tasks t
         WHERE t.project_id = ? AND ' . projectProgressFeatureCondition('t')
    );
    if ($featureProgressStmt !== false) {
        $featureProgressStmt->bind_param('i', $projectId);
        $featureProgressStmt->execute();
        $featureProgress = $featureProgressStmt->get_result()->fetch_assoc() ?: [];
        $featureProgressStmt->close();

        $taskCounts['total_features'] = (int) ($featureProgress['total_features'] ?? 0);
        $taskCounts['merged_features'] = (int) ($featureProgress['merged_features'] ?? 0);
        $taskCounts['progress_percent'] = projectProgressPercent($taskCounts['total_features'], $taskCounts['merged_features']);
    }

    $recentTaskStmt = $conn->prepare(
        'SELECT t.id, t.title, t.status, t.priority, t.deadline, u.full_name AS assigned_to_name
         FROM tasks t
         LEFT JOIN users u ON u.id = t.assigned_to
         WHERE t.project_id = ?
         ORDER BY t.updated_at DESC
         LIMIT 6'
    );
    $recentTaskStmt->bind_param('i', $projectId);
    $recentTaskStmt->execute();
    $recentTasks = $recentTaskStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $recentTaskStmt->close();

    $documentStmt = $conn->prepare(
        'SELECT ad.id, ad.document_type, ad.file_name, ad.file_path, ad.description,
                ad.created_at, u.full_name AS uploaded_by_name
         FROM architecture_documents ad
         LEFT JOIN users u ON u.id = ad.uploaded_by
         WHERE ad.project_id = ?
         ORDER BY ad.created_at DESC'
    );
    $documentStmt->bind_param('i', $projectId);
    $documentStmt->execute();
    $documents = $documentStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $documentStmt->close();

    $techStmt = $conn->prepare(
        'SELECT tr.service_type, tr.estimated_cost, tr.annual_cost, tr.quantity, tr.notes,
                t.name AS technology_name, t.category AS technology_category
         FROM tech_recommendations tr
         LEFT JOIN technologies t ON t.id = tr.tech_id
         WHERE tr.project_id = ?
         ORDER BY t.category ASC, t.name ASC'
    );
    $techStmt->bind_param('i', $projectId);
    $techStmt->execute();
    $technologies = $techStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $techStmt->close();

    $teamMembers = [];
    $taskTeamStmt = $conn->prepare(
        'SELECT DISTINCT u.id, u.full_name, u.role
         FROM tasks t
         INNER JOIN users u ON u.id = t.assigned_to
         WHERE t.project_id = ?
         ORDER BY u.role ASC, u.full_name ASC'
    );
    $taskTeamStmt->bind_param('i', $projectId);
    $taskTeamStmt->execute();
    foreach ($taskTeamStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $member) {
        $teamMembers[(int) $member['id']] = $member;
    }
    $taskTeamStmt->close();

    $allocationTeamStmt = $conn->prepare(
        'SELECT DISTINCT u.id, u.full_name, u.role
         FROM developer_allocations da
         INNER JOIN developer_allocation_assignments daa ON daa.allocation_id = da.id
         INNER JOIN users u ON u.id = daa.developer_id
         WHERE da.project_id = ?
         ORDER BY u.role ASC, u.full_name ASC'
    );
    $allocationTeamStmt->bind_param('i', $projectId);
    $allocationTeamStmt->execute();
    foreach ($allocationTeamStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $member) {
        $teamMembers[(int) $member['id']] = $member;
    }
    $allocationTeamStmt->close();

    $expertAssignmentStmt = $conn->prepare(
        'SELECT t.id AS task_id, t.title, t.status, t.deadline,
                u.id AS user_id, u.full_name, u.role,
                fe.min_days, fe.likely_days, fe.max_days, fe.status AS estimation_status
         FROM tasks t
         INNER JOIN users u ON u.id = t.assigned_to
         LEFT JOIN feature_estimations fe ON fe.task_id = t.id AND fe.expert_id = u.id
         WHERE t.project_id = ? AND u.role = \'expert\'
         ORDER BY u.full_name ASC, t.deadline ASC'
    );
    $expertAssignmentStmt->bind_param('i', $projectId);
    $expertAssignmentStmt->execute();
    $expertAssignments = $expertAssignmentStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $expertAssignmentStmt->close();

    $developerTaskStmt = $conn->prepare(
        'SELECT t.id AS task_id, t.title, t.status, t.deadline,
                u.id AS user_id, u.full_name, u.role
         FROM tasks t
         INNER JOIN users u ON u.id = t.assigned_to
         WHERE t.project_id = ? AND u.role = \'developer\'
         ORDER BY u.full_name ASC, t.deadline ASC'
    );
    $developerTaskStmt->bind_param('i', $projectId);
    $developerTaskStmt->execute();
    $developerTasks = $developerTaskStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $developerTaskStmt->close();

    $developerAllocationStmt = $conn->prepare(
        'SELECT da.role AS allocation_role, da.skill_level, da.required_count, da.allocated_count,
                daa.status AS assignment_status, daa.allocation_percentage, daa.assigned_at,
                u.id AS user_id, u.full_name, u.role
         FROM developer_allocations da
         LEFT JOIN developer_allocation_assignments daa ON daa.allocation_id = da.id
         LEFT JOIN users u ON u.id = daa.developer_id
         WHERE da.project_id = ?
         ORDER BY da.role ASC, u.full_name ASC'
    );
    $developerAllocationStmt->bind_param('i', $projectId);
    $developerAllocationStmt->execute();
    $developerAllocations = $developerAllocationStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $developerAllocationStmt->close();

    $receiptStmt = $conn->prepare(
        'SELECT r.id, r.report_id, r.receipt_number, r.file_path, r.file_name,
                r.amount_due, r.amount_paid, r.due_date, r.status, r.created_at,
                sr.total_cost, sr.currency
         FROM receipts r
         INNER JOIN sales_reports sr ON sr.id = r.report_id
         WHERE sr.project_id = ?
         ORDER BY r.created_at DESC'
    );
    $receiptStmt->bind_param('i', $projectId);
    $receiptStmt->execute();
    $receipts = $receiptStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $receiptStmt->close();

    $featureCountStmt = $conn->prepare(
        'SELECT COUNT(*) AS total FROM tasks WHERE project_id = ? AND task_type = \'feature\''
    );
    $featureCountStmt->bind_param('i', $projectId);
    $featureCountStmt->execute();
    $featureCount = (int) ($featureCountStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $featureCountStmt->close();

    $allocationCountStmt = $conn->prepare(
        'SELECT COUNT(*) AS total FROM developer_allocations WHERE project_id = ?'
    );
    $allocationCountStmt->bind_param('i', $projectId);
    $allocationCountStmt->execute();
    $allocationCount = (int) ($allocationCountStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $allocationCountStmt->close();

    $documents = array_map(static function (array $document): array {
        if (!empty($document['file_path']) && !str_starts_with((string) $document['file_path'], 'http')) {
            $document['file_url'] = BASE_URL . ltrim((string) $document['file_path'], '/');
        }
        return $document;
    }, $documents);

    $receipts = array_map(static function (array $receipt): array {
        if (!empty($receipt['file_path']) && !str_starts_with((string) $receipt['file_path'], 'http')) {
            $receipt['file_url'] = BASE_URL . ltrim((string) $receipt['file_path'], '/');
        }
        return $receipt;
    }, $receipts);

    $summaryReady = $featureCount > 0
        && count($technologies) > 0
        && $allocationCount > 0
        && count($documents) > 0;

    $project['task_counts'] = $taskCounts;
    $project['recent_tasks'] = $recentTasks;
    $project['architecture_documents'] = $documents;
    $project['technology_recommendations'] = $technologies;
    $project['team_members'] = array_values($teamMembers);
    $project['current_user_role'] = $user['role'] ?? '';
    $project['architecture_summary'] = [
        'ready' => $summaryReady,
        'feature_count' => $featureCount,
        'technology_count' => count($technologies),
        'allocation_count' => $allocationCount,
        'document_count' => count($documents),
        'submitted' => !in_array((string) ($project['status'] ?? ''), ['pending'], true),
        'review_url' => BASE_URL . 'modules/architect/project-review.php?id=' . $projectId,
        'summary_url' => BASE_URL . 'modules/architect/summary.php?id=' . $projectId,
        'upload_url' => BASE_URL . 'modules/architect/upload-diagram.php?id=' . $projectId,
    ];
    $project['sul_assignments'] = [
        'sul_name' => $project['sul_name'] ?? 'Unassigned',
        'experts' => $expertAssignments,
        'developer_tasks' => $developerTasks,
        'developer_allocations' => $developerAllocations,
    ];
    $project['approved_timeline'] = buildApprovedTimeline($conn, $project);
    $project['sales_receipts'] = array_map(static function (array $receipt): array {
        $receipt['receipt_url'] = BASE_URL . 'modules/sales/receipt.php?receipt_id=' . (int) $receipt['id'] . '&report_id=' . (int) $receipt['report_id'];
        return $receipt;
    }, $receipts);

    $sharedFilesStmt = $conn->prepare(
        'SELECT fi.id, fi.original_file_name, fi.file_type, fi.file_size, fi.file_path,
                fi.created_at, u.full_name AS owner_name, fo.name AS folder_name
         FROM files fi
         INNER JOIN folders fo ON fo.id = fi.folder_id
         LEFT JOIN users u ON u.id = fi.uploaded_by
         WHERE fo.project_id = ?
         ORDER BY fi.created_at DESC'
    );
    $sharedFilesStmt->bind_param('i', $projectId);
    $sharedFilesStmt->execute();
    $sharedFolderFiles = $sharedFilesStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $sharedFilesStmt->close();

    $project['shared_folder_files'] = array_map(static function (array $file): array {
        if (!empty($file['file_path']) && !str_starts_with((string) $file['file_path'], 'http')) {
            $file['file_url'] = BASE_URL . ltrim((string) $file['file_path'], '/');
        }
        return $file;
    }, $sharedFolderFiles);

    return $project;
    } catch (Throwable $e) {
        error_log('fetchProjectContext failed for project_id=' . $projectId . ': ' . $e->getMessage());
        return null;
    }
}
