<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function projectWorkflowDepartments(): array
{
    return [
        'UI/UX' => ['UI/UX', 'UI/UX Designer'],
        'Frontend' => ['Frontend', 'Frontend Developer'],
        'Backend' => ['Backend', 'Backend Developer'],
        'Mobile' => ['Mobile', 'Mobile Developer'],
        'Database' => ['Database', 'Database Administrator'],
        'DevOps' => ['DevOps', 'DevOps Engineer'],
        'AI' => ['AI', 'AI Engineer'],
        'Security' => ['Security', 'Security Engineer'],
        'QA' => ['QA', 'QA Engineer'],
        'Expert Department' => ['Expert Department', 'Expert', 'Senior / Expert'],
        'Other Departments' => ['Other Departments', 'Other'],
    ];
}

function projectWorkflowDepartmentLabel(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    foreach (projectWorkflowDepartments() as $label => $aliases) {
        foreach ($aliases as $alias) {
            if (strcasecmp($value, $alias) === 0) {
                return $label;
            }
        }
    }

    return $value;
}

function projectWorkflowDepartmentAliases(?string $value): array
{
    $label = projectWorkflowDepartmentLabel($value);
    $aliases = projectWorkflowDepartments()[$label] ?? [$label];
    $aliases[] = $label;

    return array_values(array_unique(array_filter(array_map('strval', $aliases))));
}

function projectWorkflowSelectedDepartments(
    mysqli|PgsqlMysqliCompatConnection $conn,
    int $projectId,
    array $project = []
): array {
    if ($projectId <= 0) {
        return [];
    }

    $knownDepartments = array_keys(projectWorkflowDepartments());
    $selected = [];
    $rawDepartments = trim((string) ($project['included_departments'] ?? ''));

    if ($rawDepartments !== '') {
        $decoded = json_decode($rawDepartments, true);
        if (is_array($decoded)) {
            foreach ($decoded as $department) {
                $label = projectWorkflowDepartmentLabel((string) $department);
                if (in_array($label, $knownDepartments, true)) {
                    $selected[] = $label;
                }
            }
        }
    }

    if ($selected === [] && dbTableExists($conn, 'developer_allocations')) {
        $stmt = $conn->prepare(
            'SELECT role, required_count FROM developer_allocations WHERE project_id = ? ORDER BY id ASC'
        );
        if ($stmt !== false) {
            $stmt->bind_param('i', $projectId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                if ((int) ($row['required_count'] ?? 0) <= 0) {
                    continue;
                }
                $label = projectWorkflowDepartmentLabel((string) ($row['role'] ?? ''));
                if (in_array($label, $knownDepartments, true)) {
                    $selected[] = $label;
                }
            }
            $stmt->close();
        }
    }

    return array_values(array_unique($selected));
}

function projectWorkflowDepartmentAllocations(
    mysqli|PgsqlMysqliCompatConnection $conn,
    int $projectId,
    array $project = []
): array {
    $selectedDepartments = projectWorkflowSelectedDepartments($conn, $projectId, $project);
    $allocations = array_fill_keys($selectedDepartments, 0);

    if ($projectId <= 0 || !dbTableExists($conn, 'developer_allocations')) {
        return $allocations;
    }

    $stmt = $conn->prepare(
        'SELECT role, required_count FROM developer_allocations WHERE project_id = ? ORDER BY id ASC'
    );
    if ($stmt === false) {
        return $allocations;
    }

    $stmt->bind_param('i', $projectId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $department = projectWorkflowDepartmentLabel((string) ($row['role'] ?? ''));
        if (!array_key_exists($department, $allocations)) {
            continue;
        }
        $allocations[$department] += max(0, (int) ($row['required_count'] ?? 0));
    }
    $stmt->close();

    return $allocations;
}

function projectWorkflowUserDepartmentClause(mysqli|PgsqlMysqliCompatConnection $conn, string $alias, string $department): string
{
    $aliases = projectWorkflowDepartmentAliases($department);
    if ($aliases === []) {
        return '1 = 0';
    }

    $quoted = array_map(static fn(string $item): string => "'" . $conn->real_escape_string($item) . "'", $aliases);
    return "{$alias}.department IN (" . implode(',', $quoted) . ')';
}

function projectWorkflowProjectDepartmentClause(mysqli|PgsqlMysqliCompatConnection $conn, string $projectAlias, string $department): string
{
    $departmentLabel = projectWorkflowDepartmentLabel($department);
    $aliases = projectWorkflowDepartmentAliases($department);
    if ($aliases === []) {
        return '1 = 0';
    }

    $clauses = [];
    if ($departmentLabel === 'Expert Department' && dbTableExists($conn, 'tasks')) {
        $clauses[] = "EXISTS (
            SELECT 1
            FROM tasks t_expert_scope
            WHERE t_expert_scope.project_id = {$projectAlias}.id
              AND t_expert_scope.task_type = 'feature'
        )";
    }

    foreach ($aliases as $alias) {
        $safe = $conn->real_escape_string($alias);
        if (dbTableExists($conn, 'developer_allocations')) {
            $clauses[] = "EXISTS (
                SELECT 1
                FROM developer_allocations da_scope
                WHERE da_scope.project_id = {$projectAlias}.id
                  AND da_scope.role = '{$safe}'
            )";
        }
        if (dbColumnExists($conn, 'projects', 'included_departments')) {
            $clauses[] = "{$projectAlias}.included_departments LIKE '%\"{$safe}\"%'";
        }
    }

    return $clauses ? '(' . implode(' OR ', $clauses) . ')' : '1 = 0';
}

function projectWorkflowTaskDepartmentClause(
    mysqli|PgsqlMysqliCompatConnection $conn,
    string $taskAlias,
    string $department,
    bool $allowUnscheduledFallback = false
): string {
    if (!dbTableExists($conn, 'methodology_tasks')) {
        return $allowUnscheduledFallback ? '1 = 1' : '1 = 0';
    }

    $aliases = projectWorkflowDepartmentAliases($department);
    if ($aliases === []) {
        return '1 = 0';
    }

    $quoted = array_map(static fn(string $item): string => "'" . $conn->real_escape_string($item) . "'", $aliases);
    $departmentMatch = "EXISTS (
        SELECT 1
        FROM methodology_tasks mt_scope
        WHERE mt_scope.task_id = {$taskAlias}.id
          AND mt_scope.department IN (" . implode(',', $quoted) . ")
    )";

    if (!$allowUnscheduledFallback) {
        return $departmentMatch;
    }

    return "({$departmentMatch} OR NOT EXISTS (
        SELECT 1
        FROM methodology_tasks mt_any
        WHERE mt_any.task_id = {$taskAlias}.id
    ))";
}

function projectWorkflowEnsureExpertDepartmentSeed(mysqli|PgsqlMysqliCompatConnection $conn): void
{
    static $done = false;
    if ($done || !dbTableExists($conn, 'users')) {
        return;
    }
    $done = true;

    $password = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
    $users = [
        ['expert_sul_1', 'expert.sul@erni-pms.com', 'Evelyn Expert SUL', 'Expert Department', 'sul', 'Expert Service Unit Leader'],
        ['expert_dept_1', 'expert.dept1@erni-pms.com', 'Rafael Estimation Expert', 'Expert Department', 'expert', 'Solution Estimation Expert'],
        ['expert_dept_2', 'expert.dept2@erni-pms.com', 'Mina Senior Estimator', 'Expert Department', 'expert', 'Senior Estimation Expert'],
    ];

    foreach ($users as $seedUser) {
        $check = $conn->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
        if ($check === false) {
            continue;
        }
        $check->bind_param('ss', $seedUser[0], $seedUser[1]);
        $check->execute();
        $exists = $check->get_result()->fetch_assoc();
        $check->close();

        if ($exists) {
            continue;
        }

        $insert = $conn->prepare('
            INSERT INTO users (username, email, password, full_name, department, role, status, designation)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        if ($insert === false) {
            continue;
        }
        $status = 'active';
        $insert->bind_param('ssssssss', $seedUser[0], $seedUser[1], $password, $seedUser[2], $seedUser[3], $seedUser[4], $status, $seedUser[5]);
        $insert->execute();
        $insert->close();
    }
}

function projectWorkflowClassifyComplexity(?string $timelineStart, ?string $timelineEnd, int $featureCount): string
{
    $days = 0;
    if ($timelineStart && $timelineEnd) {
        $start = strtotime($timelineStart);
        $end = strtotime($timelineEnd);
        if ($start !== false && $end !== false && $end >= $start) {
            $days = (int) floor(($end - $start) / 86400) + 1;
        }
    }

    if ($featureCount >= 20 || ($featureCount >= 12 && $days > 120)) {
        return 'Enterprise';
    }
    if ($featureCount >= 12 || $days > 90) {
        return 'High';
    }
    if ($featureCount >= 6 || $days > 30) {
        return 'Medium';
    }

    return 'Low';
}

function projectWorkflowEnsureSchema(mysqli|PgsqlMysqliCompatConnection $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (defined('DB_DRIVER') && DB_DRIVER === 'mysql' && dbColumnExists($conn, 'projects', 'complexity_level')) {
        $conn->query('ALTER TABLE projects MODIFY complexity_level VARCHAR(30) DEFAULT NULL');
    }

    $textType = defined('DB_DRIVER') && DB_DRIVER === 'pgsql' ? 'TEXT' : 'LONGTEXT';
    $columns = [
        'priority' => 'VARCHAR(30) NULL',
        'sales_notes' => 'TEXT NULL',
        'sales_attachments' => "{$textType} NULL",
        'workflow_status' => 'VARCHAR(60) NULL',
    ];

    foreach ($columns as $column => $definition) {
        if (!dbColumnExists($conn, 'projects', $column)) {
            $conn->query("ALTER TABLE projects ADD COLUMN {$column} {$definition}");
        }
    }

    projectWorkflowEnsureExpertDepartmentSeed($conn);
}

function projectWorkflowNotifyDepartmentSuls(
    mysqli|PgsqlMysqliCompatConnection $conn,
    string $department,
    string $title,
    string $message,
    string $actionUrl,
    int $projectId
): void {
    $where = projectWorkflowUserDepartmentClause($conn, 'u', $department);
    $result = $conn->query("SELECT u.id FROM users u WHERE u.role = 'sul' AND u.status = 'active' AND {$where}");
    if (!$result) {
        return;
    }

    while ($row = $result->fetch_assoc()) {
        createNotification((int) $row['id'], $title, $message, 'project', 'projects', $projectId, $actionUrl);
    }
}

function projectWorkflowNotifySelectedSuls(mysqli|PgsqlMysqliCompatConnection $conn, int $projectId, array $departments, string $projectName): void
{
    foreach ($departments as $department) {
        projectWorkflowNotifyDepartmentSuls(
            $conn,
            (string) $department,
            'Project Roadmap Ready: ' . $projectName,
            'Architect submitted the plan. Your department can view the roadmap and will unlock when its phase becomes active.',
            BASE_URL . 'modules/sul/task-allocation.php?project_id=' . $projectId,
            $projectId
        );
    }
}

function projectWorkflowTaskDepartment(mysqli|PgsqlMysqliCompatConnection $conn, int $taskId): string
{
    $row = $conn->query("
        SELECT mt.department
        FROM methodology_tasks mt
        WHERE mt.task_id = {$taskId}
        ORDER BY mt.id ASC
        LIMIT 1
    ")->fetch_assoc();

    return projectWorkflowDepartmentLabel($row['department'] ?? '');
}

function projectWorkflowSyncRoadmap(mysqli|PgsqlMysqliCompatConnection $conn, int $projectId): void
{
    if ($projectId <= 0 || !dbTableExists($conn, 'methodology_phases') || !dbTableExists($conn, 'methodology_tasks')) {
        return;
    }

    $phaseRows = $conn->query("SELECT id FROM methodology_phases WHERE project_id = {$projectId}");
    if (!$phaseRows) {
        return;
    }

    while ($phase = $phaseRows->fetch_assoc()) {
        $phaseId = (int) $phase['id'];
        $counts = $conn->query("
            SELECT
                COUNT(mt.id) AS total,
                SUM(CASE WHEN t.status IN ('completed', 'merged', 'ready_for_release') THEN 1 ELSE 0 END) AS done_count,
                SUM(CASE WHEN t.status IN ('for_review', 'qa_testing', 'qa_passed', 'architect_review') THEN 1 ELSE 0 END) AS approval_count,
                SUM(CASE WHEN t.status IN ('in_progress', 'sul_revision', 'qa_failed') THEN 1 ELSE 0 END) AS progress_count
            FROM methodology_tasks mt
            LEFT JOIN tasks t ON t.id = mt.task_id
            WHERE mt.phase_id = {$phaseId}
        ")->fetch_assoc();

        $total = (int) ($counts['total'] ?? 0);
        $status = 'planned';
        if ($total > 0 && (int) ($counts['done_count'] ?? 0) >= $total) {
            $status = 'completed';
        } elseif ((int) ($counts['approval_count'] ?? 0) > 0) {
            $status = 'on_hold';
        } elseif ((int) ($counts['progress_count'] ?? 0) > 0) {
            $status = 'in_progress';
        }

        $conn->query("UPDATE methodology_phases SET status = '{$status}' WHERE id = {$phaseId}");
    }
}

function projectWorkflowPhaseStatusLabel(string $status, bool $isLocked = false): string
{
    if ($isLocked) {
        return 'Waiting';
    }

    return match ($status) {
        'planned' => 'Pending',
        'in_progress' => 'In Progress',
        'on_hold' => 'For Approval',
        'blocked' => 'Revision Required',
        'completed' => 'Completed',
        default => ucwords(str_replace('_', ' ', $status)),
    };
}
?>
