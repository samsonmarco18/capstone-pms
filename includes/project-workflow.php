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

function projectWorkflowMethodologyTaskDepartmentClause(
    mysqli|PgsqlMysqliCompatConnection $conn,
    string $methodologyTaskAlias,
    string $department
): string {
    if (!dbTableExists($conn, 'methodology_tasks')) {
        return '1 = 0';
    }

    $aliases = projectWorkflowDepartmentAliases($department);
    if ($aliases === []) {
        return '1 = 0';
    }

    $quoted = array_map(static fn(string $item): string => "'" . $conn->real_escape_string($item) . "'", $aliases);
    $departmentClauses = [
        "{$methodologyTaskAlias}.department IN (" . implode(',', $quoted) . ')',
    ];

    if (dbTableExists($conn, 'methodology_task_departments')) {
        $departmentClauses[] = "EXISTS (
            SELECT 1
            FROM methodology_task_departments mtd_scope
            WHERE mtd_scope.methodology_task_id = {$methodologyTaskAlias}.id
              AND mtd_scope.department IN (" . implode(',', $quoted) . ")
        )";
    }

    return '(' . implode(' OR ', $departmentClauses) . ')';
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

    $methodologyDepartmentMatch = projectWorkflowMethodologyTaskDepartmentClause($conn, 'mt_scope', $department);
    $departmentMatch = "EXISTS (
        SELECT 1
        FROM methodology_tasks mt_scope
        WHERE mt_scope.task_id = {$taskAlias}.id
          AND {$methodologyDepartmentMatch}
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

function projectWorkflowClassifyBudget(?float $budget): string
{
    if ($budget === null || $budget < 500000) {
        return 'low';
    }

    if ($budget <= 1500000) {
        return 'medium';
    }

    return 'high';
}

function projectWorkflowClassifyTimeline(?string $timelineStart, ?string $timelineEnd): string
{
    $days = 0;
    if ($timelineStart && $timelineEnd) {
        $start = strtotime($timelineStart);
        $end = strtotime($timelineEnd);
        if ($start !== false && $end !== false && $end >= $start) {
            $days = (int) floor(($end - $start) / 86400) + 1;
        }
    }

    if ($days > 0 && $days <= 30) {
        return 'short';
    }

    if ($days > 0 && $days <= 90) {
        return 'moderate';
    }

    return 'long';
}

function projectWorkflowClassifyComplexity(?string $timelineStart, ?string $timelineEnd, ?float $budget, string $priority): string
{
    $budgetBand = projectWorkflowClassifyBudget($budget);
    $timelineBand = projectWorkflowClassifyTimeline($timelineStart, $timelineEnd);
    $priorityBand = strtolower(trim($priority));

    $budgetScore = ['low' => 0, 'medium' => 1, 'high' => 2][$budgetBand] ?? 1;
    $timelineScore = ['long' => 0, 'moderate' => 1, 'short' => 2][$timelineBand] ?? 1;
    $priorityScore = ['low' => 0, 'medium' => 1, 'high' => 2, 'critical' => 2][$priorityBand] ?? 1;

    $score = $budgetScore + $timelineScore + $priorityScore;
    if ($score >= 4) {
        return 'High';
    }

    if ($score >= 2) {
        return 'Medium';
    }

    return 'Low';
}

function projectWorkflowExtractLegacySalesBudget(?string $requestedFeatures): ?float
{
    $raw = trim((string) $requestedFeatures);
    if ($raw === '') {
        return null;
    }

    if (!preg_match('/(?:^|\R)\s*Budget\s*:\s*(?:\R\s*)?(?:PHP|₱)?\s*([0-9][0-9,]*(?:\.[0-9]{1,2})?)/iu', $raw, $matches)) {
        return null;
    }

    $budget = (float) str_replace(',', '', (string) ($matches[1] ?? ''));
    return $budget > 0 ? $budget : null;
}

function projectWorkflowBackfillLegacySalesBudgets(mysqli|PgsqlMysqliCompatConnection $conn): void
{
    if (!dbColumnExists($conn, 'projects', 'sales_budget')) {
        return;
    }

    $result = $conn->query("
        SELECT id, requested_features
        FROM projects
        WHERE (sales_budget IS NULL OR sales_budget = 0)
          AND requested_features IS NOT NULL
    ");
    if (!$result || !method_exists($result, 'fetch_assoc')) {
        return;
    }

    $update = $conn->prepare('UPDATE projects SET sales_budget = ? WHERE id = ? AND (sales_budget IS NULL OR sales_budget = 0)');
    if ($update === false) {
        return;
    }

    while ($project = $result->fetch_assoc()) {
        $budget = projectWorkflowExtractLegacySalesBudget($project['requested_features'] ?? null);
        if ($budget === null) {
            continue;
        }

        $formattedBudget = number_format($budget, 2, '.', '');
        $projectId = (int) ($project['id'] ?? 0);
        if ($projectId <= 0) {
            continue;
        }

        $update->bind_param('si', $formattedBudget, $projectId);
        $update->execute();
    }

    $update->close();
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
        'project_type' => 'VARCHAR(80) NULL',
        'priority' => 'VARCHAR(30) NULL',
        'sales_notes' => 'TEXT NULL',
        'sales_attachments' => "{$textType} NULL",
        'sales_budget' => 'DECIMAL(15, 2) NULL',
        'workflow_status' => 'VARCHAR(60) NULL',
    ];

    foreach ($columns as $column => $definition) {
        if (!dbColumnExists($conn, 'projects', $column)) {
            $conn->query("ALTER TABLE projects ADD COLUMN {$column} {$definition}");
        }
    }

    projectWorkflowBackfillLegacySalesBudgets($conn);

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
            'Architect submitted the plan. Your department can view the roadmap and begin assigned work once earlier phase gates are clear.',
            BASE_URL . 'modules/sul/task-allocation.php?project_id=' . $projectId,
            $projectId
        );
    }
}

function projectWorkflowTaskDepartment(mysqli|PgsqlMysqliCompatConnection $conn, int $taskId): string
{
    $task = $conn->query("SELECT project_id FROM tasks WHERE id = {$taskId} LIMIT 1")->fetch_assoc();
    $projectId = (int) ($task['project_id'] ?? 0);
    $rows = projectWorkflowRoadmapTaskRows($conn, $projectId, $taskId);
    if ($rows !== []) {
        return projectWorkflowDepartmentLabel((string) ($rows[0]['department_label'] ?? ($rows[0]['department'] ?? '')));
    }

    return '';
}

function projectWorkflowRoadmapTaskStatus(?string $workflowStatus): string
{
    $workflowStatus = strtolower(trim((string) $workflowStatus));

    if (in_array($workflowStatus, ['completed', 'merged', 'ready_for_release'], true)) {
        return 'completed';
    }

    if (in_array($workflowStatus, ['blocked', 'overdue', 'sul_revision', 'qa_failed'], true)) {
        return 'blocked';
    }

    if (in_array($workflowStatus, ['in_progress', 'for_review', 'qa_testing', 'qa_passed', 'architect_review'], true)) {
        return 'in_progress';
    }

    return 'planned';
}

function projectWorkflowRoadmapDepartmentStatus(?string $workflowStatus): string
{
    $workflowStatus = strtolower(trim((string) $workflowStatus));

    if (in_array($workflowStatus, ['completed', 'merged', 'ready_for_release'], true)) {
        return 'completed';
    }

    if (in_array($workflowStatus, ['blocked', 'overdue', 'sul_revision', 'qa_failed'], true)) {
        return 'for_revision';
    }

    if ($workflowStatus === 'for_review') {
        return 'for_review';
    }

    if (in_array($workflowStatus, ['in_progress', 'qa_testing', 'qa_passed', 'architect_review'], true)) {
        return 'in_progress';
    }

    return 'planned';
}

function projectWorkflowRoadmapTaskRows(mysqli|PgsqlMysqliCompatConnection $conn, int $projectId, int $taskId): array
{
    if (
        $projectId <= 0
        || $taskId <= 0
        || !dbTableExists($conn, 'methodology_phases')
        || !dbTableExists($conn, 'methodology_tasks')
    ) {
        return [];
    }

    $rowsResult = $conn->query("
        SELECT mt.*, mp.sequence_no AS phase_sequence, mp.name AS phase_name,
               mp.start_date AS phase_start_date, mp.end_date AS phase_end_date
        FROM methodology_tasks mt
        INNER JOIN methodology_phases mp ON mp.id = mt.phase_id
        WHERE mt.project_id = {$projectId}
          AND mt.task_id = {$taskId}
        ORDER BY
            COALESCE(NULLIF(mp.sequence_no, 0), mp.id) ASC,
            mt.start_date ASC,
            mt.id ASC
    ");

    if (!$rowsResult) {
        return [];
    }

    $rows = [];
    $rowIds = [];
    while ($row = $rowsResult->fetch_assoc()) {
        $rowId = (int) ($row['id'] ?? 0);
        if ($rowId <= 0) {
            continue;
        }

        $row['department_labels'] = [];
        $rows[$rowId] = $row;
        $rowIds[] = $rowId;
    }

    $expandedRows = [];
    if ($rowIds && dbTableExists($conn, 'methodology_task_departments')) {
        $idList = implode(',', array_map('intval', $rowIds));
        $departmentRows = $conn->query("
            SELECT id, methodology_task_id, department, required_count, status,
                   assigned_developer_id, workflow_task_id, deadline, submitted_at,
                   approved_by, approved_at, revision_notes
            FROM methodology_task_departments
            WHERE methodology_task_id IN ({$idList})
            ORDER BY id ASC
        ");

        if ($departmentRows) {
            while ($departmentRow = $departmentRows->fetch_assoc()) {
                $methodologyTaskId = (int) ($departmentRow['methodology_task_id'] ?? 0);
                if (!isset($rows[$methodologyTaskId])) {
                    continue;
                }

                $department = projectWorkflowDepartmentLabel((string) ($departmentRow['department'] ?? ''));
                if ($department !== '') {
                    $rows[$methodologyTaskId]['department_labels'][] = $department;
                }

                $lane = $rows[$methodologyTaskId];
                $lane['department_progress_id'] = (int) ($departmentRow['id'] ?? 0);
                $lane['department_labels'] = $department !== '' ? [$department] : [];
                $lane['department_label'] = $department;
                $lane['required_count'] = max(0, (int) ($departmentRow['required_count'] ?? 0));
                $lane['status'] = (string) ($departmentRow['status'] ?? 'planned');
                $lane['department_status'] = (string) ($departmentRow['status'] ?? 'planned');
                $lane['assigned_developer_id'] = (int) ($departmentRow['assigned_developer_id'] ?? 0);
                $lane['workflow_task_id'] = (int) ($departmentRow['workflow_task_id'] ?? 0);
                $lane['department_deadline'] = (string) ($departmentRow['deadline'] ?? '');
                $lane['submitted_at'] = (string) ($departmentRow['submitted_at'] ?? '');
                $lane['approved_by'] = (int) ($departmentRow['approved_by'] ?? 0);
                $lane['approved_at'] = (string) ($departmentRow['approved_at'] ?? '');
                $lane['revision_notes'] = (string) ($departmentRow['revision_notes'] ?? '');
                $expandedRows[] = $lane;
            }
        }
    }

    if ($expandedRows !== []) {
        return $expandedRows;
    }

    foreach ($rows as &$row) {
        if ($row['department_labels'] === []) {
            $department = projectWorkflowDepartmentLabel((string) ($row['department'] ?? ''));
            if ($department !== '') {
                $row['department_labels'][] = $department;
            }
        }
        $row['department_labels'] = array_values(array_unique($row['department_labels']));
        $row['department_label'] = (string) ($row['department_labels'][0] ?? projectWorkflowDepartmentLabel((string) ($row['department'] ?? '')));
    }
    unset($row);

    return array_values($rows);
}

function projectWorkflowRoadmapTaskMatchesDepartment(array $roadmapTask, string $department): bool
{
    $departmentLabel = projectWorkflowDepartmentLabel($department);
    if ($departmentLabel === '') {
        return false;
    }

    foreach (($roadmapTask['department_labels'] ?? []) as $rowDepartment) {
        if (strcasecmp(projectWorkflowDepartmentLabel((string) $rowDepartment), $departmentLabel) === 0) {
            return true;
        }
    }

    return false;
}

function projectWorkflowCurrentRoadmapTask(
    mysqli|PgsqlMysqliCompatConnection $conn,
    int $projectId,
    int $taskId,
    ?string $department = null
): ?array {
    $rows = projectWorkflowRoadmapTaskRows($conn, $projectId, $taskId);
    if ($rows === []) {
        return null;
    }

    foreach ($rows as $index => $row) {
        if ((string) ($row['status'] ?? 'planned') === 'completed') {
            continue;
        }

        if ($department !== null && $department !== '' && !projectWorkflowRoadmapTaskMatchesDepartment($row, $department)) {
            continue;
        }

        $candidatePhasePosition = (int) ($row['phase_sequence'] ?? 0);
        $candidatePhaseId = (int) ($row['phase_id'] ?? 0);
        foreach ($rows as $previousIndex => $previousRow) {
            if ($previousIndex >= $index || (string) ($previousRow['status'] ?? 'planned') === 'completed') {
                continue;
            }

            $previousPhasePosition = (int) ($previousRow['phase_sequence'] ?? 0);
            $previousPhaseId = (int) ($previousRow['phase_id'] ?? 0);
            $previousPhaseBlocks = $previousPhasePosition < $candidatePhasePosition
                || ($previousPhasePosition === $candidatePhasePosition && $previousPhaseId !== $candidatePhaseId);
            if ($previousPhaseBlocks) {
                return null;
            }
        }

        return $row;
    }

    return null;
}

function projectWorkflowNextRoadmapTask(
    mysqli|PgsqlMysqliCompatConnection $conn,
    int $projectId,
    int $taskId,
    int $currentRoadmapTaskId
): ?array {
    $rows = projectWorkflowRoadmapTaskRows($conn, $projectId, $taskId);
    if ($rows === []) {
        return null;
    }

    $currentIndex = null;
    foreach ($rows as $index => $row) {
        if ((int) ($row['id'] ?? 0) === $currentRoadmapTaskId) {
            $currentIndex = $index;
            break;
        }
    }

    $startIndex = $currentIndex === null ? 0 : $currentIndex + 1;
    for ($index = $startIndex; $index < count($rows); $index++) {
        if ((string) ($rows[$index]['status'] ?? 'planned') !== 'completed') {
            return $rows[$index];
        }
    }

    return null;
}

function projectWorkflowCurrentRoadmapTaskClause(
    mysqli|PgsqlMysqliCompatConnection $conn,
    string $taskAlias,
    string $department,
    bool $allowUnscheduledFallback = false
): string {
    if (!dbTableExists($conn, 'methodology_phases') || !dbTableExists($conn, 'methodology_tasks')) {
        return $allowUnscheduledFallback ? '1 = 1' : '1 = 0';
    }

    $departmentMatch = projectWorkflowMethodologyTaskDepartmentClause($conn, 'mt_ready', $department);
    $positionReady = 'COALESCE(NULLIF(mp_ready.sequence_no, 0), mp_ready.id)';
    $positionPrev = 'COALESCE(NULLIF(mp_prev.sequence_no, 0), mp_prev.id)';

    $aliases = projectWorkflowDepartmentAliases($department);
    $quotedDepartments = array_map(static fn(string $item): string => "'" . $conn->real_escape_string($item) . "'", $aliases);

    if (dbTableExists($conn, 'methodology_task_departments') && $quotedDepartments !== []) {
        $clause = "EXISTS (
            SELECT 1
            FROM methodology_tasks mt_ready
            INNER JOIN methodology_phases mp_ready ON mp_ready.id = mt_ready.phase_id
            INNER JOIN methodology_task_departments mtd_ready ON mtd_ready.methodology_task_id = mt_ready.id
            WHERE mt_ready.project_id = {$taskAlias}.project_id
              AND mt_ready.task_id = {$taskAlias}.id
              AND mtd_ready.department IN (" . implode(',', $quotedDepartments) . ")
              AND COALESCE(mtd_ready.status, 'planned') != 'completed'
              AND NOT EXISTS (
                  SELECT 1
                  FROM methodology_tasks mt_prev
                  INNER JOIN methodology_phases mp_prev ON mp_prev.id = mt_prev.phase_id
                  LEFT JOIN methodology_task_departments mtd_prev ON mtd_prev.methodology_task_id = mt_prev.id
                  WHERE mt_prev.project_id = mt_ready.project_id
                    AND mt_prev.task_id = mt_ready.task_id
                    AND {$positionPrev} < {$positionReady}
                    AND COALESCE(mtd_prev.status, mt_prev.status, 'planned') != 'completed'
              )
        )";
    } else {
        $clause = "EXISTS (
        SELECT 1
        FROM methodology_tasks mt_ready
        INNER JOIN methodology_phases mp_ready ON mp_ready.id = mt_ready.phase_id
        WHERE mt_ready.project_id = {$taskAlias}.project_id
          AND mt_ready.task_id = {$taskAlias}.id
          AND COALESCE(mt_ready.status, 'planned') != 'completed'
          AND {$departmentMatch}
          AND NOT EXISTS (
              SELECT 1
              FROM methodology_tasks mt_prev
              INNER JOIN methodology_phases mp_prev ON mp_prev.id = mt_prev.phase_id
              WHERE mt_prev.project_id = mt_ready.project_id
                AND mt_prev.task_id = mt_ready.task_id
                AND mt_prev.id != mt_ready.id
                AND (
                    {$positionPrev} < {$positionReady}
                    OR ({$positionPrev} = {$positionReady} AND mt_prev.id < mt_ready.id)
                )
                AND COALESCE(mt_prev.status, 'planned') != 'completed'
          )
        )";
    }

    if (!$allowUnscheduledFallback) {
        return $clause;
    }

    return "({$clause} OR NOT EXISTS (
        SELECT 1
        FROM methodology_tasks mt_any
        WHERE mt_any.project_id = {$taskAlias}.project_id
          AND mt_any.task_id = {$taskAlias}.id
    ))";
}

function projectWorkflowAutomaticPhaseStatus(array $phase, array $counts, ?DateTimeImmutable $today = null): string
{
    $total = (int) ($counts['total'] ?? 0);
    $doneCount = (int) ($counts['done_count'] ?? 0);
    $approvalCount = (int) ($counts['approval_count'] ?? 0);
    $progressCount = (int) ($counts['progress_count'] ?? 0);

    if ($total > 0 && $doneCount >= $total) {
        return 'completed';
    }

    if ($approvalCount > 0) {
        return 'on_hold';
    }

    if ($progressCount > 0) {
        return 'in_progress';
    }

    $today ??= new DateTimeImmutable('today');
    $phaseStart = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($phase['start_date'] ?? '')) ?: null;
    $phaseEnd = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($phase['end_date'] ?? '')) ?: null;

    if ($total === 0 && $phaseEnd && $phaseEnd < $today) {
        return 'completed';
    }

    if ($phaseStart && $phaseStart <= $today) {
        return 'in_progress';
    }

    return 'planned';
}

function projectWorkflowSyncRoadmap(mysqli|PgsqlMysqliCompatConnection $conn, int $projectId): void
{
    if ($projectId <= 0 || !dbTableExists($conn, 'methodology_phases') || !dbTableExists($conn, 'methodology_tasks')) {
        return;
    }

    $workflowRows = $conn->query("
        SELECT id, status, assigned_to, architect_reviewed_at, merged_at
        FROM tasks
        WHERE project_id = {$projectId}
    ");

    $hasDepartmentProgress = dbTableExists($conn, 'methodology_task_departments');

    if ($workflowRows) {
        $updateDepartmentTask = $hasDepartmentProgress
            ? $conn->prepare('UPDATE methodology_task_departments SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND project_id = ?')
            : false;
        $updateRoadmapTask = $conn->prepare('UPDATE methodology_tasks SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND project_id = ?');
        if ($updateRoadmapTask) {
            while ($workflowTask = $workflowRows->fetch_assoc()) {
                $taskId = (int) ($workflowTask['id'] ?? 0);
                $rows = projectWorkflowRoadmapTaskRows($conn, $projectId, $taskId);
                if ($rows === []) {
                    continue;
                }

                $workflowStatus = strtolower((string) ($workflowTask['status'] ?? 'todo'));
                $isFinalComplete = in_array($workflowStatus, ['merged', 'ready_for_release'], true)
                    || ($workflowStatus === 'completed' && (!empty($workflowTask['architect_reviewed_at']) || !empty($workflowTask['merged_at'])));
                $assignedTo = (int) ($workflowTask['assigned_to'] ?? 0);
                $automaticDepartmentStatus = $isFinalComplete
                    ? 'completed'
                    : projectWorkflowRoadmapDepartmentStatus($workflowStatus);

                foreach ($rows as $row) {
                    $rowId = (int) ($row['id'] ?? 0);
                    if ($rowId <= 0) {
                        continue;
                    }

                    $departmentProgressId = (int) ($row['department_progress_id'] ?? 0);
                    if ($departmentProgressId > 0 && $updateDepartmentTask) {
                        $matchesWorkflowTask = (int) ($row['workflow_task_id'] ?? 0) === $taskId;
                        $matchesAssignee = $assignedTo > 0 && (int) ($row['assigned_developer_id'] ?? 0) === $assignedTo;
                        if (!$isFinalComplete && !$matchesWorkflowTask && !$matchesAssignee) {
                            continue;
                        }

                        if (!$isFinalComplete && (string) ($row['status'] ?? 'planned') === 'completed') {
                            continue;
                        }

                        if ($automaticDepartmentStatus === (string) ($row['status'] ?? 'planned')) {
                            continue;
                        }

                        $updateDepartmentTask->bind_param('sii', $automaticDepartmentStatus, $departmentProgressId, $projectId);
                        $updateDepartmentTask->execute();
                        continue;
                    }

                    $automaticTaskStatus = $isFinalComplete
                        ? 'completed'
                        : projectWorkflowRoadmapTaskStatus($workflowStatus);
                    $updateRoadmapTask->bind_param('sii', $automaticTaskStatus, $rowId, $projectId);
                    $updateRoadmapTask->execute();
                }
            }
            $updateRoadmapTask->close();
            if ($updateDepartmentTask) {
                $updateDepartmentTask->close();
            }
        }
    }

    if ($hasDepartmentProgress) {
        $parentRows = $conn->query("
            SELECT
                mt.id,
                COUNT(mtd.id) AS lane_count,
                SUM(CASE WHEN mtd.status = 'completed' THEN 1 ELSE 0 END) AS completed_count,
                SUM(CASE WHEN mtd.status IN ('for_revision', 'blocked') THEN 1 ELSE 0 END) AS blocked_count,
                SUM(CASE WHEN mtd.status IN ('in_progress', 'for_review', 'completed') THEN 1 ELSE 0 END) AS active_count
            FROM methodology_tasks mt
            LEFT JOIN methodology_task_departments mtd ON mtd.methodology_task_id = mt.id
            WHERE mt.project_id = {$projectId}
            GROUP BY mt.id
        ");
        $updateParentTask = $conn->prepare('UPDATE methodology_tasks SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND project_id = ?');
        if ($parentRows && $updateParentTask) {
            while ($parent = $parentRows->fetch_assoc()) {
                $laneCount = (int) ($parent['lane_count'] ?? 0);
                $completedCount = (int) ($parent['completed_count'] ?? 0);
                $blockedCount = (int) ($parent['blocked_count'] ?? 0);
                $activeCount = (int) ($parent['active_count'] ?? 0);
                if ($laneCount <= 0) {
                    continue;
                }

                if ($laneCount > 0 && $completedCount >= $laneCount) {
                    $parentStatus = 'completed';
                } elseif ($blockedCount > 0) {
                    $parentStatus = 'blocked';
                } elseif ($activeCount > 0) {
                    $parentStatus = 'in_progress';
                } else {
                    $parentStatus = 'planned';
                }

                $parentId = (int) ($parent['id'] ?? 0);
                if ($parentId <= 0) {
                    continue;
                }
                $updateParentTask->bind_param('sii', $parentStatus, $parentId, $projectId);
                $updateParentTask->execute();
            }
            $updateParentTask->close();
        }
    }

    $phaseRows = $conn->query("SELECT id, status, start_date, end_date FROM methodology_phases WHERE project_id = {$projectId}");
    if (!$phaseRows) {
        return;
    }

    $today = new DateTimeImmutable('today');
    while ($phase = $phaseRows->fetch_assoc()) {
        $phaseId = (int) $phase['id'];
        $countsSql = $hasDepartmentProgress ? "
            SELECT
                COUNT(mtd.id) AS total,
                SUM(CASE WHEN mtd.status = 'completed' THEN 1 ELSE 0 END) AS done_count,
                SUM(CASE WHEN mtd.status = 'for_review' THEN 1 ELSE 0 END) AS approval_count,
                SUM(CASE WHEN mtd.status IN ('in_progress', 'for_revision', 'blocked', 'completed') THEN 1 ELSE 0 END) AS progress_count
            FROM methodology_tasks mt
            LEFT JOIN methodology_task_departments mtd ON mtd.methodology_task_id = mt.id
            WHERE mt.phase_id = {$phaseId}
        " : "
            SELECT
                COUNT(mt.id) AS total,
                SUM(CASE WHEN mt.status = 'completed' THEN 1 ELSE 0 END) AS done_count,
                SUM(CASE WHEN COALESCE(mt.status, 'planned') != 'completed' AND t.status IN ('for_review', 'qa_testing', 'qa_passed', 'architect_review') THEN 1 ELSE 0 END) AS approval_count,
                SUM(CASE WHEN mt.status IN ('in_progress', 'blocked') OR (COALESCE(mt.status, 'planned') != 'completed' AND t.status IN ('in_progress', 'sul_revision', 'qa_failed', 'overdue', 'blocked')) THEN 1 ELSE 0 END) AS progress_count
            FROM methodology_tasks mt
            LEFT JOIN tasks t ON t.id = mt.task_id
            WHERE mt.phase_id = {$phaseId}
        ";
        $counts = $conn->query($countsSql)->fetch_assoc();

        $status = projectWorkflowAutomaticPhaseStatus($phase, $counts, $today);

        if ($status !== (string) ($phase['status'] ?? '')) {
            $conn->query("UPDATE methodology_phases SET status = '{$status}', updated_at = CURRENT_TIMESTAMP WHERE id = {$phaseId}");
        }
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
