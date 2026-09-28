<?php
// Shared source for Architect dashboard KPIs and the assistant's review queues.
function architectOverview($conn, int $userId): array
{
    $reviewStatuses = ['pending', 'review', 'architectural_review'];
    $groups = [];
    foreach ([
        'review' => ['Review', 'fa-folder-open', 'Architecture Review', 'No architecture reviews need attention.'],
        'qa' => ['QA Reviews', 'fa-vial', 'QA Reviews', 'No QA results are waiting for Architect approval.'],
        'progress' => ['Progress', 'fa-gears', 'Planning Progress', 'No planning work needs attention.'],
        'overdue' => ['Overdue', 'fa-triangle-exclamation', 'Overdue Watch', 'No overdue architecture work was detected.'],
    ] as $key => [$label, $icon, $title, $empty]) {
        $groups[$key] = ['label' => $label, 'icon' => $icon, 'list_title' => $title, 'empty' => $empty, 'items' => [], 'total' => 0];
    }
    $counts = ['total' => 0, 'review' => 0, 'progress' => 0, 'completed' => 0, 'qa' => 0];
    $projects = $conn->query("SELECT p.id, p.name, p.status, p.timeline_end,
        CASE WHEN p.timeline_end < CURRENT_DATE THEN 1 ELSE 0 END AS is_overdue
        FROM projects p WHERE p.architect_id = {$userId} OR p.architect_id IS NULL
        ORDER BY CASE WHEN p.timeline_end IS NULL THEN 1 ELSE 0 END, p.timeline_end, p.id");
    while ($project = $projects->fetch_assoc()) {
        $counts['total']++;
        $status = (string) $project['status'];
        if ($status === 'completed') {
            $counts['completed']++;
            continue;
        }
        if ($status === 'cancelled') continue;
        $key = in_array($status, $reviewStatuses, true) ? 'review' : 'progress';
        $counts[$key]++;
        $item = [
            'name' => $project['name'],
            'meta' => ucwords(str_replace('_', ' ', $status)) . ' · Target ' . ($project['timeline_end'] ?: 'not set'),
            'url' => BASE_URL . 'modules/architect/project-review.php?id=' . (int) $project['id'],
        ];
        $groups[$key]['items'][] = $item;
        if ((int) $project['is_overdue'] === 1) $groups['overdue']['items'][] = $item;
    }
    $tasks = $conn->query("SELECT t.id, t.title, t.project_id, p.name AS project_name
        FROM tasks t JOIN projects p ON p.id = t.project_id
        WHERE (p.architect_id = {$userId} OR p.architect_id IS NULL)
          AND p.status != 'cancelled' AND t.status = 'qa_passed'
        ORDER BY t.id ASC");
    while ($task = $tasks->fetch_assoc()) {
        $groups['qa']['items'][] = [
            'name' => $task['title'],
            'meta' => $task['project_name'] . ' · QA passed · Awaiting final approval',
            'url' => BASE_URL . 'modules/architect/final-approval.php?project_id=' . (int) $task['project_id'],
        ];
    }
    foreach ($groups as &$group) $group['total'] = count($group['items']);
    unset($group);
    $counts['qa'] = $groups['qa']['total'];
    return ['counts' => $counts, 'summary' => [
        'variant' => 'tabbed', 'title' => 'Live Overview',
        'intro' => $counts['review'] . ' architecture reviews and ' . $counts['qa'] . ' QA reviews awaiting approval.',
        'tab_order' => array_keys($groups), 'tab_groups' => $groups, 'metrics' => [],
    ]];
}
