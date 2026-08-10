<?php
require_once '../../includes/auth.php';
require_once '../../includes/workflow.php';
require_once '../../includes/methodology-roadmap.php';

require_once '../../includes/project-estimation.php';
require_once '../../includes/project-compilation.php';

require_once '../../includes/project-workflow.php';
requireRole('expert');

$conn = getConnection();
$user = getCurrentUser();
methodologyRoadmapEnsureSchema($conn);
projectEstimationEnsureSchema($conn);

$availableDepartments = array_values(array_unique(array_merge(array_keys(projectWorkflowDepartments()), [
    'Frontend Developer',
    'Backend Developer',
    'Full Stack Developer',
    'QA Engineer',
    'UI/UX Designer',
    'DevOps Engineer',
    'Database Administrator',
])));

function featureRecommendationSignature(string $value): string
{
    $normalized = function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);
    $normalized = preg_replace('/[^a-z0-9]+/i', ' ', $normalized) ?? '';
    $normalized = trim(preg_replace('/\s+/', ' ', $normalized) ?? '');

    return substr($normalized, 0, 190);
}

function saveRecommendationFeedback(mysqli|PgsqlMysqliCompatConnection $conn, int $taskId, string $sourceType, int $sourceId, string $action, int $expertId): void
{
    if ($taskId <= 0 || $sourceId <= 0) {
        return;
    }

    if (!in_array($sourceType, ['history', 'manual'], true) || !in_array($action, ['accepted', 'rejected'], true)) {
        return;
    }

    $upsertSql = DB_DRIVER === 'pgsql'
        ? "INSERT INTO feature_estimation_recommendation_feedback (task_id, source_type, source_id, action, acted_by)
           VALUES (?, ?, ?, ?, ?)
           ON CONFLICT (task_id, source_type, source_id)
           DO UPDATE SET action = EXCLUDED.action, acted_by = EXCLUDED.acted_by, updated_at = CURRENT_TIMESTAMP"
        : "INSERT INTO feature_estimation_recommendation_feedback (task_id, source_type, source_id, action, acted_by)
           VALUES (?, ?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE action = VALUES(action), acted_by = VALUES(acted_by), updated_at = CURRENT_TIMESTAMP";
    $stmt = $conn->prepare($upsertSql);
    if (!$stmt) {
        return;
    }

    $stmt->bind_param('isisi', $taskId, $sourceType, $sourceId, $action, $expertId);
    $stmt->execute();
    $stmt->close();
}

function decodeRecommendationJson(?string $json): array
{
    $decoded = json_decode((string) $json, true);
    return is_array($decoded) ? $decoded : [];
}

function featureEstimationEmptyArchitectPlan(): array
{
    return [
        'phase_name' => '',
        'planned_start' => '',
        'planned_end' => '',
        'estimate_duration_days' => 0,
        'duration_basis_label' => '',
        'involvement_plan' => [],
        'component_plan' => [],
    ];
}

function featureEstimationDateValue(?string $value): ?DateTimeImmutable
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    return DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10)) ?: null;
}

function featureEstimationInclusiveDays(?string $start, ?string $end): int
{
    $startDate = featureEstimationDateValue($start);
    $endDate = featureEstimationDateValue($end);
    if (!$startDate || !$endDate || $endDate < $startDate) {
        return 0;
    }

    return (int) $startDate->diff($endDate)->days + 1;
}

function featureEstimationApplyDurationWindow(array &$plan): void
{
    $rows = $plan['_estimation_rows'] ?? [];
    if (!is_array($rows) || $rows === []) {
        return;
    }

    $validRows = array_values(array_filter($rows, static function (array $row): bool {
        return featureEstimationInclusiveDays($row['start_date'] ?? '', $row['end_date'] ?? '') > 0;
    }));
    if ($validRows === []) {
        return;
    }

    $executionRows = array_values(array_filter($validRows, static function (array $row): bool {
        $phaseOrder = (int) ($row['phase_order'] ?? 0);
        return in_array($phaseOrder, [2, 3, 4], true);
    }));
    $basisRows = $executionRows !== [] ? $executionRows : $validRows;
    $usesExecutionWindow = $executionRows !== [];

    usort($basisRows, static function (array $a, array $b): int {
        return [
            (int) ($a['phase_order'] ?? 0),
            (string) ($a['start_date'] ?? ''),
            (int) ($a['id'] ?? 0),
        ] <=> [
            (int) ($b['phase_order'] ?? 0),
            (string) ($b['start_date'] ?? ''),
            (int) ($b['id'] ?? 0),
        ];
    });

    $firstStart = null;
    $actualLastEnd = null;
    $allocatedDays = 0;
    $maxPeerCount = 1;
    $phaseNames = [];

    foreach ($basisRows as $row) {
        $startDate = featureEstimationDateValue($row['start_date'] ?? null);
        $endDate = featureEstimationDateValue($row['end_date'] ?? null);
        if (!$startDate || !$endDate || $endDate < $startDate) {
            continue;
        }

        $firstStart = $firstStart === null || $startDate < $firstStart ? $startDate : $firstStart;
        $actualLastEnd = $actualLastEnd === null || $endDate > $actualLastEnd ? $endDate : $actualLastEnd;
        $rowDays = (int) $startDate->diff($endDate)->days + 1;
        $peerCount = max(1, (int) ($row['peer_count'] ?? 1));
        $maxPeerCount = max($maxPeerCount, $peerCount);
        $allocatedDays += max(1, (int) ceil($rowDays / $peerCount));

        $phaseName = trim((string) ($row['phase_name'] ?? ''));
        if ($phaseName !== '') {
            $phaseNames[$phaseName] = true;
        }
    }

    if ($firstStart === null || $allocatedDays <= 0) {
        return;
    }

    $displayEnd = $actualLastEnd ?: $firstStart->modify('+' . ($allocatedDays - 1) . ' days');
    $windowStart = $firstStart->format('Y-m-d');
    $windowEnd = $displayEnd->format('Y-m-d');
    $plan['planned_start'] = $usesExecutionWindow || $plan['planned_start'] === '' ? $windowStart : $plan['planned_start'];
    $plan['planned_end'] = $usesExecutionWindow || $plan['planned_end'] === '' ? $windowEnd : $plan['planned_end'];
    $plan['estimate_duration_days'] = $allocatedDays;
    $label = implode(' + ', array_keys($phaseNames)) ?: 'Roadmap task schedule';
    $plan['duration_basis_label'] = $maxPeerCount > 1
        ? $label . ' (split across ' . $maxPeerCount . ' bulk features)'
        : $label . ' task duration';
}

function featureEstimationLoadArchitectPlanning(mysqli|PgsqlMysqliCompatConnection $conn, array $taskIds): array
{
    $taskIds = array_values(array_unique(array_filter(array_map('intval', $taskIds))));
    if ($taskIds === [] || !dbTableExists($conn, 'methodology_tasks')) {
        return [];
    }

    $plans = [];
    $methodologyTaskMap = [];
    $fallbackDepartments = [];
    $taskIdList = implode(',', $taskIds);
    $result = $conn->query("
        SELECT
            mt.id,
            mt.task_id,
            mt.component_name,
            mt.department,
            mt.start_date,
            mt.end_date,
            mt.developer_entity_key,
            mp.name AS phase_name,
            COALESCE(mp.sequence_no, mp.id) AS phase_order,
            (
                SELECT COUNT(DISTINCT mt_bulk.task_id)
                FROM methodology_tasks mt_bulk
                WHERE mt_bulk.project_id = mt.project_id
                  AND mt_bulk.phase_id = mt.phase_id
                  AND COALESCE(mt_bulk.developer_entity_key, '') = COALESCE(mt.developer_entity_key, '')
                  AND COALESCE(mt_bulk.developer_entity_key, '') <> ''
                  AND mt_bulk.task_id IS NOT NULL
                  AND mt_bulk.task_id > 0
            ) AS phase_entity_task_count,
            (
                SELECT COUNT(DISTINCT mt_same_window.task_id)
                FROM methodology_tasks mt_same_window
                WHERE mt_same_window.project_id = mt.project_id
                  AND mt_same_window.phase_id = mt.phase_id
                  AND mt_same_window.start_date = mt.start_date
                  AND mt_same_window.end_date = mt.end_date
                  AND mt_same_window.task_id IS NOT NULL
                  AND mt_same_window.task_id > 0
            ) AS phase_schedule_task_count
        FROM methodology_tasks mt
        LEFT JOIN methodology_phases mp ON mp.id = mt.phase_id
        WHERE mt.task_id IN ({$taskIdList})
        ORDER BY mt.task_id ASC, COALESCE(mp.sequence_no, mp.id) ASC, mt.id ASC
    ");

    if (!$result) {
        return [];
    }

    while ($row = $result->fetch_assoc()) {
        $taskId = (int) ($row['task_id'] ?? 0);
        $methodologyTaskId = (int) ($row['id'] ?? 0);
        if ($taskId <= 0 || $methodologyTaskId <= 0) {
            continue;
        }

        if (!isset($plans[$taskId])) {
            $plans[$taskId] = featureEstimationEmptyArchitectPlan();
            $plans[$taskId]['_department_counts'] = [];
            $plans[$taskId]['_department_days'] = [];
            $plans[$taskId]['_component_names'] = [];
            $plans[$taskId]['_estimation_rows'] = [];
        }

        $methodologyTaskMap[$methodologyTaskId] = $taskId;

        $phaseName = trim((string) ($row['phase_name'] ?? ''));
        if ($plans[$taskId]['phase_name'] === '' && $phaseName !== '') {
            $plans[$taskId]['phase_name'] = $phaseName;
        }

        $startDate = trim((string) ($row['start_date'] ?? ''));
        if ($plans[$taskId]['planned_start'] === '' && $startDate !== '') {
            $plans[$taskId]['planned_start'] = $startDate;
        }

        $endDate = trim((string) ($row['end_date'] ?? ''));
        if ($endDate !== '') {
            $plans[$taskId]['planned_end'] = $endDate;
        }

        $plans[$taskId]['_estimation_rows'][] = [
            'id' => $methodologyTaskId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'phase_name' => $phaseName,
            'phase_order' => (int) ($row['phase_order'] ?? 0),
            'peer_count' => max(1, (int) ($row['phase_entity_task_count'] ?? 1), (int) ($row['phase_schedule_task_count'] ?? 1)),
        ];
        $plans[$taskId]['_methodology_row_days'][$methodologyTaskId] = max(1, (int) ceil(featureEstimationInclusiveDays($startDate, $endDate) / max(1, (int) ($row['phase_entity_task_count'] ?? 1), (int) ($row['phase_schedule_task_count'] ?? 1))));

        $componentName = trim((string) ($row['component_name'] ?? ''));
        if ($componentName !== '') {
            $plans[$taskId]['_component_names'][$componentName] = true;
        }

        $department = projectWorkflowDepartmentLabel((string) ($row['department'] ?? ''));
        if ($department !== '') {
            $fallbackDepartments[$methodologyTaskId][$department] = 1;
        }
    }

    if ($methodologyTaskMap !== [] && dbTableExists($conn, 'methodology_task_departments')) {
        $requirementResult = $conn->query("
            SELECT methodology_task_id, department, required_count
            FROM methodology_task_departments
            WHERE methodology_task_id IN (" . implode(',', array_keys($methodologyTaskMap)) . ")
            ORDER BY id ASC
        ");

        if ($requirementResult) {
            while ($requirement = $requirementResult->fetch_assoc()) {
                $methodologyTaskId = (int) ($requirement['methodology_task_id'] ?? 0);
                $taskId = $methodologyTaskMap[$methodologyTaskId] ?? 0;
                $department = projectWorkflowDepartmentLabel((string) ($requirement['department'] ?? ''));
                $requiredCount = max(0, (int) ($requirement['required_count'] ?? 0));
                if ($taskId <= 0 || $department === '' || $requiredCount <= 0) {
                    continue;
                }
                $plans[$taskId]['_department_counts'][$department] = ($plans[$taskId]['_department_counts'][$department] ?? 0) + $requiredCount;
                $plans[$taskId]['_department_days'][$department] = ($plans[$taskId]['_department_days'][$department] ?? 0) + (int) ($plans[$taskId]['_methodology_row_days'][$methodologyTaskId] ?? 1);
            }
        }
    }

    foreach ($methodologyTaskMap as $methodologyTaskId => $taskId) {
        if (!empty($plans[$taskId]['_department_counts'])) {
            continue;
        }
        foreach ($fallbackDepartments[$methodologyTaskId] ?? [] as $department => $requiredCount) {
            $plans[$taskId]['_department_counts'][$department] = ($plans[$taskId]['_department_counts'][$department] ?? 0) + $requiredCount;
            $plans[$taskId]['_department_days'][$department] = ($plans[$taskId]['_department_days'][$department] ?? 0) + (int) ($plans[$taskId]['_methodology_row_days'][$methodologyTaskId] ?? 1);
        }
    }

    foreach ($plans as $taskId => $plan) {
        featureEstimationApplyDurationWindow($plans[$taskId]);

        $departmentCounts = $plan['_department_counts'] ?? [];
        $departmentDays = $plan['_department_days'] ?? [];
        $totalDays = array_sum($departmentDays);
        foreach ($departmentCounts as $department => $requiredCount) {
            $plans[$taskId]['involvement_plan'][] = [
                'role' => $department,
                'percentage' => $totalDays > 0 ? (int) round(((float) ($departmentDays[$department] ?? 0) / $totalDays) * 100) : 0,
                'required_count' => (int) $requiredCount,
            ];
        }

        foreach (array_keys($plan['_component_names'] ?? []) as $componentName) {
            $plans[$taskId]['component_plan'][] = ['name' => $componentName];
        }

        unset($plans[$taskId]['_department_counts'], $plans[$taskId]['_department_days'], $plans[$taskId]['_methodology_row_days'], $plans[$taskId]['_component_names'], $plans[$taskId]['_estimation_rows']);
    }

    return $plans;
}

// Check if phase_name column exists in feature_estimations
$hasPhaseName = dbColumnExists($conn, 'feature_estimations', 'phase_name');
if (!$hasPhaseName) {
    try {
        $conn->query("ALTER TABLE feature_estimations ADD COLUMN phase_name VARCHAR(150) NULL");
        $hasPhaseName = dbColumnExists($conn, 'feature_estimations', 'phase_name');
    } catch (Throwable $e) {
        // Leave $hasPhaseName as false
    }
}

$hasInvolvementPlan = dbColumnExists($conn, 'feature_estimations', 'involvement_plan_json');
if (!$hasInvolvementPlan) {
    try {
        $conn->query("ALTER TABLE feature_estimations ADD COLUMN involvement_plan_json " . (DB_DRIVER === 'pgsql' ? "TEXT" : "LONGTEXT") . " NULL");
        $hasInvolvementPlan = dbColumnExists($conn, 'feature_estimations', 'involvement_plan_json');
    } catch (Throwable $e) {
        // Leave $hasInvolvementPlan as false
    }
}

$hasComponentPlan = dbColumnExists($conn, 'feature_estimations', 'component_plan_json');
if (!$hasComponentPlan) {
    try {
        $conn->query("ALTER TABLE feature_estimations ADD COLUMN component_plan_json " . (DB_DRIVER === 'pgsql' ? "TEXT" : "LONGTEXT") . " NULL");
        $hasComponentPlan = dbColumnExists($conn, 'feature_estimations', 'component_plan_json');
    } catch (Throwable $e) {
        // Leave $hasComponentPlan as false
    }
}

$hasManualRecommendationTable = dbTableExists($conn, 'feature_estimation_manual_recommendations');
if (!$hasManualRecommendationTable) {
    try {
        $manualRecommendationSql = DB_DRIVER === 'pgsql' ? "
            CREATE TABLE feature_estimation_manual_recommendations (
                id INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                feature_signature VARCHAR(190) NOT NULL,
                feature_title VARCHAR(255) NOT NULL,
                recommendation_name VARCHAR(255) NOT NULL,
                source_project_id INT NULL,
                source_task_id INT NULL,
                phase_name VARCHAR(150) NULL,
                expectation_description TEXT,
                min_days INT NOT NULL,
                likely_days INT NOT NULL,
                max_days INT NOT NULL,
                involvement_plan_json TEXT NULL,
                component_plan_json TEXT NULL,
                created_by INT NOT NULL,
                updated_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (created_by, feature_signature, recommendation_name),
                CONSTRAINT fk_manual_recommendation_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_manual_recommendation_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_manual_recommendation_project FOREIGN KEY (source_project_id) REFERENCES projects(id) ON DELETE SET NULL,
                CONSTRAINT fk_manual_recommendation_task FOREIGN KEY (source_task_id) REFERENCES tasks(id) ON DELETE SET NULL
            )
        " : "
            CREATE TABLE feature_estimation_manual_recommendations (
                id INT PRIMARY KEY AUTO_INCREMENT,
                feature_signature VARCHAR(190) NOT NULL,
                feature_title VARCHAR(255) NOT NULL,
                recommendation_name VARCHAR(255) NOT NULL,
                source_project_id INT NULL,
                source_task_id INT NULL,
                phase_name VARCHAR(150) NULL,
                expectation_description LONGTEXT,
                min_days INT NOT NULL,
                likely_days INT NOT NULL,
                max_days INT NOT NULL,
                involvement_plan_json LONGTEXT NULL,
                component_plan_json LONGTEXT NULL,
                created_by INT NOT NULL,
                updated_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_manual_feature_recommendation (created_by, feature_signature, recommendation_name),
                KEY idx_manual_feature_signature (feature_signature),
                CONSTRAINT fk_manual_recommendation_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_manual_recommendation_updater FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
                CONSTRAINT fk_manual_recommendation_project FOREIGN KEY (source_project_id) REFERENCES projects(id) ON DELETE SET NULL,
                CONSTRAINT fk_manual_recommendation_task FOREIGN KEY (source_task_id) REFERENCES tasks(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        $conn->query($manualRecommendationSql);
        $hasManualRecommendationTable = dbTableExists($conn, 'feature_estimation_manual_recommendations');
    } catch (Throwable $e) {
        $hasManualRecommendationTable = false;
    }
}

$hasRecommendationFeedbackTable = dbTableExists($conn, 'feature_estimation_recommendation_feedback');
if (!$hasRecommendationFeedbackTable) {
    try {
        $feedbackSql = DB_DRIVER === 'pgsql' ? "
            CREATE TABLE feature_estimation_recommendation_feedback (
                id INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                task_id INT NOT NULL,
                source_type TEXT NOT NULL,
                source_id INT NOT NULL,
                action TEXT NOT NULL,
                acted_by INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (task_id, source_type, source_id),
                CONSTRAINT fk_recommendation_feedback_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
                CONSTRAINT fk_recommendation_feedback_actor FOREIGN KEY (acted_by) REFERENCES users(id) ON DELETE CASCADE
            )
        " : "
            CREATE TABLE feature_estimation_recommendation_feedback (
                id INT PRIMARY KEY AUTO_INCREMENT,
                task_id INT NOT NULL,
                source_type ENUM('history', 'manual') NOT NULL,
                source_id INT NOT NULL,
                action ENUM('accepted', 'rejected') NOT NULL,
                acted_by INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_task_recommendation_feedback (task_id, source_type, source_id),
                KEY idx_feedback_task (task_id),
                CONSTRAINT fk_recommendation_feedback_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
                CONSTRAINT fk_recommendation_feedback_actor FOREIGN KEY (acted_by) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        $conn->query($feedbackSql);
        $hasRecommendationFeedbackTable = dbTableExists($conn, 'feature_estimation_recommendation_feedback');
    } catch (Throwable $e) {
        $hasRecommendationFeedbackTable = false;
    }
}

// Check if sales approval columns exist in tasks (auto-create if missing)
$hasSalesApproval = dbColumnExists($conn, 'tasks', 'sales_approved');
if (!$hasSalesApproval) {
    try {
        $conn->query("ALTER TABLE tasks ADD COLUMN sales_approved " . (DB_DRIVER === 'pgsql' ? "SMALLINT" : "TINYINT(1)") . " DEFAULT 0");
        $conn->query("ALTER TABLE tasks ADD COLUMN sales_approved_by INT NULL");
        $conn->query("ALTER TABLE tasks ADD COLUMN sales_approved_at " . (DB_DRIVER === 'pgsql' ? "TIMESTAMP" : "DATETIME") . " NULL");
        $hasSalesApproval = dbColumnExists($conn, 'tasks', 'sales_approved');
    } catch (Throwable $e) {
        // Leave $hasSalesApproval as false; sales approval will be skipped
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_project_estimation'])) {
    $projectId = (int) ($_POST['project_id'] ?? 0);
    $context = $projectId > 0 ? projectEstimationFetchContext($conn, $projectId) : [];
    $project = $context['project'] ?? null;
    $allocations = $context['allocations'] ?? [];
    $technologies = $context['technologies'] ?? [];
    $postedAllocations = is_array($_POST['allocations'] ?? null) ? $_POST['allocations'] : [];

    $assignedCheck = $projectId > 0
        ? $conn->query("SELECT t.id FROM tasks t JOIN projects p ON p.id = t.project_id WHERE t.project_id = {$projectId} AND t.assigned_to = " . (int) $user['id'] . " AND p.status != 'cancelled' LIMIT 1")->fetch_assoc()
        : null;

    if (!$project || !$assignedCheck || (string) ($project['status'] ?? '') === 'cancelled') {
        $_SESSION['error'] = 'Please select one of your assigned projects before submitting a project estimation.';
        header('Location: feature-estimation.php');
        exit();
    }
    if (
        trim((string) ($project['name'] ?? '')) === ''
        || trim((string) ($project['client_name'] ?? '')) === ''
        || trim((string) ($project['timeline_start'] ?? '')) === ''
        || trim((string) ($project['timeline_end'] ?? '')) === ''
        || (float) ($project['sales_budget'] ?? 0) <= 0
    ) {
        $_SESSION['error'] = 'Project overview, timeline, and client budget must be complete before the final submission to Sales.';
        header('Location: feature-estimation.php?project_estimation_id=' . $projectId);
        exit();
    }

    if (empty($technologies)) {
        $_SESSION['error'] = 'The technology stack must be completed before the final submission to Sales.';
        header('Location: feature-estimation.php?project_estimation_id=' . $projectId);
        exit();
    }
    if (empty($allocations) || empty($postedAllocations)) {
        $_SESSION['error'] = 'Developer allocation and cost details must be completed before the final submission to Sales.';
        header('Location: feature-estimation.php?project_estimation_id=' . $projectId);
        exit();
    }
    $featureCompletion = $conn->query("
        SELECT COUNT(DISTINCT t.id) AS total_features,
               COUNT(DISTINCT CASE
                   WHEN fe.status = 'completed'
                    AND TRIM(COALESCE(fe.expectation_description, '')) <> ''
                    AND fe.min_days >= 1
                    AND fe.likely_days >= fe.min_days
                    AND fe.max_days >= fe.likely_days
                   THEN t.id ELSE NULL END) AS completed_features
        FROM tasks t
        LEFT JOIN feature_estimations fe
          ON fe.task_id = t.id AND fe.expert_id = " . (int) $user['id'] . "
        WHERE t.project_id = {$projectId}
          AND t.assigned_to = " . (int) $user['id'] . "
          AND t.task_type = 'feature'
          AND t.status IN ('todo', 'in_review')
    ")->fetch_assoc() ?: [];
    $requiredFeatureCount = (int) ($featureCompletion['total_features'] ?? 0);
    $completedFeatureCount = (int) ($featureCompletion['completed_features'] ?? 0);
    if ($requiredFeatureCount <= 0 || $completedFeatureCount !== $requiredFeatureCount) {
        $_SESSION['error'] = 'Every assigned feature must have an expectation and valid Minimum, Likely, and Maximum estimates before the final submission to Sales.';
        header('Location: feature-estimation.php?project_estimation_id=' . $projectId);
        exit();
    }

    $durationMonths = projectEstimationDurationMonths($conn, $project);
    $techMonthlyCost = array_sum(array_map(static fn(array $row): float => (float) ($row['estimated_cost'] ?? 0), $technologies));
    $complexity = projectEstimationComplexity(
        $durationMonths,
        array_sum(array_map(static fn(array $row): int => (int) ($row['required_count'] ?? 0), $allocations)),
        count($technologies)
    );
    $additionalCosts = max(0.0, (float) ($_POST['additional_costs'] ?? 0));

    if (projectEstimationSave($conn, $projectId, (int) $user['id'], $allocations, $durationMonths, $techMonthlyCost, $additionalCosts, $complexity, $postedAllocations)) {
        $salesReset = $hasSalesApproval ? ', sales_approved = 0, sales_approved_by = NULL, sales_approved_at = NULL' : '';
        $conn->query("UPDATE tasks SET status = 'in_review'{$salesReset} WHERE project_id = {$projectId} AND assigned_to = " . (int) $user['id'] . " AND task_type = 'feature'");
        projectCompilationRecord($conn, $projectId, (int) $user['id'], 'estimates');
        workflowNotifyRoles(
            $conn,
            ['sales'],
            'Project Estimation Ready for Review',
            'An Expert Developer submitted a project cost estimation for Sales review.',
            BASE_URL . 'modules/sales/estimation-approval.php',
            $projectId,
            'approval',
            'projects'
        );
        logActivity((int) $user['id'], 'submit_project_estimation', 'projects', $projectId, null, $_POST);
        $_SESSION['success'] = 'Project estimation submitted to Sales for review.';
    } else {
        $_SESSION['error'] = 'Failed to submit project estimation.';
    }

    header('Location: feature-estimation.php?project_estimation_id=' . $projectId);
    exit();
}

// Get tasks assigned to this expert that are pending estimation (status = 'todo')
$tasks = $conn->query("
    SELECT t.*, p.name as project_name, p.id as project_id,
           p.timeline_start, p.timeline_end
    FROM tasks t
    JOIN projects p ON t.project_id = p.id
    WHERE t.assigned_to = {$user['id']} 
      AND t.task_type = 'feature'
      AND t.status IN ('todo', 'in_review')
      AND p.status != 'cancelled'
    ORDER BY 
        CASE t.status WHEN 'todo' THEN 1 ELSE 2 END,
        t.deadline ASC
");

// Get projects for project setup (show all active projects)
$expertProjectsResult = $conn->query("
    SELECT p.id, p.name, p.timeline_start, p.timeline_end
    FROM projects p
    WHERE p.status NOT IN ('completed', 'cancelled')
    ORDER BY p.name ASC
");

$expertProjects = [];
while ($row = $expertProjectsResult->fetch_assoc()) {
    $durationWindow = projectEstimationDurationWindow($conn, $row);
    $row['estimation_window_start'] = $durationWindow['start'] ?? ($row['timeline_start'] ?? '');
    $row['estimation_window_end'] = $durationWindow['end'] ?? ($row['timeline_end'] ?? '');
    $row['estimation_window_label'] = $durationWindow['label'] ?? 'Project timeline';
    $expertProjects[] = $row;
}

// Roadmap phases are now controlled by the Architect methodology step.
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['project_setup'])) {
    $projectId = (int) ($_POST['project_id'] ?? 0);
    if ($projectId > 0) {
        $_SESSION['error'] = "Project phases are managed in the Architect roadmap step. Open the roadmap to add expert staffing per task.";
    } else {
        $_SESSION['error'] = "Please select a project roadmap to review.";
    }

    header('Location: feature-estimation.php');
    exit();
}

// Save all feature estimates from the consolidated progress table.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_save_feature_estimations'])) {
    $projectId = max(0, (int) ($_POST['project_id'] ?? 0));
    $postedEstimates = is_array($_POST['feature_estimates'] ?? null) ? $_POST['feature_estimates'] : [];
    $taskIds = array_values(array_unique(array_filter(array_map('intval', array_keys($postedEstimates)))));
    $redirectUrl = 'feature-estimation.php' . ($projectId > 0 ? '?project_estimation_id=' . $projectId : '');

    try {
        if ($projectId <= 0 || empty($taskIds)) {
            throw new RuntimeException('No feature estimates were provided.');
        }

        $assignedTaskResult = $conn->query("
            SELECT id
            FROM tasks t
            JOIN projects p ON p.id = t.project_id
            WHERE t.project_id = {$projectId}
              AND t.assigned_to = " . (int) $user['id'] . "
              AND t.task_type = 'feature'
              AND t.status = 'todo'
              AND p.status != 'cancelled'
        ");
        $requiredTaskIds = [];
        if ($assignedTaskResult) {
            while ($assignedTask = $assignedTaskResult->fetch_assoc()) {
                $requiredTaskIds[] = (int) $assignedTask['id'];
            }
        }
        sort($requiredTaskIds);
        $submittedTaskIds = $taskIds;
        sort($submittedTaskIds);
        if ($requiredTaskIds !== $submittedTaskIds) {
            throw new RuntimeException('All assigned feature tasks must be completed and included in the final submission.');
        }

        $taskResult = $conn->query("
            SELECT t.id, t.project_id, t.title, p.timeline_start, p.timeline_end
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            WHERE t.id IN (" . implode(',', $taskIds) . ")
              AND t.project_id = {$projectId}
              AND t.assigned_to = " . (int) $user['id'] . "
              AND t.task_type = 'feature'
              AND p.status != 'cancelled'
        ");
        $taskLookup = [];
        if ($taskResult) {
            while ($taskRow = $taskResult->fetch_assoc()) {
                $taskLookup[(int) $taskRow['id']] = $taskRow;
            }
        }
        if (count($taskLookup) !== count($taskIds)) {
            throw new RuntimeException('One or more feature rows are no longer assigned to you. Refresh and try again.');
        }

        $architectPlans = featureEstimationLoadArchitectPlanning($conn, $taskIds);
        $conn->begin_transaction();
        $savedCount = 0;

        foreach ($taskIds as $taskId) {
            $taskInfo = $taskLookup[$taskId];
            $estimateRow = is_array($postedEstimates[$taskId] ?? null) ? $postedEstimates[$taskId] : [];
            $expectation = trim((string) ($estimateRow['expectation'] ?? ''));
            $minDays = max(0, (int) ($estimateRow['min_days'] ?? 0));
            $likelyDays = max(0, (int) ($estimateRow['likely_days'] ?? 0));
            $maxDays = max(0, (int) ($estimateRow['max_days'] ?? 0));

            if ($expectation === '' || $minDays < 1 || $likelyDays < 1 || $maxDays < 1) {
                throw new RuntimeException('Complete the expectation and all three day estimates for every feature before saving.');
            }
            if ($minDays > $likelyDays || $likelyDays > $maxDays) {
                throw new RuntimeException('Every feature must follow Minimum <= Most Likely <= Maximum.');
            }

            $architectPlan = $architectPlans[$taskId] ?? featureEstimationEmptyArchitectPlan();
            $durationWindow = projectEstimationDurationWindow($conn, [
                'id' => $projectId,
                'timeline_start' => $taskInfo['timeline_start'] ?? '',
                'timeline_end' => $taskInfo['timeline_end'] ?? '',
            ]);
            $phaseName = trim((string) ($architectPlan['phase_name'] ?? '')) ?: null;
            $plannedStart = trim((string) ($architectPlan['planned_start'] ?? ''));
            $plannedEnd = trim((string) ($architectPlan['planned_end'] ?? ''));
            if ($plannedStart === '') {
                $plannedStart = trim((string) ($durationWindow['start'] ?? ''));
            }
            if ($plannedEnd === '') {
                $plannedEnd = trim((string) ($durationWindow['end'] ?? ''));
            }

            $plannedStartDate = DateTimeImmutable::createFromFormat('Y-m-d', $plannedStart);
            $plannedEndDate = DateTimeImmutable::createFromFormat('Y-m-d', $plannedEnd);
            if (!$plannedStartDate || !$plannedEndDate || $plannedEndDate < $plannedStartDate) {
                throw new RuntimeException('A valid Architect-planned schedule is required for ' . (string) $taskInfo['title'] . '.');
            }
            $plannedWindowDays = max(1, (int) $plannedStartDate->diff($plannedEndDate)->days + 1);
            if ($maxDays > $plannedWindowDays) {
                throw new RuntimeException(
                    (string) $taskInfo['title'] . ' cannot exceed its planned window of ' . $plannedWindowDays . ' day(s).'
                );
            }

            $normalizedInvolvement = [];
            foreach ((array) ($architectPlan['involvement_plan'] ?? []) as $entry) {
                $role = trim((string) ($entry['role'] ?? ''));
                $percentage = max(0, min(100, (int) ($entry['percentage'] ?? 0)));
                if ($role === '' && $percentage <= 0) {
                    continue;
                }
                $normalizedInvolvement[] = [
                    'role' => $role,
                    'percentage' => $percentage,
                    'required_count' => max(0, (int) ($entry['required_count'] ?? 0)),
                ];
            }
            $normalizedComponents = [];
            foreach ((array) ($architectPlan['component_plan'] ?? []) as $entry) {
                $component = trim((string) ($entry['name'] ?? ''));
                if ($component !== '') {
                    $normalizedComponents[] = ['name' => $component];
                }
            }
            $involvementPlanJson = json_encode($normalizedInvolvement, JSON_UNESCAPED_UNICODE);
            $componentPlanJson = json_encode($normalizedComponents, JSON_UNESCAPED_UNICODE);

            $existingStmt = $conn->prepare("SELECT id FROM feature_estimations WHERE task_id = ? AND expert_id = ? LIMIT 1");
            $expertId = (int) $user['id'];
            $existingStmt->bind_param('ii', $taskId, $expertId);
            $existingStmt->execute();
            $existingEstimate = $existingStmt->get_result()->fetch_assoc();
            $existingStmt->close();

            if ($existingEstimate) {
                $updateParts = ['min_days=?', 'likely_days=?', 'max_days=?'];
                $types = 'iii';
                $params = [$minDays, $likelyDays, $maxDays];
                if ($hasPhaseName) {
                    $updateParts[] = 'phase_name=?';
                    $types .= 's';
                    $params[] = $phaseName;
                }
                if ($hasInvolvementPlan) {
                    $updateParts[] = 'involvement_plan_json=?';
                    $types .= 's';
                    $params[] = $involvementPlanJson;
                }
                if ($hasComponentPlan) {
                    $updateParts[] = 'component_plan_json=?';
                    $types .= 's';
                    $params[] = $componentPlanJson;
                }
                $updateParts[] = 'expectation_description=?';
                $types .= 'sii';
                $params[] = $expectation;
                $params[] = $taskId;
                $params[] = $expertId;
                $saveStmt = $conn->prepare(
                    "UPDATE feature_estimations SET " . implode(', ', $updateParts) . ", status='completed', submitted_at=NOW() WHERE task_id=? AND expert_id=?"
                );
                $saveStmt->bind_param($types, ...$params);
            } else {
                $columns = ['task_id', 'expert_id', 'project_id', 'min_days', 'likely_days', 'max_days'];
                $placeholders = ['?', '?', '?', '?', '?', '?'];
                $types = 'iiiiii';
                $params = [$taskId, $expertId, $projectId, $minDays, $likelyDays, $maxDays];
                if ($hasPhaseName) {
                    $columns[] = 'phase_name';
                    $placeholders[] = '?';
                    $types .= 's';
                    $params[] = $phaseName;
                }
                if ($hasInvolvementPlan) {
                    $columns[] = 'involvement_plan_json';
                    $placeholders[] = '?';
                    $types .= 's';
                    $params[] = $involvementPlanJson;
                }
                if ($hasComponentPlan) {
                    $columns[] = 'component_plan_json';
                    $placeholders[] = '?';
                    $types .= 's';
                    $params[] = $componentPlanJson;
                }
                $columns[] = 'expectation_description';
                $placeholders[] = '?';
                $types .= 's';
                $params[] = $expectation;
                $saveStmt = $conn->prepare(
                    "INSERT INTO feature_estimations (" . implode(', ', $columns) . ", status, submitted_at) VALUES (" . implode(', ', $placeholders) . ", 'completed', NOW())"
                );
                $saveStmt->bind_param($types, ...$params);
            }

            if (!$saveStmt->execute()) {
                throw new RuntimeException('Failed to save ' . (string) $taskInfo['title'] . '.');
            }
            $saveStmt->close();

            $salesColumns = $hasSalesApproval ? ', sales_approved = 0, sales_approved_by = NULL, sales_approved_at = NULL' : '';
            $taskUpdate = $conn->prepare(
                "UPDATE tasks SET status='todo', started_at=?, deadline=?, estimated_days_min=?, estimated_days_likely=?, estimated_days_max=?{$salesColumns} WHERE id=?"
            );
            $taskUpdate->bind_param('ssiiii', $plannedStart, $plannedEnd, $minDays, $likelyDays, $maxDays, $taskId);
            if (!$taskUpdate->execute()) {
                throw new RuntimeException('Failed to update the task schedule for ' . (string) $taskInfo['title'] . '.');
            }
            $taskUpdate->close();
            $savedCount++;
            logActivity($expertId, 'submit_estimation', 'tasks', $taskId, null, $estimateRow);
        }

        $conn->commit();
        $_SESSION['success'] = $savedCount . ' feature estimate' . ($savedCount === 1 ? '' : 's') . ' saved. Complete the project cost summary for the final submission to Sales.';
    } catch (Throwable $e) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {
            // Preserve the original validation or save error.
        }
        $_SESSION['error'] = $e->getMessage();
    }

    header('Location: ' . $redirectUrl);
    exit();
}

// Handle task estimation save (draft)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['task_id']) && !isset($_POST['submit_all'])) {
    $taskId = (int) ($_POST['task_id'] ?? 0);
    $minDays = max(0, (int) ($_POST['min_days'] ?? 0));
    $likelyDays = max(0, (int) ($_POST['likely_days'] ?? 0));
    $maxDays = max(0, (int) ($_POST['max_days'] ?? 0));
    $expectation = trim((string) ($_POST['expectation'] ?? ''));
    $phaseName = null;
    $plannedStart = !empty($_POST['planned_start']) ? $_POST['planned_start'] : null;
    $plannedEnd = !empty($_POST['planned_end']) ? $_POST['planned_end'] : null;
    $manualRecommendationName = trim((string) ($_POST['manual_recommendation_name'] ?? ''));
    $acceptedRecommendationType = trim((string) ($_POST['accepted_recommendation_type'] ?? ''));
    $acceptedRecommendationId = (int) ($_POST['accepted_recommendation_id'] ?? 0);
    $rejectedRecommendations = json_decode((string) ($_POST['rejected_recommendations_json'] ?? '[]'), true);
    $involvementPlan = json_decode((string) ($_POST['involvement_plan_json'] ?? '[]'), true);
    $componentPlan = json_decode((string) ($_POST['component_plan_json'] ?? '[]'), true);

    if ($expectation === '') {
        $_SESSION['error'] = "Please add an expectation description for the feature.";
        header('Location: feature-estimation.php');
        exit();
    }

    $taskStmt = $conn->prepare("
        SELECT t.project_id, t.title, p.timeline_start, p.timeline_end
        FROM tasks t
        JOIN projects p ON p.id = t.project_id
        WHERE t.id = ?
          AND p.status != 'cancelled'
        LIMIT 1
    ");
    $taskStmt->bind_param('i', $taskId);
    $taskStmt->execute();
    $taskInfo = $taskStmt->get_result()->fetch_assoc();
    $taskStmt->close();
    $projectId = (int) ($taskInfo['project_id'] ?? 0);
    $taskTitle = (string) ($taskInfo['title'] ?? '');

    if ($projectId <= 0 || $taskTitle === '') {
        $_SESSION['error'] = "We couldn't find that feature anymore. Please refresh and try again.";
        header('Location: feature-estimation.php');
        exit();
    }

    $architectPlan = featureEstimationLoadArchitectPlanning($conn, [$taskId])[$taskId] ?? featureEstimationEmptyArchitectPlan();
    $phaseName = trim((string) ($architectPlan['phase_name'] ?? '')) !== '' ? (string) $architectPlan['phase_name'] : null;
    $durationWindow = projectEstimationDurationWindow($conn, [
        'id' => $projectId,
        'timeline_start' => $taskInfo['timeline_start'] ?? '',
        'timeline_end' => $taskInfo['timeline_end'] ?? '',
    ]);
    $projectTimelineStart = trim((string) ($durationWindow['start'] ?? ''));
    $projectTimelineEnd = trim((string) ($durationWindow['end'] ?? ''));
    $architectPlannedStart = trim((string) ($architectPlan['planned_start'] ?? ''));
    $architectDeadline = trim((string) ($architectPlan['planned_end'] ?? ''));
    if ($architectDeadline === '') {
        $architectDeadline = $projectTimelineEnd;
    }
    if ($architectPlannedStart !== '') {
        $plannedStart = $architectPlannedStart;
    } elseif ($plannedStart === null) {
        $plannedStart = $projectTimelineStart !== '' ? $projectTimelineStart : null;
    }
    if ($architectDeadline !== '') {
        $plannedEnd = $architectDeadline;
    }

    if ($plannedStart === null || $plannedEnd === null) {
        $_SESSION['error'] = "Please select a planned start and end date for this feature.";
        header('Location: feature-estimation.php');
        exit();
    }

    if ($architectDeadline === '') {
        $_SESSION['error'] = "This project needs an Architect deadline before feature estimation can be saved.";
        header('Location: feature-estimation.php');
        exit();
    }

    $plannedStartDate = DateTimeImmutable::createFromFormat('Y-m-d', $plannedStart);
    $plannedEndDate = DateTimeImmutable::createFromFormat('Y-m-d', $plannedEnd);
    $architectDeadlineDate = DateTimeImmutable::createFromFormat('Y-m-d', $architectDeadline);

    if (!$plannedStartDate || !$plannedEndDate || !$architectDeadlineDate) {
        $_SESSION['error'] = "Please use valid feature dates.";
        header('Location: feature-estimation.php');
        exit();
    }

    if ($plannedEndDate < $plannedStartDate) {
        $_SESSION['error'] = "Feature end date must be the same as or later than the start date.";
        header('Location: feature-estimation.php');
        exit();
    }

    if ($plannedEndDate > $architectDeadlineDate) {
        $_SESSION['error'] = "Feature deadline cannot be later than the Architect deadline.";
        header('Location: feature-estimation.php');
        exit();
    }

    $deadlineDuration = max(1, (int) $plannedStartDate->diff($plannedEndDate)->days + 1);
    if ($minDays < 1 || $likelyDays < 1 || $maxDays < 1) {
        $_SESSION['error'] = 'Accept the Evortix feature estimate or enter all three day values before saving.';
        header('Location: feature-estimation.php?project_estimation_id=' . $projectId);
        exit();
    }
    if ($minDays > $likelyDays || $likelyDays > $maxDays) {
        $_SESSION['error'] = 'Feature estimates must follow Minimum <= Most Likely <= Maximum.';
        header('Location: feature-estimation.php?project_estimation_id=' . $projectId);
        exit();
    }
    if ($maxDays > $deadlineDuration) {
        $_SESSION['error'] = 'The maximum feature estimate cannot exceed its Architect-planned window of ' . $deadlineDuration . ' day(s).';
        header('Location: feature-estimation.php?project_estimation_id=' . $projectId);
        exit();
    }
    if (!empty($architectPlan['involvement_plan'])) {
        $involvementPlan = $architectPlan['involvement_plan'];
    }
    if (!empty($architectPlan['component_plan'])) {
        $componentPlan = $architectPlan['component_plan'];
    }

    $normalizedInvolvement = [];
    if (is_array($involvementPlan)) {
        foreach ($involvementPlan as $entry) {
            $role = trim((string) ($entry['role'] ?? ''));
            $percentage = (int) ($entry['percentage'] ?? 0);
            if ($role !== '' && !in_array($role, $availableDepartments, true)) {
                continue;
            }
            if ($role === '' && $percentage <= 0) {
                continue;
            }
            $normalizedInvolvement[] = [
                'role' => $role,
                'percentage' => max(0, min(100, $percentage)),
                'required_count' => max(0, (int) ($entry['required_count'] ?? 0)),
            ];
        }
    }

    $normalizedComponents = [];
    if (is_array($componentPlan)) {
        foreach ($componentPlan as $entry) {
            $component = trim((string) ($entry['name'] ?? ''));
            if ($component === '') {
                continue;
            }
            $normalizedComponents[] = [
                'name' => $component,
            ];
        }
    }

    $involvementPlanJson = json_encode($normalizedInvolvement, JSON_UNESCAPED_UNICODE);
    $componentPlanJson = json_encode($normalizedComponents, JSON_UNESCAPED_UNICODE);
    
    // Check if estimation exists
    $existing = $conn->query("SELECT id FROM feature_estimations WHERE task_id = $taskId AND expert_id = {$user['id']}");
    
    if($existing->num_rows > 0) {
        $updateParts = [
            "min_days=?",
            "likely_days=?",
            "max_days=?",
        ];
        $types = "iii";
        $params = [$minDays, $likelyDays, $maxDays];

        if ($hasPhaseName) {
            $updateParts[] = "phase_name=?";
            $types .= "s";
            $params[] = $phaseName;
        }

        if ($hasInvolvementPlan) {
            $updateParts[] = "involvement_plan_json=?";
            $types .= "s";
            $params[] = $involvementPlanJson;
        }

        if ($hasComponentPlan) {
            $updateParts[] = "component_plan_json=?";
            $types .= "s";
            $params[] = $componentPlanJson;
        }

        $updateParts[] = "expectation_description=?";
        $types .= "s";
        $params[] = $expectation;

        $types .= "ii";
        $params[] = (int) $taskId;
        $params[] = (int) $user['id'];

        $stmt = $conn->prepare("UPDATE feature_estimations SET " . implode(', ', $updateParts) . ", status='completed', submitted_at=NOW() WHERE task_id=? AND expert_id=?");
        $stmt->bind_param($types, ...$params);
    } else {
        $columns = ['task_id', 'expert_id', 'project_id', 'min_days', 'likely_days', 'max_days'];
        $placeholders = ['?', '?', '?', '?', '?', '?'];
        $types = "iiiiii";
        $params = [(int) $taskId, (int) $user['id'], (int) $projectId, $minDays, $likelyDays, $maxDays];

        if ($hasPhaseName) {
            $columns[] = 'phase_name';
            $placeholders[] = '?';
            $types .= "s";
            $params[] = $phaseName;
        }

        if ($hasInvolvementPlan) {
            $columns[] = 'involvement_plan_json';
            $placeholders[] = '?';
            $types .= "s";
            $params[] = $involvementPlanJson;
        }

        if ($hasComponentPlan) {
            $columns[] = 'component_plan_json';
            $placeholders[] = '?';
            $types .= "s";
            $params[] = $componentPlanJson;
        }

        $columns[] = 'expectation_description';
        $placeholders[] = '?';
        $types .= "s";
        $params[] = $expectation;

        $stmt = $conn->prepare("INSERT INTO feature_estimations (" . implode(', ', $columns) . ", status, submitted_at) VALUES (" . implode(', ', $placeholders) . ", 'completed', NOW())");
        $stmt->bind_param($types, ...$params);
    }
    
    if($stmt->execute()) {
        // Save draft estimations without sending to Sales yet
        $salesColumns = $hasSalesApproval ? ", sales_approved = 0, sales_approved_by = NULL, sales_approved_at = NULL" : "";
        $taskStmt = $conn->prepare("UPDATE tasks SET status = 'todo', started_at = ?, deadline = ?, estimated_days_min = ?, estimated_days_likely = ?, estimated_days_max = ?{$salesColumns} WHERE id = ?");
        $taskStmt->bind_param("ssiiii", $plannedStart, $plannedEnd, $minDays, $likelyDays, $maxDays, $taskId);
        $taskStmt->execute();
        
        if ($hasRecommendationFeedbackTable && is_array($rejectedRecommendations)) {
            foreach ($rejectedRecommendations as $rejectedRecommendation) {
                $sourceType = trim((string) ($rejectedRecommendation['source_type'] ?? ''));
                $sourceId = (int) ($rejectedRecommendation['source_id'] ?? 0);
                saveRecommendationFeedback($conn, $taskId, $sourceType, $sourceId, 'rejected', (int) $user['id']);
            }
        }

        if ($hasRecommendationFeedbackTable && $acceptedRecommendationId > 0) {
            saveRecommendationFeedback($conn, $taskId, $acceptedRecommendationType, $acceptedRecommendationId, 'accepted', (int) $user['id']);
        }

        if ($hasManualRecommendationTable && $manualRecommendationName !== '') {
            $featureSignature = featureRecommendationSignature($taskTitle);
            $templateSql = DB_DRIVER === 'pgsql' ? "
                INSERT INTO feature_estimation_manual_recommendations (
                    feature_signature,
                    feature_title,
                    recommendation_name,
                    source_project_id,
                    source_task_id,
                    phase_name,
                    expectation_description,
                    min_days,
                    likely_days,
                    max_days,
                    involvement_plan_json,
                    component_plan_json,
                    created_by,
                    updated_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT (created_by, feature_signature, recommendation_name)
                DO UPDATE SET
                    feature_title = EXCLUDED.feature_title,
                    source_project_id = EXCLUDED.source_project_id,
                    source_task_id = EXCLUDED.source_task_id,
                    phase_name = EXCLUDED.phase_name,
                    expectation_description = EXCLUDED.expectation_description,
                    min_days = EXCLUDED.min_days,
                    likely_days = EXCLUDED.likely_days,
                    max_days = EXCLUDED.max_days,
                    involvement_plan_json = EXCLUDED.involvement_plan_json,
                    component_plan_json = EXCLUDED.component_plan_json,
                    updated_by = EXCLUDED.updated_by,
                    updated_at = CURRENT_TIMESTAMP
            " : "
                INSERT INTO feature_estimation_manual_recommendations (
                    feature_signature,
                    feature_title,
                    recommendation_name,
                    source_project_id,
                    source_task_id,
                    phase_name,
                    expectation_description,
                    min_days,
                    likely_days,
                    max_days,
                    involvement_plan_json,
                    component_plan_json,
                    created_by,
                    updated_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    feature_title = VALUES(feature_title),
                    source_project_id = VALUES(source_project_id),
                    source_task_id = VALUES(source_task_id),
                    phase_name = VALUES(phase_name),
                    expectation_description = VALUES(expectation_description),
                    min_days = VALUES(min_days),
                    likely_days = VALUES(likely_days),
                    max_days = VALUES(max_days),
                    involvement_plan_json = VALUES(involvement_plan_json),
                    component_plan_json = VALUES(component_plan_json),
                    updated_by = VALUES(updated_by),
                    updated_at = CURRENT_TIMESTAMP
            ";
            $templateStmt = $conn->prepare($templateSql);

            if ($templateStmt) {
                $expertId = (int) $user['id'];
                $templateStmt->bind_param(
                    'sssiissiiissii',
                    $featureSignature,
                    $taskTitle,
                    $manualRecommendationName,
                    $projectId,
                    $taskId,
                    $phaseName,
                    $expectation,
                    $minDays,
                    $likelyDays,
                    $maxDays,
                    $involvementPlanJson,
                    $componentPlanJson,
                    $expertId,
                    $expertId
                );
                $templateStmt->execute();
                $templateStmt->close();
            }
        }

        logActivity($user['id'], 'submit_estimation', 'tasks', $taskId, null, $_POST);
        $_SESSION['feature_estimation_submitted'] = 'Feature estimation saved. Use the Project Cost Summary for the final submission to Sales.';
    } else {
        $_SESSION['error'] = "Failed to save estimation: " . $conn->error;
    }
    
    header('Location: feature-estimation.php?project_estimation_id=' . $projectId . '&feature_submitted=1');
    exit();
}

// Completed project estimates are sent to Sales from the normal estimate-save path.

$taskRows = [];
while ($row = $tasks->fetch_assoc()) {
    $taskRows[] = $row;
}

$roadmapPhasesByProject = [];
$featureCountByProject = [];
$roadmapProjectIds = array_values(array_unique(array_filter(array_map(static function (array $taskRow): int {
    return (int) ($taskRow['project_id'] ?? 0);
}, $taskRows))));
if (!empty($roadmapProjectIds)) {
    $featureCountResult = $conn->query("
        SELECT project_id, COUNT(*) AS feature_count
        FROM tasks
        WHERE task_type = 'feature' AND project_id IN (" . implode(',', $roadmapProjectIds) . ")
        GROUP BY project_id
    ");
    if ($featureCountResult) {
        while ($featureCountRow = $featureCountResult->fetch_assoc()) {
            $featureCountByProject[(int) ($featureCountRow['project_id'] ?? 0)] = max(1, (int) ($featureCountRow['feature_count'] ?? 1));
        }
    }

    $phaseResult = $conn->query("
        SELECT project_id, name
        FROM methodology_phases
        WHERE project_id IN (" . implode(',', $roadmapProjectIds) . ")
        ORDER BY start_date ASC, sequence_no ASC, id ASC
    ");
    if ($phaseResult) {
        while ($phaseRow = $phaseResult->fetch_assoc()) {
            $roadmapPhasesByProject[(int) $phaseRow['project_id']][] = (string) $phaseRow['name'];
        }
    }
}

$recommendationsByTask = [];
$taskFeedbackMap = [];
$taskIds = array_values(array_filter(array_map(static function (array $taskRow): int {
    return (int) ($taskRow['id'] ?? 0);
}, $taskRows)));
$architectPlanningByTask = featureEstimationLoadArchitectPlanning($conn, $taskIds);

if (!empty($taskIds) && $hasRecommendationFeedbackTable) {
    $feedbackSql = "
        SELECT task_id, source_type, source_id, action
        FROM feature_estimation_recommendation_feedback
        WHERE acted_by = {$user['id']} AND task_id IN (" . implode(',', $taskIds) . ")
    ";
    $feedbackResult = $conn->query($feedbackSql);
    if ($feedbackResult) {
        while ($feedbackRow = $feedbackResult->fetch_assoc()) {
            $taskFeedbackMap[(int) $feedbackRow['task_id']][$feedbackRow['source_type'] . ':' . (int) $feedbackRow['source_id']] = (string) $feedbackRow['action'];
        }
    }
}

$historyRecommendationsBySignature = [];
$historyRecommendationsResult = $conn->query("
    SELECT
        fe.id AS recommendation_id,
        fe.task_id,
        fe.project_id,
        fe.phase_name,
        fe.expectation_description,
        fe.min_days,
        fe.likely_days,
        fe.max_days,
        fe.involvement_plan_json,
        fe.component_plan_json,
        fe.updated_at,
        t.title AS task_title,
        p.name AS project_name,
        u.full_name AS expert_name
    FROM feature_estimations fe
    INNER JOIN tasks t ON t.id = fe.task_id
    INNER JOIN projects p ON p.id = fe.project_id
    LEFT JOIN users u ON u.id = fe.expert_id
    WHERE fe.status = 'completed' AND t.task_type = 'feature'
    ORDER BY fe.updated_at DESC, fe.id DESC
");

if ($historyRecommendationsResult) {
    while ($historyRow = $historyRecommendationsResult->fetch_assoc()) {
        $signature = featureRecommendationSignature((string) ($historyRow['task_title'] ?? ''));
        if ($signature === '') {
            continue;
        }

        $historyRecommendationsBySignature[$signature][] = [
            'source_type' => 'history',
            'source_id' => (int) $historyRow['recommendation_id'],
            'label' => (string) ($historyRow['task_title'] ?? 'Previous feature'),
            'subtitle' => trim((string) ($historyRow['project_name'] ?? '')) !== ''
                ? 'Used in ' . (string) $historyRow['project_name']
                : 'Saved from a previous project',
            'expert_name' => (string) ($historyRow['expert_name'] ?? ''),
            'updated_at' => (string) ($historyRow['updated_at'] ?? ''),
            'task_id' => (int) $historyRow['task_id'],
            'payload' => [
                'expectation' => (string) ($historyRow['expectation_description'] ?? ''),
                'phase_name' => (string) ($historyRow['phase_name'] ?? ''),
                'min_days' => (int) ($historyRow['min_days'] ?? 0),
                'likely_days' => (int) ($historyRow['likely_days'] ?? 0),
                'max_days' => (int) ($historyRow['max_days'] ?? 0),
                'involvement_plan' => decodeRecommendationJson($historyRow['involvement_plan_json'] ?? '[]'),
                'component_plan' => decodeRecommendationJson($historyRow['component_plan_json'] ?? '[]'),
            ],
        ];
    }
}

$manualRecommendationsBySignature = [];
if ($hasManualRecommendationTable) {
    $manualRecommendationsResult = $conn->query("
        SELECT r.*, p.name AS project_name
        FROM feature_estimation_manual_recommendations r
        LEFT JOIN projects p ON p.id = r.source_project_id
        WHERE r.created_by = {$user['id']}
        ORDER BY r.updated_at DESC, r.id DESC
    ");

    if ($manualRecommendationsResult) {
        while ($manualRow = $manualRecommendationsResult->fetch_assoc()) {
            $signature = (string) ($manualRow['feature_signature'] ?? '');
            if ($signature === '') {
                continue;
            }

            $manualRecommendationsBySignature[$signature][] = [
                'source_type' => 'manual',
                'source_id' => (int) $manualRow['id'],
                'label' => (string) ($manualRow['recommendation_name'] ?? 'Saved recommendation'),
                'subtitle' => trim((string) ($manualRow['project_name'] ?? '')) !== ''
                    ? 'Your saved version from ' . (string) $manualRow['project_name']
                    : 'Your saved manual recommendation',
                'expert_name' => (string) ($user['full_name'] ?? ''),
                'updated_at' => (string) ($manualRow['updated_at'] ?? ''),
                'task_id' => (int) ($manualRow['source_task_id'] ?? 0),
                'payload' => [
                    'expectation' => (string) ($manualRow['expectation_description'] ?? ''),
                    'phase_name' => (string) ($manualRow['phase_name'] ?? ''),
                    'min_days' => (int) ($manualRow['min_days'] ?? 0),
                    'likely_days' => (int) ($manualRow['likely_days'] ?? 0),
                    'max_days' => (int) ($manualRow['max_days'] ?? 0),
                    'involvement_plan' => decodeRecommendationJson($manualRow['involvement_plan_json'] ?? '[]'),
                    'component_plan' => decodeRecommendationJson($manualRow['component_plan_json'] ?? '[]'),
                ],
            ];
        }
    }
}

foreach ($taskRows as $taskRow) {
    $taskId = (int) ($taskRow['id'] ?? 0);
    $signature = featureRecommendationSignature((string) ($taskRow['title'] ?? ''));
    $taskRecommendations = [];

    foreach ($manualRecommendationsBySignature[$signature] ?? [] as $recommendation) {
        $feedbackKey = $recommendation['source_type'] . ':' . $recommendation['source_id'];
        if (($taskFeedbackMap[$taskId][$feedbackKey] ?? '') === 'rejected') {
            continue;
        }
        $taskRecommendations[] = $recommendation;
    }

    foreach ($historyRecommendationsBySignature[$signature] ?? [] as $recommendation) {
        if ((int) $recommendation['task_id'] === $taskId) {
            continue;
        }
        $feedbackKey = $recommendation['source_type'] . ':' . $recommendation['source_id'];
        if (($taskFeedbackMap[$taskId][$feedbackKey] ?? '') === 'rejected') {
            continue;
        }
        $taskRecommendations[] = $recommendation;
    }

    $recommendationsByTask[$taskId] = array_slice($taskRecommendations, 0, 4);
}

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<style>
:root {
    --theme-primary: #1f6392;
    --theme-dark: #184f73;
    --theme-light: #3a7ca3;
    --gray-50: #f8f9fa;
    --gray-100: #f1f3f5;
    --gray-200: #e9ecef;
    --gray-300: #dee2e6;
    --gray-400: #ced4da;
    --gray-500: #adb5bd;
    --gray-600: #6c757d;
    --gray-700: #495057;
    --gray-800: #343a40;
    --gray-900: #212529;
    --shadow-sm: 0 1px 3px rgba(0,0,0,0.04), 0 1px 2px rgba(0,0,0,0.03);
    --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03);
}

/* Page Header */
.expert-estimation-page {
    max-width: 1540px;
    margin: 0 auto;
}

.page-header-modern {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    border-radius: 16px;
    padding: 1.4rem 1.6rem;
    margin-bottom: 1.25rem;
    position: relative;
    overflow: hidden;
    box-shadow: 0 8px 24px rgba(24, 79, 115, 0.14);
}

.page-header-modern::before {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 220px;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1));
    pointer-events: none;
}

.page-header-modern h2 {
    font-size: 1.5rem;
    font-weight: 600;
    margin-bottom: 0.35rem;
    color: white;
}

.page-header-modern h2 i {
    color: white;
}

.page-header-modern p {
    opacity: 0.9;
    margin-bottom: 0;
    font-size: 0.9rem;
    color: rgba(255, 255, 255, 0.9);
}

.page-header-content {
    position: relative;
    z-index: 1;
    min-width: 0;
}

.expert-workspace-switcher {
    align-items: flex-end;
    border-bottom: 1px solid var(--gray-300);
    display: flex;
    gap: 0.25rem;
    margin-bottom: 1.25rem;
    overflow-x: auto;
    overflow-y: hidden;
    scrollbar-width: none;
}

.expert-workspace-switcher::-webkit-scrollbar {
    display: none;
}

.expert-workspace-tab {
    align-items: center;
    background: transparent;
    border: 0;
    border-bottom: 3px solid transparent;
    color: var(--gray-600);
    display: inline-flex;
    font-size: 0.86rem;
    font-weight: 600;
    gap: 0.5rem;
    margin-bottom: -1px;
    padding: 0.75rem 1rem;
    white-space: nowrap;
}

.expert-workspace-tab:hover,
.expert-workspace-tab:focus-visible {
    color: var(--theme-primary);
    outline: none;
}

.expert-workspace-tab.is-active {
    border-bottom-color: var(--theme-primary);
    color: var(--theme-primary);
}

.expert-workspace-panel[hidden] {
    display: none !important;
}

/* Timeline Section */
    .timeline-section {
        background: white;
        border-radius: 14px;
        border: 1px solid var(--gray-200);
        box-shadow: var(--shadow-sm);
        margin-bottom: 1.25rem;
    }

.timeline-header {
    padding: 1rem 1.5rem;
    border-bottom: 1px solid var(--gray-200);
}

.timeline-header h5 {
    margin: 0 0 0.25rem 0;
    font-size: 1rem;
    font-weight: 600;
    color: var(--gray-800);
}

.gantt-container {
    padding: 1.5rem;
}

.gantt-container canvas {
    width: 100% !important;
    height: 320px !important;
}

/* Phase Management Section */
.phase-management-card {
    background: white;
    border-radius: 14px;
    border: 1px solid var(--gray-200);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    margin-bottom: 1.25rem;
}

.phase-header {
    padding: 1rem 1.5rem;
    background: var(--gray-50);
    border-bottom: 1px solid var(--gray-200);
}

.phase-header h5 {
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
    color: var(--gray-800);
}

.phase-header h5 i {
    color: var(--theme-primary);
    margin-right: 0.5rem;
}

.project-selector {
    padding: 1rem 1.5rem;
    border-bottom: 1px solid var(--gray-200);
}

.feature-project-action {
    align-items: center;
    color: #fff;
    display: flex;
    height: 100%;
    justify-content: center;
    min-height: 42px;
    transition: background 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
    width: 100%;
}

.feature-project-action:visited {
    color: #fff;
}

.feature-project-action:hover,
.feature-project-action:focus-visible {
    background: var(--theme-dark);
    box-shadow: 0 6px 14px rgba(24, 79, 115, 0.24);
    color: #fff;
    transform: translateY(-2px);
}

.feature-project-action[hidden] {
    display: none;
}

.phase-list {
    padding: 1.5rem;
}

.phase-item {
    display: flex;
    gap: 1rem;
    margin-bottom: 1rem;
    padding: 1rem;
    background: var(--gray-50);
    border-radius: 12px;
    align-items: center;
    flex-wrap: wrap;
}

.phase-name {
    flex: 2;
    min-width: 150px;
}

.phase-name input {
    width: 100%;
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--gray-300);
    border-radius: 8px;
    font-size: 0.85rem;
}

.phase-dates {
    flex: 1;
    min-width: 120px;
}

.phase-dates input {
    width: 100%;
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--gray-300);
    border-radius: 8px;
    font-size: 0.85rem;
}

.phase-remove {
    flex-shrink: 0;
}

.btn-remove-phase {
    background: #fee2e2;
    border: none;
    color: #dc2626;
    padding: 0.4rem 0.8rem;
    border-radius: 8px;
    font-size: 0.7rem;
    cursor: pointer;
}

.btn-remove-phase:hover {
    background: #dc2626;
    color: white;
}

.btn-add-phase {
    background: transparent;
    border: 1px dashed var(--gray-400);
    color: var(--gray-600);
    padding: 0.5rem 1rem;
    border-radius: 9px;
    font-size: 0.8rem;
    margin-top: 1rem;
    cursor: pointer;
    width: 100%;
}

.btn-add-phase:hover {
    border-color: var(--theme-primary);
    color: var(--theme-primary);
}

.btn-save-phases {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    color: white;
    border: none;
    padding: 0.5rem 1.5rem;
    border-radius: 9px;
    font-size: 0.8rem;
    font-weight: 500;
    cursor: pointer;
}

.btn-save-phases:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(31, 99, 146, 0.3);
}

/* Project Setup Sections */
.setup-section {
    border-top: 1px solid var(--gray-200);
}

.setup-section-header {
    padding: 1rem 1.5rem;
    background: var(--gray-50);
    border-bottom: 1px solid var(--gray-200);
}

.setup-section-header h6 {
    margin: 0;
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--gray-800);
}

.setup-section-header h6 i {
    color: var(--theme-primary);
    margin-right: 0.5rem;
}

.setup-section-body {
    padding: 1.5rem;
}

.team-item,
.cost-item {
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-radius: 12px;
    padding: 1rem;
    margin-bottom: 0.75rem;
}

.team-role {
    font-weight: 600;
    color: var(--gray-800);
}

.btn-remove-item {
    background: #fee2e2;
    border: none;
    color: #dc2626;
    padding: 0.35rem 0.7rem;
    border-radius: 8px;
    font-size: 0.7rem;
    cursor: pointer;
}

.btn-remove-item:hover {
    background: #dc2626;
    color: white;
}

/* Gantt Preview */
.gantt-preview {
    display: grid;
    gap: 0.5rem;
}

.gantt-row-preview {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.gantt-label {
    width: 50px;
    font-size: 0.7rem;
    font-weight: 600;
    color: var(--gray-600);
    text-transform: uppercase;
}

.gantt-bar-track {
    flex: 1;
    height: 10px;
    background: var(--gray-200);
    border-radius: 10px;
    overflow: hidden;
    display: flex;
}

.gantt-bar {
    height: 100%;
    transition: width 0.2s ease;
}

.min-bar { background: #60a5fa; }
.likely-bar { background: #34d399; }
.max-bar { background: #f97316; }

.management-table-section {
    background: white;
    border-radius: 14px;
    border: 1px solid var(--gray-200);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
    margin-bottom: 1.5rem;
}

.phase-management-card .management-table-section {
    background: transparent;
    border: 0;
    border-radius: 0;
    box-shadow: none;
    margin-bottom: 0;
}

.management-table-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--gray-200);
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
    flex-wrap: wrap;
}

.management-table-header h5 {
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
    color: var(--gray-800);
}

.management-table-header p {
    margin: 0.35rem 0 0;
    color: var(--gray-600);
    font-size: 0.82rem;
}

.management-table-meta {
    font-size: 0.78rem;
    color: var(--theme-primary);
    font-weight: 600;
}

.management-table-wrap {
    overflow-x: auto;
}

.management-table {
    width: 100%;
    min-width: 820px;
    border-collapse: separate;
    border-spacing: 0;
}

.management-table th,
.management-table td {
    padding: 0.9rem 1.25rem;
    border-bottom: 1px solid var(--gray-200);
    vertical-align: top;
}

.management-table th {
    background: var(--gray-50);
    color: var(--gray-700);
    font-size: 0.72rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    white-space: nowrap;
}

.management-table tbody tr:last-child td {
    border-bottom: none;
}

.management-table tbody tr:hover td {
    background: #fbfdff;
}

.table-feature-name {
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--gray-900);
    margin-bottom: 0.4rem;
}

.table-feature-meta {
    font-size: 0.78rem;
    color: var(--gray-600);
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.45rem 0.75rem;
}

.table-phase-tag {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0;
    background: transparent;
    color: #0369a1;
    font-size: 0.72rem;
    font-weight: 500;
}

.table-date-range {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
}

.management-shared-allocation {
    align-items: center;
    background: #ffffff;
    border-bottom: 1px solid var(--gray-200);
    display: grid;
    gap: 1rem;
    grid-template-columns: minmax(180px, 0.45fr) minmax(420px, 1.55fr);
    padding: 1rem 1.25rem;
}

.management-shared-allocation__copy {
    display: grid;
    gap: 0.18rem;
}

.management-shared-allocation__copy strong {
    align-items: center;
    color: var(--gray-800);
    display: inline-flex;
    font-size: 0.82rem;
    font-weight: 600;
    gap: 0.4rem;
}

.management-shared-allocation__copy span {
    color: var(--gray-600);
    font-size: 0.74rem;
}

.management-shared-allocation__visual {
    align-items: center;
    display: grid;
    gap: 0.75rem 1rem;
    grid-column: 1 / -1;
    grid-template-columns: auto minmax(180px, 0.7fr) minmax(420px, 1.3fr);
    width: 100%;
}

.management-plan-facts {
    display: grid;
    gap: 0;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
}

.management-plan-fact {
    border-left: 1px solid var(--gray-200);
    display: grid;
    gap: 0.16rem;
    min-width: 0;
    padding: 0.1rem 1rem;
}

.management-plan-fact:first-child {
    border-left: 0;
}

.management-plan-fact small,
.management-allocation-label {
    color: var(--gray-500);
    font-size: 0.66rem;
    font-weight: 600;
    letter-spacing: 0.05em;
    text-transform: uppercase;
}

.management-plan-fact strong {
    color: var(--gray-800);
    font-size: 0.78rem;
    font-weight: 500;
}

.table-allocation-stack {
    background: #dce5ec;
    border-radius: 999px;
    display: flex;
    height: 6px;
    overflow: hidden;
    width: 100%;
}

.table-allocation-segment {
    background: var(--allocation-color, var(--theme-primary));
    display: block;
    height: 100%;
    min-width: 2px;
}

.management-allocation-legend {
    display: grid;
    gap: 0.6rem 1rem;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
}

.management-allocation-legend__item {
    align-items: center;
    color: var(--gray-600);
    display: grid;
    font-size: 0.72rem;
    gap: 0.15rem 0.4rem;
    grid-template-columns: auto minmax(0, 1fr) auto;
}

.management-allocation-legend__dot {
    background: var(--allocation-color, var(--theme-primary));
    border-radius: 50%;
    height: 8px;
    width: 8px;
}

.management-allocation-legend__item strong {
    color: var(--gray-800);
    font-weight: 600;
}

.management-allocation-legend__item small {
    color: var(--gray-500);
    font-size: 0.68rem;
    grid-column: 2 / -1;
}

.management-allocation-label {
    white-space: nowrap;
}

.management-table--grouped {
    min-width: 620px;
}

.management-feature-list__header {
    align-items: center;
    background: var(--gray-50);
    border-bottom: 1px solid var(--gray-200);
    color: var(--gray-700);
    display: flex;
    font-size: 0.72rem;
    font-weight: 600;
    justify-content: space-between;
    letter-spacing: 0.04em;
    padding: 0.78rem 1.25rem;
    text-transform: uppercase;
}

.management-feature-list__header small {
    color: var(--gray-500);
    font-size: 0.68rem;
    font-weight: 500;
    letter-spacing: 0;
    text-transform: none;
}

.management-feature-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
}

.management-feature-grid__item {
    align-items: center;
    border-bottom: 1px solid var(--gray-200);
    border-right: 1px solid var(--gray-200);
    color: var(--gray-800);
    display: grid;
    font-size: 0.82rem;
    font-weight: 500;
    gap: 0.7rem;
    grid-template-columns: auto minmax(0, 1fr);
    min-height: 52px;
    padding: 0.72rem 1.25rem;
}

.management-feature-grid__item:nth-child(3n) {
    border-right: 0;
}

.management-feature-grid__index {
    color: var(--gray-400);
    font-size: 0.68rem;
    font-weight: 600;
    letter-spacing: 0.04em;
}

.table-allocation-details summary {
    align-items: center;
    cursor: pointer;
    display: grid;
    gap: 0.65rem;
    grid-template-columns: auto minmax(120px, 1fr) auto;
    list-style: none;
}

.table-allocation-details summary::-webkit-details-marker {
    display: none;
}

.table-allocation-summary-label {
    color: var(--gray-700);
    font-size: 0.76rem;
    white-space: nowrap;
}

.table-allocation-details__chevron {
    color: var(--theme-primary);
    font-size: 0.68rem;
    transition: transform 0.18s ease;
}

.table-allocation-details[open] .table-allocation-details__chevron {
    transform: rotate(180deg);
}

.table-allocation-list {
    display: grid;
    gap: 0;
    margin-top: 0.6rem;
}

.table-allocation-row {
    align-items: center;
    border-top: 1px solid var(--gray-200);
    display: grid;
    gap: 0.75rem;
    grid-template-columns: minmax(0, 1fr) auto;
    padding: 0.42rem 0;
}

.table-allocation-copy {
    min-width: 0;
}

.table-allocation-name {
    color: var(--gray-800);
    display: block;
    font-size: 0.78rem;
    font-weight: 500;
}

.table-allocation-count,
.table-component-copy,
.table-empty-copy {
    color: var(--gray-600);
    display: block;
    font-size: 0.72rem;
    line-height: 1.45;
}

.table-allocation-percent {
    color: var(--theme-primary);
    font-size: 0.78rem;
    font-weight: 600;
    white-space: nowrap;
}

.table-component-copy {
    font-size: 0.8rem;
}

@media (max-width: 767.98px) {
    .management-shared-allocation {
        grid-template-columns: 1fr;
    }

    .management-plan-facts {
        grid-template-columns: 1fr;
    }

    .management-plan-fact,
    .management-plan-fact:first-child {
        border-left: 0;
        border-top: 1px solid var(--gray-200);
        padding: 0.55rem 0;
    }

    .management-shared-allocation__visual {
        align-items: stretch;
        grid-template-columns: 1fr;
    }

    .management-feature-grid {
        grid-template-columns: 1fr;
    }

    .management-feature-grid__item,
    .management-feature-grid__item:nth-child(3n) {
        border-right: 0;
    }
}

.days-stack {
    display: grid;
    gap: 0.45rem;
}

.day-badge {
    display: flex;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 0.55rem 0.7rem;
    border-radius: 12px;
    font-size: 0.78rem;
    font-weight: 600;
}

.day-badge.min {
    background: rgba(96, 165, 250, 0.14);
    color: #1d4ed8;
}

.day-badge.likely {
    background: rgba(52, 211, 153, 0.14);
    color: #047857;
}

.day-badge.max {
    background: rgba(249, 115, 22, 0.14);
    color: #c2410c;
}

.table-empty {
    padding: 1.5rem;
    text-align: center;
    color: var(--gray-600);
}

.table-input {
    width: 100%;
    border: 1px solid var(--gray-300);
    border-radius: 10px;
    padding: 0.5rem 0.65rem;
    font-size: 0.82rem;
    background: white;
    color: var(--gray-800);
}

.table-input:focus {
    outline: none;
    border-color: var(--theme-primary);
    box-shadow: 0 0 0 3px rgba(31, 99, 146, 0.12);
}

.table-input[disabled] {
    background: var(--gray-100);
    color: var(--gray-600);
}

.pm-inline-btn {
    border: 1px dashed var(--gray-300);
    background: white;
    color: var(--theme-primary);
    border-radius: 10px;
    padding: 0.4rem 0.65rem;
    font-size: 0.76rem;
    font-weight: 600;
    cursor: pointer;
}

.pm-inline-btn:hover {
    border-color: var(--theme-primary);
    background: #f7fbff;
}

.pm-inline-btn.remove {
    color: #dc2626;
    border-color: #fecaca;
}

.pm-inline-btn.remove:hover {
    background: #fff5f5;
}

.pm-action-row {
    margin-top: 0.5rem;
}

.pm-days-grid {
    display: grid;
    gap: 0.55rem;
}

.pm-day-label {
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--gray-600);
    margin-bottom: 0.3rem;
    display: block;
}

/* Feature List */
.feature-list {
    background: white;
    border-radius: 14px;
    border: 1px solid var(--gray-200);
    box-shadow: var(--shadow-sm);
}

.feature-bulk-form {
    display: grid;
    gap: 1rem;
}

.feature-bulk-table-wrap {
    border: 1px solid var(--gray-200);
    border-radius: 12px;
    overflow: auto;
}

.feature-bulk-table {
    border-collapse: separate;
    border-spacing: 0;
    min-width: 1040px;
    table-layout: fixed;
    width: 100%;
}

.feature-bulk-table th,
.feature-bulk-table td {
    border-bottom: 1px solid var(--gray-200);
    padding: 0.65rem 0.8rem;
    vertical-align: middle;
}

.feature-bulk-table th {
    background: var(--gray-50);
    color: var(--gray-600);
    font-size: 0.66rem;
    font-weight: 600;
    letter-spacing: 0.04em;
    position: sticky;
    text-transform: uppercase;
    top: 0;
    z-index: 1;
}

.feature-bulk-table th:nth-child(1) { width: 20%; }
.feature-bulk-table th:nth-child(2) { width: 29%; }
.feature-bulk-table th:nth-child(3),
.feature-bulk-table th:nth-child(4),
.feature-bulk-table th:nth-child(5) { width: 9%; }
.feature-bulk-table th:nth-child(6) { width: 18%; }
.feature-bulk-table th:nth-child(7) { width: 9%; }

.feature-bulk-table tbody tr:last-child td {
    border-bottom: 0;
}

.feature-bulk-table tbody tr.is-incomplete td {
    background: #fffbeb;
}

.feature-bulk-table tbody tr.is-ready td {
    background: #f2fbf5;
}

.feature-bulk-table tbody tr {
    transition: background-color 0.18s ease;
}

.feature-bulk-table tbody tr.is-incomplete:hover td {
    background: #fff7dc;
}

.feature-bulk-table tbody tr.is-ready:hover td {
    background: #eaf8ef;
}

.feature-bulk-name {
    color: var(--gray-900);
    display: block;
    font-size: 0.8rem;
    font-weight: 600;
    line-height: 1.35;
    min-width: 170px;
}

.feature-bulk-phase,
.feature-bulk-schedule,
.feature-bulk-pert {
    color: var(--gray-600);
    display: block;
    font-size: 0.7rem;
    line-height: 1.4;
}

.feature-bulk-schedule {
    text-align: center;
    white-space: nowrap;
}

.feature-bulk-pert {
    font-weight: 600;
    text-align: center;
    white-space: nowrap;
}

.feature-bulk-apply-all {
    align-items: center;
    border-radius: 8px;
    display: inline-flex;
    font-size: 0.68rem;
    font-weight: 700;
    gap: 0.35rem;
    justify-content: center;
}

.feature-bulk-table th:nth-child(6),
.feature-bulk-table th:nth-child(7),
.feature-bulk-table td:nth-child(6),
.feature-bulk-table td:nth-child(7),
.feature-bulk-table td:nth-child(3),
.feature-bulk-table td:nth-child(4),
.feature-bulk-table td:nth-child(5) {
    text-align: center;
}

.feature-bulk-table textarea,
.feature-bulk-table input[type="number"] {
    background: #fff;
    border: 1px solid var(--gray-300);
    border-radius: 8px;
    color: var(--gray-900);
    font: inherit;
    min-height: 38px;
    padding: 0.48rem 0.6rem;
    width: 100%;
}

.feature-bulk-table textarea {
    height: 44px;
    line-height: 1.35;
    min-height: 44px;
    resize: none;
}

.feature-bulk-table input[type="number"] {
    min-width: 0;
    text-align: center;
}

.feature-bulk-table tbody tr.is-changed textarea,
.feature-bulk-table tbody tr.is-changed input[type="number"] {
    border-color: rgba(31, 99, 146, 0.58);
}

.feature-bulk-table textarea:focus,
.feature-bulk-table input[type="number"]:focus {
    border-color: var(--theme-primary);
    box-shadow: 0 0 0 3px rgba(31, 99, 146, 0.1);
    outline: 0;
}

.feature-bulk-actions {
    align-items: center;
    bottom: 0.75rem;
    display: flex;
    gap: 0.65rem;
    justify-content: flex-end;
    padding: 0.25rem 0;
    position: sticky;
    z-index: 8;
}

.feature-bulk-apply-all {
    background: #f1f8fc;
    border: 1px solid rgba(31, 99, 146, 0.3);
    color: var(--theme-primary);
    min-height: 40px;
    padding: 0.6rem 0.9rem;
}

.feature-bulk-apply-all:hover {
    background: #e5f3fa;
}

.feature-bulk-apply-all.is-applied {
    background: #ecfdf3;
    border-color: #86efac;
    color: #15803d;
}

.feature-bulk-save-button {
    align-items: center;
    background: #164f73;
    border: 1px solid #164f73;
    border-radius: 10px;
    box-shadow: 0 8px 18px rgba(15, 42, 64, 0.18);
    color: #fff;
    display: inline-flex;
    flex: 0 0 auto;
    font-size: 0.78rem;
    font-weight: 600;
    gap: 0.45rem;
    justify-content: center;
    min-height: 40px;
    padding: 0.6rem 0.9rem;
}

.feature-bulk-save-button:not(:disabled):hover {
    background: #0f405f;
    border-color: #0f405f;
    color: #fff;
    transform: translateY(-1px);
}

.feature-bulk-save-button:disabled {
    cursor: not-allowed;
    opacity: 0.48;
}

.feature-bulk-form [data-generic-save-draft],
.feature-bulk-form [data-generic-draft-status] {
    display: none !important;
}

#appConfirmModal.feature-estimate-confirm-modal .modal-dialog {
    max-width: min(920px, calc(100vw - 2rem));
}

#appConfirmModal.feature-estimate-confirm-modal .app-confirm-note {
    align-items: start;
    display: grid;
    gap: 0.75rem;
    grid-template-columns: 24px minmax(0, 1fr);
    width: 100%;
}

#appConfirmModal.feature-estimate-confirm-modal .app-confirm-note > div {
    flex: 1 1 auto;
    min-width: 0;
}

#appConfirmModal.feature-estimate-confirm-modal .app-confirm-note > i {
    align-items: center;
    display: inline-flex;
    font-size: 1rem;
    height: 24px;
    justify-content: center;
    line-height: 1;
    margin: 0;
    width: 24px;
}

#appConfirmModal.feature-estimate-confirm-modal #appConfirmSubject {
    line-height: 24px;
    margin-bottom: 0 !important;
}

.feature-save-confirm-overview {
    align-items: center;
    background: #f3f8fb;
    border: 1px solid #d8e7f0;
    border-radius: 10px;
    color: #31536b;
    display: flex;
    font-size: 0.76rem;
    justify-content: space-between;
    margin: 0.75rem 0;
    padding: 0.65rem 0.75rem;
}

.feature-save-confirm-table-wrap {
    border: 1px solid #dce6ed;
    border-radius: 10px;
    max-height: min(52vh, 520px);
    overflow: auto;
}

.feature-save-confirm-table {
    border-collapse: collapse;
    font-size: 0.72rem;
    width: 100%;
}

.feature-save-confirm-table th,
.feature-save-confirm-table td {
    border-bottom: 1px solid #e3eaf0;
    padding: 0.58rem 0.65rem;
    text-align: left;
    vertical-align: top;
}

.feature-save-confirm-table th {
    background: #f7f9fb;
    color: #617487;
    font-size: 0.64rem;
    font-weight: 600;
    letter-spacing: 0.04em;
    position: sticky;
    text-transform: uppercase;
    top: 0;
}

.feature-save-confirm-table tbody tr:last-child td {
    border-bottom: 0;
}

.feature-save-confirm-table strong,
.feature-save-confirm-table small {
    display: block;
}

.feature-save-confirm-table strong {
    color: #18324a;
    font-weight: 600;
}

.feature-save-confirm-table small {
    color: #6b7e90;
    margin-top: 0.15rem;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-save-confirm-overview,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-save-confirm-table th {
    background: #12263a;
    border-color: #29445c;
    color: #a8bfd2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-save-confirm-table-wrap,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-save-confirm-table th,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-save-confirm-table td {
    border-color: #29445c;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-save-confirm-table strong {
    color: #e2edf6;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-save-confirm-table small {
    color: #95aec2;
}

.feature-row {
    border-bottom: 1px solid var(--gray-200);
    padding: 1.25rem 1.5rem;
}

.feature-row:last-child {
    border-bottom: none;
}

.feature-title {
    font-size: 1rem;
    font-weight: 600;
    color: var(--gray-900);
}

.feature-meta {
    font-size: 0.75rem;
    color: var(--gray-500);
}

.feature-row.incomplete {
    background: #fff7ed;
}

.feature-row.complete {
    background: #f0fdf4;
}

.progress-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.7rem;
    font-weight: 600;
    padding: 0.2rem 0.6rem;
    border-radius: 20px;
    text-transform: uppercase;
}

.progress-badge.ready {
    background: #d1fae5;
    color: #059669;
}

.progress-badge.pending {
    background: #fef3c7;
    color: #d97706;
}

.progress-badge.empty {
    background: #e2e8f0;
    color: #475569;
}

/* Form Elements */
.form-label {
    font-size: 0.7rem;
    text-transform: uppercase;
    font-weight: 600;
    color: var(--gray-600);
    letter-spacing: 0.5px;
    margin-bottom: 0.5rem;
    display: block;
}

.form-control-custom {
    width: 100%;
    padding: 0.6rem 0.8rem;
    border: 1px solid var(--gray-300);
    border-radius: 10px;
    font-size: 0.85rem;
    transition: all 0.2s;
}

.form-control-custom:focus {
    outline: none;
    border-color: var(--theme-primary);
    box-shadow: 0 0 0 3px rgba(31, 99, 146, 0.1);
}

textarea.form-control-custom {
    resize: vertical;
    min-height: 80px;
}

/* Buttons */
.btn-submit {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    color: white;
    border: none;
    padding: 0.7rem 1rem;
    border-radius: 9px;
    font-weight: 700;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    min-height: 44px;
}

.btn-submit:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(31, 99, 146, 0.3);
    color: white;
}

.estimate-action-row {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    flex-wrap: wrap;
    gap: 0.75rem;
    margin-top: 0.25rem;
}

.estimate-action-row .btn-submit {
    min-width: min(280px, 100%);
}

.estimate-action-row [data-generic-save-draft] {
    background: #fff;
    border: 1px solid rgba(31, 99, 146, 0.35);
    color: var(--theme-primary);
    padding: 0.7rem 1rem;
    border-radius: 9px;
    font-weight: 700;
    min-height: 44px;
    min-width: 150px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    margin-left: 0 !important;
    order: 1;
    transition: all 0.2s;
}

.estimate-action-row [data-generic-save-draft]:hover {
    background: rgba(31, 99, 146, 0.08);
    border-color: var(--theme-primary);
    color: var(--theme-primary);
    transform: translateY(-1px);
}

.estimate-action-row .btn-submit {
    order: 2;
}

.estimate-action-row [data-generic-draft-status] {
    order: 3;
    width: 100%;
    margin-left: 0 !important;
    text-align: right;
}

/* Alert Info */
.alert-info-custom {
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-radius: 12px;
    padding: 0.75rem;
    margin-top: 1rem;
}

.alert-info-custom small {
    color: var(--gray-600);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.alert-info-custom i {
    color: var(--theme-primary);
}

.recommendation-panel {
    border: 1px solid #bfdbfe;
    background: linear-gradient(180deg, #eff6ff 0%, #f8fafc 100%);
    border-radius: 16px;
    padding: 1rem;
}

.recommendation-panel-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 0.9rem;
}

.recommendation-panel-header h6 {
    margin: 0 0 0.2rem 0;
    font-size: 0.95rem;
    font-weight: 700;
    color: #1e3a8a;
}

.recommendation-panel-header p {
    margin: 0;
    font-size: 0.78rem;
    color: #475569;
}

.recommendation-count {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: 0.3rem 0.7rem;
    background: #dbeafe;
    color: #1d4ed8;
    font-size: 0.72rem;
    font-weight: 700;
    white-space: nowrap;
}

.recommendation-list {
    display: grid;
    gap: 0.85rem;
}

.recommendation-card {
    background: white;
    border: 1px solid #dbeafe;
    border-radius: 14px;
    padding: 0.95rem;
    box-shadow: 0 6px 18px rgba(15, 23, 42, 0.04);
}

.recommendation-card.is-hidden {
    display: none;
}

.recommendation-card.is-accepted {
    border-color: #86efac;
    background: #f0fdf4;
}

.recommendation-meta-row,
.recommendation-stats,
.recommendation-actions {
    display: flex;
    align-items: center;
    gap: 0.55rem;
    flex-wrap: wrap;
}

.recommendation-meta-row {
    justify-content: space-between;
    margin-bottom: 0.55rem;
}

.recommendation-tag {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: 0.2rem 0.6rem;
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.recommendation-tag.history {
    background: #dbeafe;
    color: #1d4ed8;
}

.recommendation-tag.manual {
    background: #dcfce7;
    color: #15803d;
}

.recommendation-updated,
.recommendation-subtitle,
.recommendation-phase,
.recommendation-expectation {
    color: #475569;
}

.recommendation-updated {
    font-size: 0.73rem;
}

.recommendation-title {
    font-size: 0.95rem;
    font-weight: 700;
    color: #0f172a;
}

.recommendation-subtitle {
    font-size: 0.78rem;
    margin-top: 0.1rem;
}

.recommendation-stats {
    margin-top: 0.75rem;
}

.recommendation-stats span {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 999px;
    padding: 0.22rem 0.65rem;
    font-size: 0.72rem;
    font-weight: 700;
    color: #334155;
}

.recommendation-phase {
    margin-top: 0.55rem;
    font-size: 0.75rem;
    font-weight: 600;
}

.recommendation-expectation {
    font-size: 0.78rem;
    margin: 0.65rem 0 0 0;
}

.recommendation-actions {
    margin-top: 0.85rem;
}

.recommendation-btn {
    border: none;
    border-radius: 999px;
    padding: 0.48rem 0.85rem;
    font-size: 0.76rem;
    font-weight: 700;
}

.recommendation-btn.accept {
    background: #1d4ed8;
    color: white;
}

.recommendation-btn.reject {
    background: #e2e8f0;
    color: #334155;
}

.recommendation-save-panel {
    background: #f8fafc;
    border: 1px dashed #cbd5e1;
    border-radius: 14px;
    padding: 0.9rem;
}

.recommendation-save-panel.highlight {
    border-color: #1d4ed8;
    background: #eff6ff;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 4rem 2rem;
    background: white;
    border-radius: 14px;
    border: 1px solid var(--gray-200);
}

.empty-state i {
    font-size: 4rem;
    color: var(--gray-300);
    margin-bottom: 1rem;
}

.empty-state h5 {
    font-size: 1.25rem;
    font-weight: 600;
    color: var(--gray-800);
    margin-bottom: 0.5rem;
}

.empty-state p {
    color: var(--gray-500);
    margin-bottom: 0;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #page-content-wrapper,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .container-fluid {
    background: #06111e;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern {
    background: #071321 !important;
    border: 1px solid rgba(31, 99, 146, 0.24);
    box-shadow: 0 20px 45px rgba(0, 0, 0, 0.22);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern::before {
    background: linear-gradient(90deg, transparent, rgba(31, 99, 146, 0.16));
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern h2,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern h2 i {
    color: #d7e4f2 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern p {
    color: #9db6cf !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .expert-workspace-switcher {
    border-color: rgba(157, 182, 207, 0.24);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .expert-workspace-tab {
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .expert-workspace-tab:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .expert-workspace-tab:focus-visible,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .expert-workspace-tab.is-active {
    color: #7cc0ee;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .expert-workspace-tab.is-active {
    border-bottom-color: #7cc0ee;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .timeline-section,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .phase-management-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .management-table-section,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-list,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .empty-state,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .recommendation-card {
    background: #071321 !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
    box-shadow: 0 20px 45px rgba(0, 0, 0, 0.22);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .phase-management-card .management-table-section {
    border: 0 !important;
    box-shadow: none;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .management-shared-allocation {
    background: #0a1b2c;
    border-color: rgba(31, 99, 146, 0.24);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .timeline-header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .phase-header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-selector,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .setup-section-header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .phase-item,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .team-item,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .cost-item,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .management-table th,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .management-feature-list__header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .management-table tbody tr:hover td,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .recommendation-panel,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .recommendation-stats span,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .recommendation-save-panel,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .alert-info-custom {
    background: #0a1b2c !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-row.incomplete {
    background: rgba(245, 158, 11, 0.08) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-row.complete,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .recommendation-card.is-accepted {
    background: rgba(16, 185, 129, 0.08) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-bulk-table-wrap,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-bulk-table th,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-bulk-table td {
    border-color: #20364a;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-bulk-table th {
    background: #12263a;
    color: #b9cee1;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-bulk-table tbody tr.is-incomplete td {
    background: rgba(245, 158, 11, 0.08);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-bulk-table tbody tr.is-ready td {
    background: rgba(22, 163, 74, 0.08);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-bulk-table tbody tr.is-incomplete:hover td {
    background: rgba(245, 158, 11, 0.12);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-bulk-table tbody tr.is-ready:hover td {
    background: rgba(22, 163, 74, 0.12);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-bulk-name {
    color: #f1f7fc;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-bulk-phase,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-bulk-schedule,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-bulk-pert {
    color: #b5cbe0;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-bulk-table textarea,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-bulk-table input[type="number"] {
    background: #071321;
    border-color: #2c4b65;
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-bulk-apply-all {
    background: #102a3f;
    border-color: #315872;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .timeline-header h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .phase-header h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .setup-section-header h6,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .team-role,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .management-table-header h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .management-table td,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .table-feature-name,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .table-allocation-name,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .table-component-copy,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .management-shared-allocation__copy strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .management-plan-fact strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .management-feature-grid__item,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .management-allocation-legend__item strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .table-allocation-summary-label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-title,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .recommendation-panel-header h6,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .recommendation-title,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .empty-state h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .fw-semibold {
    color: #d7e4f2 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .timeline-header p,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .management-table-header p,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .management-table th,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .table-feature-meta,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .table-allocation-count,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .table-empty-copy,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .management-shared-allocation__copy span,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .management-plan-fact small,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .management-allocation-label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .management-feature-list__header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .management-allocation-legend__item,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .table-empty,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .pm-day-label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-meta,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .alert-info-custom small,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .recommendation-panel-header p,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .recommendation-updated,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .recommendation-subtitle,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .recommendation-phase,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .recommendation-expectation,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .recommendation-stats span,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .empty-state p,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .text-muted,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) small {
    color: #9db6cf !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .timeline-header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .phase-header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-selector,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .setup-section,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .setup-section-header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .management-table-header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .management-table th,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .management-table td,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .management-plan-fact,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .management-feature-list__header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .management-feature-grid__item,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .table-allocation-row,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-row {
    border-color: rgba(31, 99, 146, 0.24) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .table-phase-tag,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .table-allocation-percent,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .table-allocation-details__chevron {
    color: #7cc0ee;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .table-allocation-stack {
    background: #334155;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .phase-name input,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .phase-dates input,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-control-custom,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .table-input {
    background: #071321;
    border-color: rgba(31, 99, 146, 0.28);
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .table-input[disabled],
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-control-custom[disabled] {
    background: rgba(157, 182, 207, 0.08);
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-control-custom::placeholder,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .table-input::placeholder {
    color: #7f9bb6;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .gantt-bar-track {
    background: rgba(157, 182, 207, 0.18);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .table-phase-tag,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .recommendation-count,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .recommendation-tag.history {
    background: rgba(31, 99, 146, 0.18);
    color: #7cc0ee;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .recommendation-tag.manual,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .progress-badge.ready {
    background: rgba(16, 185, 129, 0.16);
    color: #86efac;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .progress-badge.pending,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .day-badge.likely {
    background: rgba(245, 158, 11, 0.16);
    color: #fbbf24;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .progress-badge.empty {
    background: rgba(100, 116, 139, 0.22);
    color: #cbd5e1;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .pm-inline-btn,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-add-phase,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .estimate-action-row [data-generic-save-draft] {
    background: rgba(31, 99, 146, 0.12);
    border-color: rgba(31, 99, 146, 0.3);
    color: #7cc0ee;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .pm-inline-btn.remove,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-remove-phase,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-remove-item {
    background: rgba(220, 38, 38, 0.16);
    color: #fca5a5;
}

/* Responsive */
@media (max-width: 768px) {
    .page-header-modern {
        padding: 1.35rem;
    }
    
    .page-header-modern h2 {
        font-size: 1.4rem;
    }

    .phase-item {
        flex-direction: column;
        align-items: stretch;
    }
    
    .card-header-custom {
        padding: 1rem 1.25rem;
    }
    
    .card-body-custom {
        padding: 1.25rem;
    }

    .estimate-action-row {
        justify-content: stretch;
    }

    .estimate-action-row .btn-submit,
    .estimate-action-row [data-generic-save-draft] {
        width: 100%;
    }

    .estimate-action-row [data-generic-draft-status] {
        text-align: left;
    }

    .feature-bulk-actions {
        align-items: stretch;
        flex-direction: column;
    }

    .feature-bulk-save-button,
    .feature-bulk-apply-all {
        width: 100%;
    }

    .feature-save-confirm-overview {
        align-items: flex-start;
        flex-direction: column;
        gap: 0.25rem;
    }
}
</style>

<div class="container-fluid px-4 py-4 expert-estimation-page">
    <?php di_render_project_attention_banner($conn, $user, max(0, (int) ($_GET['project_estimation_id'] ?? 0))); ?>

    <!-- Page Header -->
    <div class="page-header-modern">
        <div class="page-header-content">
            <h2>
                <i class="fas fa-calculator me-2"></i> 
                Project Estimation
            </h2>
            <p>Review project inputs, calculate delivery costs, and track estimation completion.</p>
        </div>
    </div>

    <?php projectEstimationRenderExpertModule($conn, $user); ?>
</div>

<div id="featureEstimatorSource" hidden aria-hidden="true">
    <section class="expert-workspace-panel" id="featureEstimationWorkspace" role="tabpanel" aria-labelledby="featureEstimationTab" data-expert-workspace-panel="features" hidden>
    <div class="phase-management-card" id="featureProjectChooser">
        <div class="phase-header">
            <h5><i class="fas fa-folder-open me-2"></i>Select Project</h5>
        </div>
        <div class="project-selector">
            <label class="form-label">Choose a project to load features, roadmap, and estimations</label>
            <div class="row g-2">
                <div class="col-md-9">
                    <select id="phaseProjectSelect" class="form-control-custom">
                        <option value="">-- Select a project --</option>
                        <?php foreach ($expertProjects as $proj): ?>
                            <option value="<?php echo $proj['id']; ?>" 
                                    data-start="<?php echo htmlspecialchars((string) ($proj['estimation_window_start'] ?? $proj['timeline_start']), ENT_QUOTES, 'UTF-8'); ?>"
                                    data-end="<?php echo htmlspecialchars((string) ($proj['estimation_window_end'] ?? $proj['timeline_end']), ENT_QUOTES, 'UTF-8'); ?>"
                                    data-duration-label="<?php echo htmlspecialchars((string) ($proj['estimation_window_label'] ?? 'Project timeline'), ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($proj['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <a class="btn-save-phases feature-project-action text-center text-decoration-none" id="editTaskStaffingBtn" href="<?php echo BASE_URL; ?>modules/roadmap/methodology.php" hidden>
                        <i class="fas fa-user-plus"></i> Edit Task Staffing
                    </a>
                </div>
            </div>
        </div>
        <div id="projectManagementSection" style="display: none;">
            <div class="management-table-section">
                <div class="management-table-header">
                    <div>
                        <h5><i class="fas fa-table me-2"></i>Project Management Table</h5>
                        <p>Architect roadmap allocations and component planning.</p>
                    </div>
                    <div class="management-table-meta" id="managementTableMeta">Select a project to load the planning table.</div>
                </div>
                <div class="management-table-wrap" id="projectManagementTable">
                    <div class="table-empty">Select a project to load the planning table.</div>
                </div>
            </div>
        </div>
    </div>

    <div id="projectSections" style="display: none;">
        <!-- Feature List -->
        <div class="feature-list">
            <div class="feature-row incomplete" id="featureProgressRow">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div class="feature-title">Feature Estimation Progress</div>
                    <div class="d-flex align-items-center gap-2">
                        <div class="feature-meta" id="featureProgressText">Estimated: 0 / 0</div>
                        <span class="progress-badge pending" id="featureProgressBadge">Not Ready</span>
                    </div>
                </div>
                <small class="text-muted" id="featureProgressHelp">All features must be estimated before submission to Sales.</small>
            </div>
            <?php if(count($taskRows) > 0): ?>
            <?php foreach($taskRows as $task): 
                $estimation = $conn->query("SELECT * FROM feature_estimations WHERE task_id = {$task['id']} AND expert_id = {$user['id']}")->fetch_assoc();
                $isEstimated = $estimation && !empty($estimation['min_days']) && !empty($estimation['likely_days']) && !empty($estimation['max_days']);
                $taskRecommendations = $recommendationsByTask[(int) $task['id']] ?? [];
                $architectPlan = $architectPlanningByTask[(int) $task['id']] ?? featureEstimationEmptyArchitectPlan();
                $featurePhaseName = (string) ($architectPlan['phase_name'] ?: ($estimation['phase_name'] ?? ''));
                $featureInvolvementPlanJson = json_encode(
                    !empty($architectPlan['involvement_plan']) ? $architectPlan['involvement_plan'] : decodeRecommendationJson($estimation['involvement_plan_json'] ?? '[]'),
                    JSON_UNESCAPED_UNICODE
                );
                $featureComponentPlanJson = json_encode(
                    !empty($architectPlan['component_plan']) ? $architectPlan['component_plan'] : decodeRecommendationJson($estimation['component_plan_json'] ?? '[]'),
                    JSON_UNESCAPED_UNICODE
                );
                $featureDurationWindow = projectEstimationDurationWindow($conn, [
                    'id' => (int) $task['project_id'],
                    'timeline_start' => $task['timeline_start'] ?? '',
                    'timeline_end' => $task['timeline_end'] ?? '',
                ]);
                $featureWindowStart = trim((string) ($featureDurationWindow['start'] ?? ($task['timeline_start'] ?? '')));
                $featureWindowEnd = trim((string) ($featureDurationWindow['end'] ?? ($task['timeline_end'] ?? '')));
                $featureWindowLabel = (string) ($featureDurationWindow['label'] ?? 'Project timeline');
                $projectFeatureCount = max(1, (int) ($featureCountByProject[(int) $task['project_id']] ?? 1));
                $featureEstimateDurationDays = max(0, (int) ($architectPlan['estimate_duration_days'] ?? 0));
                $featureEstimateBasisLabel = trim((string) ($architectPlan['duration_basis_label'] ?? ''));
                if ($featureEstimateDurationDays <= 0) {
                    $projectWindowDays = featureEstimationInclusiveDays($featureWindowStart, $featureWindowEnd);
                    $featureEstimateDurationDays = max(1, (int) ceil($projectWindowDays / $projectFeatureCount));
                }
                if ($featureEstimateBasisLabel === '') {
                    $featureEstimateBasisLabel = $featureWindowLabel . ' divided across ' . $projectFeatureCount . ' feature(s)';
                }
                $evortixMaxDays = max(1, $featureEstimateDurationDays);
                $evortixLikelyDays = max(1, (int) ceil($evortixMaxDays * 0.85));
                $evortixMinDays = max(1, (int) ceil($evortixMaxDays * 0.70));
                $evortixEstimateSource = 'Architect roadmap duration';
                $evortixMatchedPayload = [];
                foreach ($taskRecommendations as $taskRecommendation) {
                    $historyPayload = is_array($taskRecommendation['payload'] ?? null) ? $taskRecommendation['payload'] : [];
                    $historyMin = max(0, (int) ($historyPayload['min_days'] ?? 0));
                    $historyLikely = max(0, (int) ($historyPayload['likely_days'] ?? 0));
                    $historyMax = max(0, (int) ($historyPayload['max_days'] ?? 0));
                    if ($historyMin < 1 || $historyLikely < $historyMin || $historyMax < $historyLikely) {
                        continue;
                    }

                    $evortixMaxDays = min($evortixMaxDays, $historyMax);
                    $evortixLikelyDays = min($evortixMaxDays, max(1, $historyLikely));
                    $evortixMinDays = min($evortixLikelyDays, max(1, $historyMin));
                    $evortixEstimateSource = 'Similar completed feature: ' . (string) ($taskRecommendation['label'] ?? 'past estimate');
                    $evortixMatchedPayload = $historyPayload;
                    break;
                }
                $evortixExpectation = trim((string) ($evortixMatchedPayload['expectation'] ?? ''));
                if ($evortixExpectation === '') {
                    $evortixExpectation = trim((string) ($task['description'] ?? ''));
                }
                if ($evortixExpectation === '') {
                    $evortixExpectation = 'Deliver ' . trim((string) ($task['title'] ?? 'this feature'))
                        . ' according to the approved requirements, acceptance criteria, and roadmap scope.';
                }
                $evortixInvolvementPlan = !empty($architectPlan['involvement_plan'])
                    ? (array) $architectPlan['involvement_plan']
                    : (array) ($evortixMatchedPayload['involvement_plan'] ?? []);
                $evortixComponentPlan = !empty($architectPlan['component_plan'])
                    ? (array) $architectPlan['component_plan']
                    : (array) ($evortixMatchedPayload['component_plan'] ?? []);
                $evortixPlannedStart = trim((string) ($architectPlan['planned_start'] ?? '')) ?: $featureWindowStart;
                $evortixPlannedEnd = trim((string) ($architectPlan['planned_end'] ?? '')) ?: $featureWindowEnd;
                $evortixEstimatePayload = [
                    'expectation' => $evortixExpectation,
                    'min_days' => $evortixMinDays,
                    'likely_days' => $evortixLikelyDays,
                    'max_days' => $evortixMaxDays,
                    'pert_days' => round(($evortixMinDays + (4 * $evortixLikelyDays) + $evortixMaxDays) / 6, 1),
                    'basis' => $featureEstimateBasisLabel,
                    'source' => $evortixEstimateSource,
                    'phase_name' => $featurePhaseName,
                    'planned_start' => $evortixPlannedStart,
                    'planned_end' => $evortixPlannedEnd,
                    'involvement_plan' => $evortixInvolvementPlan,
                    'component_plan' => $evortixComponentPlan,
                    'confidence' => $evortixMatchedPayload !== [] ? 'Historical match' : 'Roadmap-based',
                ];
                $architectPlanStartValue = trim((string) ($architectPlan['planned_start'] ?? ''));
                $architectPlanEndValue = trim((string) ($architectPlan['planned_end'] ?? ''));
                $architectDeadlineValue = $architectPlanEndValue !== '' ? $architectPlanEndValue : $featureWindowEnd;
                $featurePlannedStartValue = $architectPlanStartValue !== ''
                    ? $architectPlanStartValue
                    : (!empty($task['started_at']) ? date('Y-m-d', strtotime((string) $task['started_at'])) : $featureWindowStart);
                $featurePlannedEndValue = $architectPlanEndValue !== ''
                    ? $architectPlanEndValue
                    : (!empty($task['deadline']) ? date('Y-m-d', strtotime((string) $task['deadline'])) : $featureWindowEnd);
                if ($featurePlannedEndValue !== '' && $architectDeadlineValue !== '' && strtotime($featurePlannedEndValue) > strtotime($architectDeadlineValue)) {
                    $featurePlannedEndValue = $architectDeadlineValue;
                }
                $architectDeadlineLabel = $architectDeadlineValue !== ''
                    ? date('M d, Y', strtotime($architectDeadlineValue))
                    : 'not set';
            ?>
            <?php $isReadonly = $task['status'] !== 'todo'; ?>
            <div id="estimate-task-<?php echo (int) $task['id']; ?>" class="feature-row estimation-card <?php echo $isEstimated ? 'complete' : 'incomplete'; ?>" data-project-id="<?php echo (int) $task['project_id']; ?>" data-task-id="<?php echo (int) $task['id']; ?>" data-task-title="<?php echo htmlspecialchars((string) $task['title'], ENT_QUOTES, 'UTF-8'); ?>" data-readonly="<?php echo $isReadonly ? '1' : '0'; ?>" data-estimated="<?php echo $isEstimated ? '1' : '0'; ?>" data-saved-estimated="<?php echo $isEstimated ? '1' : '0'; ?>" data-architect-deadline="<?php echo htmlspecialchars($architectDeadlineValue, ENT_QUOTES, 'UTF-8'); ?>" data-project-start="<?php echo htmlspecialchars($featureWindowStart, ENT_QUOTES, 'UTF-8'); ?>" data-project-end="<?php echo htmlspecialchars($featureWindowEnd, ENT_QUOTES, 'UTF-8'); ?>" data-duration-label="<?php echo htmlspecialchars($featureWindowLabel, ENT_QUOTES, 'UTF-8'); ?>" data-estimate-duration-days="<?php echo (int) $featureEstimateDurationDays; ?>" data-evortix-estimate="<?php echo htmlspecialchars(json_encode($evortixEstimatePayload, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>" style="display:none;">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                    <div>
                        <div class="feature-title"><?php echo htmlspecialchars($task['title']); ?></div>
                        <div class="feature-meta"><i class="fas fa-folder me-1"></i><?php echo htmlspecialchars($task['project_name']); ?></div>
                    </div>
                    <?php if ($isReadonly): ?>
                        <span class="progress-badge ready">Submitted</span>
                    <?php endif; ?>
                </div>
                <div class="mb-3">
                    <div class="text-muted small mb-2">Description</div>
                    <p class="mb-0 text-secondary"><?php echo htmlspecialchars($task['description']); ?></p>
                </div>
                
                <form method="POST">
                    <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                    <input type="hidden" name="phase_name" value="<?php echo htmlspecialchars($featurePhaseName, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="involvement_plan_json" value="<?php echo htmlspecialchars($featureInvolvementPlanJson ?: '[]', ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="component_plan_json" value="<?php echo htmlspecialchars($featureComponentPlanJson ?: '[]', ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="accepted_recommendation_type" value="">
                    <input type="hidden" name="accepted_recommendation_id" value="">
                    <input type="hidden" name="rejected_recommendations_json" value="[]">
                    <input type="hidden" name="estimate_duration_days" value="<?php echo (int) $featureEstimateDurationDays; ?>">
                    <input type="hidden" class="evortix-feature-estimate-decision" value="">

                    <?php if (!empty($taskRecommendations)): ?>
                    <div class="recommendation-panel mb-4 d-none" aria-hidden="true">
                        <div class="recommendation-panel-header">
                            <div>
                                <h6><i class="fas fa-lightbulb me-2"></i>Feature Recommendation</h6>
                                <p>We found similar estimates from past projects and your saved manual versions.</p>
                            </div>
                            <span class="recommendation-count"><?php echo count($taskRecommendations); ?> match<?php echo count($taskRecommendations) === 1 ? '' : 'es'; ?></span>
                        </div>
                        <div class="recommendation-list">
                            <?php foreach ($taskRecommendations as $recommendation): ?>
                                <?php
                                    $recommendationPayload = htmlspecialchars(json_encode($recommendation['payload'], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                                    $recommendationSourceType = htmlspecialchars($recommendation['source_type'], ENT_QUOTES, 'UTF-8');
                                    $recommendationSourceId = (int) $recommendation['source_id'];
                                ?>
                                <div class="recommendation-card" data-source-type="<?php echo $recommendationSourceType; ?>" data-source-id="<?php echo $recommendationSourceId; ?>">
                                    <div class="recommendation-meta-row">
                                        <span class="recommendation-tag <?php echo $recommendation['source_type'] === 'manual' ? 'manual' : 'history'; ?>">
                                            <?php echo $recommendation['source_type'] === 'manual' ? 'Manual' : 'Past Project'; ?>
                                        </span>
                                        <?php if (!empty($recommendation['updated_at'])): ?>
                                            <span class="recommendation-updated">Updated <?php echo htmlspecialchars(date('M d, Y', strtotime((string) $recommendation['updated_at']))); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="recommendation-title"><?php echo htmlspecialchars($recommendation['label']); ?></div>
                                    <div class="recommendation-subtitle"><?php echo htmlspecialchars($recommendation['subtitle']); ?></div>
                                    <div class="recommendation-stats">
                                        <span>Min <?php echo (int) $recommendation['payload']['min_days']; ?></span>
                                        <span>Likely <?php echo (int) $recommendation['payload']['likely_days']; ?></span>
                                        <span>Max <?php echo (int) $recommendation['payload']['max_days']; ?></span>
                                    </div>
                                    <?php if (!empty($recommendation['payload']['phase_name'])): ?>
                                        <div class="recommendation-phase">Phase: <?php echo htmlspecialchars($recommendation['payload']['phase_name']); ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($recommendation['payload']['expectation'])): ?>
                                        <p class="recommendation-expectation"><?php echo htmlspecialchars($recommendation['payload']['expectation']); ?></p>
                                    <?php endif; ?>
                                    <div class="recommendation-actions">
                                        <button
                                            type="button"
                                            class="recommendation-btn accept recommendation-accept"
                                            data-payload="<?php echo $recommendationPayload; ?>"
                                            data-source-type="<?php echo $recommendationSourceType; ?>"
                                            data-source-id="<?php echo $recommendationSourceId; ?>"
                                        >
                                            Use Recommendation
                                        </button>
                                        <button
                                            type="button"
                                            class="recommendation-btn reject recommendation-reject"
                                            data-source-type="<?php echo $recommendationSourceType; ?>"
                                            data-source-id="<?php echo $recommendationSourceId; ?>"
                                        >
                                            Reject
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="mb-3">
                        <label class="form-label">Expectation Description</label>
                        <textarea name="expectation" class="form-control-custom" rows="2" required placeholder="What should this feature accomplish?" <?php echo $isReadonly ? 'disabled' : ''; ?>><?php echo htmlspecialchars($estimation['expectation_description'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label">Minimum Days</label>
                            <input type="number" name="min_days" class="form-control-custom estimate-input" min="1" step="1" <?php echo $isReadonly ? 'disabled' : ''; ?>
                                   value="<?php echo $estimation['min_days'] ?? ''; ?>" required placeholder="1">
                            <small class="text-muted d-block mt-1">Accept Evortix's per-feature recommendation or enter your own value.</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Most Likely Days</label>
                            <input type="number" name="likely_days" class="form-control-custom estimate-input" min="1" step="1" <?php echo $isReadonly ? 'disabled' : ''; ?>
                                   value="<?php echo $estimation['likely_days'] ?? ''; ?>" required placeholder="1">
                            <small class="text-muted d-block mt-1">The expected delivery effort for this feature.</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Maximum Days</label>
                            <input type="number" name="max_days" class="form-control-custom estimate-input" min="1" step="1" <?php echo $isReadonly ? 'disabled' : ''; ?>
                                   value="<?php echo $estimation['max_days'] ?? ''; ?>" required placeholder="3">
                            <small class="text-muted d-block mt-1">Uses the task estimate duration, never beyond the selected deadline</small>
                        </div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Planned Start Date</label>
                            <input type="date" name="planned_start" class="form-control-custom estimate-start" <?php echo $isReadonly ? 'disabled' : 'readonly'; ?>
                                   value="<?php echo htmlspecialchars($featurePlannedStartValue, ENT_QUOTES, 'UTF-8'); ?>">
                            <small class="text-muted d-block mt-1">Set the task start for Gantt</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Planned End Date</label>
                            <input type="date" name="planned_end" class="form-control-custom estimate-end" <?php echo $isReadonly ? 'disabled' : 'readonly'; ?>
                                   value="<?php echo htmlspecialchars($featurePlannedEndValue, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $architectDeadlineValue !== '' ? 'max="' . htmlspecialchars($architectDeadlineValue, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>>
                            <small class="text-muted d-block mt-1">Can be earlier than the Architect deadline, never later than <?php echo htmlspecialchars($architectDeadlineLabel); ?>. Estimate basis: <?php echo htmlspecialchars($featureEstimateBasisLabel); ?></small>
                        </div>
                    </div>

                    <div class="mb-4">
                        <div class="text-muted small mb-2">Gantt Preview (Min / Likely / Max)</div>
                        <div class="gantt-preview" data-task-id="<?php echo $task['id']; ?>">
                            <div class="gantt-row-preview">
                                <span class="gantt-label">Days</span>
                                <div class="gantt-bar-track">
                                    <div class="gantt-bar min-bar"></div>
                                    <div class="gantt-bar likely-bar"></div>
                                    <div class="gantt-bar max-bar"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if (!$isReadonly): ?>
                    <div class="recommendation-save-panel mb-4">
                        <label class="form-label">Save This Estimate As A Reusable Recommendation</label>
                        <input
                            type="text"
                            name="manual_recommendation_name"
                            class="form-control-custom manual-recommendation-name"
                            maxlength="255"
                            placeholder="Optional name, like Standard Login Flow - Complex"
                            value=""
                        >
                        <small class="text-muted d-block mt-1 manual-recommendation-help">Useful when you adjust or reject a suggested estimate and want your version available next time.</small>
                    </div>
                    <?php endif; ?>
                     
                    <?php if (!$isReadonly): ?>
                        <div class="text-muted small">
                            Complete this feature in the project estimation table, then save all feature estimates before the final Sales submission.
                        </div>
                    <?php else: ?>
                        <div class="text-muted small">This feature has been submitted to Sales.</div>
                    <?php endif; ?>
                </form>
                
                <?php if($estimation): ?>
                <div class="alert-info-custom">
                    <small>
                        <i class="fas fa-chart-line"></i> 
                        <strong>PERT Estimate:</strong> 
                        <?php echo round(($estimation['min_days'] + 4*$estimation['likely_days'] + $estimation['max_days'])/6, 1); ?> days
                        <span class="text-muted">(Min: <?php echo $estimation['min_days']; ?> | Likely: <?php echo $estimation['likely_days']; ?> | Max: <?php echo $estimation['max_days']; ?>)</span>
                    </small>
                </div>
        <?php endif; ?>
    </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </div>
    </section>
</div>

<button type="button" class="evortix-dss-replay-trigger" id="evortixFeatureEstimateReplay" hidden>
    <i class="fas fa-rotate-left" aria-hidden="true"></i>
    <span>Ask Evortix again</span>
</button>

<script>
let currentProjectId = null;
const existingDepartments = <?php echo json_encode($availableDepartments, JSON_UNESCAPED_UNICODE); ?>;
const architectPlanningByTask = <?php echo json_encode($architectPlanningByTask, JSON_UNESCAPED_UNICODE); ?>;
const currentExpertId = <?php echo (int) ($user['id'] ?? 0); ?>;
const expertWorkspaceStorageKey = 'expert-estimation-active-workspace';
const selectedFeatureProjectId = <?php echo json_encode((string) max(0, (int) ($_GET['project_estimation_id'] ?? 0))); ?>;
const featureSubmittedMessage = <?php echo json_encode((string) ($_SESSION['feature_estimation_submitted'] ?? '')); unset($_SESSION['feature_estimation_submitted']); ?>;
const evortixFeatureEstimateReplay = document.getElementById('evortixFeatureEstimateReplay');
let evortixFeatureReview = { projectId: '', items: [], accepted: 0, skipped: 0, state: {} };
let evortixFeatureReviewTimer = null;

function updateFeatureProjectView(hasProject) {
    const sections = document.getElementById('projectSections');
    if (sections) sections.style.display = hasProject ? 'block' : 'none';
    const managementSection = document.getElementById('projectManagementSection');
    if (managementSection) managementSection.style.display = hasProject ? 'block' : 'none';
}

function mountFeatureEstimationEditors() {
    const slot = document.getElementById('featureEstimationEditorSlot');
    const source = document.getElementById('featureEstimatorSource');
    if (!slot || !source) {
        return;
    }

    const projectId = selectedFeatureProjectId !== '0'
        ? selectedFeatureProjectId
        : String(currentProjectId || '');
    const matchingRows = Array.from(source.querySelectorAll('.estimation-card')).filter((row) => {
        return projectId !== ''
            && row.getAttribute('data-project-id') === projectId
            && row.getAttribute('data-readonly') !== '1';
    });

    if (!matchingRows.length) {
        const emptyState = document.createElement('div');
        emptyState.className = 'text-muted small py-2';
        emptyState.textContent = 'No editable feature estimates are assigned to you for this project.';
        slot.replaceChildren(emptyState);
        return;
    }

    const inputValue = (row, selector) => row.querySelector(selector)?.value || '';
    const escapeAttribute = (value) => escapeHtml(String(value ?? ''))
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    const displayDate = (value) => {
        if (!value) return 'Not scheduled';
        const date = new Date(`${value}T00:00:00`);
        return Number.isNaN(date.getTime())
            ? value
            : date.toLocaleDateString(undefined, { month: 'short', day: '2-digit', year: 'numeric' });
    };
    const tableRows = matchingRows.map((row) => {
        const taskId = row.getAttribute('data-task-id') || '';
        const title = row.querySelector('.feature-title')?.textContent?.trim() || 'Feature';
        const phase = inputValue(row, 'input[name="phase_name"]') || 'Roadmap phase';
        const expectation = inputValue(row, 'textarea[name="expectation"]');
        const minDays = inputValue(row, 'input[name="min_days"]');
        const likelyDays = inputValue(row, 'input[name="likely_days"]');
        const maxDays = inputValue(row, 'input[name="max_days"]');
        const plannedStart = inputValue(row, 'input[name="planned_start"]');
        const plannedEnd = inputValue(row, 'input[name="planned_end"]');
        const evortixSuggestion = safeJsonParse(row.getAttribute('data-evortix-estimate') || '{}', {});
        const initialPert = minDays && likelyDays && maxDays
            ? ((Number(minDays) + (4 * Number(likelyDays)) + Number(maxDays)) / 6).toFixed(1)
            : '—';

        return `
            <tr
                data-feature-bulk-row
                data-task-id="${escapeAttribute(taskId)}"
                data-evortix-suggestion="${escapeAttribute(JSON.stringify(evortixSuggestion))}"
            >
                <td>
                    <span class="feature-bulk-name">${escapeHtml(title)}</span>
                    <span class="feature-bulk-phase">${escapeHtml(phase)}</span>
                </td>
                <td>
                    <textarea
                        name="feature_estimates[${escapeAttribute(taskId)}][expectation]"
                        rows="2"
                        required
                        data-original="${escapeAttribute(expectation)}"
                        aria-label="Expectation for ${escapeAttribute(title)}"
                        placeholder="Expected feature outcome"
                    >${escapeHtml(expectation)}</textarea>
                </td>
                <td>
                    <input
                        type="number"
                        name="feature_estimates[${escapeAttribute(taskId)}][min_days]"
                        min="1"
                        step="1"
                        required
                        value="${escapeAttribute(minDays)}"
                        data-original="${escapeAttribute(minDays)}"
                        aria-label="Minimum days for ${escapeAttribute(title)}"
                    >
                </td>
                <td>
                    <input
                        type="number"
                        name="feature_estimates[${escapeAttribute(taskId)}][likely_days]"
                        min="1"
                        step="1"
                        required
                        value="${escapeAttribute(likelyDays)}"
                        data-original="${escapeAttribute(likelyDays)}"
                        aria-label="Most likely days for ${escapeAttribute(title)}"
                    >
                </td>
                <td>
                    <input
                        type="number"
                        name="feature_estimates[${escapeAttribute(taskId)}][max_days]"
                        min="1"
                        step="1"
                        required
                        value="${escapeAttribute(maxDays)}"
                        data-original="${escapeAttribute(maxDays)}"
                        aria-label="Maximum days for ${escapeAttribute(title)}"
                    >
                </td>
                <td>
                    <span class="feature-bulk-schedule">
                        ${escapeHtml(displayDate(plannedStart))}
                        <span aria-hidden="true">→</span>
                        ${escapeHtml(displayDate(plannedEnd))}
                    </span>
                </td>
                <td>
                    <span class="feature-bulk-pert" data-feature-bulk-pert>${escapeHtml(initialPert)}${initialPert === '—' ? '' : ' days'}</span>
                </td>
            </tr>
        `;
    }).join('');

    const bulkForm = document.createElement('form');
    bulkForm.method = 'POST';
    bulkForm.className = 'feature-bulk-form';
    bulkForm.id = 'bulkFeatureEstimationForm';
    bulkForm.dataset.genericDraftDisabled = '1';
    bulkForm.dataset.confirmSkip = '1';
    bulkForm.innerHTML = `
        <input type="hidden" name="bulk_save_feature_estimations" value="1">
        <input type="hidden" name="project_id" value="${escapeAttribute(projectId)}">
        <div class="feature-bulk-table-wrap">
            <table class="feature-bulk-table">
                <thead>
                    <tr>
                        <th scope="col">Feature</th>
                        <th scope="col">Expectation</th>
                        <th scope="col">Min</th>
                        <th scope="col">Likely</th>
                        <th scope="col">Max</th>
                        <th scope="col">Schedule</th>
                        <th scope="col">PERT</th>
                    </tr>
                </thead>
                <tbody>${tableRows}</tbody>
            </table>
        </div>
        <div class="feature-bulk-actions">
            <button type="button" class="feature-bulk-apply-all" data-evortix-open-review>
                <i class="fas fa-robot" aria-hidden="true"></i>
                <span>Ask Evortix for suggestions</span>
            </button>
            <button type="submit" class="feature-bulk-save-button">
                <i class="fas fa-floppy-disk" aria-hidden="true"></i>
                <span>Save Feature Estimates</span>
            </button>
        </div>
    `;
    slot.replaceChildren(bulkForm);

    const updateBulkSummary = () => {
        const rows = Array.from(bulkForm.querySelectorAll('[data-feature-bulk-row]'));
        let readyCount = 0;
        let changedCount = 0;
        let totalPert = 0;

        rows.forEach((row) => {
            const expectationInput = row.querySelector('textarea');
            const numberInputs = Array.from(row.querySelectorAll('input[type="number"]'));
            const [minInput, likelyInput, maxInput] = numberInputs;
            const expectation = expectationInput?.value.trim() || '';
            const minDays = Number(minInput?.value || 0);
            const likelyDays = Number(likelyInput?.value || 0);
            const maxDays = Number(maxInput?.value || 0);
            const hasValidEstimate = minDays >= 1
                && minDays <= likelyDays
                && likelyDays <= maxDays;
            const isReady = expectation !== '' && hasValidEstimate;
            const isChanged = [expectationInput, ...numberInputs].some((input) => {
                return input && input.value !== (input.dataset.original || '');
            });
            const pert = hasValidEstimate ? (minDays + (4 * likelyDays) + maxDays) / 6 : 0;

            if (isReady) {
                readyCount++;
            }
            if (hasValidEstimate) totalPert += pert;
            if (isChanged) changedCount++;
            row.classList.toggle('is-incomplete', !isReady);
            row.classList.toggle('is-ready', isReady);
            row.classList.toggle('is-changed', isReady && isChanged);

            const pertLabel = row.querySelector('[data-feature-bulk-pert]');
            if (pertLabel) {
                pertLabel.textContent = hasValidEstimate ? `${pert.toFixed(1)} days` : '—';
            }
        });

        const incompleteCount = rows.length - readyCount;
        const saveButton = bulkForm.querySelector('.feature-bulk-save-button');
        if (saveButton) {
            saveButton.disabled = rows.length === 0 || incompleteCount > 0;
            saveButton.dataset.featureCount = String(rows.length);
            saveButton.dataset.changedCount = String(changedCount);
            saveButton.dataset.totalPert = totalPert.toFixed(1);
        }
    };

    bulkForm.addEventListener('click', (event) => {
        const askEvortixButton = event.target.closest('[data-evortix-open-review]');
        if (!askEvortixButton) return;
        event.preventDefault();
        startEvortixFeatureReview(projectId, true);
    });

    bulkForm.addEventListener('input', updateBulkSummary);
    bulkForm.addEventListener('change', updateBulkSummary);
    bulkForm.addEventListener('submit', async (event) => {
        if (bulkForm.dataset.featureConfirmed === '1') {
            delete bulkForm.dataset.featureConfirmed;
            return;
        }

        updateBulkSummary();
        const firstIncomplete = bulkForm.querySelector('[data-feature-bulk-row].is-incomplete');
        if (firstIncomplete) {
            event.preventDefault();
            firstIncomplete.querySelector('textarea:invalid, input:invalid, textarea, input')?.focus();
            return;
        }

        event.preventDefault();
        const saveButton = bulkForm.querySelector('.feature-bulk-save-button');
        const rows = Array.from(bulkForm.querySelectorAll('[data-feature-bulk-row]'));
        const totalPert = Number(saveButton?.dataset.totalPert || 0);
        const changedCount = Number(saveButton?.dataset.changedCount || 0);
        const detailRows = rows.map((row) => {
            const title = row.querySelector('.feature-bulk-name')?.textContent?.trim() || 'Feature';
            const phase = row.querySelector('.feature-bulk-phase')?.textContent?.trim() || 'Roadmap phase';
            const expectation = row.querySelector('textarea')?.value.trim() || 'No expectation';
            const numberInputs = Array.from(row.querySelectorAll('input[type="number"]'));
            const minDays = numberInputs[0]?.value || '—';
            const likelyDays = numberInputs[1]?.value || '—';
            const maxDays = numberInputs[2]?.value || '—';
            const schedule = row.querySelector('.feature-bulk-schedule')?.textContent?.replace(/\s+/g, ' ').trim() || 'Not scheduled';
            const pert = row.querySelector('[data-feature-bulk-pert]')?.textContent?.trim() || '—';
            return `
                <tr>
                    <td><strong>${escapeHtml(title)}</strong><small>${escapeHtml(phase)}</small></td>
                    <td>${escapeHtml(expectation)}</td>
                    <td><strong>${escapeHtml(`${minDays} / ${likelyDays} / ${maxDays} days`)}</strong><small>Min / likely / max · PERT ${escapeHtml(pert)}</small></td>
                    <td>${escapeHtml(schedule)}</td>
                </tr>
            `;
        }).join('');

        let confirmed = true;
        if (typeof window.appConfirm === 'function') {
            const confirmModal = document.getElementById('appConfirmModal');
            confirmModal?.classList.add('feature-estimate-confirm-modal');
            const confirmation = window.appConfirm({
                title: 'Save Feature Estimates',
                titleIcon: 'fas fa-list-check',
                subject: `Save all ${rows.length} completed feature estimates?`,
                message: 'These estimates will be validated and saved. Use the Project Cost Summary for the final submission to Sales.',
                icon: 'fas fa-table-list',
                confirmIcon: 'fas fa-paper-plane',
                confirmText: 'Save Estimates',
                cancelText: 'Continue Editing'
            });
            const message = document.getElementById('appConfirmMessage');
            if (message) {
                message.innerHTML = `
                    <div class="feature-save-confirm-overview">
                        <span>${rows.length} features · ${changedCount} edited</span>
                        <strong>${totalPert.toFixed(1)} total PERT days</strong>
                    </div>
                    <div class="feature-save-confirm-table-wrap">
                        <table class="feature-save-confirm-table">
                            <thead>
                                <tr><th>Feature</th><th>Expectation</th><th>Estimate</th><th>Schedule</th></tr>
                            </thead>
                            <tbody>${detailRows}</tbody>
                        </table>
                    </div>
                `;
            }
            confirmed = await confirmation;
            confirmModal?.classList.remove('feature-estimate-confirm-modal');
        } else {
            confirmed = window.confirm(`Save all ${rows.length} feature estimates?`);
        }
        if (!confirmed) return;

        try {
            window.sessionStorage.setItem('evortix.expert.estimation.scroll', JSON.stringify({
                scrollY: window.scrollY,
                projectId
            }));
        } catch (error) {
            // Saving remains available when browser storage is unavailable.
        }
        bulkForm.dataset.featureConfirmed = '1';
        bulkForm.requestSubmit(saveButton || undefined);
    });
    updateBulkSummary();
    scheduleEvortixFeatureReview(projectId);
}

function setExpertWorkspace(workspace, persist = true) {
    const activeWorkspace = workspace === 'features' ? 'features' : 'cost';
    document.querySelectorAll('[data-expert-workspace]').forEach((tab) => {
        const isActive = tab.dataset.expertWorkspace === activeWorkspace;
        tab.classList.toggle('is-active', isActive);
        tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
        tab.tabIndex = isActive ? 0 : -1;
    });
    document.querySelectorAll('[data-expert-workspace-panel]').forEach((panel) => {
        panel.hidden = panel.dataset.expertWorkspacePanel !== activeWorkspace;
    });

    if (persist) {
        try {
            window.localStorage.setItem(expertWorkspaceStorageKey, activeWorkspace);
        } catch (error) {
            // The workspace still switches when browser storage is unavailable.
        }
    }

    if (activeWorkspace === 'features') {
        window.requestAnimationFrame(() => {
            renderProjectManagementTable();
            renderExpertGantt();
            if (currentProjectId) scheduleEvortixFeatureReview(currentProjectId);
        });
    }
}

document.querySelectorAll('[data-expert-workspace]').forEach((tab) => {
    tab.addEventListener('click', () => setExpertWorkspace(tab.dataset.expertWorkspace || 'cost'));
    tab.addEventListener('keydown', (event) => {
        if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
        event.preventDefault();
        const nextWorkspace = tab.dataset.expertWorkspace === 'cost' ? 'features' : 'cost';
        setExpertWorkspace(nextWorkspace);
        document.querySelector(`[data-expert-workspace="${nextWorkspace}"]`)?.focus();
    });
});

let initialExpertWorkspace = <?php echo json_encode((string) ($_GET['workspace'] ?? '') === 'features' ? 'features' : 'cost'); ?>;
try {
    initialExpertWorkspace = initialExpertWorkspace || window.localStorage.getItem(expertWorkspaceStorageKey) || 'cost';
} catch (error) {
    initialExpertWorkspace = initialExpertWorkspace || 'cost';
}
setExpertWorkspace(initialExpertWorkspace, false);

function buildRoadmapUrl(projectId) {
    const baseUrl = (window.BASE_URL || '<?php echo BASE_URL; ?>').replace(/\/$/, '');
    const roadmapUrl = `${baseUrl}/modules/roadmap/methodology.php?project_id=${encodeURIComponent(projectId)}`;
    const returnToUrl = `${baseUrl}/modules/expert/feature-estimation.php?project_estimation_id=${encodeURIComponent(projectId)}`;
    return `${roadmapUrl}&return_to=${encodeURIComponent(returnToUrl)}`;
}

function loadProjectSetup() {
    const select = document.getElementById('phaseProjectSelect');
    if (!select) return;
    const projectId = select.value;
    
    if (!projectId) {
        currentProjectId = null;
        if (evortixFeatureEstimateReplay) evortixFeatureEstimateReplay.hidden = true;
        const editTaskStaffingBtn = document.getElementById('editTaskStaffingBtn');
        if (editTaskStaffingBtn) editTaskStaffingBtn.hidden = true;
        updateFeatureProjectView(false);
        renderProjectManagementTable();
        filterFeatureList();
        return;
    }
    
    currentProjectId = projectId;

    const roadmapUrl = buildRoadmapUrl(projectId);
    const editTaskStaffingBtn = document.getElementById('editTaskStaffingBtn');
    if (editTaskStaffingBtn) {
        editTaskStaffingBtn.href = roadmapUrl;
        editTaskStaffingBtn.hidden = false;
    }

    renderProjectManagementTable();

    updateFeatureProjectView(true);
    filterFeatureList();
    if (document.querySelector('#featureEstimationEditorSlot [data-feature-bulk-row]')) {
        scheduleEvortixFeatureReview(projectId);
    }
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function getSelectedProjectName() {
    const select = document.getElementById('phaseProjectSelect');
    if (!select || !select.value) {
        return '';
    }
    return select.options[select.selectedIndex]?.textContent?.trim() || '';
}

function getSelectedProjectStartDate() {
    const select = document.getElementById('phaseProjectSelect');
    if (!select || !select.value) {
        return '';
    }
    return select.options[select.selectedIndex]?.getAttribute('data-start') || '';
}

function renderDepartmentOptions(selectedValue) {
    const currentValue = String(selectedValue || '').trim();
    const options = currentValue && !existingDepartments.includes(currentValue)
        ? [currentValue, ...existingDepartments]
        : existingDepartments;

    if (!options.length) {
        return '<option value="">No departments available</option>';
    }

    return ['<option value="">Select department</option>'].concat(
        options.map(department => {
            const selected = department === currentValue ? 'selected' : '';
            return `<option value="${escapeHtml(department)}" ${selected}>${escapeHtml(department)}</option>`;
        })
    ).join('');
}

function updateGanttPreview(card) {
    if (!card) return;
    const minVal = Math.max(0, parseInt(card.querySelector('input[name="min_days"]')?.value || '0', 10));
    const likelyVal = Math.max(minVal, parseInt(card.querySelector('input[name="likely_days"]')?.value || '0', 10));
    const maxVal = Math.max(likelyVal, parseInt(card.querySelector('input[name="max_days"]')?.value || '0', 10));
    const maxScale = Math.max(minVal, likelyVal, maxVal, 1);

    const minBar = card.querySelector('.min-bar');
    const likelyBar = card.querySelector('.likely-bar');
    const maxBar = card.querySelector('.max-bar');

    const minWidth = (minVal / maxScale) * 100;
    const likelyWidth = (Math.max(likelyVal - minVal, 0) / maxScale) * 100;
    const maxWidth = (Math.max(maxVal - Math.max(likelyVal, minVal), 0) / maxScale) * 100;

    if (minBar) minBar.style.width = `${minWidth}%`;
    if (likelyBar) likelyBar.style.width = `${likelyWidth}%`;
    if (maxBar) maxBar.style.width = `${maxWidth}%`;
}

function parseDateInputValue(value) {
    if (!value) return null;
    const parsed = new Date(`${value}T00:00:00`);
    return Number.isNaN(parsed.getTime()) ? null : parsed;
}

function inclusiveDurationDays(startValue, endValue) {
    const startDate = parseDateInputValue(startValue);
    const endDate = parseDateInputValue(endValue);
    if (!startDate || !endDate || endDate < startDate) {
        return 0;
    }
    return Math.floor((endDate - startDate) / 86400000) + 1;
}

function syncEstimateFromDeadline(card) {
    if (!card) return false;
    const isReadonly = card.getAttribute('data-readonly') === '1';
    const startInput = card.querySelector('input[name="planned_start"]');
    const endInput = card.querySelector('input[name="planned_end"]');
    const minInput = card.querySelector('input[name="min_days"]');
    const likelyInput = card.querySelector('input[name="likely_days"]');
    const maxInput = card.querySelector('input[name="max_days"]');
    const architectDeadline = card.getAttribute('data-architect-deadline') || card.getAttribute('data-project-end') || '';
    const basisDuration = Math.max(0, parseInt(card.getAttribute('data-estimate-duration-days') || '0', 10));
    const durationInput = card.querySelector('input[name="estimate_duration_days"]');

    if (endInput && architectDeadline) {
        endInput.max = architectDeadline;
    }
    if (durationInput && basisDuration > 0) {
        durationInput.value = String(basisDuration);
    }

    if (isReadonly) {
        updateGanttPreview(card);
        return true;
    }

    if (startInput && !startInput.value) {
        startInput.value = card.getAttribute('data-project-start') || '';
    }

    if (endInput && endInput.value && architectDeadline) {
        const endDate = parseDateInputValue(endInput.value);
        const architectDate = parseDateInputValue(architectDeadline);
        if (endDate && architectDate && endDate > architectDate) {
            endInput.value = architectDeadline;
        }
    }

    updateGanttPreview(card);
    const isValid = validateEstimateCard(card);
    updateEstimateCardState(card, isValid);
    return isValid;
}

function validateEstimateCard(card) {
    if (!card) return true;
    const minInput = card.querySelector('input[name="min_days"]');
    const likelyInput = card.querySelector('input[name="likely_days"]');
    const maxInput = card.querySelector('input[name="max_days"]');
    const startInput = card.querySelector('input[name="planned_start"]');
    const endInput = card.querySelector('input[name="planned_end"]');
    if (!minInput || !likelyInput || !maxInput) return true;

    const minVal = parseInt(minInput.value || '0', 10);
    const likelyVal = parseInt(likelyInput.value || '0', 10);
    const maxVal = parseInt(maxInput.value || '0', 10);
    const duration = inclusiveDurationDays(startInput?.value || '', endInput?.value || '');
    const architectDeadline = card.getAttribute('data-architect-deadline') || card.getAttribute('data-project-end') || '';

    minInput.setCustomValidity('');
    likelyInput.setCustomValidity('');
    maxInput.setCustomValidity('');
    if (startInput) startInput.setCustomValidity('');
    if (endInput) endInput.setCustomValidity('');

    if (startInput?.value && endInput?.value && duration <= 0) {
        const message = 'Feature end date must be the same as or later than the start date.';
        startInput.setCustomValidity(message);
        endInput.setCustomValidity(message);
        return false;
    }

    if (endInput?.value && architectDeadline) {
        const endDate = parseDateInputValue(endInput.value);
        const architectDate = parseDateInputValue(architectDeadline);
        if (endDate && architectDate && endDate > architectDate) {
            const message = 'Feature deadline cannot be later than the Architect deadline.';
            endInput.setCustomValidity(message);
            return false;
        }
    }

    if ((minInput.value || likelyInput.value || maxInput.value) && (minVal <= 0 || likelyVal <= 0 || maxVal <= 0)) {
        const message = 'All day estimates must be greater than zero.';
        minInput.setCustomValidity(message);
        likelyInput.setCustomValidity(message);
        maxInput.setCustomValidity(message);
        return false;
    }

    if (duration > 0 && maxVal > duration) {
        const message = 'Maximum estimate cannot exceed the selected deadline duration.';
        maxInput.setCustomValidity(message);
        return false;
    }

    if (minVal > likelyVal || likelyVal > maxVal) {
        const message = 'Use Minimum <= Most Likely <= Maximum.';
        minInput.setCustomValidity(message);
        likelyInput.setCustomValidity(message);
        maxInput.setCustomValidity(message);
        return false;
    }

    return true;
}

function updateEstimateCardState(card, isValid = validateEstimateCard(card)) {
    if (!card) return false;
    const minVal = parseInt(card.querySelector('input[name="min_days"]')?.value || '0', 10) || 0;
    const likelyVal = parseInt(card.querySelector('input[name="likely_days"]')?.value || '0', 10) || 0;
    const maxVal = parseInt(card.querySelector('input[name="max_days"]')?.value || '0', 10) || 0;
    const startValue = card.querySelector('input[name="planned_start"]')?.value || '';
    const endValue = card.querySelector('input[name="planned_end"]')?.value || '';
    const isDone = startValue !== '' && endValue !== '' && minVal > 0 && likelyVal > 0 && maxVal > 0 && minVal <= likelyVal && likelyVal <= maxVal && isValid;
    card.setAttribute('data-estimated', isDone ? '1' : '0');
    card.classList.toggle('complete', isDone);
    card.classList.toggle('incomplete', !isDone);
    return isDone;
}

function bindPreviewHandlers() {
    document.querySelectorAll('.estimation-card').forEach(card => {
        const inputs = card.querySelectorAll('.estimate-input');
        inputs.forEach(input => {
            input.addEventListener('input', () => updateGanttPreview(card));
        });
        card.querySelectorAll('input[name="planned_start"], input[name="planned_end"]').forEach(input => {
            input.addEventListener('input', () => {
                syncEstimateFromDeadline(card);
                renderExpertGantt();
                renderProjectManagementTable();
                filterFeatureList();
            });
            input.addEventListener('change', () => {
                syncEstimateFromDeadline(card);
                renderExpertGantt();
                renderProjectManagementTable();
                filterFeatureList();
            });
        });
        syncEstimateFromDeadline(card);
    });
}

function safeJsonParse(value, fallback = []) {
    try {
        const parsed = JSON.parse(value || '[]');
        if (Array.isArray(fallback)) {
            return Array.isArray(parsed) ? parsed : fallback;
        }
        if (fallback && typeof fallback === 'object') {
            return parsed && typeof parsed === 'object' ? parsed : fallback;
        }
        return parsed ?? fallback;
    } catch (error) {
        return fallback;
    }
}

function normalizeInvolvementPlan(entries, includeBlank = false) {
    const normalized = Array.isArray(entries)
        ? entries.map(entry => ({
            role: String(entry?.role || '').trim(),
            percentage: entry?.percentage === '' ? '' : (parseInt(entry?.percentage ?? '0', 10) || 0),
            required_count: parseInt(entry?.required_count ?? '0', 10) || 0
        })).filter(entry => includeBlank || entry.role !== '' || (entry.percentage !== '' && entry.percentage > 0))
        : [];

    if (!normalized.length && includeBlank) {
        return [{ role: '', percentage: '' }];
    }

    return normalized;
}

function normalizeComponentPlan(entries, includeBlank = false) {
    const normalized = Array.isArray(entries)
        ? entries.map(entry => ({
            name: String(entry?.name || '').trim()
        })).filter(entry => includeBlank || entry.name !== '')
        : [];

    if (!normalized.length && includeBlank) {
        return [{ name: '' }];
    }

    return normalized;
}

function getFeatureCard(taskId) {
    return document.querySelector(`.feature-row[data-task-id="${taskId}"]`);
}

function getFeaturePlanInput(taskId, inputName) {
    const card = getFeatureCard(taskId);
    return card ? card.querySelector(`input[name="${inputName}"]`) : null;
}

function getFeatureFormInput(taskId, inputName) {
    const card = getFeatureCard(taskId);
    return card ? card.querySelector(`[name="${inputName}"]`) : null;
}

function getFeatureInvolvementPlan(taskId, includeBlank = false) {
    const input = getFeaturePlanInput(taskId, 'involvement_plan_json');
    return normalizeInvolvementPlan(safeJsonParse(input?.value || '[]'), includeBlank);
}

function setFeatureInvolvementPlan(taskId, entries) {
    const input = getFeaturePlanInput(taskId, 'involvement_plan_json');
    if (!input) return;
    input.value = JSON.stringify(Array.isArray(entries) ? entries : []);
}

function getFeatureComponentPlan(taskId, includeBlank = false) {
    const input = getFeaturePlanInput(taskId, 'component_plan_json');
    return normalizeComponentPlan(safeJsonParse(input?.value || '[]'), includeBlank);
}

function setFeatureComponentPlan(taskId, entries) {
    const input = getFeaturePlanInput(taskId, 'component_plan_json');
    if (!input) return;
    input.value = JSON.stringify(Array.isArray(entries) ? entries : []);
}

function getArchitectPlan(taskId) {
    return architectPlanningByTask?.[String(taskId)] || architectPlanningByTask?.[taskId] || {
        phase_name: '',
        planned_start: '',
        planned_end: '',
        involvement_plan: [],
        component_plan: []
    };
}

function getFeatureHiddenInput(taskId, inputName) {
    const card = getFeatureCard(taskId);
    return card ? card.querySelector(`input[name="${inputName}"]`) : null;
}

function getRejectedRecommendations(taskId) {
    const input = getFeatureHiddenInput(taskId, 'rejected_recommendations_json');
    const entries = safeJsonParse(input?.value || '[]', []);
    return Array.isArray(entries) ? entries : [];
}

function setRejectedRecommendations(taskId, entries) {
    const input = getFeatureHiddenInput(taskId, 'rejected_recommendations_json');
    if (!input) return;
    input.value = JSON.stringify(Array.isArray(entries) ? entries : []);
}

function updateRecommendationPanel(card) {
    if (!card) return;
    const panel = card.querySelector('.recommendation-panel');
    if (!panel) return;
    const visibleCards = panel.querySelectorAll('.recommendation-card:not(.is-hidden)');
    panel.style.display = visibleCards.length ? '' : 'none';
}

function highlightManualRecommendationPanel(card, sourceLabel = '') {
    const panel = card?.querySelector('.recommendation-save-panel');
    const help = card?.querySelector('.manual-recommendation-help');
    if (!panel || !help) {
        return;
    }

    panel.classList.add('highlight');
    help.textContent = sourceLabel
        ? `You rejected ${sourceLabel}. Add a name here if you want your manual version to become the next recommendation.`
        : 'You can save this manual version as a reusable recommendation for later.';
}

function applyEvortixPayloadToBulkRow(taskId, payload) {
    const normalizedTaskId = String(taskId || '').replace(/\D+/g, '');
    if (!normalizedTaskId) return;
    const row = document.querySelector(
        `#bulkFeatureEstimationForm [data-feature-bulk-row][data-task-id="${normalizedTaskId}"]`
    );
    if (!row || !payload) return;

    const expectationInput = row.querySelector('textarea');
    const numberInputs = Array.from(row.querySelectorAll('input[type="number"]'));
    if (expectationInput && String(payload.expectation || '').trim() !== '') {
        expectationInput.value = String(payload.expectation);
    }
    if (numberInputs[0]) numberInputs[0].value = String(payload.min_days || '');
    if (numberInputs[1]) numberInputs[1].value = String(payload.likely_days || '');
    if (numberInputs[2]) numberInputs[2].value = String(payload.max_days || '');

    row.classList.add('is-evortix-applied');
    (expectationInput || numberInputs[0])?.dispatchEvent(new Event('input', { bubbles: true }));
}

function applyRecommendationToCard(card, payload, sourceType, sourceId) {
    if (!card || !payload) return;
    const taskId = card.getAttribute('data-task-id');
    const expectationInput = card.querySelector('textarea[name="expectation"]');
    const minInput = card.querySelector('input[name="min_days"]');
    const likelyInput = card.querySelector('input[name="likely_days"]');
    const maxInput = card.querySelector('input[name="max_days"]');
    const plannedStartInput = card.querySelector('input[name="planned_start"]');
    const plannedEndInput = card.querySelector('input[name="planned_end"]');
    const acceptedTypeInput = card.querySelector('input[name="accepted_recommendation_type"]');
    const acceptedIdInput = card.querySelector('input[name="accepted_recommendation_id"]');

    if (expectationInput && String(payload.expectation || '').trim() !== '') {
        expectationInput.value = payload.expectation;
    }
    if (minInput) minInput.value = payload.min_days || '';
    if (likelyInput) likelyInput.value = payload.likely_days || '';
    if (maxInput) maxInput.value = payload.max_days || '';

    const projectStartValue = getSelectedProjectStartDate();
    if (plannedStartInput && !plannedStartInput.value && projectStartValue) {
        plannedStartInput.value = projectStartValue;
    }
    if (plannedEndInput && !plannedEndInput.value && plannedStartInput?.value) {
        const duration = Math.max(1, parseInt(payload.likely_days || payload.max_days || payload.min_days || '1', 10));
        const endDate = new Date(`${plannedStartInput.value}T00:00:00`);
        endDate.setDate(endDate.getDate() + duration - 1);
        plannedEndInput.value = endDate.toISOString().slice(0, 10);
    }

    if (acceptedTypeInput) acceptedTypeInput.value = sourceType || '';
    if (acceptedIdInput) acceptedIdInput.value = String(sourceId || '');
    card.setAttribute('data-evortix-estimate-accepted', sourceType === 'evortix' ? '1' : '0');
    if (sourceType === 'evortix') {
        applyEvortixPayloadToBulkRow(taskId, payload);
    }

    if (Array.isArray(payload.involvement_plan) && payload.involvement_plan.length > 0) {
        setFeatureInvolvementPlan(taskId, payload.involvement_plan);
    }
    if (Array.isArray(payload.component_plan) && payload.component_plan.length > 0) {
        setFeatureComponentPlan(taskId, payload.component_plan);
    }

    const rejectedEntries = getRejectedRecommendations(taskId).filter(entry => {
        return !(entry?.source_type === sourceType && String(entry?.source_id) === String(sourceId));
    });
    setRejectedRecommendations(taskId, rejectedEntries);

    const recommendationCards = card.querySelectorAll('.recommendation-card');
    recommendationCards.forEach(item => {
        item.classList.toggle(
            'is-accepted',
            item.getAttribute('data-source-type') === sourceType && item.getAttribute('data-source-id') === String(sourceId)
        );
    });

    syncEstimateFromDeadline(card);
    card.setAttribute('data-estimated', '1');
    card.classList.add('complete');
    card.classList.remove('incomplete');
    renderProjectManagementTable();
    renderExpertGantt();
    filterFeatureList();
}

function evortixFeatureStateKey(projectId) {
    return `evortix.dss.expert.${currentExpertId}.feature-estimation.bulk-v2.project.${projectId}`;
}

function evortixFeaturePayload(card) {
    return safeJsonParse(card?.getAttribute('data-evortix-estimate') || '{}', {});
}

function evortixFeatureSignature(card, payload) {
    return `${card?.getAttribute('data-task-id') || ''}|${JSON.stringify(payload || {})}`;
}

function saveEvortixFeatureState() {
    if (!evortixFeatureReview.projectId) return;
    try {
        window.localStorage.setItem(
            evortixFeatureStateKey(evortixFeatureReview.projectId),
            JSON.stringify(evortixFeatureReview.state || {})
        );
    } catch (error) {
        // The review still works when browser storage is unavailable.
    }
}

function loadEvortixFeatureState(projectId) {
    try {
        const saved = JSON.parse(window.localStorage.getItem(evortixFeatureStateKey(projectId)) || '{}');
        return saved && typeof saved === 'object' ? saved : {};
    } catch (error) {
        return {};
    }
}

function closeEvortixFeatureSpeech() {
    const speech = document.querySelector('#chat-bot-float .chat-bot-speech');
    if (!speech) return;
    speech.hidden = true;
    speech.setAttribute('aria-hidden', 'true');
    speech.style.pointerEvents = 'none';
    window.chatBotFloatRobot?.setEmotion?.('', 0);
}

function showEvortixFeatureDecision() {
    const pendingItems = evortixFeatureReview.items
        .map((item, itemIndex) => ({ ...item, itemIndex }))
        .filter((item) => {
            const decision = evortixFeatureReview.state[item.taskId] || null;
            return !decision || decision.signature !== item.signature;
        });

    if (pendingItems.length === 0) {
        const total = evortixFeatureReview.accepted + evortixFeatureReview.skipped;
        closeEvortixFeatureSpeech();
        if (total > 0) {
            window.evortixAnnounceNotification?.(
                'Bulk feature estimates reviewed',
                `${evortixFeatureReview.accepted} estimate${evortixFeatureReview.accepted === 1 ? '' : 's'} applied and ${evortixFeatureReview.skipped} left for later.`,
                ''
            );
        }
        return;
    }

    const bot = document.getElementById('chat-bot-float');
    const speech = bot?.querySelector('.chat-bot-speech');
    if (!bot || !speech) {
        evortixFeatureReviewTimer = window.setTimeout(showEvortixFeatureDecision, 300);
        return;
    }

    const featureMessages = pendingItems.map((item) => {
        const payload = item.payload;
        const staffingText = Array.isArray(payload.involvement_plan) && payload.involvement_plan.length > 0
            ? payload.involvement_plan.map((entry) => {
                const role = String(entry?.role || '').trim();
                const count = Math.max(0, Number(entry?.required_count || 0));
                return role ? `${role}${count > 0 ? ` ×${count}` : ''}` : '';
            }).filter(Boolean).join(', ')
            : '';
        const componentText = Array.isArray(payload.component_plan) && payload.component_plan.length > 0
            ? payload.component_plan.map((entry) => String(entry?.name || '').trim()).filter(Boolean).join(', ')
            : '';
        const planningFacts = [
            ['Phase', String(payload.phase_name || '').trim()],
            ['Schedule', payload.planned_start && payload.planned_end ? `${payload.planned_start} to ${payload.planned_end}` : ''],
            ['Staffing', staffingText],
            ['Components', componentText],
            ['Basis', String(payload.basis || 'Architect roadmap')],
            ['Source', String(payload.source || 'Project planning data')]
        ].filter(([, value]) => String(value || '').trim() !== '');

        return `
            <article class="evortix-feature-message-card">
                <span class="evortix-feature-message-title">${escapeHtml(item.title)}</span>
                <span class="evortix-feature-message-copy">${escapeHtml(payload.expectation || 'Deliver the approved feature scope and acceptance criteria.')}</span>
                <div class="evortix-feature-estimate-chips" aria-label="Suggested estimate for ${escapeHtml(item.title)}">
                    <span>Min<strong>${payload.min_days}d</strong></span>
                    <span>Likely<strong>${payload.likely_days}d</strong></span>
                    <span>Max<strong>${payload.max_days}d</strong></span>
                    <span>PERT<strong>${Number(payload.pert_days || 0).toFixed(1)}d</strong></span>
                </div>
                <details class="evortix-feature-message-details">
                    <summary>Roadmap details</summary>
                    <div class="evortix-feature-message-facts">
                        ${planningFacts.map(([label, value]) => `
                            <div><span>${escapeHtml(label)}</span><strong>${escapeHtml(value)}</strong></div>
                        `).join('')}
                    </div>
                </details>
                <div class="evortix-feature-message-actions">
                    <button type="button" data-evortix-feature-choice="skip" data-evortix-feature-index="${item.itemIndex}">Not now</button>
                    <button type="button" data-evortix-feature-choice="accept" data-evortix-feature-index="${item.itemIndex}">Apply</button>
                </div>
            </article>`;
    }).join('');

    speech.className = 'chat-bot-speech evortix-project-notification evortix-architect-dss';
    speech.hidden = false;
    speech.setAttribute('aria-hidden', 'false');
    speech.style.pointerEvents = 'auto';
    speech.innerHTML = `
        <strong class="evortix-dss-heading"><i class="fas fa-robot me-1"></i>${pendingItems.length} feature suggestion${pendingItems.length === 1 ? '' : 's'} ready</strong>
        <span class="evortix-feature-message-intro">Here are all estimates for this bulk selection. Review them together or apply them all now.</span>
        <div class="evortix-feature-message-list">${featureMessages}</div>
        <div class="evortix-dss-actions">
            <button type="button" class="btn btn-light btn-sm" data-evortix-feature-choice="close">Review later</button>
            <button type="button" class="btn btn-primary btn-sm" data-evortix-feature-choice="accept-all">Apply all ${pendingItems.length}</button>
        </div>`;

    bot.hidden = false;
    bot.setAttribute('aria-hidden', 'false');
    bot.classList.remove('is-returning-bed', 'is-preparing-speech');
    bot.classList.add('is-speaking');
    window.chatBotWidgetRobot?.setMode?.('empty-bed');
    window.chatBotFloatRobot?.setMode?.('standing');
    window.chatBotFloatRobot?.point?.('left', 0);
}

function startEvortixFeatureReview(projectId, replay = false) {
    const normalizedProjectId = String(projectId || '');
    if (!normalizedProjectId) return;
    if (evortixFeatureReviewTimer) window.clearTimeout(evortixFeatureReviewTimer);

    const cards = Array.from(document.querySelectorAll(`.estimation-card[data-project-id="${normalizedProjectId}"]`))
        .filter((card) => card.getAttribute('data-readonly') !== '1' && card.getAttribute('data-saved-estimated') !== '1');
    const state = replay ? {} : loadEvortixFeatureState(normalizedProjectId);

    cards.forEach((card) => {
        const taskId = String(card.getAttribute('data-task-id') || '');
        const payload = evortixFeaturePayload(card);
        const decision = state[taskId] || null;
        if (decision?.status === 'accepted' && decision.signature === evortixFeatureSignature(card, payload)) {
            applyRecommendationToCard(card, decision.payload || payload, 'evortix', taskId);
        }
    });

    const items = cards.map((card) => {
        const taskId = String(card.getAttribute('data-task-id') || '');
        const payload = evortixFeaturePayload(card);
        return {
            card,
            taskId,
            title: String(card.getAttribute('data-task-title') || 'Feature'),
            payload,
            signature: evortixFeatureSignature(card, payload)
        };
    });
    const hasPendingSuggestions = items.some((item) => {
        const decision = state[item.taskId] || null;
        return !decision || decision.signature !== item.signature;
    });

    evortixFeatureReview = {
        projectId: normalizedProjectId,
        items,
        accepted: 0,
        skipped: 0,
        state
    };
    if (evortixFeatureEstimateReplay) evortixFeatureEstimateReplay.hidden = cards.length === 0;
    if (items.length === 0 || !hasPendingSuggestions) {
        closeEvortixFeatureSpeech();
        return;
    }

    evortixFeatureReviewTimer = window.setTimeout(showEvortixFeatureDecision, 650);
}

function scheduleEvortixFeatureReview(projectId) {
    if (evortixFeatureReviewTimer) window.clearTimeout(evortixFeatureReviewTimer);
    evortixFeatureReviewTimer = window.setTimeout(() => startEvortixFeatureReview(projectId), 450);
}

document.addEventListener('click', (event) => {
    const replayButton = event.target.closest('#evortixFeatureEstimateReplay');
    if (replayButton) {
        event.preventDefault();
        if (!currentProjectId) return;
        try { window.localStorage.removeItem(evortixFeatureStateKey(currentProjectId)); } catch (error) {}
        startEvortixFeatureReview(currentProjectId, true);
        return;
    }

    const choice = event.target.closest('[data-evortix-feature-choice]');
    if (!choice) return;
    event.preventDefault();
    event.stopPropagation();
    const action = choice.getAttribute('data-evortix-feature-choice') || '';
    if (action === 'close') {
        closeEvortixFeatureSpeech();
        return;
    }

    const pendingItems = evortixFeatureReview.items.filter((item) => {
        const decision = evortixFeatureReview.state[item.taskId] || null;
        return !decision || decision.signature !== item.signature;
    });
    const selectedIndex = Number(choice.getAttribute('data-evortix-feature-index') || -1);
    const selectedItem = selectedIndex >= 0 ? evortixFeatureReview.items[selectedIndex] : null;
    const targets = action === 'accept-all'
        ? pendingItems
        : (selectedItem && pendingItems.includes(selectedItem) ? [selectedItem] : []);
    if (targets.length === 0) return;

    const accepted = action === 'accept' || action === 'accept-all';
    targets.forEach((item) => {
        if (accepted) {
            applyRecommendationToCard(item.card, item.payload, 'evortix', item.taskId);
            evortixFeatureReview.accepted++;
        } else {
            evortixFeatureReview.skipped++;
        }
        evortixFeatureReview.state[item.taskId] = {
            status: accepted ? 'accepted' : 'skipped',
            signature: item.signature,
            payload: item.payload
        };
    });
    saveEvortixFeatureState();
    window.chatBotFloatRobot?.setEmotion?.(accepted ? 'happy' : 'thinking', 650);
    window.setTimeout(showEvortixFeatureDecision, 260);
}, true);

async function persistRejectedRecommendation(taskId, sourceType, sourceId) {
    const response = await fetch(`${window.BASE_URL}api/save-feature-recommendation-feedback.php`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
        },
        body: new URLSearchParams({
            task_id: String(taskId || ''),
            source_type: String(sourceType || ''),
            source_id: String(sourceId || ''),
            action: 'rejected'
        })
    });

    if (!response.ok) {
        throw new Error('Unable to save rejected recommendation.');
    }

    return response.json();
}

function bindRecommendationHandlers() {
    document.addEventListener('click', async (event) => {
        const acceptButton = event.target.closest('.recommendation-accept');
        if (acceptButton) {
            const card = acceptButton.closest('.estimation-card');
            if (!card) return;

            const payload = safeJsonParse(acceptButton.getAttribute('data-payload') || '{}', {});
            applyRecommendationToCard(
                card,
                payload,
                acceptButton.getAttribute('data-source-type') || '',
                acceptButton.getAttribute('data-source-id') || ''
            );
            return;
        }

        const rejectButton = event.target.closest('.recommendation-reject');
        if (!rejectButton) {
            return;
        }

        const card = rejectButton.closest('.estimation-card');
        const recommendationCard = rejectButton.closest('.recommendation-card');
        const taskId = card?.getAttribute('data-task-id') || '';
        const sourceType = rejectButton.getAttribute('data-source-type') || '';
        const sourceId = rejectButton.getAttribute('data-source-id') || '';

        if (!card || !recommendationCard || !taskId || !sourceType || !sourceId) {
            return;
        }

        const rejectedEntries = getRejectedRecommendations(taskId).filter(entry => {
            return !(entry?.source_type === sourceType && String(entry?.source_id) === String(sourceId));
        });
        rejectedEntries.push({ source_type: sourceType, source_id: sourceId });
        setRejectedRecommendations(taskId, rejectedEntries);

        recommendationCard.classList.add('is-hidden');
        updateRecommendationPanel(card);
        highlightManualRecommendationPanel(card, recommendationCard.querySelector('.recommendation-title')?.textContent?.trim() || 'that suggestion');

        const acceptedTypeInput = card.querySelector('input[name="accepted_recommendation_type"]');
        const acceptedIdInput = card.querySelector('input[name="accepted_recommendation_id"]');
        if (acceptedTypeInput?.value === sourceType && acceptedIdInput?.value === String(sourceId)) {
            acceptedTypeInput.value = '';
            acceptedIdInput.value = '';
        }

        try {
            await persistRejectedRecommendation(taskId, sourceType, sourceId);
        } catch (error) {
            console.warn(error);
        }
    });
}

function renderProjectManagementTable() {
    const container = document.getElementById('projectManagementTable');
    const meta = document.getElementById('managementTableMeta');
    if (!container || !meta) {
        return;
    }

    if (!currentProjectId) {
        meta.textContent = 'Select a project to load the planning table.';
        container.innerHTML = '<div class="table-empty">Select a project to load the planning table.</div>';
        return;
    }

    const projectName = getSelectedProjectName();
    const featureRows = Array.from(document.querySelectorAll('.feature-row')).filter(row => {
        return row.id !== 'featureProgressRow' && row.getAttribute('data-project-id') === String(currentProjectId);
    });
    meta.textContent = `${projectName || 'Selected project'} | ${featureRows.length} feature${featureRows.length === 1 ? '' : 's'}`;

    if (!featureRows.length) {
        container.innerHTML = '<div class="table-empty">No features are available for this project yet.</div>';
        return;
    }

    const allocationColors = ['#19758b', '#ee9638', '#a8456d', '#376fc2', '#168a4b'];
    const featurePlans = featureRows.map(row => {
        const taskId = row.getAttribute('data-task-id') || '';
        const architectPlan = getArchitectPlan(taskId);
        return {
            featureName: row.querySelector('.feature-title')?.textContent?.trim() || 'Feature',
            phaseName: String(architectPlan.phase_name || '').trim(),
            plannedStart: String(architectPlan.planned_start || '').trim(),
            plannedEnd: String(architectPlan.planned_end || '').trim(),
            involvementPlan: normalizeInvolvementPlan(architectPlan.involvement_plan || []),
            componentPlan: normalizeComponentPlan(architectPlan.component_plan || []),
        };
    });

    const allocationSignature = (plan) => JSON.stringify(
        plan.map((item) => ({
            role: String(item.role || 'Department').trim().toLowerCase(),
            requiredCount: Math.max(0, Number(item.required_count || 0)),
            percentage: Math.max(0, Math.min(100, Number(item.percentage || 0))),
        })).sort((left, right) => left.role.localeCompare(right.role))
    );

    const firstAllocationSignature = allocationSignature(featurePlans[0]?.involvementPlan || []);
    const hasSharedAllocation = featurePlans.length > 1
        && featurePlans[0].involvementPlan.length > 0
        && featurePlans.every((feature) => allocationSignature(feature.involvementPlan) === firstAllocationSignature);
    const sharedAllocationPlan = hasSharedAllocation ? featurePlans[0].involvementPlan : [];
    const hasAnyComponents = featurePlans.some((feature) => feature.componentPlan.length > 0);
    const planningContextSignature = (feature) => JSON.stringify({
        phase: String(feature.phaseName || '').trim().toLowerCase(),
        start: String(feature.plannedStart || '').trim(),
        end: String(feature.plannedEnd || '').trim(),
    });
    const firstPlanningContext = featurePlans[0] || {};
    const firstPlanningSignature = planningContextSignature(firstPlanningContext);
    const hasSharedPlanningContext = featurePlans.length > 1
        && Boolean(firstPlanningContext.phaseName || firstPlanningContext.plannedStart || firstPlanningContext.plannedEnd)
        && featurePlans.every((feature) => planningContextSignature(feature) === firstPlanningSignature);
    const useCompactFeatureList = hasSharedAllocation && hasSharedPlanningContext && !hasAnyComponents;
    const formatPlanDate = (value) => {
        const normalizedValue = String(value || '').trim();
        if (!normalizedValue) {
            return 'TBD';
        }
        const parsedDate = new Date(`${normalizedValue}T00:00:00`);
        if (Number.isNaN(parsedDate.getTime())) {
            return normalizedValue;
        }
        return parsedDate.toLocaleDateString(undefined, {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
        });
    };

    const renderAllocationStack = (plan) => {
        if (!plan.length) {
            return '<span class="table-empty-copy">No allocation planned</span>';
        }

        const percentages = plan.map((item) => Math.max(0, Math.min(100, Number(item.percentage || 0))));
        const total = percentages.reduce((sum, value) => sum + value, 0);
        const chartTotal = total > 0 ? total : plan.length;
        const chartLabel = plan.map((item, index) => `${item.role || 'Department'} ${percentages[index]}%`).join(', ');
        const segments = plan.map((item, index) => {
            const segmentWeight = total > 0 ? percentages[index] : 1;
            const segmentWidth = (segmentWeight / chartTotal) * 100;
            const allocationColor = allocationColors[index % allocationColors.length];
            return `<span class="table-allocation-segment" style="--allocation-color:${allocationColor};width:${segmentWidth.toFixed(3)}%" title="${escapeHtml(item.role || 'Department')}: ${percentages[index]}%"></span>`;
        }).join('');

        return `<span class="table-allocation-stack" role="img" aria-label="${escapeHtml(chartLabel)}">${segments}</span>`;
    };

    const renderAllocationDetails = (plan) => `
        <div class="table-allocation-list">
            ${plan.map((item) => {
                const requiredCount = Math.max(0, Number(item.required_count || 0));
                const allocationPercentage = Math.max(0, Math.min(100, Number(item.percentage || 0)));
                const countLabel = requiredCount > 0
                    ? `${requiredCount} ${requiredCount === 1 ? 'person' : 'people'}`
                    : 'Headcount not set';
                return `
                    <div class="table-allocation-row">
                        <div class="table-allocation-copy">
                            <span class="table-allocation-name">${escapeHtml(item.role || 'Department')}</span>
                            <span class="table-allocation-count">${countLabel}</span>
                        </div>
                        <span class="table-allocation-percent">${allocationPercentage}%</span>
                    </div>
                `;
            }).join('')}
        </div>
    `;

    const renderAllocationLegend = (plan) => plan.map((item, index) => {
        const requiredCount = Math.max(0, Number(item.required_count || 0));
        const allocationPercentage = Math.max(0, Math.min(100, Number(item.percentage || 0)));
        const countLabel = requiredCount > 0
            ? `${requiredCount} ${requiredCount === 1 ? 'person' : 'people'}`
            : 'Headcount not set';
        const allocationColor = allocationColors[index % allocationColors.length];
        return `
            <span class="management-allocation-legend__item">
                <i class="management-allocation-legend__dot" style="--allocation-color:${allocationColor}" aria-hidden="true"></i>
                <span>${escapeHtml(item.role || 'Department')}</span>
                <strong>${allocationPercentage}%</strong>
                <small>${countLabel}</small>
            </span>
        `;
    }).join('');

    const sharedPlanFacts = [
        ...(hasSharedPlanningContext && firstPlanningContext.phaseName
            ? [{
                label: 'Phase',
                value: firstPlanningContext.phaseName,
            }]
            : []),
        ...(hasSharedPlanningContext && (firstPlanningContext.plannedStart || firstPlanningContext.plannedEnd)
            ? [{
                label: 'Schedule',
                value: `${formatPlanDate(firstPlanningContext.plannedStart)} \u2013 ${formatPlanDate(firstPlanningContext.plannedEnd)}`,
            }]
            : []),
        {
            label: 'Features',
            value: `${featurePlans.length} included`,
        },
    ];
    const sharedPlanFactsMarkup = sharedPlanFacts.map((fact) => `
        <span class="management-plan-fact">
            <small>${escapeHtml(fact.label)}</small>
            <strong>${escapeHtml(fact.value)}</strong>
        </span>
    `).join('');

    const sharedAllocationMarkup = hasSharedAllocation || hasSharedPlanningContext
        ? `
            <div class="management-shared-allocation">
                <div class="management-shared-allocation__copy">
                    <strong><i class="fas fa-layer-group" aria-hidden="true"></i> Plan Overview</strong>
                    <span>Common planning details shown once for this feature group</span>
                </div>
                <div class="management-plan-facts">${sharedPlanFactsMarkup}</div>
                ${hasSharedAllocation
                    ? `<div class="management-shared-allocation__visual">
                        <span class="management-allocation-label">Staffing mix</span>
                        ${renderAllocationStack(sharedAllocationPlan)}
                        <div class="management-allocation-legend">${renderAllocationLegend(sharedAllocationPlan)}</div>
                    </div>`
                    : ''}
            </div>
        `
        : '';

    const rowsHtml = featurePlans.map((feature) => {
        const {
            featureName,
            phaseName,
            plannedStart,
            plannedEnd,
            involvementPlan,
            componentPlan,
        } = feature;
        let allocationMarkup = '<span class="table-empty-copy">No allocation planned</span>';
        if (involvementPlan.length) {
            allocationMarkup = `
                <details class="table-allocation-details">
                    <summary>
                        <span class="table-allocation-summary-label">${involvementPlan.length} department${involvementPlan.length === 1 ? '' : 's'}</span>
                        ${renderAllocationStack(involvementPlan)}
                        <i class="fas fa-chevron-down table-allocation-details__chevron" aria-hidden="true"></i>
                    </summary>
                    ${renderAllocationDetails(involvementPlan)}
                </details>
            `;
        }

        const componentNames = componentPlan
            .map((item) => String(item.name || '').trim())
            .filter(Boolean)
            .map((name) => escapeHtml(name));
        const componentMarkup = componentNames.length
            ? `<span class="table-component-copy">${componentNames.join(', ')}</span>`
            : '<span class="table-empty-copy" title="No component planned">&mdash;</span>';
        const plannedRangeMarkup = plannedStart || plannedEnd
            ? `<span class="table-date-range"><i class="far fa-calendar" aria-hidden="true"></i>${escapeHtml(plannedStart || 'TBD')} &ndash; ${escapeHtml(plannedEnd || 'TBD')}</span>`
            : '';

        return `
            <tr>
                <td><div class="table-feature-name">${escapeHtml(featureName)}</div></td>
                ${hasSharedPlanningContext
                    ? ''
                    : `<td>${phaseName ? `<span class="table-phase-tag"><i class="fas fa-layer-group"></i>${escapeHtml(phaseName)}</span>` : '<span class="table-empty-copy">Not specified</span>'}</td>
                       <td>${plannedRangeMarkup || '<span class="table-empty-copy">Not scheduled</span>'}</td>`}
                ${hasSharedAllocation ? '' : `<td>${allocationMarkup}</td>`}
                ${hasAnyComponents ? `<td>${componentMarkup}</td>` : ''}
            </tr>
        `;
    }).join('');

    const compactFeatureListMarkup = `
        <section class="management-feature-list" aria-label="Features in this plan">
            <div class="management-feature-list__header">
                <span>Features in this plan</span>
                <small>${featurePlans.length} items</small>
            </div>
            <div class="management-feature-grid" role="list">
                ${featurePlans.map((feature, index) => `
                    <div class="management-feature-grid__item" role="listitem">
                        <span class="management-feature-grid__index">${String(index + 1).padStart(2, '0')}</span>
                        <span>${escapeHtml(feature.featureName)}</span>
                    </div>
                `).join('')}
            </div>
        </section>
    `;

    container.innerHTML = `
        ${sharedAllocationMarkup}
        ${useCompactFeatureList
            ? compactFeatureListMarkup
            : `<table class="management-table management-table--grouped">
                <thead>
                    <tr>
                        <th>Feature</th>
                        ${hasSharedPlanningContext ? '' : '<th>Phase</th><th>Schedule</th>'}
                        ${hasSharedAllocation ? '' : '<th>Department Allocation</th>'}
                        ${hasAnyComponents ? '<th>Components</th>' : ''}
                    </tr>
                </thead>
                <tbody>${rowsHtml}</tbody>
            </table>`}
    `;
}

function buildGanttTasks() {
    const tasks = [];
    const projectStartFallback = getSelectedProjectStartDate() || new Date().toISOString().slice(0, 10);
    document.querySelectorAll('.feature-row').forEach(row => {
        if (currentProjectId && row.getAttribute('data-project-id') !== currentProjectId) {
            return;
        }
        const title = row.querySelector('.feature-title')?.textContent?.trim() || 'Feature';
        const taskId = row.querySelector('input[name="task_id"]')?.value || title;
        const startInput = row.querySelector('input[name="planned_start"]')?.value;
        const endInput = row.querySelector('input[name="planned_end"]')?.value;
        const minDays = Math.max(0, parseInt(row.querySelector('input[name="min_days"]')?.value || '0', 10));
        const likelyDays = Math.max(minDays, parseInt(row.querySelector('input[name="likely_days"]')?.value || '0', 10));
        const maxDays = Math.max(likelyDays, parseInt(row.querySelector('input[name="max_days"]')?.value || '0', 10));

        let startDate = startInput || projectStartFallback;
        let endDate = endInput;

        if (startDate && !endDate && maxDays > 0) {
            const start = new Date(startDate + 'T00:00:00');
            start.setDate(start.getDate() + Math.max(1, maxDays) - 1);
            endDate = start.toISOString().slice(0, 10);
        }

        if (!endDate) {
            const duration = maxDays || likelyDays || minDays || 1;
            const start = new Date(startDate + 'T00:00:00');
            start.setDate(start.getDate() + Math.max(1, duration) - 1);
            endDate = start.toISOString().slice(0, 10);
        }

        tasks.push({
            id: `task-${taskId}`,
            name: title,
            start: startDate,
            end: endDate,
            progress: 0,
            custom_class: 'gantt-task',
            min_days: minDays,
            likely_days: likelyDays,
            max_days: maxDays,
            is_phase: false
        });
    });
    return tasks;
}

function diffDays(startDate, endDate) {
    return Math.max(1, Math.round((endDate - startDate) / 86400000) + 1);
}

function buildThreePointChartRows(tasks) {
    const rows = tasks.map(task => {
        const startDate = new Date(`${task.start}T00:00:00`);
        const endDate = new Date(`${task.end}T00:00:00`);
        const dateDuration = Number.isNaN(startDate.getTime()) || Number.isNaN(endDate.getTime())
            ? 1
            : diffDays(startDate, endDate);
        const maxDays = dateDuration;
        const minDays = Math.min(maxDays, Math.max(0, parseInt(task.min_days || '0', 10)));
        const likelyDays = Math.min(maxDays, Math.max(minDays, parseInt(task.likely_days || '0', 10)));

        return {
            name: task.name,
            startDate,
            minDays,
            likelyDays: Math.max(likelyDays - minDays, 0),
            maxDays: Math.max(maxDays - likelyDays, 0)
        };
    }).filter(task => !Number.isNaN(task.startDate.getTime()));

    if (!rows.length) {
        return rows;
    }

    const earliestStart = new Date(Math.min(...rows.map(task => task.startDate.getTime())));
    rows.forEach(task => {
        task.offset = Math.max(0, diffDays(earliestStart, task.startDate) - 1);
    });

    return rows;
}

function renderExpertGantt() {
    const container = document.getElementById('expertGanttContainer');
    if (!container) return;
    const tasks = buildGanttTasks();
    if (tasks.length === 0) {
        container.innerHTML = '<div class="text-muted small">No timeline data yet.</div>';
        return;
    }
    if (typeof Chart === 'undefined') {
        container.innerHTML = '<div class="text-danger small">Chart.js failed to load. Please check your internet connection.</div>';
        return;
    }

    container.innerHTML = '<canvas id="expertGanttCanvas" height="240"></canvas>';
    const ctx = document.getElementById('expertGanttCanvas').getContext('2d');
    const chartRows = buildThreePointChartRows(tasks);
    if (!chartRows.length) {
        container.innerHTML = '<div class="text-muted small">Add start dates and day estimates to build the timeline.</div>';
        return;
    }
    const labels = chartRows.map(task => task.name);
    const offsets = chartRows.map(task => task.offset);
    const minSegments = chartRows.map(task => task.minDays);
    const likelySegments = chartRows.map(task => task.likelyDays);
    const maxSegments = chartRows.map(task => task.maxDays);

    if (window.expertGanttChart) {
        window.expertGanttChart.destroy();
    }

    window.expertGanttChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: 'Offset',
                data: offsets,
                backgroundColor: 'rgba(0,0,0,0)',
                borderWidth: 0,
                borderSkipped: false,
                stack: 'gantt'
            }, {
                label: 'Minimum',
                data: minSegments,
                backgroundColor: '#bfdbfe',
                borderRadius: 999,
                borderSkipped: false,
                barThickness: 12,
                stack: 'gantt'
            }, {
                label: 'Most Likely',
                data: likelySegments,
                backgroundColor: '#86efac',
                borderRadius: 999,
                borderSkipped: false,
                barThickness: 12,
                stack: 'gantt'
            }, {
                label: 'Maximum',
                data: maxSegments,
                backgroundColor: '#fdba74',
                borderRadius: 999,
                borderSkipped: false,
                barThickness: 12,
                stack: 'gantt'
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            layout: {
                padding: {
                    top: 8,
                    right: 16,
                    bottom: 4,
                    left: 4
                }
            },
            scales: {
                x: {
                    beginAtZero: true,
                    stacked: true,
                    title: { display: true, text: 'Days' },
                    grid: {
                        color: 'rgba(148, 163, 184, 0.18)',
                        drawBorder: false
                    },
                    ticks: {
                        color: '#64748b',
                        padding: 8
                    },
                    border: { display: false }
                },
                y: {
                    stacked: true,
                    grid: { display: false, drawBorder: false },
                    ticks: {
                        color: '#334155',
                        padding: 10
                    },
                    border: { display: false }
                }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#0f172a',
                    titleColor: '#f8fafc',
                    bodyColor: '#e2e8f0',
                    padding: 10,
                    displayColors: false,
                    callbacks: {
                        label: (context) => {
                            if (context.dataset.label === 'Offset') {
                                return null;
                            }
                            const value = Number(context.raw || 0);
                            return `${context.dataset.label}: ${value} day${value === 1 ? '' : 's'}`;
                        }
                    }
                }
            }
        }
    });
}

function bindGanttHandlers() {
    document.addEventListener('input', (e) => {
        if (e.target.matches('input[name="planned_start"], input[name="planned_end"], input[name="likely_days"], input[name="min_days"], input[name="max_days"]')) {
            const row = e.target.closest('.feature-row');
            if (row) {
                syncEstimateFromDeadline(row);
            }
            renderExpertGantt();
            renderProjectManagementTable();
            if (row) {
                updateEstimateCardState(row);
                filterFeatureList();
            }
        }
    });

    document.addEventListener('submit', (e) => {
        const form = e.target;
        if (!(form instanceof HTMLFormElement) || !form.closest('.estimation-card')) {
            return;
        }
        const card = form.closest('.estimation-card');
        if (!validateEstimateCard(card)) {
            e.preventDefault();
            form.reportValidity();
            return;
        }
        try {
            window.sessionStorage.setItem('evortix.expert.estimation.scroll', JSON.stringify({
                scrollY: window.scrollY,
                taskId: card.getAttribute('data-task-id') || '',
                projectId: card.getAttribute('data-project-id') || ''
            }));
        } catch (error) {
            // Saving still works if browser storage is unavailable.
        }
    });

    renderExpertGantt();
    renderProjectManagementTable();
}

function filterFeatureList() {
    let total = 0;
    let estimated = 0;
    document.querySelectorAll('.feature-row').forEach(row => {
        if (row.id === 'featureProgressRow') {
            return;
        }
        const match = !currentProjectId || row.getAttribute('data-project-id') === currentProjectId;
        row.style.display = match ? '' : 'none';
        if (match) {
            total += 1;
            if (row.getAttribute('data-estimated') === '1') {
                estimated += 1;
            }
        }
    });
    const progressText = document.getElementById('featureProgressText');
    const progressBadge = document.getElementById('featureProgressBadge');
    const progressHelp = document.getElementById('featureProgressHelp');
    if (progressText) {
        progressText.textContent = `Estimated: ${estimated} / ${total}`;
    }
    if (progressBadge) {
        const empty = total === 0;
        const ready = total > 0 && estimated === total;
        progressBadge.textContent = empty ? 'No Features' : (ready ? 'Ready' : 'Not Ready');
        progressBadge.classList.toggle('ready', ready);
        progressBadge.classList.toggle('pending', !ready && !empty);
        progressBadge.classList.toggle('empty', empty);
    }
    if (progressHelp) {
        progressHelp.textContent = total === 0
            ? 'No features are available for estimation in this project yet.'
            : 'All features must be estimated before submission to Sales.';
    }
}

// Initialize
const phaseSelect = document.getElementById('phaseProjectSelect');
if (phaseSelect) {
    phaseSelect.addEventListener('change', loadProjectSetup);
    phaseSelect.value = selectedFeatureProjectId !== '0' ? selectedFeatureProjectId : '';
    loadProjectSetup();
}
mountFeatureEstimationEditors();
bindPreviewHandlers();
bindGanttHandlers();
bindRecommendationHandlers();
filterFeatureList();
if (featureSubmittedMessage) {
    try {
        const savedPosition = JSON.parse(window.sessionStorage.getItem('evortix.expert.estimation.scroll') || 'null');
        window.sessionStorage.removeItem('evortix.expert.estimation.scroll');
        if (savedPosition && Number.isFinite(Number(savedPosition.scrollY))) {
            if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
            const restorePosition = () => window.scrollTo({ top: Math.max(0, Number(savedPosition.scrollY)), behavior: 'auto' });
            window.requestAnimationFrame(restorePosition);
            window.setTimeout(restorePosition, 180);
            window.setTimeout(restorePosition, 500);
        }
    } catch (error) {
        // Fall back to the browser's normal scroll behavior.
    }
}
setTimeout(renderExpertGantt, 50);
setTimeout(renderProjectManagementTable, 50);
if (featureSubmittedMessage) {
    setTimeout(() => {
        window.appToast?.(
            'Estimation submitted',
            featureSubmittedMessage,
            'success'
        );
    }, 150);
}
</script>

<?php
$architectDssAssistantConfig = ['items' => []];
include '../../includes/architect-dss-assistant.php';
?>

<style>
body.expert-feature-estimation-page .chat-bot-speech.evortix-architect-dss {
    top: auto;
    right: calc(100% + 6px);
    bottom: 22px;
    width: min(340px, calc(100vw - 175px));
    min-width: 280px;
    max-height: min(420px, calc(100vh - 110px));
    transform: none;
}

body.expert-feature-estimation-page .chat-bot-float.is-speaking .chat-bot-speech.evortix-architect-dss {
    animation: none;
    opacity: 1;
    transform: none;
    visibility: visible;
}

body.expert-feature-estimation-page .chat-bot-speech.evortix-architect-dss::after {
    top: auto;
    bottom: 48px;
    transform: rotate(45deg);
}

html:not([data-theme="dark"]) body.expert-feature-estimation-page:not([data-theme="dark"]):not(.dark-mode)
    .chat-bot-speech.evortix-architect-dss .evortix-dss-heading {
    color: #083a5d !important;
    opacity: 1 !important;
}

html:not([data-theme="dark"]) body.expert-feature-estimation-page:not([data-theme="dark"]):not(.dark-mode)
    .chat-bot-speech.evortix-architect-dss .evortix-feature-message-intro {
    color: #175f8f !important;
    opacity: 1 !important;
}

body.expert-feature-estimation-page .evortix-feature-message-title {
    margin-top: 0.5rem;
    color: #083a5d;
    font-size: 0.9rem;
    font-weight: 800;
    line-height: 1.25;
}

body.expert-feature-estimation-page .evortix-feature-message-intro {
    margin-top: 0.35rem;
    color: #175f8f;
    font-size: 0.72rem;
    line-height: 1.35;
}

body.expert-feature-estimation-page .evortix-feature-message-list {
    display: grid;
    gap: 0.55rem;
    max-height: 270px;
    margin-top: 0.55rem;
    padding-right: 0.2rem;
    overflow-y: auto;
    overscroll-behavior: contain;
}

body.expert-feature-estimation-page .evortix-feature-message-card {
    padding: 0.6rem;
    border: 1px solid rgba(77, 190, 238, 0.24);
    border-radius: 14px 14px 14px 5px;
    background: rgba(255, 255, 255, 0.48);
}

body.expert-feature-estimation-page .evortix-feature-message-card .evortix-feature-message-title {
    margin-top: 0;
}

body.expert-feature-estimation-page .evortix-feature-message-copy {
    margin-top: 0.35rem;
    padding: 0.55rem 0.65rem;
    border: 1px solid rgba(77, 190, 238, 0.22);
    border-radius: 12px 12px 12px 4px;
    background: rgba(255, 255, 255, 0.62);
    color: #175f8f;
    font-size: 0.76rem;
    line-height: 1.4;
}

body.expert-feature-estimation-page .evortix-feature-message-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.35rem;
    margin-top: 0.5rem;
}

body.expert-feature-estimation-page .evortix-feature-message-actions button {
    min-height: 29px;
    padding: 0.3rem 0.65rem;
    border: 1px solid rgba(31, 99, 146, 0.24);
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.76);
    color: #175f8f;
    font-size: 0.66rem;
    font-weight: 800;
}

body.expert-feature-estimation-page .evortix-feature-message-actions button:last-child {
    border-color: #1877b1;
    background: #1877b1;
    color: #fff;
}

body.expert-feature-estimation-page .evortix-feature-estimate-chips {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 0.3rem;
    margin-top: 0.55rem;
}

body.expert-feature-estimation-page .evortix-feature-estimate-chips span {
    min-height: 0;
    padding: 0.3rem 0.2rem;
    border: 1px solid rgba(77, 190, 238, 0.24);
    border-radius: 9px;
    background: rgba(255, 255, 255, 0.74);
    color: #397397;
    font-size: 0.58rem;
    font-weight: 700;
    line-height: 1.15;
    text-align: center;
}

body.expert-feature-estimation-page .evortix-feature-estimate-chips strong {
    display: block;
    min-height: 0;
    padding: 0.12rem 0 0;
    color: #083a5d;
    font-size: 0.76rem;
    line-height: 1.1;
}

body.expert-feature-estimation-page .evortix-feature-message-details {
    margin-top: 0.5rem;
    color: #175f8f;
    font-size: 0.7rem;
}

body.expert-feature-estimation-page .evortix-feature-message-details summary {
    cursor: pointer;
    font-weight: 800;
}

body.expert-feature-estimation-page .evortix-feature-message-facts {
    display: grid;
    gap: 0.3rem;
    margin-top: 0.4rem;
    padding: 0.5rem;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.54);
}

body.expert-feature-estimation-page .evortix-feature-message-facts div {
    display: grid;
    grid-template-columns: 64px minmax(0, 1fr);
    gap: 0.4rem;
}

body.expert-feature-estimation-page .evortix-feature-message-facts span,
body.expert-feature-estimation-page .evortix-feature-message-facts strong {
    min-height: 0;
    padding: 0;
    font-size: 0.66rem;
    line-height: 1.3;
}

body.expert-feature-estimation-page .evortix-feature-message-facts span {
    color: #397397;
    font-weight: 700;
}

body.expert-feature-estimation-page .evortix-feature-message-facts strong {
    color: #083a5d;
    overflow-wrap: anywhere;
}

body.expert-feature-estimation-page .chat-bot-speech.evortix-architect-dss .evortix-dss-actions {
    gap: 0.4rem;
    margin-top: 0.6rem;
}

body.expert-feature-estimation-page .chat-bot-speech.evortix-architect-dss .evortix-dss-actions .btn {
    min-height: 34px;
    border-radius: 999px;
    font-size: 0.7rem;
}

@media (max-width: 600px) {
    body.expert-feature-estimation-page .chat-bot-speech.evortix-architect-dss {
        right: calc(100% + 4px);
        bottom: 18px;
        width: min(270px, calc(100vw - 132px));
        min-width: 215px;
        max-height: min(390px, calc(100vh - 80px));
    }
}
</style>
<script>
document.body.classList.add('expert-feature-estimation-page');
</script>

<?php include '../../includes/footer.php'; ?>
