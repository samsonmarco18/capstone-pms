<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function di_float(mixed $value): float
{
    return is_numeric($value) ? (float) $value : 0.0;
}

function di_int(mixed $value): int
{
    return (int) round(di_float($value));
}

function di_clamp(float $value, float $min = 0.0, float $max = 100.0): float
{
    return max($min, min($max, $value));
}

function di_ratio(float $numerator, float $denominator): float
{
    return $numerator / max($denominator, 0.00001);
}

function di_scale(float $value, float $target): float
{
    return di_clamp(di_ratio($value, max($target, 0.00001)) * 100);
}

function di_average(array $values): float
{
    $count = count($values);

    return array_sum($values) / max($count, 1);
}

function di_day_diff(string $from, string $to): int
{
    $fromTs = strtotime($from) ?: time();
    $toTs = strtotime($to) ?: time();

    return (int) floor(($toTs - $fromTs) / 86400);
}

function di_prepare(mysqli|PgsqlMysqliCompatConnection $conn, string $sql): mysqli_stmt|PgsqlMysqliCompatStatement
{
    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        throw new RuntimeException('Failed to prepare decision insights query: ' . $conn->error);
    }

    return $stmt;
}

function di_execute(mysqli_stmt|PgsqlMysqliCompatStatement $stmt, string $types = '', array $params = []): mysqli_stmt|PgsqlMysqliCompatStatement
{
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        $error = $stmt instanceof mysqli_stmt ? (string) $stmt->error : 'Unknown error';
        $stmt->close();
        throw new RuntimeException('Failed to execute decision insights query: ' . $error);
    }

    return $stmt;
}

function di_fetch_all(mysqli_stmt|PgsqlMysqliCompatStatement $stmt): array
{
    $rows = [];
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    $stmt->close();

    return $rows;
}

function di_fetch_one(mysqli_stmt|PgsqlMysqliCompatStatement $stmt): array
{
    $result = $stmt->get_result();
    $row = $result->fetch_assoc() ?: [];
    $stmt->close();

    return $row;
}

function di_bands(): array
{
    return [
        ['min' => 0.0, 'max' => 24.99, 'label' => 'Stable', 'tone' => 'success'],
        ['min' => 25.0, 'max' => 49.99, 'label' => 'Watch', 'tone' => 'info'],
        ['min' => 50.0, 'max' => 74.99, 'label' => 'At Risk', 'tone' => 'warning'],
        ['min' => 75.0, 'max' => 100.0, 'label' => 'Critical', 'tone' => 'danger'],
    ];
}

function di_heatmap_bands(): array
{
    return [
        ['min' => 0.0, 'max' => 33.33, 'label' => 'Low', 'index' => 1],
        ['min' => 33.34, 'max' => 66.66, 'label' => 'Medium', 'index' => 2],
        ['min' => 66.67, 'max' => 100.0, 'label' => 'High', 'index' => 3],
    ];
}

function di_band_for_score(float $score, ?array $bands = null): array
{
    $bands = $bands ?? di_bands();

    foreach ($bands as $band) {
        if ($score >= $band['min'] && $score <= $band['max']) {
            return $band;
        }
    }

    return end($bands) ?: ['min' => 0.0, 'max' => 100.0, 'label' => 'Stable', 'tone' => 'success'];
}

function di_access_rule(array $user): array
{
    $userId = (int) ($user['id'] ?? 0);
    $rules = [
        'admin' => ['where' => '1 = 1', 'types' => '', 'params' => []],
        'sales' => ['where' => '1 = 1', 'types' => '', 'params' => []],
        'sul' => ['where' => '1 = 1', 'types' => '', 'params' => []],
        'architect' => ['where' => '(p.architect_id = ? OR p.architect_id IS NULL)', 'types' => 'i', 'params' => [$userId]],
        'expert' => ['where' => 'EXISTS (SELECT 1 FROM tasks tx WHERE tx.project_id = p.id AND tx.assigned_to = ?)', 'types' => 'i', 'params' => [$userId]],
        'developer' => ['where' => 'EXISTS (SELECT 1 FROM tasks tx WHERE tx.project_id = p.id AND tx.assigned_to = ?)', 'types' => 'i', 'params' => [$userId]],
    ];

    return $rules[$user['role'] ?? 'admin'] ?? $rules['admin'];
}

function di_load_accessible_projects(mysqli|PgsqlMysqliCompatConnection $conn, array $user): array
{
    $rule = di_access_rule($user);
    $sql = "
        SELECT
            p.id,
            p.name,
            p.status,
            p.complexity_level,
            p.created_at,
            c.name AS client_name,
            p.timeline_start,
            p.timeline_end
        FROM projects p
        JOIN clients c ON c.id = p.client_id
        WHERE p.status != 'cancelled' AND {$rule['where']}
        ORDER BY p.created_at DESC, p.id DESC
    ";

    return di_fetch_all(di_execute(di_prepare($conn, $sql), $rule['types'], $rule['params']));
}

function di_load_attention_project_summaries(mysqli|PgsqlMysqliCompatConnection $conn, array $user): array
{
    $rule = di_access_rule($user);
    $sql = "
        SELECT
            p.id,
            p.name,
            COUNT(DISTINCT t.id) AS issue_count,
            SUM(CASE WHEN t.status = 'blocked' THEN 1 ELSE 0 END) AS blocked_count,
            SUM(CASE WHEN t.status IN ('qa_failed', 'sul_revision') THEN 1 ELSE 0 END) AS revision_count,
            SUM(CASE WHEN (
                t.status = 'overdue'
                OR (
                    t.deadline IS NOT NULL
                    AND t.deadline < CURRENT_DATE
                    AND t.status NOT IN ('completed', 'done')
                )
            ) THEN 1 ELSE 0 END) AS overdue_count
        FROM projects p
        INNER JOIN tasks t ON t.project_id = p.id
        WHERE p.status NOT IN ('completed', 'cancelled')
          AND {$rule['where']}
          AND (
              t.status IN ('blocked', 'overdue', 'qa_failed', 'sul_revision')
              OR (
                  t.deadline IS NOT NULL
                  AND t.deadline < CURRENT_DATE
                  AND t.status NOT IN ('completed', 'done')
              )
          )
        GROUP BY p.id, p.name
        ORDER BY issue_count DESC, p.name ASC
    ";

    $rows = di_fetch_all(di_execute(di_prepare($conn, $sql), $rule['types'], $rule['params']));
    $summaries = [];
    foreach ($rows as $row) {
        $projectId = (int) ($row['id'] ?? 0);
        if ($projectId <= 0) {
            continue;
        }

        $summaries[$projectId] = [
            'id' => $projectId,
            'name' => (string) ($row['name'] ?? 'Project'),
            'issue_count' => max(1, (int) ($row['issue_count'] ?? 1)),
            'blocked_count' => max(0, (int) ($row['blocked_count'] ?? 0)),
            'revision_count' => max(0, (int) ($row['revision_count'] ?? 0)),
            'overdue_count' => max(0, (int) ($row['overdue_count'] ?? 0)),
        ];
    }

    return $summaries;
}

function di_load_attention_projects(mysqli|PgsqlMysqliCompatConnection $conn, array $user): array
{
    $rows = di_load_attention_project_summaries($conn, $user);
    $projects = [];
    foreach ($rows as $row) {
        $projectId = (int) ($row['id'] ?? 0);
        if ($projectId > 0) {
            $projects[$projectId] = max(1, (int) ($row['issue_count'] ?? 1));
        }
    }

    return $projects;
}

function di_render_project_attention_banner(mysqli|PgsqlMysqliCompatConnection $conn, array $user, int $projectId, array $options = []): void
{
    if ($projectId <= 0) {
        return;
    }

    $summaries = di_load_attention_project_summaries($conn, $user);
    $summary = $summaries[$projectId] ?? null;
    if ($summary === null) {
        return;
    }

    static $stylesPrinted = false;
    if (!$stylesPrinted) {
        echo '<style>
        .project-insight-alert{align-items:center;background:#fff7ed;border:1px solid #fed7aa;border-left:5px solid #f97316;border-radius:12px;box-shadow:0 10px 24px rgba(15,23,42,.08);color:#7c2d12;display:flex;gap:.9rem;margin-bottom:1rem;padding:1rem 1.1rem}
        .project-insight-alert__icon{align-items:center;background:#f97316;border-radius:999px;color:#fff;display:inline-flex;flex:0 0 auto;font-weight:800;height:34px;justify-content:center;width:34px}
        .project-insight-alert__body{min-width:0}
        .project-insight-alert__body strong{display:block;font-size:.98rem;margin-bottom:.15rem}
        .project-insight-alert__body span{display:block;font-size:.86rem;line-height:1.35}
        .project-insight-alert__action{background:#f97316;border-radius:999px;color:#fff;font-size:.84rem;font-weight:700;margin-left:auto;padding:.55rem .9rem;text-decoration:none;white-space:nowrap}
        .project-insight-alert__action:hover{background:#ea580c;color:#fff}
        @media(max-width:768px){.project-insight-alert{align-items:flex-start;flex-direction:column}.project-insight-alert__action{margin-left:0}}
        :is(html[data-theme="dark"],body[data-theme="dark"],body.dark-mode) .project-insight-alert{background:rgba(249,115,22,.12);border-color:rgba(251,146,60,.35);color:#fed7aa}
        </style>';
        $stylesPrinted = true;
    }

    $issueCount = (int) $summary['issue_count'];
    $details = [];
    if ((int) $summary['blocked_count'] > 0) {
        $details[] = (int) $summary['blocked_count'] . ' blocked';
    }
    if ((int) $summary['overdue_count'] > 0) {
        $details[] = (int) $summary['overdue_count'] . ' overdue';
    }
    if ((int) $summary['revision_count'] > 0) {
        $details[] = (int) $summary['revision_count'] . ' failed or revision';
    }
    $detailText = $details === [] ? $issueCount . ' item needs attention' : implode(', ', $details);
    $title = trim((string) ($options['title'] ?? 'Project Insights needs attention'));
    $url = BASE_URL . 'modules/insights/decision-center.php?project_id=' . $projectId . '&view=statistics';

    echo '<div class="project-insight-alert">';
    echo '<span class="project-insight-alert__icon">!</span>';
    echo '<div class="project-insight-alert__body">';
    echo '<strong>' . htmlspecialchars($title) . ': ' . htmlspecialchars((string) $summary['name']) . '</strong>';
    echo '<span>' . htmlspecialchars($detailText) . '. Open Project Insights to see the recommended next action.</span>';
    echo '</div>';
    echo '<a class="project-insight-alert__action" href="' . htmlspecialchars($url) . '"><i class="fas fa-lightbulb"></i> View Insights</a>';
    echo '</div>';
}

function di_empty_decision_insights(): array
{
    $zeroBand = di_band_for_score(0.0);
    $heatBand = di_band_for_score(0.0, di_heatmap_bands());

    return [
        'project' => [],
        'metrics' => [
            'total_tasks' => 0,
            'completed_tasks' => 0,
            'open_tasks' => 0,
            'active_tasks' => 0,
            'overdue_tasks' => 0,
            'blocked_tasks' => 0,
            'unassigned_tasks' => 0,
            'review_tasks' => 0,
            'stalled_tasks' => 0,
            'recent_git_updates' => 0,
            'assigned_members' => 0,
            'required_members' => 0,
            'timeline_total_days' => 1,
            'timeline_elapsed_days' => 0,
            'timeline_remaining_days' => 1,
            'planned_progress' => 0,
            'actual_progress' => 0,
            'estimated_work_days' => 1,
            'earned_value' => 0,
            'planned_value' => 0,
            'schedule_variance' => 0,
            'projected_remaining_days' => 0,
            'forecast_delay_days' => 0,
            'monthly_burn' => 0,
            'budget_total' => 0,
            'budget_baseline_source' => 'Modeled project budget floor',
            'budget_health' => 100,
            'budget_consumed_ratio' => 0,
            'overspend_forecast' => 0,
            'planning_readiness' => 0,
            'resource_efficiency' => 0,
            'git_momentum' => 0,
            'schedule_stability' => 100,
            'risk_probability' => 0,
            'risk_impact' => 0,
            'risk_exposure' => 0,
            'decision_confidence' => 0,
            'testing_risk' => 0,
            'deployment_risk' => 0,
            'client_risk' => 0,
            'impact_chain_score' => 0,
        ],
        'bands' => [
            'risk_probability' => $zeroBand,
            'risk_impact' => $zeroBand,
            'risk_exposure' => $zeroBand,
            'decision_confidence' => $zeroBand,
            'resource_efficiency' => $zeroBand,
            'planning_readiness' => $zeroBand,
            'budget_health' => $zeroBand,
            'schedule_stability' => $zeroBand,
            'testing_risk' => $zeroBand,
            'deployment_risk' => $zeroBand,
            'client_risk' => $zeroBand,
            'impact_chain_score' => $zeroBand,
            'probability_heat' => $heatBand,
            'impact_heat' => $heatBand,
        ],
        'workloads' => [],
        'kpis' => [],
        'insights' => [],
        'baseline' => [
            'monthly_burn' => 0,
            'forecast_delay_days' => 0,
            'risk_score' => 0,
            'resource_efficiency' => 0,
            'confidence_score' => 0,
            'budget_health' => 100,
            'planning_readiness' => 0,
            'remaining_work_days' => 0,
            'remaining_timeline_days' => 0,
            'developer_unit_cost' => 15000,
            'budget_total' => 0,
        ],
    ];
}

function di_scenario_catalog(): array
{
    return [
        'current_state' => [
            'label' => 'Current Overview',
            'unit_label' => 'state',
            'default_value' => 0,
            'min' => 0,
            'max' => 0,
        ],
        'add_developers' => [
            'label' => 'Add Developers',
            'unit_label' => 'developers',
            'default_value' => 2,
            'min' => 1,
            'max' => 6,
        ],
        'extend_deadline' => [
            'label' => 'Extend Deadline',
            'unit_label' => 'days',
            'default_value' => 7,
            'min' => 3,
            'max' => 45,
        ],
        'reduce_scope' => [
            'label' => 'Reduce Scope',
            'unit_label' => 'percent',
            'default_value' => 10,
            'min' => 5,
            'max' => 40,
        ],
        'budget_cut' => [
            'label' => 'Budget Cut',
            'unit_label' => 'percent',
            'default_value' => 10,
            'min' => 5,
            'max' => 35,
        ],
        'team_reduction' => [
            'label' => 'Team Reduction',
            'unit_label' => 'members',
            'default_value' => 1,
            'min' => 1,
            'max' => 4,
        ],
    ];
}

function di_simulate_scenario(array $baseline, string $scenario, float $value): array
{
    $catalog = di_scenario_catalog();
    $config = $catalog[$scenario] ?? $catalog['current_state'];
    $boundedValue = di_clamp($value, (float) $config['min'], (float) $config['max']);

    $simulators = [
        'current_state' => static function (array $base, float $units): array {
            $costDelta = 0.0;
            $delayDelta = 0.0;
            $riskDelta = 0.0;
            $productivityDelta = 0.0;
            $confidenceDelta = 0.0;

            return compact('costDelta', 'delayDelta', 'riskDelta', 'productivityDelta', 'confidenceDelta');
        },
        'add_developers' => static function (array $base, float $units): array {
            $costDelta = $base['developer_unit_cost'] * $units;
            $delayDelta = -min($base['forecast_delay_days'], ($units * 2.4) + ($base['remaining_work_days'] * 0.08));
            $riskDelta = -min(24.0, $units * 4.8);
            $productivityDelta = min(20.0, $units * 4.2);
            $confidenceDelta = min(14.0, $units * 3.1);

            return compact('costDelta', 'delayDelta', 'riskDelta', 'productivityDelta', 'confidenceDelta');
        },
        'extend_deadline' => static function (array $base, float $days): array {
            $costDelta = $base['monthly_burn'] * ($days / 30);
            $delayDelta = -min($base['forecast_delay_days'], $days * 0.85);
            $riskDelta = -min(12.0, $days * 0.55);
            $productivityDelta = min(8.0, $days * 0.32);
            $confidenceDelta = min(10.0, $days * 0.38);

            return compact('costDelta', 'delayDelta', 'riskDelta', 'productivityDelta', 'confidenceDelta');
        },
        'reduce_scope' => static function (array $base, float $percent): array {
            $scopeFactor = $percent / 100;
            $costDelta = -$base['monthly_burn'] * $scopeFactor * 0.55;
            $delayDelta = -min($base['forecast_delay_days'], $percent * 0.45);
            $riskDelta = -min(26.0, $percent * 0.85);
            $productivityDelta = min(18.0, $percent * 0.55);
            $confidenceDelta = min(16.0, $percent * 0.42);

            return compact('costDelta', 'delayDelta', 'riskDelta', 'productivityDelta', 'confidenceDelta');
        },
        'budget_cut' => static function (array $base, float $percent): array {
            $costDelta = -$base['monthly_burn'] * ($percent / 100);
            $delayDelta = min(30.0, $percent * 0.34);
            $riskDelta = min(28.0, $percent * 0.9);
            $productivityDelta = -min(18.0, $percent * 0.5);
            $confidenceDelta = -min(16.0, $percent * 0.48);

            return compact('costDelta', 'delayDelta', 'riskDelta', 'productivityDelta', 'confidenceDelta');
        },
        'team_reduction' => static function (array $base, float $members): array {
            $costDelta = -$base['developer_unit_cost'] * $members;
            $delayDelta = min(36.0, ($members * 3.0) + ($base['remaining_work_days'] * 0.06));
            $riskDelta = min(24.0, $members * 5.3);
            $productivityDelta = -min(22.0, $members * 5.0);
            $confidenceDelta = -min(18.0, $members * 4.4);

            return compact('costDelta', 'delayDelta', 'riskDelta', 'productivityDelta', 'confidenceDelta');
        },
    ];

    $deltas = ($simulators[$scenario] ?? $simulators['current_state'])($baseline, $boundedValue);

    $after = [
        'monthly_burn' => max($baseline['monthly_burn'] + $deltas['costDelta'], 0.0),
        'forecast_delay_days' => max($baseline['forecast_delay_days'] + $deltas['delayDelta'], 0.0),
        'risk_score' => di_clamp($baseline['risk_score'] + $deltas['riskDelta']),
        'resource_efficiency' => di_clamp($baseline['resource_efficiency'] + $deltas['productivityDelta']),
        'confidence_score' => di_clamp($baseline['confidence_score'] + $deltas['confidenceDelta']),
        'budget_health' => di_clamp($baseline['budget_health'] - di_ratio($deltas['costDelta'], max($baseline['budget_total'], 1.0)) * 100),
        'planning_readiness' => $baseline['planning_readiness'],
    ];

    return [
        'scenario' => $scenario,
        'config' => $config,
        'value' => $boundedValue,
        'deltas' => $deltas,
        'before' => $baseline,
        'after' => $after,
        'risk_band' => di_band_for_score($after['risk_score']),
        'confidence_band' => di_band_for_score($after['confidence_score']),
    ];
}

function di_load_decision_insights(mysqli|PgsqlMysqliCompatConnection $conn, int $projectId): array
{
    $empty = di_empty_decision_insights();

    if ($projectId <= 0) {
        return $empty;
    }

    $projectSql = "
        SELECT
            p.*,
            c.name AS client_name,
            c.industry AS client_industry,
            (
                SELECT COUNT(*)
                FROM projects px
                WHERE px.client_id = p.client_id
            ) AS client_project_count
        FROM projects p
        JOIN clients c ON c.id = p.client_id
        WHERE p.id = ?
        LIMIT 1
    ";
    $project = di_fetch_one(di_execute(di_prepare($conn, $projectSql), 'i', [$projectId]));

    if ($project === []) {
        return $empty;
    }

    $taskSql = "
        SELECT
            COUNT(*) AS total_tasks,
            SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) AS completed_tasks,
            SUM(CASE WHEN t.status IN ('in_progress', 'review', 'in_review', 'blocked') THEN 1 ELSE 0 END) AS active_tasks,
            SUM(CASE WHEN t.status != 'completed' AND t.deadline < CURDATE() THEN 1 ELSE 0 END) AS overdue_tasks,
            SUM(CASE WHEN t.status = 'blocked' THEN 1 ELSE 0 END) AS blocked_tasks,
            SUM(CASE WHEN COALESCE(t.assigned_to, 0) = 0 THEN 1 ELSE 0 END) AS unassigned_tasks,
            SUM(CASE WHEN t.status IN ('review', 'in_review') THEN 1 ELSE 0 END) AS review_tasks,
            SUM(CASE WHEN t.priority IN ('high', 'critical') AND t.status != 'completed' THEN 1 ELSE 0 END) AS high_pressure_tasks,
            SUM(COALESCE(t.sales_estimate_days, t.estimated_days_likely, fe.avg_likely_days, t.estimated_days_max, t.estimated_days_min, 0)) AS estimated_work_days,
            SUM(
                CASE
                    WHEN t.status = 'completed'
                    THEN COALESCE(t.actual_days, t.sales_estimate_days, t.estimated_days_likely, fe.avg_likely_days, t.estimated_days_max, t.estimated_days_min, 1)
                    ELSE 0
                END
            ) AS completed_estimated_work_days,
            SUM(COALESCE(NULLIF(t.actual_days, 0), GREATEST(DATEDIFF(CURDATE(), DATE(COALESCE(t.started_at, t.created_at))), 0))) AS actual_work_days_proxy,
            SUM(COALESCE(NULLIF(t.actual_effort_hours, 0), NULLIF(t.estimated_effort_hours, 0), 0)) AS logged_effort_hours,
            AVG(
                CASE
                    WHEN t.status = 'completed' AND t.completed_at IS NOT NULL
                    THEN TIMESTAMPDIFF(DAY, COALESCE(t.started_at, t.created_at), t.completed_at)
                    ELSE NULL
                END
            ) AS average_completion_days
        FROM tasks t
        LEFT JOIN (
            SELECT task_id, AVG(likely_days) AS avg_likely_days
            FROM feature_estimations
            GROUP BY task_id
        ) fe ON fe.task_id = t.id
        WHERE t.project_id = ?
    ";
    $taskMetrics = array_replace(
        [
            'total_tasks' => 0,
            'completed_tasks' => 0,
            'active_tasks' => 0,
            'overdue_tasks' => 0,
            'blocked_tasks' => 0,
            'unassigned_tasks' => 0,
            'review_tasks' => 0,
            'high_pressure_tasks' => 0,
            'estimated_work_days' => 0,
            'completed_estimated_work_days' => 0,
            'actual_work_days_proxy' => 0,
            'logged_effort_hours' => 0,
            'average_completion_days' => 0,
        ],
        di_fetch_one(di_execute(di_prepare($conn, $taskSql), 'i', [$projectId]))
    );

    $flowSql = "
        SELECT
            SUM(
                CASE
                    WHEN COALESCE(DATEDIFF(CURDATE(), DATE(COALESCE(g.last_update_at, t.updated_at))), 999) >= 3
                        AND t.status IN ('in_progress', 'review', 'in_review', 'blocked')
                    THEN 1
                    ELSE 0
                END
            ) AS stalled_tasks,
            SUM(
                CASE
                    WHEN DATE(COALESCE(g.last_update_at, t.updated_at)) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                    THEN 1
                    ELSE 0
                END
            ) AS recent_git_updates
        FROM tasks t
        LEFT JOIN (
            SELECT task_id, MAX(created_at) AS last_update_at
            FROM task_git_updates
            GROUP BY task_id
        ) g ON g.task_id = t.id
        WHERE t.project_id = ?
    ";
    $flowMetrics = array_replace(
        ['stalled_tasks' => 0, 'recent_git_updates' => 0],
        di_fetch_one(di_execute(di_prepare($conn, $flowSql), 'i', [$projectId]))
    );

    $artifactSql = "
        SELECT
            (SELECT COUNT(*) FROM architecture_documents WHERE project_id = ?) AS architecture_docs,
            (SELECT COUNT(*) FROM tech_recommendations WHERE project_id = ?) AS tech_options,
            (SELECT COUNT(*) FROM developer_allocations WHERE project_id = ?) AS allocation_rows,
            (SELECT COALESCE(SUM(required_count), 0) FROM developer_allocations WHERE project_id = ?) AS required_members,
            (SELECT COUNT(*) FROM team_compositions WHERE project_id = ?) AS team_roles,
            (SELECT COUNT(*) FROM feature_estimations WHERE project_id = ?) AS estimation_rows,
            (SELECT COUNT(*) FROM cost_estimations WHERE project_id = ?) AS cost_plan_items,
            (SELECT COALESCE(SUM(COALESCE(estimated_monthly_cost, monthly_cost, 0)), 0) FROM cost_estimations WHERE project_id = ?) AS cost_estimation_monthly,
            (SELECT COALESCE(SUM(COALESCE(estimated_cost, 0)), 0) FROM tech_recommendations WHERE project_id = ?) AS tech_recommendation_monthly,
            (
                SELECT COALESCE(sr.total_cost, 0)
                FROM sales_reports sr
                WHERE sr.project_id = ?
                ORDER BY
                    CASE sr.status
                        WHEN 'approved' THEN 0
                        WHEN 'submitted' THEN 1
                        WHEN 'invoiced' THEN 2
                        ELSE 3
                    END,
                    sr.report_date DESC,
                    sr.created_at DESC,
                    sr.id DESC
                LIMIT 1
            ) AS latest_sales_report_total,
            (
                SELECT COALESCE(sr.developer_cost, 0)
                FROM sales_reports sr
                WHERE sr.project_id = ?
                ORDER BY
                    CASE sr.status
                        WHEN 'approved' THEN 0
                        WHEN 'submitted' THEN 1
                        WHEN 'invoiced' THEN 2
                        ELSE 3
                    END,
                    sr.report_date DESC,
                    sr.created_at DESC,
                    sr.id DESC
                LIMIT 1
            ) AS latest_sales_report_developer_cost,
            (
                SELECT COALESCE(
                    CASE
                        WHEN COALESCE(sr.technology_cost, 0) > 0
                             AND COALESCE(sr.resource_cost, 0) > 0
                             AND ABS(COALESCE(sr.technology_cost, 0) - COALESCE(sr.resource_cost, 0)) < 0.01
                        THEN GREATEST(COALESCE(sr.technology_cost, 0), COALESCE(sr.resource_cost, 0))
                        ELSE COALESCE(sr.technology_cost, 0) + COALESCE(sr.resource_cost, 0)
                    END,
                    0
                )
                FROM sales_reports sr
                WHERE sr.project_id = ?
                ORDER BY
                    CASE sr.status
                        WHEN 'approved' THEN 0
                        WHEN 'submitted' THEN 1
                        WHEN 'invoiced' THEN 2
                        ELSE 3
                    END,
                    sr.report_date DESC,
                    sr.created_at DESC,
                    sr.id DESC
                LIMIT 1
            ) AS latest_sales_report_platform_cost
    ";
    $artifactMetrics = array_replace(
        [
            'architecture_docs' => 0,
            'tech_options' => 0,
            'allocation_rows' => 0,
            'required_members' => 0,
            'team_roles' => 0,
            'estimation_rows' => 0,
            'cost_plan_items' => 0,
            'cost_estimation_monthly' => 0,
            'tech_recommendation_monthly' => 0,
            'latest_sales_report_total' => 0,
            'latest_sales_report_developer_cost' => 0,
            'latest_sales_report_platform_cost' => 0,
        ],
        di_fetch_one(di_execute(
            di_prepare($conn, $artifactSql),
            'iiiiiiiiiiii',
            [$projectId, $projectId, $projectId, $projectId, $projectId, $projectId, $projectId, $projectId, $projectId, $projectId, $projectId, $projectId]
        ))
    );

    $assignmentSql = "
        SELECT
            COUNT(DISTINCT CASE WHEN daa.status IN ('assigned', 'accepted', 'completed') THEN daa.developer_id ELSE NULL END) AS allocated_members,
            COUNT(DISTINCT CASE WHEN daa.status IN ('accepted', 'completed') THEN daa.developer_id ELSE NULL END) AS committed_members
        FROM developer_allocations da
        LEFT JOIN developer_allocation_assignments daa ON daa.allocation_id = da.id
        WHERE da.project_id = ?
    ";
    $assignmentMetrics = array_replace(
        ['allocated_members' => 0, 'committed_members' => 0],
        di_fetch_one(di_execute(di_prepare($conn, $assignmentSql), 'i', [$projectId]))
    );

    $performanceSql = "
        SELECT
            COUNT(*) AS metric_rows,
            AVG(completion_rate) AS average_completion_rate,
            AVG(quality_score) AS average_quality_score,
            SUM(hours_logged) AS total_hours_logged
        FROM performance_metrics
        WHERE project_id = ?
    ";
    $performanceMetrics = array_replace(
        [
            'metric_rows' => 0,
            'average_completion_rate' => 0,
            'average_quality_score' => 0,
            'total_hours_logged' => 0,
        ],
        di_fetch_one(di_execute(di_prepare($conn, $performanceSql), 'i', [$projectId]))
    );

    $workloadSql = "
        SELECT
            COALESCE(u.id, 0) AS id,
            COALESCE(u.full_name, 'Unassigned Pool') AS full_name,
            COALESCE(u.role, 'unassigned') AS role,
            COUNT(t.id) AS total_tasks,
            SUM(CASE WHEN t.status IN ('in_progress', 'review', 'in_review', 'blocked') THEN 1 ELSE 0 END) AS active_tasks,
            SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) AS completed_tasks,
            SUM(CASE WHEN t.status != 'completed' AND t.deadline < CURDATE() THEN 1 ELSE 0 END) AS overdue_tasks
        FROM tasks t
        LEFT JOIN users u ON u.id = t.assigned_to
        WHERE t.project_id = ?
        GROUP BY u.id, u.full_name, u.role
        ORDER BY active_tasks DESC, total_tasks DESC, full_name ASC
    ";
    $workloads = di_fetch_all(di_execute(di_prepare($conn, $workloadSql), 'i', [$projectId]));

    $today = date('Y-m-d');
    $timelineStart = trim((string) ($project['timeline_start'] ?? '')) ?: substr((string) ($project['created_at'] ?? $today), 0, 10);
    $timelineEnd = trim((string) ($project['timeline_end'] ?? '')) ?: $timelineStart;
    $timelineTotalDays = max(di_day_diff($timelineStart, $timelineEnd) + 1, 1);
    $timelineElapsedDays = (int) di_clamp((float) (di_day_diff($timelineStart, $today) + 1), 0.0, (float) $timelineTotalDays);
    $timelineRemainingDays = max($timelineTotalDays - $timelineElapsedDays, 0);

    $totalTasks = di_int($taskMetrics['total_tasks']);
    $completedTasks = di_int($taskMetrics['completed_tasks']);
    $activeTasks = di_int($taskMetrics['active_tasks']);
    $overdueTasks = di_int($taskMetrics['overdue_tasks']);
    $blockedTasks = di_int($taskMetrics['blocked_tasks']);
    $unassignedTasks = di_int($taskMetrics['unassigned_tasks']);
    $reviewTasks = di_int($taskMetrics['review_tasks']);
    $stalledTasks = di_int($flowMetrics['stalled_tasks']);
    $recentGitUpdates = di_int($flowMetrics['recent_git_updates']);
    $requiredMembers = max(di_int($artifactMetrics['required_members']), 0);
    $workloadAssignedMembers = count(array_filter($workloads, static fn(array $row): bool => di_int($row['id']) > 0));
    $allocatedMembers = max(di_int($assignmentMetrics['allocated_members']), 0);
    $committedMembers = max(di_int($assignmentMetrics['committed_members']), 0);
    $assignedMembers = max($workloadAssignedMembers, $allocatedMembers, $committedMembers, 0);
    $estimatedWorkDays = max(di_float($taskMetrics['estimated_work_days']), (float) max($totalTasks, 1));
    $completedEstimatedWorkDays = max(di_float($taskMetrics['completed_estimated_work_days']), 0.0);
    $actualWorkDaysProxy = max(di_float($taskMetrics['actual_work_days_proxy']), 0.0);
    $loggedEffortHours = max(di_float($taskMetrics['logged_effort_hours']), 0.0);
    $openTasks = max($totalTasks - $completedTasks, 0);
    $plannedProgress = di_ratio((float) $timelineElapsedDays, (float) $timelineTotalDays);
    $actualProgress = $estimatedWorkDays > 0
        ? di_ratio($completedEstimatedWorkDays, $estimatedWorkDays)
        : di_ratio((float) $completedTasks, (float) max($totalTasks, 1));
    $earnedValue = min($estimatedWorkDays, $completedEstimatedWorkDays > 0 ? $completedEstimatedWorkDays : ($estimatedWorkDays * $actualProgress));
    $plannedValue = $estimatedWorkDays * $plannedProgress;
    $scheduleVariance = $earnedValue - $plannedValue;
    $deliveryVelocity = di_ratio($earnedValue, (float) max($timelineElapsedDays, 1));
    $projectedRemainingDays = max($estimatedWorkDays - $earnedValue, 0.0) / max($deliveryVelocity, 0.25);
    $forecastDelayDays = max($projectedRemainingDays - $timelineRemainingDays, 0.0);

    $salesReportPlatformMonthly = di_float($artifactMetrics['latest_sales_report_platform_cost']);
    $salesReportDeveloperMonthly = di_float($artifactMetrics['latest_sales_report_developer_cost']);
    $salesReportMonthly = ($salesReportDeveloperMonthly + $salesReportPlatformMonthly) > 0
        ? ($salesReportDeveloperMonthly + $salesReportPlatformMonthly)
        : di_float($artifactMetrics['latest_sales_report_total']);
    $infrastructureMonthly = max(
        $salesReportPlatformMonthly,
        di_float($artifactMetrics['cost_estimation_monthly']),
        di_float($artifactMetrics['tech_recommendation_monthly'])
    );
    $developerMonthly = max(
        $salesReportDeveloperMonthly,
        max((float) max($requiredMembers, $assignedMembers), 0.0) * 15000.0
    );
    $monthlyBurn = $infrastructureMonthly + $developerMonthly;
    $projectMonths = max($timelineTotalDays / 30, 1.0);
    $salesRequirementBudget = di_float($project['sales_budget'] ?? 0);
    $modeledBudgetFloor = $monthlyBurn * $projectMonths;
    $budgetTotal = $modeledBudgetFloor;
    $budgetBaselineSource = 'Modeled project budget floor';

    if ($salesRequirementBudget > 0) {
        $budgetTotal = $salesRequirementBudget;
        $budgetBaselineSource = 'Sales requirement budget';
    } elseif ($salesReportMonthly > 0) {
        $budgetTotal = max($modeledBudgetFloor, $salesReportMonthly * $projectMonths);
        $budgetBaselineSource = 'Latest sales report extrapolated across project timeline';
    }

    $budgetConsumed = $monthlyBurn * ($timelineElapsedDays / 30);
    $budgetConsumedRatio = di_ratio($budgetConsumed, max($budgetTotal, 1.0));
    $plannedMonthlyBudget = di_ratio($budgetTotal, $projectMonths);
    $remainingSpendProjection = $monthlyBurn * ($projectedRemainingDays / 30);
    $projectedTotalSpend = $budgetConsumed + $remainingSpendProjection;
    $overspendForecast = max($projectedTotalSpend - $budgetTotal, 0.0);
    $budgetHealth = di_clamp(100 - ($budgetConsumedRatio * 100));

    if (di_float($performanceMetrics['average_completion_rate']) <= 0 && $totalTasks > 0) {
        $performanceMetrics['average_completion_rate'] = round($actualProgress * 100, 1);
    }
    if (di_float($performanceMetrics['total_hours_logged']) <= 0 && $loggedEffortHours > 0) {
        $performanceMetrics['total_hours_logged'] = $loggedEffortHours;
    }

    $openTaskBase = max($openTasks, 1);
    $overdueRatio = di_ratio((float) $overdueTasks, (float) $openTaskBase);
    $blockedRatio = di_ratio((float) $blockedTasks, (float) $openTaskBase);
    $stalledRatio = di_ratio((float) $stalledTasks, (float) $openTaskBase);
    $reviewRatio = di_ratio((float) $reviewTasks, (float) $openTaskBase);
    $unassignedRatio = di_ratio((float) $unassignedTasks, (float) max($totalTasks, 1));
    $highPressureRatio = di_ratio(di_float($taskMetrics['high_pressure_tasks']), (float) max($openTasks, 1));
    $slipGap = di_clamp(max($plannedProgress - $actualProgress, 0.0) * 100);
    $budgetDrift = di_clamp(di_ratio($overspendForecast, max($budgetTotal, 1.0)) * 100);

    $complexityScoreMap = [
        'low' => 35.0,
        'simple' => 35.0,
        'medium' => 60.0,
        'high' => 85.0,
        'complex' => 85.0,
    ];
    $complexityScore = $complexityScoreMap[$project['complexity_level'] ?? 'medium'] ?? 60.0;
    $clientExposure = di_clamp(di_ratio(di_float($project['client_project_count'] ?? 1), 3.0) * 100);

    $riskProbability = di_clamp(
        ($overdueRatio * 28)
        + ($blockedRatio * 18)
        + ($stalledRatio * 16)
        + ($reviewRatio * 10)
        + ($unassignedRatio * 12)
        + ($highPressureRatio * 16)
        + ($slipGap * 0.16)
    );
    $riskImpact = di_clamp(
        ($complexityScore * 0.35)
        + (di_clamp($highPressureRatio * 100) * 0.20)
        + (di_clamp(($projectedTotalSpend / max($budgetTotal, 1.0)) * 100) * 0.20)
        + ($clientExposure * 0.10)
        + (di_clamp($plannedProgress * 100) * 0.15)
    );
    $riskExposure = di_clamp(($riskProbability * $riskImpact) / 100);

    $memberActiveLoads = array_values(array_map(
        static fn(array $row): float => di_float($row['active_tasks']),
        array_filter($workloads, static fn(array $row): bool => di_int($row['id']) > 0)
    ));
    $memberCount = max(count($memberActiveLoads), 1);
    $meanLoad = array_sum($memberActiveLoads) / $memberCount;
    $variance = array_sum(array_map(static fn(float $load): float => ($load - $meanLoad) ** 2, $memberActiveLoads)) / $memberCount;
    $stddevLoad = sqrt($variance);
    $balanceScore = di_clamp(100 - (di_ratio($stddevLoad, max($meanLoad, 1.0)) * 35));
    $utilizationScore = di_clamp(100 - abs((di_ratio((float) $activeTasks, (float) max($memberCount * 3, 1)) * 100) - 75));
    $gitMomentum = di_clamp(di_ratio((float) $recentGitUpdates, (float) max($activeTasks, 1)) * 100);
    $resourceEfficiency = di_clamp(
        ((di_clamp($actualProgress * 100)) * 0.35)
        + ($balanceScore * 0.20)
        + ($utilizationScore * 0.15)
        + ((100 - di_clamp($overdueRatio * 100)) * 0.15)
        + ($gitMomentum * 0.15)
    );

    $planningReadiness = di_average([
        di_scale(di_float($artifactMetrics['architecture_docs']), 1.0),
        di_scale(di_float($artifactMetrics['tech_options']), 1.0),
        di_scale((float) max(di_int($artifactMetrics['allocation_rows']), di_int($artifactMetrics['team_roles'])), 1.0),
        di_scale(di_float($artifactMetrics['estimation_rows']), 1.0),
        di_scale((float) max(di_int($artifactMetrics['cost_plan_items']), di_int($artifactMetrics['tech_options'])), 1.0),
    ]);

    $scheduleStability = di_clamp(
        100 - (
            (max($plannedProgress - $actualProgress, 0.0) * 55)
            + ($blockedRatio * 20)
            + ($stalledRatio * 15)
            + (min($forecastDelayDays, 60.0) * 0.5)
            + ($reviewRatio * 10)
        )
    );

    $testingRisk = di_clamp(
        (($reviewRatio * 100) * 0.45)
        + (($blockedRatio * 100) * 0.25)
        + (($stalledRatio * 100) * 0.15)
        + (($overdueRatio * 100) * 0.15)
    );
    $deploymentRisk = di_clamp(
        (($blockedRatio * 100) * 0.35)
        + (($stalledRatio * 100) * 0.35)
        + (((100 - $gitMomentum)) * 0.15)
        + (($unassignedRatio * 100) * 0.15)
    );
    $clientRisk = di_clamp(
        ($scheduleStability * -0.45 + 45)
        + (((100 - $budgetHealth)) * 0.25)
        + ($riskExposure * 0.30)
    );
    $impactChainScore = di_average([$testingRisk, $deploymentRisk, $clientRisk]);

    $decisionConfidence = di_clamp(
        ((100 - $riskExposure) * 0.20)
        + ($scheduleStability * 0.30)
        + ($budgetHealth * 0.25)
        + ($resourceEfficiency * 0.25)
    );

    $metrics = [
        'total_tasks' => $totalTasks,
        'completed_tasks' => $completedTasks,
        'open_tasks' => $openTasks,
        'active_tasks' => $activeTasks,
        'overdue_tasks' => $overdueTasks,
        'blocked_tasks' => $blockedTasks,
        'unassigned_tasks' => $unassignedTasks,
        'review_tasks' => $reviewTasks,
        'stalled_tasks' => $stalledTasks,
        'recent_git_updates' => $recentGitUpdates,
        'assigned_members' => $assignedMembers,
        'required_members' => $requiredMembers,
        'timeline_total_days' => $timelineTotalDays,
        'timeline_elapsed_days' => $timelineElapsedDays,
        'timeline_remaining_days' => $timelineRemainingDays,
        'planned_progress' => $plannedProgress,
        'actual_progress' => $actualProgress,
        'estimated_work_days' => $estimatedWorkDays,
        'actual_work_days_proxy' => $actualWorkDaysProxy,
        'average_completion_days' => round(di_float($taskMetrics['average_completion_days']), 1),
        'earned_value' => round($earnedValue, 2),
        'planned_value' => round($plannedValue, 2),
        'schedule_variance' => round($scheduleVariance, 2),
        'projected_remaining_days' => round($projectedRemainingDays, 1),
        'forecast_delay_days' => round($forecastDelayDays, 1),
        'infrastructure_monthly' => round($infrastructureMonthly, 2),
        'developer_monthly' => round($developerMonthly, 2),
        'monthly_burn' => round($monthlyBurn, 2),
        'budget_total' => round($budgetTotal, 2),
        'budget_baseline_source' => $budgetBaselineSource,
        'budget_health' => round($budgetHealth, 1),
        'budget_consumed_ratio' => round($budgetConsumedRatio * 100, 1),
        'overspend_forecast' => round($overspendForecast, 2),
        'planning_readiness' => round($planningReadiness, 1),
        'resource_efficiency' => round($resourceEfficiency, 1),
        'git_momentum' => round($gitMomentum, 1),
        'schedule_stability' => round($scheduleStability, 1),
        'risk_probability' => round($riskProbability, 1),
        'risk_impact' => round($riskImpact, 1),
        'risk_exposure' => round($riskExposure, 1),
        'decision_confidence' => round($decisionConfidence, 1),
        'testing_risk' => round($testingRisk, 1),
        'deployment_risk' => round($deploymentRisk, 1),
        'client_risk' => round($clientRisk, 1),
        'impact_chain_score' => round($impactChainScore, 1),
        'average_quality_score' => round(di_float($performanceMetrics['average_quality_score']), 1),
        'average_completion_rate' => round(di_float($performanceMetrics['average_completion_rate']), 1),
        'total_hours_logged' => round(di_float($performanceMetrics['total_hours_logged']), 1),
    ];

    $bands = [
        'risk_probability' => di_band_for_score($metrics['risk_probability']),
        'risk_impact' => di_band_for_score($metrics['risk_impact']),
        'risk_exposure' => di_band_for_score($metrics['risk_exposure']),
        'decision_confidence' => di_band_for_score($metrics['decision_confidence']),
        'resource_efficiency' => di_band_for_score($metrics['resource_efficiency']),
        'planning_readiness' => di_band_for_score($metrics['planning_readiness']),
        'budget_health' => di_band_for_score($metrics['budget_health']),
        'schedule_stability' => di_band_for_score($metrics['schedule_stability']),
        'testing_risk' => di_band_for_score($metrics['testing_risk']),
        'deployment_risk' => di_band_for_score($metrics['deployment_risk']),
        'client_risk' => di_band_for_score($metrics['client_risk']),
        'impact_chain_score' => di_band_for_score($metrics['impact_chain_score']),
        'probability_heat' => di_band_for_score($metrics['risk_probability'], di_heatmap_bands()),
        'impact_heat' => di_band_for_score($metrics['risk_impact'], di_heatmap_bands()),
    ];

    $kpis = [
        [
            'label' => 'Completion Rate',
            'target' => 85.0,
            'actual' => round($actualProgress * 100, 1),
            'deviation' => round(($actualProgress * 100) - 85.0, 1),
            'direction' => 'higher',
        ],
        [
            'label' => 'Overdue Rate',
            'target' => 5.0,
            'actual' => round($overdueRatio * 100, 1),
            'deviation' => round(5.0 - ($overdueRatio * 100), 1),
            'direction' => 'lower',
        ],
        [
            'label' => 'Planning Readiness',
            'target' => 100.0,
            'actual' => $metrics['planning_readiness'],
            'deviation' => round($metrics['planning_readiness'] - 100.0, 1),
            'direction' => 'higher',
        ],
        [
            'label' => 'Resource Efficiency',
            'target' => 80.0,
            'actual' => $metrics['resource_efficiency'],
            'deviation' => round($metrics['resource_efficiency'] - 80.0, 1),
            'direction' => 'higher',
        ],
        [
            'label' => 'Budget Health',
            'target' => 80.0,
            'actual' => $metrics['budget_health'],
            'deviation' => round($metrics['budget_health'] - 80.0, 1),
            'direction' => 'higher',
        ],
        [
            'label' => 'Git Momentum',
            'target' => 70.0,
            'actual' => $metrics['git_momentum'],
            'deviation' => round($metrics['git_momentum'] - 70.0, 1),
            'direction' => 'higher',
        ],
        [
            'label' => 'Schedule Stability',
            'target' => 75.0,
            'actual' => $metrics['schedule_stability'],
            'deviation' => round($metrics['schedule_stability'] - 75.0, 1),
            'direction' => 'higher',
        ],
    ];

    $insights = [
        [
            'title' => 'Delivery Risk Pressure',
            'score' => $metrics['risk_exposure'],
            'detail' => "{$overdueTasks} overdue, {$blockedTasks} blocked, {$stalledTasks} stalled tasks are driving risk.",
            'action' => 'Re-sequence delivery or reinforce the current team before the delay grows.',
            'band' => $bands['risk_exposure'],
        ],
        [
            'title' => 'Deadline Slippage Outlook',
            'score' => di_clamp($forecastDelayDays * 4),
            'detail' => "Projected delay is {$metrics['forecast_delay_days']} day(s) with schedule variance {$metrics['schedule_variance']}.",
            'action' => 'Use scenario simulation to compare adding capacity, extending time, or reducing scope.',
            'band' => di_band_for_score(di_clamp($forecastDelayDays * 4)),
        ],
        [
            'title' => 'Budget Burn Exposure',
            'score' => di_clamp(100 - $metrics['budget_health']),
            'detail' => "Estimated monthly burn is " . number_format($metrics['monthly_burn'], 2) . " against the {$metrics['budget_baseline_source']} of " . number_format($metrics['budget_total'], 2) . '.',
            'action' => 'Watch overspend forecast and align budget with scope before approval pressure increases.',
            'band' => di_band_for_score(di_clamp(100 - $metrics['budget_health'])),
        ],
        [
            'title' => 'Planning Readiness',
            'score' => di_clamp(100 - $metrics['planning_readiness']),
            'detail' => "{$artifactMetrics['architecture_docs']} architecture docs, {$artifactMetrics['tech_options']} tech options, {$artifactMetrics['estimation_rows']} estimation records detected.",
            'action' => 'Complete missing planning artifacts before pushing more work into execution.',
            'band' => di_band_for_score(di_clamp(100 - $metrics['planning_readiness'])),
        ],
        [
            'title' => 'Resource Balance',
            'score' => di_clamp(100 - $metrics['resource_efficiency']),
            'detail' => "{$assignedMembers} active members are carrying {$activeTasks} active tasks with momentum score {$metrics['git_momentum']}.",
            'action' => 'Use the workload table to rebalance active ownership and reduce flow interruptions.',
            'band' => di_band_for_score(di_clamp(100 - $metrics['resource_efficiency'])),
        ],
    ];

    usort($insights, static fn(array $left, array $right): int => $right['score'] <=> $left['score']);

    return [
        'project' => $project,
        'metrics' => $metrics,
        'bands' => $bands,
        'workloads' => $workloads,
        'kpis' => $kpis,
        'insights' => array_slice($insights, 0, 5),
        'baseline' => [
            'monthly_burn' => $metrics['monthly_burn'],
            'forecast_delay_days' => $metrics['forecast_delay_days'],
            'risk_score' => $metrics['risk_exposure'],
            'resource_efficiency' => $metrics['resource_efficiency'],
            'confidence_score' => $metrics['decision_confidence'],
            'budget_health' => $metrics['budget_health'],
            'planning_readiness' => $metrics['planning_readiness'],
            'remaining_work_days' => $metrics['projected_remaining_days'],
            'remaining_timeline_days' => $metrics['timeline_remaining_days'],
            'developer_unit_cost' => 15000.0,
            'budget_total' => $metrics['budget_total'],
        ],
    ];
}
