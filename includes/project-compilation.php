<?php

/**
 * Immutable architect compilation history. A new version is stored only when
 * the material project plan differs from the latest saved compilation.
 */
function projectCompilationEnsureSchema(mysqli|PgsqlMysqliCompatConnection $conn): void
{
    $id = DB_DRIVER === 'pgsql' ? 'SERIAL PRIMARY KEY' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $text = DB_DRIVER === 'pgsql' ? 'TEXT' : 'LONGTEXT';
    $timestamp = DB_DRIVER === 'pgsql' ? 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP' : 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP';
    $conn->query("CREATE TABLE IF NOT EXISTS project_compilations (
        id {$id}, project_id INT NOT NULL, compilation_no INT NOT NULL,
        fingerprint VARCHAR(64) NOT NULL, snapshot_json {$text} NOT NULL,
        change_source VARCHAR(80) NULL, impact_json {$text} NULL, created_by INT NULL, created_at {$timestamp}
    )");
    if (!dbColumnExists($conn, 'project_compilations', 'impact_json')) {
        $conn->query("ALTER TABLE project_compilations ADD COLUMN impact_json {$text} NULL");
    }
}

function projectCompilationRows(mysqli|PgsqlMysqliCompatConnection $conn, string $sql): array
{
    $rows = [];
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) $rows[] = $row;
    }
    return $rows;
}

function projectCompilationSnapshot(mysqli|PgsqlMysqliCompatConnection $conn, int $projectId): array
{
    $projectResult = $conn->query("SELECT * FROM projects WHERE id = {$projectId} LIMIT 1");
    $project = $projectResult ? ($projectResult->fetch_assoc() ?: []) : [];
    return [
        'project' => $project,
        'budget' => ['sales_budget' => (float) ($project['sales_budget'] ?? 0)],
        'features' => projectCompilationRows($conn, "SELECT title, description, priority, status FROM tasks WHERE project_id = {$projectId} AND task_type = 'feature' ORDER BY id"),
        'technologies' => projectCompilationRows($conn, "SELECT tech_id, service_type, estimated_cost, notes FROM tech_recommendations WHERE project_id = {$projectId} ORDER BY tech_id"),
        'allocations' => projectCompilationRows($conn, "SELECT role, required_count, complexity_level FROM developer_allocations WHERE project_id = {$projectId} ORDER BY role"),
        'roadmap_phases' => dbTableExists($conn, 'methodology_phases') ? projectCompilationRows($conn, "SELECT name, start_date, end_date, developer_count, sequence_no FROM methodology_phases WHERE project_id = {$projectId} ORDER BY sequence_no, id") : [],
        'roadmap_tasks' => dbTableExists($conn, 'methodology_tasks') ? projectCompilationRows($conn, "SELECT phase_id, task_id, title, department, start_date, end_date FROM methodology_tasks WHERE project_id = {$projectId} ORDER BY phase_id, id") : [],
        'estimates' => dbTableExists($conn, 'expert_project_estimations') ? projectCompilationRows($conn, "SELECT project_duration_months, project_complexity, allocation_json, additional_costs, total_developer_cost, total_technology_stack_cost, overall_estimated_project_cost FROM expert_project_estimations WHERE project_id = {$projectId} ORDER BY id") : [],
        'documents' => projectCompilationRows($conn, "SELECT document_type, file_name, description FROM architecture_documents WHERE project_id = {$projectId} ORDER BY id"),
    ];
}

function projectCompilationChangeImpact(array $previous, array $current): array
{
    $changed = static fn(string $key): bool => json_encode($previous[$key] ?? null) !== json_encode($current[$key] ?? null);
    $featureChanged = $changed('features') || $changed('project');
    $allocationChanged = $changed('allocations') || $featureChanged || $changed('roadmap_phases') || $changed('roadmap_tasks');
    $technologyChanged = $changed('technologies') || $featureChanged;
    $roadmapChanged = $changed('roadmap_phases') || $changed('roadmap_tasks') || $featureChanged || $changed('allocations');
    $estimateChanged = $changed('estimates') || $allocationChanged || $technologyChanged || $roadmapChanged;
    $budgetChanged = $changed('budget') || $estimateChanged;
    $documentsChanged = $changed('documents') || $featureChanged || $technologyChanged || $roadmapChanged;

    return [
        'Developer allocation' => $allocationChanged ? 'Recalculate roles and headcounts from the revised feature workload and schedule.' : 'No recalculation required.',
        'Technology' => $technologyChanged ? 'Revalidate stack compatibility, services, integrations, and recurring technology costs.' : 'No recalculation required.',
        'Roadmap' => $roadmapChanged ? 'Regenerate or review phase dates, task placement, dependencies, and staffing capacity.' : 'No recalculation required.',
        'Estimates' => $estimateChanged ? 'Recalculate duration, developer contribution, technology cost, and the overall estimate.' : 'No recalculation required.',
        'Budget' => $budgetChanged ? 'Compare the recalculated salary-based project cost with the client budget.' : 'No recalculation required.',
        'Documents' => $documentsChanged ? 'Review architecture, workflow, deployment, and handoff documents for outdated details.' : 'No recalculation required.',
    ];
}

function projectCompilationRecord(mysqli|PgsqlMysqliCompatConnection $conn, int $projectId, int $userId, string $source): int
{
    projectCompilationEnsureSchema($conn);
    $snapshot = projectCompilationSnapshot($conn, $projectId);
    $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $fingerprint = hash('sha256', (string) $json);
    $latest = $conn->query("SELECT compilation_no, fingerprint, snapshot_json FROM project_compilations WHERE project_id = {$projectId} ORDER BY compilation_no DESC LIMIT 1");
    $previous = $latest ? $latest->fetch_assoc() : null;
    if ($previous && hash_equals((string) $previous['fingerprint'], $fingerprint)) return (int) $previous['compilation_no'];

    $number = ((int) ($previous['compilation_no'] ?? 0)) + 1;
    $previousSnapshot = json_decode((string) ($previous['snapshot_json'] ?? ''), true);
    $impact = projectCompilationChangeImpact(is_array($previousSnapshot) ? $previousSnapshot : [], $snapshot);
    $impactJson = json_encode($impact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $conn->prepare('INSERT INTO project_compilations (project_id, compilation_no, fingerprint, snapshot_json, change_source, impact_json, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('iissssi', $projectId, $number, $fingerprint, $json, $source, $impactJson, $userId);
    $stmt->execute();
    $stmt->close();
    if ($previous) {
        $_SESSION['evortix_change_impact'] = ['project_id' => $projectId, 'compilation_no' => $number, 'items' => $impact];
    }
    return $number;
}

function projectCompilationLatestNumber(mysqli|PgsqlMysqliCompatConnection $conn, int $projectId): int
{
    projectCompilationEnsureSchema($conn);
    $result = $conn->query("SELECT MAX(compilation_no) AS compilation_no FROM project_compilations WHERE project_id = {$projectId}");
    return (int) (($result ? $result->fetch_assoc()['compilation_no'] ?? 0 : 0));
}
