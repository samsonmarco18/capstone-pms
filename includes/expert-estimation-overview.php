<?php
require_once __DIR__ . '/project-estimation.php';

function expertEstimationOverview($conn, int $expertId): array
{
    $projects = projectEstimationFetchProjects($conn, $expertId);
    $pending = [];
    $overdue = [];
    foreach ($projects as $project) {
        if (!in_array($project['estimation_status'] ?? 'draft', ['draft', 'sul_rejected', 'sales_rejected'], true)) {
            continue;
        }
        $item = [
            'name' => $project['name'],
            'meta' => empty($project['estimation_status']) ? 'Not estimated yet' : 'Estimation needs submission',
            'url' => BASE_URL . 'modules/expert/feature-estimation.php?project_estimation_id=' . (int) $project['id'],
        ];
        $pending[] = $item;
        if (!empty($project['timeline_end']) && substr($project['timeline_end'], 0, 10) < date('Y-m-d')) {
            $overdue[] = $item;
        }
    }
    $groups = [];
    foreach (['estimate' => $pending, 'overdue' => $overdue] as $key => $items) {
        $groups[$key] = [
            'label' => $key === 'estimate' ? 'Estimate' : 'Overdue',
            'icon' => $key === 'estimate' ? 'fa-calculator' : 'fa-triangle-exclamation',
            'total' => count($items),
            'list_title' => $key === 'estimate' ? 'Estimation Queue' : 'Overdue Estimations',
            'entity_label' => 'project',
            'empty' => $key === 'estimate' ? 'No projects are awaiting estimation.' : 'No overdue project estimations.',
            'items' => $items,
        ];
    }
    return [
        'pending' => count($pending),
        'active' => count($projects),
        'summary' => [
            'variant' => 'tabbed', 'title' => 'Live Overview',
            'intro' => count($pending) . ' projects awaiting estimation.',
            'tab_order' => ['estimate', 'overdue'], 'tab_groups' => $groups, 'metrics' => [],
        ],
    ];
}
