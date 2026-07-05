<?php
declare(strict_types=1);

function projectProgressFeatureCondition(string $taskAlias = 't'): string
{
    return "{$taskAlias}.task_type IN ('feature', 'enhancement')";
}

function projectProgressMergedCondition(string $taskAlias = 't'): string
{
    return "({$taskAlias}.merged_at IS NOT NULL OR {$taskAlias}.architect_reviewed_at IS NOT NULL OR {$taskAlias}.status IN ('merged', 'ready_for_release', 'completed'))";
}

function projectProgressPercent(int $totalFeatures, int $mergedFeatures): int
{
    if ($totalFeatures <= 0) {
        return 0;
    }

    return max(0, min(100, (int) round(($mergedFeatures / $totalFeatures) * 100)));
}

function projectProgressIsAdditionalFeature(array $feature): bool
{
    $taskType = strtolower((string) ($feature['task_type'] ?? ''));
    $title = strtolower((string) ($feature['title'] ?? ''));
    $description = strtolower((string) ($feature['description'] ?? ''));

    return $taskType === 'enhancement'
        || str_contains($title, 'additional')
        || str_contains($description, 'additional')
        || str_contains($description, 'change request')
        || str_contains($description, 'client request');
}

function fetchSalesProjectChecklists(mysqli|PgsqlMysqliCompatConnection $conn, int $limit = 5): array
{
    $limit = max(1, min(12, $limit));
    $featureCondition = projectProgressFeatureCondition('t');
    $mergedCondition = projectProgressMergedCondition('t');

    $projectsResult = $conn->query("
        SELECT
            p.id,
            p.name AS project_name,
            p.status AS project_status,
            p.created_by AS project_created_by,
            c.name AS client_name,
            c.created_by AS client_created_by,
            (
                SELECT pr.id
                FROM project_repositories pr
                WHERE pr.project_id = p.id
                  AND pr.repository_status = 'active'
                ORDER BY pr.updated_at DESC, pr.id DESC
                LIMIT 1
            ) AS repository_id,
            (
                SELECT pr.default_branch_name
                FROM project_repositories pr
                WHERE pr.project_id = p.id
                  AND pr.repository_status = 'active'
                ORDER BY pr.updated_at DESC, pr.id DESC
                LIMIT 1
            ) AS repository_branch_name,
            COUNT(t.id) AS total_features,
            SUM(CASE WHEN {$mergedCondition} THEN 1 ELSE 0 END) AS merged_features
        FROM projects p
        JOIN clients c ON c.id = p.client_id
        JOIN tasks t ON t.project_id = p.id AND {$featureCondition}
        WHERE p.status != 'cancelled'
        GROUP BY p.id, p.name, p.status, p.created_by, c.name, c.created_by
        ORDER BY p.updated_at DESC, p.id DESC
        LIMIT {$limit}
    ");

    if (!$projectsResult) {
        return [];
    }

    $projects = [];
    $projectIds = [];
    while ($project = $projectsResult->fetch_assoc()) {
        $projectId = (int) ($project['id'] ?? 0);
        if ($projectId <= 0) {
            continue;
        }

        $totalFeatures = (int) ($project['total_features'] ?? 0);
        $mergedFeatures = (int) ($project['merged_features'] ?? 0);
        $projects[$projectId] = [
            'id' => $projectId,
            'project_name' => (string) ($project['project_name'] ?? 'Project'),
            'project_status' => (string) ($project['project_status'] ?? ''),
            'project_created_by' => (int) ($project['project_created_by'] ?? 0),
            'client_name' => (string) ($project['client_name'] ?? 'Client'),
            'client_created_by' => (int) ($project['client_created_by'] ?? 0),
            'repository_id' => (int) ($project['repository_id'] ?? 0),
            'repository_branch_name' => (string) (($project['repository_branch_name'] ?? '') ?: 'main'),
            'total_features' => $totalFeatures,
            'merged_features' => $mergedFeatures,
            'progress' => projectProgressPercent($totalFeatures, $mergedFeatures),
            'features' => [],
            'additional_features' => [],
        ];
        $projectIds[] = $projectId;
    }

    if (!$projectIds) {
        return [];
    }

    $idList = implode(',', array_map('intval', $projectIds));
    $featureRows = $conn->query("
        SELECT
            t.id,
            t.project_id,
            t.title,
            t.description,
            t.task_type,
            t.status,
            t.merged_at,
            t.architect_reviewed_at,
            architect.full_name AS architect_name
        FROM tasks t
        LEFT JOIN users architect ON architect.id = t.architect_reviewed_by
        WHERE t.project_id IN ({$idList})
          AND " . projectProgressFeatureCondition('t') . "
        ORDER BY t.project_id ASC, t.id ASC
    ");

    if ($featureRows) {
        while ($feature = $featureRows->fetch_assoc()) {
            $projectId = (int) ($feature['project_id'] ?? 0);
            if (!isset($projects[$projectId])) {
                continue;
            }

            $feature['is_merged'] = !empty($feature['merged_at'])
                || !empty($feature['architect_reviewed_at'])
                || (string) ($feature['status'] ?? '') === 'merged';

            if (projectProgressIsAdditionalFeature($feature)) {
                $projects[$projectId]['additional_features'][] = $feature;
            } else {
                $projects[$projectId]['features'][] = $feature;
            }
        }
    }

    return array_values($projects);
}
?>
