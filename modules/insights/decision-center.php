<?php
require_once '../../includes/auth.php';
require_once '../../includes/decision-insights.php';
require_once '../../includes/project-delay-insights.php';

requireRole(['admin', 'sales', 'architect', 'sul', 'qa', 'expert', 'developer']);

$conn = getConnection();
$user = getCurrentUser();
$projects = di_load_accessible_projects($conn, $user);
$attentionProjectsById = di_load_attention_projects($conn, $user);
$attentionProjectCount = count($attentionProjectsById);
$userRole = (string) ($user['role'] ?? 'guest');
$canViewBudget = $userRole !== 'sul';
$scenarioCatalog = di_scenario_catalog();
if (!$canViewBudget) {
    unset($scenarioCatalog['budget_cut']);
}

$selectedProjectId = (int) ($_GET['project_id'] ?? ($projects[0]['id'] ?? 0));
$requestedView = (string) ($_GET['view'] ?? 'overview');
$currentView = $requestedView === 'statistics' ? 'statistics' : 'overview';
$requestedScenario = (string) ($_GET['scenario'] ?? 'current_state');
$defaultScenario = array_key_first($scenarioCatalog) ?: 'current_state';
$scenarioType = array_key_exists($requestedScenario, $scenarioCatalog) ? $requestedScenario : $defaultScenario;
$scenarioConfig = $scenarioCatalog[$scenarioType];
$scenarioValue = di_clamp(
    di_float($_GET['scenario_value'] ?? $scenarioConfig['default_value']),
    (float) $scenarioConfig['min'],
    (float) $scenarioConfig['max']
);

$insights = di_load_decision_insights($conn, $selectedProjectId);
$simulation = di_simulate_scenario($insights['baseline'], $scenarioType, $scenarioValue);
$comparisonKeys = ['add_developers', 'extend_deadline', 'reduce_scope', 'budget_cut', 'team_reduction'];
if (!$canViewBudget) {
    $comparisonKeys = array_values(array_filter($comparisonKeys, static fn(string $key): bool => $key !== 'budget_cut'));
}
$strategyComparisons = array_map(
    static function (string $key) use ($scenarioCatalog, $insights): array {
        $sim = di_simulate_scenario($insights['baseline'], $key, (float) $scenarioCatalog[$key]['default_value']);

        return [
            'key' => $key,
            'simulation' => $sim,
        ];
    },
    $comparisonKeys
);

$metricCards = [
    [
        'label' => 'Risk Exposure',
        'value' => number_format($insights['metrics']['risk_exposure'], 1),
        'suffix' => '/100',
        'tone' => $insights['bands']['risk_exposure']['tone'],
        'meta' => $insights['bands']['risk_exposure']['label'],
        'icon' => 'shield-halved',
    ],
    [
        'label' => 'Decision Confidence',
        'value' => number_format($insights['metrics']['decision_confidence'], 1),
        'suffix' => '%',
        'tone' => $insights['bands']['decision_confidence']['tone'],
        'meta' => $insights['bands']['decision_confidence']['label'],
        'icon' => 'chart-line',
    ],
    [
        'label' => 'Forecast Delay',
        'value' => number_format($insights['metrics']['forecast_delay_days'], 1),
        'suffix' => ' days',
        'tone' => $insights['metrics']['forecast_delay_days'] > 0 ? 'warning' : 'success',
        'meta' => 'Projected slippage',
        'icon' => 'clock',
    ],
    [
        'label' => 'Monthly Burn',
        'value' => number_format($insights['metrics']['monthly_burn'], 2),
        'suffix' => '',
        'tone' => 'info',
        'meta' => 'Estimated budget usage',
        'icon' => 'coins',
    ],
];
if (!$canViewBudget) {
    $metricCards = array_values(array_filter($metricCards, static fn(array $card): bool => $card['label'] !== 'Monthly Burn'));
}

$heatmapRows = [
    ['label' => 'High', 'index' => 3],
    ['label' => 'Medium', 'index' => 2],
    ['label' => 'Low', 'index' => 1],
];
$heatmapColumns = [
    ['label' => 'Low', 'index' => 1],
    ['label' => 'Medium', 'index' => 2],
    ['label' => 'High', 'index' => 3],
];

$workloadRows = $insights['workloads'];
$kpiRows = $insights['kpis'];
$topInsights = $insights['insights'];
if (!$canViewBudget) {
    $kpiRows = array_values(array_filter($kpiRows, static fn(array $kpi): bool => $kpi['label'] !== 'Budget Health'));
    $topInsights = array_values(array_filter($topInsights, static fn(array $insight): bool => $insight['title'] !== 'Budget Burn Exposure'));
}
$projectTitle = $insights['project']['name'] ?? 'No project selected';
$projectClient = $insights['project']['client_name'] ?? 'No client';
$projectComplexity = ucfirst((string) ($insights['project']['complexity_level'] ?? 'n/a'));
$projectStatus = ucwords(str_replace('_', ' ', (string) ($insights['project']['status'] ?? 'n/a')));

$radarLabels = ['Timeline Stability'];
$radarBefore = [
    $simulation['before']['confidence_score'] > 0 ? $insights['metrics']['schedule_stability'] : 0,
];
$radarAfter = [
    max(0, min(100, $insights['metrics']['schedule_stability'] - ($simulation['after']['forecast_delay_days'] - $simulation['before']['forecast_delay_days']) * 3)),
];
if ($canViewBudget) {
    $radarLabels[] = 'Budget Health';
    $radarBefore[] = $simulation['before']['budget_health'];
    $radarAfter[] = $simulation['after']['budget_health'];
}
$radarLabels = array_merge($radarLabels, ['Resource Efficiency', 'Decision Confidence', 'Planning Readiness']);
$radarBefore = array_merge(
    $radarBefore,
    [
        $simulation['before']['resource_efficiency'],
        $simulation['before']['confidence_score'],
        $simulation['before']['planning_readiness'],
    ]
);
$radarAfter = array_merge(
    $radarAfter,
    [
        $simulation['after']['resource_efficiency'],
        $simulation['after']['confidence_score'],
        $simulation['after']['planning_readiness'],
    ]
);

$radarChart = [
    'labels' => $radarLabels,
    'before' => $radarBefore,
    'after' => $radarAfter,
];

$comparisonChart = [
    'labels' => array_map(static fn(array $row): string => $row['simulation']['config']['label'], $strategyComparisons),
    'confidence' => array_map(static fn(array $row): float => round($row['simulation']['after']['confidence_score'], 1), $strategyComparisons),
    'risk' => array_map(static fn(array $row): float => round($row['simulation']['after']['risk_score'], 1), $strategyComparisons),
];

$overviewInsights = delayInsightsLoadInsights($conn, $user, [
    'limit' => max(1, count($projects)),
]);

$overviewInsightsByProject = [];
foreach ($overviewInsights as $overviewInsight) {
    $overviewInsightsByProject[(int) $overviewInsight['project']['id']] = $overviewInsight;
}

$overviewUrl = BASE_URL . 'modules/insights/decision-center.php?project_id=' . $selectedProjectId . '&view=overview';
$statisticsBaseUrl = BASE_URL . 'modules/insights/decision-center.php?project_id=' . $selectedProjectId . '&view=statistics';
$statisticsUrl = $statisticsBaseUrl;
if ($scenarioType !== 'current_state') {
    $statisticsUrl .= '&scenario=' . urlencode($scenarioType) . '&scenario_value=' . (float) $scenarioValue;
}
$signalSectionUrls = [
    'risk' => $statisticsBaseUrl . '#risk-heatmap',
    'deadline' => $statisticsBaseUrl . '#deadline-analyzer',
    'budget' => $statisticsBaseUrl . '#budget-engine',
    'resource' => $statisticsBaseUrl . '#resource-efficiency',
    'planning' => $statisticsBaseUrl . '#kpi-monitoring',
];
$signalActionResolver = static function (array $insight) use ($userRole, $selectedProjectId, $insights, $signalSectionUrls, $statisticsBaseUrl): array {
    $projectId = (int) $selectedProjectId;
    $clientId = (int) ($insights['project']['client_id'] ?? 0);

    $actionsByTitle = [
        'Delivery Risk Pressure' => match ($userRole) {
            'architect' => ['url' => BASE_URL . 'modules/architect/developer-allocation.php?id=' . $projectId, 'label' => 'Open Developer Allocation'],
            'sul' => ['url' => BASE_URL . 'modules/sul/task-allocation.php?project_id=' . $projectId, 'label' => 'Open Task Allocation'],
            'developer' => ['url' => BASE_URL . 'modules/developer/task.php', 'label' => 'Open My Tasks'],
            'expert' => ['url' => BASE_URL . 'modules/expert/feature-estimation.php', 'label' => 'Open Feature Estimation'],
            default => ['url' => $signalSectionUrls['risk'], 'label' => 'Review Risk Details'],
        },
        'Deadline Slippage Outlook' => match ($userRole) {
            'architect' => ['url' => BASE_URL . 'modules/architect/developer-allocation.php?id=' . $projectId, 'label' => 'Adjust Team Plan'],
            'sul' => ['url' => BASE_URL . 'modules/sul/task-allocation.php?project_id=' . $projectId, 'label' => 'Adjust Task Schedule'],
            'developer' => ['url' => BASE_URL . 'modules/developer/task.php', 'label' => 'Update Task Progress'],
            'expert' => ['url' => BASE_URL . 'modules/expert/feature-estimation.php', 'label' => 'Review Estimates'],
            default => ['url' => $signalSectionUrls['deadline'], 'label' => 'Review Deadline Analyzer'],
        },
        'Budget Burn Exposure' => match ($userRole) {
            'sales' => ['url' => BASE_URL . 'modules/sales/report.php' . ($clientId > 0 ? '?client_id=' . $clientId : ''), 'label' => 'Open Sales Report'],
            default => ['url' => $signalSectionUrls['budget'], 'label' => 'Review Budget Insight'],
        },
        'Planning Readiness' => match ($userRole) {
            'architect' => ['url' => BASE_URL . 'modules/architect/project-review.php?id=' . $projectId, 'label' => 'Complete Project Planning'],
            'sales' => ['url' => BASE_URL . 'modules/sales/clients.php', 'label' => 'Open Client Setup'],
            'expert' => ['url' => BASE_URL . 'modules/expert/feature-estimation.php', 'label' => 'Review Estimations'],
            'sul' => ['url' => BASE_URL . 'modules/sul/task-allocation.php?project_id=' . $projectId, 'label' => 'Review Handoff Tasks'],
            default => ['url' => $signalSectionUrls['planning'], 'label' => 'Review Planning KPI'],
        },
        'Resource Balance' => match ($userRole) {
            'architect' => ['url' => BASE_URL . 'modules/architect/developer-allocation.php?id=' . $projectId, 'label' => 'Rebalance Team Plan'],
            'sul' => ['url' => BASE_URL . 'modules/sul/task-allocation.php?project_id=' . $projectId, 'label' => 'Reassign Tasks'],
            'developer' => ['url' => BASE_URL . 'modules/developer/task.php', 'label' => 'Check My Tasks'],
            'expert' => ['url' => BASE_URL . 'modules/expert/feature-estimation.php', 'label' => 'Review Workload Inputs'],
            default => ['url' => $signalSectionUrls['resource'], 'label' => 'Review Resource Details'],
        },
    ];

    return $actionsByTitle[$insight['title']] ?? ['url' => $statisticsBaseUrl, 'label' => 'Open Insights'];
};
$topInsights = array_map(
    static function (array $insight) use ($signalActionResolver): array {
        return array_merge($insight, $signalActionResolver($insight));
    },
    $topInsights
);
$metricCatalog = [
    'risk_exposure' => [
        'label' => 'Risk Exposure',
        'value' => number_format($insights['metrics']['risk_exposure'], 1),
        'suffix' => '/100',
        'tone' => $insights['bands']['risk_exposure']['tone'],
        'meta' => $insights['bands']['risk_exposure']['label'],
        'icon' => 'shield-halved',
    ],
    'decision_confidence' => [
        'label' => 'Decision Confidence',
        'value' => number_format($insights['metrics']['decision_confidence'], 1),
        'suffix' => '%',
        'tone' => $insights['bands']['decision_confidence']['tone'],
        'meta' => $insights['bands']['decision_confidence']['label'],
        'icon' => 'chart-line',
    ],
    'forecast_delay_days' => [
        'label' => 'Forecast Delay',
        'value' => number_format($insights['metrics']['forecast_delay_days'], 1),
        'suffix' => ' days',
        'tone' => $insights['metrics']['forecast_delay_days'] > 0 ? 'warning' : 'success',
        'meta' => 'Projected slippage',
        'icon' => 'clock',
    ],
    'monthly_burn' => [
        'label' => 'Monthly Burn',
        'value' => number_format($insights['metrics']['monthly_burn'], 2),
        'suffix' => '',
        'tone' => 'info',
        'meta' => 'Estimated budget usage',
        'icon' => 'coins',
    ],
    'budget_health' => [
        'label' => 'Budget Health',
        'value' => number_format($insights['metrics']['budget_health'], 1),
        'suffix' => '%',
        'tone' => $insights['bands']['budget_health']['tone'],
        'meta' => $insights['bands']['budget_health']['label'],
        'icon' => 'wallet',
    ],
    'planning_readiness' => [
        'label' => 'Planning Readiness',
        'value' => number_format($insights['metrics']['planning_readiness'], 1),
        'suffix' => '%',
        'tone' => $insights['bands']['planning_readiness']['tone'],
        'meta' => $insights['bands']['planning_readiness']['label'],
        'icon' => 'clipboard-check',
    ],
    'resource_efficiency' => [
        'label' => 'Resource Efficiency',
        'value' => number_format($insights['metrics']['resource_efficiency'], 1),
        'suffix' => '%',
        'tone' => $insights['bands']['resource_efficiency']['tone'],
        'meta' => $insights['bands']['resource_efficiency']['label'],
        'icon' => 'users-gear',
    ],
    'git_momentum' => [
        'label' => 'Git Momentum',
        'value' => number_format($insights['metrics']['git_momentum'], 1),
        'suffix' => '%',
        'tone' => di_band_for_score($insights['metrics']['git_momentum'])['tone'],
        'meta' => (int) $insights['metrics']['recent_git_updates'] . ' recent updates',
        'icon' => 'code-branch',
    ],
    'schedule_stability' => [
        'label' => 'Schedule Stability',
        'value' => number_format($insights['metrics']['schedule_stability'], 1),
        'suffix' => '%',
        'tone' => $insights['bands']['schedule_stability']['tone'],
        'meta' => $insights['bands']['schedule_stability']['label'],
        'icon' => 'calendar-check',
    ],
    'testing_risk' => [
        'label' => 'Testing Risk',
        'value' => number_format($insights['metrics']['testing_risk'], 1),
        'suffix' => '/100',
        'tone' => $insights['bands']['testing_risk']['tone'],
        'meta' => $insights['bands']['testing_risk']['label'],
        'icon' => 'vial-circle-check',
    ],
    'client_risk' => [
        'label' => 'Client Risk',
        'value' => number_format($insights['metrics']['client_risk'], 1),
        'suffix' => '/100',
        'tone' => $insights['bands']['client_risk']['tone'],
        'meta' => $insights['bands']['client_risk']['label'],
        'icon' => 'handshake-angle',
    ],
    'impact_chain_score' => [
        'label' => 'Impact Chain',
        'value' => number_format($insights['metrics']['impact_chain_score'], 1),
        'suffix' => '/100',
        'tone' => $insights['bands']['impact_chain_score']['tone'],
        'meta' => $insights['bands']['impact_chain_score']['label'],
        'icon' => 'diagram-project',
    ],
];
$roleProfiles = [
    'admin' => [
        'focus' => 'Portfolio control, escalation, and governance',
        'hero_summary' => 'Prioritize intervention on the projects creating the highest delivery, budget, and coordination risk.',
        'decision_question' => 'Which project needs leadership intervention first?',
        'signal_title' => 'Administrator Intervention Signals',
        'kpi_title' => 'Administrator KPI Watchlist',
        'kpi_note' => 'Use these signals to spot projects that need escalation, governance correction, or resource intervention.',
        'metric_keys' => ['risk_exposure', 'forecast_delay_days', 'budget_health'],
        'kpi_labels' => ['Overdue Rate', 'Budget Health', 'Schedule Stability', 'Resource Efficiency'],
        'insight_priority' => ['Delivery Risk Pressure', 'Budget Burn Exposure', 'Deadline Slippage Outlook', 'Resource Balance', 'Planning Readiness'],
        'priority_items' => ['Portfolio-wide delivery risk', 'Budget and overspend exposure', 'Cross-team workload imbalance'],
        'next_actions' => ['Escalate the riskiest project owner', 'Rebalance capacity across teams', 'Tighten approval or governance checkpoints'],
    ],
    'sales' => [
        'focus' => 'Client commitment, profitability, and scope control',
        'hero_summary' => 'Prioritize insights that affect delivery promises, client trust, and commercial margin.',
        'decision_question' => 'Which project needs a client-facing reset first?',
        'signal_title' => 'Sales Commitment Signals',
        'kpi_title' => 'Sales Commercial Watchlist',
        'kpi_note' => 'Use these KPIs to judge whether delivery promises, budget, and client expectation management are still healthy.',
        'metric_keys' => ['budget_health', 'forecast_delay_days', 'client_risk'],
        'kpi_labels' => ['Budget Health', 'Schedule Stability', 'Completion Rate', 'Planning Readiness'],
        'insight_priority' => ['Budget Burn Exposure', 'Deadline Slippage Outlook', 'Planning Readiness', 'Delivery Risk Pressure', 'Resource Balance'],
        'priority_items' => ['Client-facing delay exposure', 'Margin and burn-rate pressure', 'Scope and approval readiness'],
        'next_actions' => ['Prepare scope or timeline tradeoffs', 'Follow up estimate and approval bottlenecks', 'Reset client expectations before trust drops'],
    ],
    'architect' => [
        'focus' => 'Planning readiness, staffing fit, and technical delivery design',
        'hero_summary' => 'Prioritize the structural conditions that determine whether the team can deliver at the planned pace.',
        'decision_question' => 'What should be corrected in the plan or team design now?',
        'signal_title' => 'Architect Delivery Design Signals',
        'kpi_title' => 'Architect Planning Watchlist',
        'kpi_note' => 'These KPIs show whether the project plan, staffing model, and delivery structure are strong enough for current complexity.',
        'metric_keys' => ['planning_readiness', 'resource_efficiency', 'schedule_stability'],
        'kpi_labels' => ['Planning Readiness', 'Resource Efficiency', 'Schedule Stability', 'Git Momentum'],
        'insight_priority' => ['Planning Readiness', 'Resource Balance', 'Deadline Slippage Outlook', 'Delivery Risk Pressure', 'Budget Burn Exposure'],
        'priority_items' => ['Planning artifact completeness', 'Team composition versus workload', 'Schedule stability against complexity'],
        'next_actions' => ['Adjust developer allocation', 'Close planning and architecture gaps', 'Reduce dependency-driven slowdown'],
    ],
    'sul' => [
        'focus' => 'Task flow, blockers, and workload balance',
        'hero_summary' => 'Prioritize execution signals that show where delivery is slowing down inside the team.',
        'decision_question' => 'Which execution bottleneck should be resolved first?',
        'signal_title' => 'Service Unit Leader Flow Signals',
        'kpi_title' => 'Service Unit Leader Execution Watchlist',
        'kpi_note' => 'These KPIs focus on overdue work, flow interruptions, and the health of day-to-day task execution.',
        'metric_keys' => ['forecast_delay_days', 'resource_efficiency', 'git_momentum'],
        'kpi_labels' => ['Overdue Rate', 'Resource Efficiency', 'Git Momentum', 'Completion Rate'],
        'insight_priority' => ['Delivery Risk Pressure', 'Resource Balance', 'Deadline Slippage Outlook', 'Planning Readiness'],
        'priority_items' => ['Blocked and overdue task flow', 'Workload distribution across members', 'Execution pace against timeline'],
        'next_actions' => ['Reassign tasks and unblock work', 'Re-sequence sprint priorities', 'Push stalled work back into active progress'],
    ],
    'expert' => [
        'focus' => 'Estimation realism, complexity feasibility, and delivery impact',
        'hero_summary' => 'Prioritize signals that reveal where estimates, solution assumptions, or complexity are weakening delivery confidence.',
        'decision_question' => 'Which assumptions need to be re-estimated or clarified now?',
        'signal_title' => 'Expert Feasibility Signals',
        'kpi_title' => 'Expert Estimation Watchlist',
        'kpi_note' => 'These KPIs help experts judge whether scope, planning quality, and downstream impact still match the current delivery path.',
        'metric_keys' => ['planning_readiness', 'testing_risk', 'impact_chain_score'],
        'kpi_labels' => ['Planning Readiness', 'Schedule Stability', 'Resource Efficiency', 'Completion Rate'],
        'insight_priority' => ['Planning Readiness', 'Deadline Slippage Outlook', 'Delivery Risk Pressure', 'Resource Balance', 'Budget Burn Exposure'],
        'priority_items' => ['Feature and scope ambiguity', 'Complexity-driven delivery impact', 'Estimate confidence against timeline'],
        'next_actions' => ['Refine estimates and assumptions', 'Flag unclear requirements early', 'Recommend safer team or scope structure'],
    ],
    'developer' => [
        'focus' => 'Execution priority, blockers, and near-term delivery pressure',
        'hero_summary' => 'Prioritize the signals that help individual contributors focus on the work most likely to affect delivery now.',
        'decision_question' => 'What work should be prioritized or unblocked first?',
        'signal_title' => 'Developer Execution Signals',
        'kpi_title' => 'Developer Delivery Watchlist',
        'kpi_note' => 'These KPIs highlight the pace, stability, and active blockers that developers should respond to first.',
        'metric_keys' => ['forecast_delay_days', 'git_momentum', 'testing_risk'],
        'kpi_labels' => ['Overdue Rate', 'Git Momentum', 'Completion Rate', 'Schedule Stability'],
        'insight_priority' => ['Delivery Risk Pressure', 'Deadline Slippage Outlook', 'Resource Balance', 'Planning Readiness'],
        'priority_items' => ['Immediate blockers and overdue work', 'Near-term deadline pressure', 'Code and review momentum'],
        'next_actions' => ['Update task progress quickly', 'Raise blockers before they age', 'Focus on the highest-risk active work first'],
    ],
];
$roleSignalOverrides = [
    'admin' => [
        'Delivery Risk Pressure' => ['title' => 'Executive Delivery Intervention', 'action' => 'Escalate the delivery owner, reassign capacity, and require a concrete recovery plan.'],
        'Deadline Slippage Outlook' => ['title' => 'Schedule Recovery Priority', 'action' => 'Decide whether to add capacity, adjust timeline, or reduce scope at the leadership level.'],
        'Budget Burn Exposure' => ['title' => 'Budget Governance Exposure', 'action' => 'Review burn pressure, approvals, and scope controls before overspend becomes structural.'],
        'Planning Readiness' => ['title' => 'Governance Readiness Gap', 'action' => 'Require missing planning artifacts before the project pushes more work into execution.'],
        'Resource Balance' => ['title' => 'Cross-Team Capacity Imbalance', 'action' => 'Rebalance teams or ownership where work distribution is weakening delivery stability.'],
    ],
    'sales' => [
        'Delivery Risk Pressure' => ['title' => 'Client Delivery Risk', 'action' => 'Prepare a client-ready explanation and internal recovery path before confidence drops externally.'],
        'Deadline Slippage Outlook' => ['title' => 'Commitment Slippage Outlook', 'action' => 'Decide whether the client needs a timeline reset, scope trim, or phased delivery plan.'],
        'Budget Burn Exposure' => ['title' => 'Margin and Burn Exposure', 'action' => 'Protect margin by aligning scope, approvals, and commercial expectations early.'],
        'Planning Readiness' => ['title' => 'Approval Readiness Gap', 'action' => 'Close planning or requirement gaps that could weaken estimate approval or client trust.'],
        'Resource Balance' => ['title' => 'Delivery Capacity Pressure', 'action' => 'Coordinate with delivery leads before capacity issues turn into missed commitments.'],
    ],
    'architect' => [
        'Delivery Risk Pressure' => ['title' => 'Delivery Architecture Pressure', 'action' => 'Correct staffing or structural bottlenecks before the delivery plan falls further behind.'],
        'Deadline Slippage Outlook' => ['title' => 'Plan Stability Outlook', 'action' => 'Rework staffing, sequencing, or technical scope to absorb projected slippage.'],
        'Budget Burn Exposure' => ['title' => 'Design Cost Pressure', 'action' => 'Check whether the current solution and staffing shape are causing avoidable burn.'],
        'Planning Readiness' => ['title' => 'Planning and Architecture Readiness', 'action' => 'Complete the missing artifacts that the team needs before scaling execution.'],
        'Resource Balance' => ['title' => 'Staffing Fit Signal', 'action' => 'Realign ownership, skills, or allocation where complexity no longer matches team capacity.'],
    ],
    'sul' => [
        'Delivery Risk Pressure' => ['title' => 'Execution Flow Pressure', 'action' => 'Unblock overdue work, shorten queues, and reset priorities for the team immediately.'],
        'Deadline Slippage Outlook' => ['title' => 'Task Flow Delay Outlook', 'action' => 'Resequence work and reassign ownership before the current pace misses the delivery target.'],
        'Budget Burn Exposure' => ['title' => 'Execution Cost Awareness', 'action' => 'Flag wasteful execution patterns that are increasing cost without moving delivery.'],
        'Planning Readiness' => ['title' => 'Handoff Readiness Gap', 'action' => 'Close the missing planning inputs that are slowing assignment and execution.'],
        'Resource Balance' => ['title' => 'Team Allocation Imbalance', 'action' => 'Reassign tasks across the team where ownership load is causing avoidable delays.'],
    ],
    'expert' => [
        'Delivery Risk Pressure' => ['title' => 'Feasibility Risk Pressure', 'action' => 'Check whether estimate or solution assumptions are making current tasks riskier than planned.'],
        'Deadline Slippage Outlook' => ['title' => 'Estimate Slippage Outlook', 'action' => 'Review whether complexity or sizing assumptions still support the committed timeline.'],
        'Budget Burn Exposure' => ['title' => 'Scope Cost Pressure', 'action' => 'Identify scope elements that are driving cost faster than value or readiness.'],
        'Planning Readiness' => ['title' => 'Estimation Readiness Gap', 'action' => 'Refine ambiguous requirements and missing sizing inputs before execution expands.'],
        'Resource Balance' => ['title' => 'Complexity-to-Capacity Fit', 'action' => 'Recommend changes where team composition no longer fits the technical workload.'],
    ],
    'developer' => [
        'Delivery Risk Pressure' => ['title' => 'Active Delivery Pressure', 'action' => 'Focus on the blocked or overdue work most likely to affect near-term delivery.'],
        'Deadline Slippage Outlook' => ['title' => 'Near-Term Slippage Outlook', 'action' => 'Update progress and escalate blockers before the current queue slips further.'],
        'Budget Burn Exposure' => ['title' => 'Delivery Efficiency Pressure', 'action' => 'Work with leads to avoid rework or stalled effort that consumes time without progress.'],
        'Planning Readiness' => ['title' => 'Execution Clarity Gap', 'action' => 'Raise unclear requirements or missing inputs before implementation slows down.'],
        'Resource Balance' => ['title' => 'Workload Balance Signal', 'action' => 'Coordinate with the lead when assignment load or review queues block your momentum.'],
    ],
];
$roleProfile = $roleProfiles[$userRole] ?? $roleProfiles['admin'];
if (!$canViewBudget) {
    $roleProfile['metric_keys'] = array_values(array_filter($roleProfile['metric_keys'], static fn(string $key): bool => $key !== 'budget_health' && $key !== 'monthly_burn'));
    $roleProfile['kpi_labels'] = array_values(array_filter($roleProfile['kpi_labels'], static fn(string $label): bool => $label !== 'Budget Health'));
    $roleProfile['insight_priority'] = array_values(array_filter($roleProfile['insight_priority'], static fn(string $title): bool => $title !== 'Budget Burn Exposure'));
}
$roleMetricCards = array_values(array_filter(
    array_map(
        static fn(string $key): ?array => $metricCatalog[$key] ?? null,
        $roleProfile['metric_keys']
    )
));
if ($roleMetricCards === []) {
    $roleMetricCards = $metricCards;
}
$kpiRowsByLabel = [];
foreach ($kpiRows as $kpi) {
    $kpiRowsByLabel[$kpi['label']] = $kpi;
}
$roleKpiRows = [];
foreach ($roleProfile['kpi_labels'] as $label) {
    if (isset($kpiRowsByLabel[$label])) {
        $roleKpiRows[] = $kpiRowsByLabel[$label];
    }
}
if ($roleKpiRows === []) {
    $roleKpiRows = $kpiRows;
}
$roleInsightPriority = array_flip($roleProfile['insight_priority']);
$topInsights = array_map(
    static function (array $insight) use ($roleSignalOverrides, $userRole): array {
        $baseTitle = $insight['title'];
        $override = $roleSignalOverrides[$userRole][$baseTitle] ?? [];

        return array_merge($insight, $override, ['base_title' => $baseTitle]);
    },
    $topInsights
);
usort(
    $topInsights,
    static function (array $left, array $right) use ($roleInsightPriority): int {
        $leftOrder = $roleInsightPriority[$left['base_title']] ?? 99;
        $rightOrder = $roleInsightPriority[$right['base_title']] ?? 99;
        if ($leftOrder !== $rightOrder) {
            return $leftOrder <=> $rightOrder;
        }

        return ($right['score'] ?? 0) <=> ($left['score'] ?? 0);
    }
);
$topInsights = array_slice($topInsights, 0, 4);
$actionCatalog = array_filter(
    $scenarioCatalog,
    static fn(string $key): bool => $key !== 'current_state',
    ARRAY_FILTER_USE_KEY
);

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<style>
:root {
    --insight-primary: #1f6392;
    --insight-dark: #184f73;
    --insight-light: #3a7ca3;
    --gray-50: #f8f9fa;
    --gray-100: #f1f3f5;
    --gray-200: #e9ecef;
    --gray-300: #dee2e6;
    --gray-500: #adb5bd;
    --gray-600: #6c757d;
    --gray-700: #495057;
    --gray-900: #212529;
    --shadow-sm: 0 2px 4px rgba(0, 0, 0, 0.02), 0 1px 2px rgba(0, 0, 0, 0.03);
    --shadow-md: 0 6px 12px rgba(0, 0, 0, 0.05), 0 2px 6px rgba(0, 0, 0, 0.03);
    --insight-surface: #071321;
    --insight-surface-soft: #0a1b2c;
    --insight-border: rgba(31, 99, 146, 0.24);
    --insight-text: #d7e4f2;
    --insight-muted: #9db6cf;
}

body,
#page-content-wrapper {
    background: #ffffff;
}

.insights-hero {
    background: linear-gradient(135deg, var(--insight-primary) 0%, var(--insight-dark) 100%);
    border-radius: 28px;
    padding: 2rem;
    color: #fff;
    position: relative;
    overflow: hidden;
    margin-bottom: 1.5rem;
}

.insights-hero h1 {
    font-size: 2rem;
    font-weight: 700;
    margin-bottom: 0.4rem;
}

.insights-hero p {
    margin-bottom: 0;
    color: rgba(255, 255, 255, 0.88);
}

.section-card {
    background: #fff;
    border: 1px solid var(--gray-200);
    border-radius: 24px;
    box-shadow: var(--shadow-sm);
    height: 100%;
    overflow: hidden;
}

.section-card .card-header {
    background: #fff;
    border-bottom: 1px solid var(--gray-200);
    padding: 1.2rem 1.4rem;
}

.section-card .card-header h5 {
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
    color: var(--gray-900);
    display: flex;
    align-items: center;
    gap: 0.6rem;
}

.section-card .card-header h5 i {
    color: var(--insight-primary);
}

.section-card .card-body {
    padding: 1.4rem;
}

.filter-card {
    background: #fff;
    border-radius: 24px;
    border: 1px solid var(--gray-200);
    box-shadow: var(--shadow-sm);
    margin-bottom: 1.5rem;
}

.insight-toolbar {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
    align-items: center;
    margin-bottom: 1.5rem;
}

.toolbar-dropdown {
    position: relative;
}

.toolbar-menu {
    min-width: 260px;
    border-radius: 18px;
    border: 1px solid var(--gray-200);
    box-shadow: var(--shadow-md);
    padding: 0.55rem;
}

.toolbar-menu .dropdown-item {
    border-radius: 12px;
    font-size: 0.86rem;
    padding: 0.7rem 0.85rem;
    white-space: normal;
}

.toolbar-menu .dropdown-item:active {
    background: var(--insight-primary);
}

.toolbar-button {
    display: inline-flex;
    align-items: center;
    gap: 0.55rem;
    border-radius: 999px;
    padding: 0.78rem 1.05rem;
    border: 1px solid var(--gray-200);
    background: #fff;
    color: var(--gray-900);
    font-weight: 600;
    text-decoration: none;
    box-shadow: var(--shadow-sm);
}

.toolbar-button:hover {
    color: var(--insight-primary);
    border-color: rgba(31, 99, 146, 0.3);
}

.toolbar-button.primary {
    background: linear-gradient(135deg, var(--insight-primary) 0%, var(--insight-dark) 100%);
    color: #fff;
    border-color: transparent;
}

.toolbar-button.primary:hover {
    color: #fff;
}

.filter-card .card-body {
    padding: 1.4rem;
}

.overview-card-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1.2rem;
    margin-bottom: 1.5rem;
}

.overview-controls {
    display: grid;
    grid-template-columns: minmax(0, 1.6fr) minmax(220px, 0.7fr);
    gap: 0.9rem;
    margin-bottom: 1.25rem;
}

.overview-control-card {
    background: #fff;
    border: 1px solid var(--gray-200);
    border-radius: 22px;
    box-shadow: var(--shadow-sm);
    padding: 1rem 1.1rem;
}

.overview-control-label {
    display: block;
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    font-weight: 700;
    color: var(--gray-600);
    margin-bottom: 0.55rem;
}

.overview-search-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 0.7rem;
    align-items: center;
}

.overview-search-input,
.overview-sort-select {
    width: 100%;
    border: 1px solid var(--gray-200);
    border-radius: 14px;
    padding: 0.78rem 0.95rem;
    font-size: 0.9rem;
    color: var(--gray-900);
    background: #fff;
}

.overview-search-input:focus,
.overview-sort-select:focus {
    outline: none;
    border-color: rgba(31, 99, 146, 0.45);
    box-shadow: 0 0 0 3px rgba(31, 99, 146, 0.12);
}

.overview-search-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.45rem;
    border-radius: 14px;
    padding: 0.78rem 1rem;
    border: none;
    background: linear-gradient(135deg, var(--insight-primary) 0%, var(--insight-dark) 100%);
    color: #fff;
    font-weight: 700;
}

.overview-results-note {
    margin-bottom: 1rem;
    font-size: 0.82rem;
    color: var(--gray-600);
}

.overview-project-card {
    background: #fff;
    border: 1px solid var(--gray-200);
    border-radius: 28px;
    box-shadow: var(--shadow-sm);
    overflow: hidden;
}

.overview-project-card summary {
    list-style: none;
    cursor: pointer;
    padding: 1.35rem 1.5rem;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    background: #fff;
}

.overview-project-card summary::-webkit-details-marker {
    display: none;
}

.overview-project-card summary:focus-visible {
    outline: 3px solid rgba(31, 99, 146, 0.22);
    outline-offset: -3px;
}

.overview-project-summary {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
}

.overview-project-toggle {
    flex-shrink: 0;
    width: 2.25rem;
    height: 2.25rem;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: rgba(31, 99, 146, 0.08);
    color: var(--insight-primary);
    transition: transform 0.2s ease, background 0.2s ease;
}

.overview-project-card[open] .overview-project-toggle {
    transform: rotate(180deg);
    background: rgba(31, 99, 146, 0.14);
}

.overview-project-body {
    padding: 0 1.5rem 1.5rem;
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
}

.overview-project-card.risk-low {
    border-color: var(--gray-200);
    box-shadow: 0 6px 12px rgba(0, 0, 0, 0.08);
}

.overview-project-card.risk-medium {
    border-color: var(--gray-200);
    box-shadow: 0 6px 12px rgba(0, 0, 0, 0.08);
}

.overview-project-card.risk-high {
    border-color: var(--gray-200);
    box-shadow: 0 6px 12px rgba(0, 0, 0, 0.08);
}

.overview-project-card.needs-attention {
    border-color: #f59e0b;
    box-shadow: 0 8px 22px rgba(245, 158, 11, 0.16);
    position: relative;
}

.overview-project-card.needs-attention::before {
    background: #f59e0b;
    content: "";
    inset: 0 auto 0 0;
    position: absolute;
    width: 5px;
    z-index: 1;
}

.overview-project-head {
    display: flex;
    justify-content: space-between;
    gap: 1.15rem;
    align-items: flex-start;
    padding-bottom: 0.2rem;
}

.overview-project-head h5 {
    margin: 0 0 0.35rem;
    font-size: 1rem;
    font-weight: 700;
    color: var(--gray-900);
}

.overview-project-meta {
    font-size: 0.76rem;
    color: var(--gray-600);
    line-height: 1.5;
}

.overview-project-badges {
    align-items: center;
    display: flex;
    flex: 0 0 auto;
    flex-wrap: wrap;
    gap: .5rem;
    justify-content: flex-end;
}

.project-attention-badge {
    align-items: center;
    background: linear-gradient(135deg, #ffedd5 0%, #fed7aa 100%);
    border: 1px solid #fb923c;
    border-radius: 999px;
    color: #c2410c;
    display: inline-flex;
    font-size: .72rem;
    font-weight: 800;
    gap: .4rem;
    padding: .5rem .8rem;
    white-space: nowrap;
}

.delay-risk-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.5rem 0.9rem;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 800;
    border: 1px solid transparent;
    box-shadow: inset 0 0 0 1px rgba(255,255,255,0.25);
}

.delay-risk-low {
    background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%);
    color: #166534;
    border-color: #86efac;
}

.delay-risk-medium {
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    color: #92400e;
    border-color: #fbbf24;
}

.delay-risk-high {
    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
    color: #991b1b;
    border-color: #f87171;
}

.overview-insight-layout {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.overview-panel {
    border: 1px solid var(--gray-200);
    border-radius: 22px;
    padding: 1.1rem 1.1rem 1rem;
    background: #fff;
}
.overview-panel.after {
    background: linear-gradient(180deg, rgba(245, 158, 11, 0.06) 0%, #ffffff 100%);
}

.overview-project-card.risk-low .overview-panel.before {
    background: linear-gradient(180deg, rgba(16, 185, 129, 0.08) 0%, #ffffff 100%);
    border-color: #bbf7d0;
}

.overview-project-card.risk-medium .overview-panel.before {
    background: linear-gradient(180deg, rgba(245, 158, 11, 0.08) 0%, #ffffff 100%);
    border-color: #fde68a;
}

.overview-project-card.risk-high .overview-panel.before {
    background: linear-gradient(180deg, rgba(239, 68, 68, 0.08) 0%, #ffffff 100%);
    border-color: #fecaca;
}

.overview-project-card.risk-low .overview-panel.after {
    background: linear-gradient(180deg, rgba(22, 163, 74, 0.05) 0%, #ffffff 100%);
}

.overview-project-card.risk-medium .overview-panel.after {
    background: linear-gradient(180deg, rgba(217, 119, 6, 0.06) 0%, #ffffff 100%);
}

.overview-project-card.risk-high .overview-panel.after {
    background: linear-gradient(180deg, rgba(220, 38, 38, 0.06) 0%, #ffffff 100%);
}

.overview-panel-title {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    margin-bottom: 0.95rem;
    font-size: 0.76rem;
    font-weight: 800;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--gray-900);
}

.overview-metric-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 0.7rem;
    margin-bottom: 0.9rem;
}

.overview-mini-card {
    border: 1px solid var(--gray-200);
    border-radius: 18px;
    padding: 0.75rem 0.85rem;
    background: #fff;
}

.overview-mini-card span {
    display: block;
    font-size: 0.67rem;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--gray-600);
    margin-bottom: 0.2rem;
}

.overview-mini-card strong {
    font-size: 1.35rem;
    color: var(--insight-primary);
    line-height: 1;
}

.overview-summary {
    font-size: 0.82rem;
    color: var(--gray-700);
    line-height: 1.6;
    margin: 0;
}

.overview-list {
    margin: 0.85rem 0 0;
    padding-left: 1rem;
    color: var(--gray-700);
    font-size: 0.79rem;
    line-height: 1.6;
}

.overview-list li {
    margin-bottom: 0.38rem;
}

.overview-list li:last-child {
    margin-bottom: 0;
}

.overview-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 0.45rem;
    margin-top: 0.9rem;
}

.overview-tag {
    display: inline-flex;
    align-items: center;
    padding: 0.34rem 0.68rem;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 600;
    border: 1px solid #fdba74;
    background: #fff7ed;
    color: #9a3412;
}

.overview-note {
    margin-top: 0.9rem;
    padding: 0.8rem 0.9rem;
    border-radius: 16px;
    border: 1px solid #e2e8f0;
    background: #f8fafc;
    color: #475569;
    font-size: 0.76rem;
    line-height: 1.5;
}

.overview-footer {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
    margin-top: 0.1rem;
}

.action-modal-note {
    font-size: 0.8rem;
    color: var(--gray-600);
}

.metric-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.metric-card {
    background: #fff;
    border: 1px solid var(--gray-200);
    border-radius: 22px;
    box-shadow: var(--shadow-sm);
    padding: 1.3rem;
    position: relative;
    overflow: hidden;
}

.metric-card .icon {
    position: absolute;
    right: 1.1rem;
    top: 1.1rem;
    font-size: 1.35rem;
    opacity: 0.25;
}

.metric-card .label {
    font-size: 0.74rem;
    text-transform: uppercase;
    font-weight: 700;
    letter-spacing: 0.06em;
    color: var(--gray-600);
    margin-bottom: 0.55rem;
}

.metric-card .value {
    font-size: 2rem;
    font-weight: 700;
    line-height: 1;
    margin-bottom: 0.35rem;
}

.metric-card .value span {
    font-size: 0.95rem;
    font-weight: 700;
    margin-left: 0.2rem;
}

.metric-card .meta {
    font-size: 0.8rem;
    color: var(--gray-600);
}

.role-brief-card .card-body {
    display: grid;
    gap: 1rem;
}

.role-brief-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) repeat(2, minmax(220px, 1fr));
    gap: 1rem;
    align-items: start;
}

.role-brief-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    font-size: 0.72rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--insight-primary);
}

.role-brief-card h6 {
    margin: 0.35rem 0 0.4rem;
    font-size: 1rem;
    color: var(--gray-900);
}

.role-brief-card p {
    margin: 0;
    color: var(--gray-700);
    font-size: 0.84rem;
    line-height: 1.6;
}

.role-brief-block {
    border: 1px solid var(--gray-200);
    border-radius: 18px;
    padding: 0.95rem 1rem;
    background: var(--gray-50);
}

.role-brief-label {
    display: block;
    font-size: 0.72rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--gray-600);
    margin-bottom: 0.7rem;
}

.role-brief-list {
    margin: 0;
    padding-left: 1rem;
    color: var(--gray-700);
    font-size: 0.82rem;
    line-height: 1.6;
}

.role-brief-list li + li {
    margin-top: 0.35rem;
}

@media (max-width: 1200px) {
    .role-brief-grid {
        grid-template-columns: 1fr;
    }
}

.tone-success { color: #059669; }
.tone-info { color: #2563eb; }
.tone-warning { color: #d97706; }
.tone-danger { color: #dc2626; }

.badge-tone {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: 0.28rem 0.7rem;
    font-size: 0.72rem;
    font-weight: 700;
}

.badge-tone.success { background: #d1fae5; color: #059669; }
.badge-tone.info { background: #dbeafe; color: #2563eb; }
.badge-tone.warning { background: #fef3c7; color: #d97706; }
.badge-tone.danger { background: #fee2e2; color: #dc2626; }

.project-meta {
    display: flex;
    gap: 0.7rem;
    flex-wrap: wrap;
    margin-top: 0.9rem;
}

.project-chip {
    background: rgba(255, 255, 255, 0.14);
    border: 1px solid rgba(255, 255, 255, 0.18);
    border-radius: 999px;
    padding: 0.45rem 0.85rem;
    font-size: 0.76rem;
}

.compare-table,
.data-table {
    width: 100%;
    border-collapse: collapse;
}

.compare-table th,
.compare-table td,
.data-table th,
.data-table td {
    padding: 0.9rem 1rem;
    border-bottom: 1px solid var(--gray-200);
    vertical-align: top;
}

.compare-table th,
.data-table th {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--gray-600);
    background: var(--gray-50);
}

.compare-table tr:last-child td,
.data-table tr:last-child td {
    border-bottom: none;
}

.mini-stat-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
}

.mini-stat {
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-radius: 18px;
    padding: 1rem;
}

.mini-stat .label {
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--gray-600);
    margin-bottom: 0.35rem;
}

.mini-stat .value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--gray-900);
}

.heatmap {
    display: grid;
    grid-template-columns: 90px repeat(3, 1fr);
    gap: 0.55rem;
}

.heatmap-label {
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.78rem;
    color: var(--gray-600);
    font-weight: 600;
}

.heat-cell {
    border-radius: 18px;
    min-height: 72px;
    padding: 0.85rem;
    border: 1px solid transparent;
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    font-size: 0.75rem;
    color: #111827;
}

.heat-low { background: #dcfce7; }
.heat-medium { background: #fef3c7; }
.heat-high { background: #fee2e2; }

.heat-cell.active {
    border-color: var(--insight-primary);
    box-shadow: inset 0 0 0 2px rgba(31, 99, 146, 0.15);
}

.insight-list {
    display: grid;
    gap: 0.9rem;
}

.insight-item {
    border: 1px solid var(--gray-200);
    border-radius: 18px;
    padding: 1rem;
    background: #fff;
}

.insight-item h6 {
    font-size: 0.95rem;
    margin-bottom: 0.35rem;
}

.insight-item p {
    margin-bottom: 0.35rem;
    color: var(--gray-700);
    font-size: 0.84rem;
}

.insight-item small {
    color: var(--gray-600);
}

.insight-actions {
    margin-top: 0.8rem;
    display: flex;
    justify-content: flex-start;
}

.insight-link {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.56rem 0.9rem;
    border-radius: 999px;
    background: linear-gradient(135deg, var(--insight-primary) 0%, var(--insight-dark) 100%);
    color: #fff;
    text-decoration: none;
    font-size: 0.78rem;
    font-weight: 700;
    box-shadow: 0 8px 18px rgba(31, 99, 146, 0.18);
    transition: transform 0.18s ease, box-shadow 0.18s ease;
}

.insight-link:hover {
    color: #fff;
    transform: translateY(-1px);
    box-shadow: 0 10px 22px rgba(31, 99, 146, 0.24);
}

.delta-pill {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: 0.22rem 0.58rem;
    font-size: 0.72rem;
    font-weight: 700;
}

.delta-positive {
    background: #d1fae5;
    color: #059669;
}

.delta-negative {
    background: #fee2e2;
    color: #dc2626;
}

.delta-neutral {
    background: #e2e8f0;
    color: #475569;
}

.scenario-note {
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-radius: 18px;
    padding: 1rem;
    font-size: 0.84rem;
    color: var(--gray-700);
}

.form-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    font-weight: 700;
    color: var(--gray-600);
}

.btn-insight {
    background: linear-gradient(135deg, var(--insight-primary) 0%, var(--insight-dark) 100%);
    color: #fff;
    border: none;
    border-radius: 12px;
    padding: 0.7rem 1.2rem;
    font-weight: 600;
}

.btn-insight:hover {
    color: #fff;
}

.subtext {
    color: var(--gray-600);
    font-size: 0.82rem;
}

.empty-state {
    border: 1px dashed var(--gray-300);
    border-radius: 20px;
    padding: 2.5rem 1.5rem;
    text-align: center;
    color: var(--gray-600);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #page-content-wrapper {
    background: #06111e;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .insights-hero {
    background: var(--insight-surface);
    border: 1px solid rgba(31, 99, 146, 0.18);
    color: var(--insight-text);
    box-shadow: 0 18px 42px rgba(3, 14, 25, 0.28);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .insights-hero p {
    color: var(--insight-muted);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .section-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .filter-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .overview-control-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .overview-project-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .metric-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .toolbar-menu,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .insight-item,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-content {
    background: var(--insight-surface);
    border-color: var(--insight-border);
    box-shadow: 0 16px 36px rgba(3, 14, 25, 0.22);
    color: var(--insight-text);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .overview-project-card summary,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .overview-project-body {
    background: var(--insight-surface);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .overview-project-toggle {
    background: rgba(157, 182, 207, 0.1);
    color: var(--insight-text);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .overview-project-card[open] .overview-project-toggle {
    background: rgba(157, 182, 207, 0.16);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .section-card .card-header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .toolbar-menu .dropdown-item,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-header {
    background: var(--insight-surface);
    border-color: var(--insight-border);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .section-card .card-header h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .overview-project-head h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .overview-panel-title,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .metric-card .value,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .role-brief-card h6,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .mini-stat .value,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .insight-item h6,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .modal-title {
    color: var(--insight-text);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .toolbar-button,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .overview-search-input,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .overview-sort-select,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-control,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-select {
    background: var(--insight-surface);
    border-color: var(--insight-border);
    color: var(--insight-text);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .toolbar-button:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .toolbar-menu .dropdown-item:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .overview-search-input:focus,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .overview-sort-select:focus,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-control:focus,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-select:focus {
    background: var(--insight-surface-soft);
    border-color: rgba(31, 99, 146, 0.45);
    color: var(--insight-text);
    box-shadow: 0 0 0 3px rgba(31, 99, 146, 0.12);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .overview-control-label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .overview-results-note,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .overview-project-meta,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .overview-mini-card span,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .overview-summary,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .overview-list,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .action-modal-note,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .metric-card .label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .metric-card .meta,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .role-brief-card p,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .role-brief-label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .role-brief-list,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .compare-table th,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .data-table th,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .mini-stat .label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .heatmap-label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .insight-item p,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .insight-item small,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .scenario-note,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .subtext,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .empty-state {
    color: var(--insight-muted);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .overview-panel,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .overview-panel.after,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .overview-project-card.risk-low .overview-panel.before,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .overview-project-card.risk-medium .overview-panel.before,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .overview-project-card.risk-high .overview-panel.before,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .overview-project-card.risk-low .overview-panel.after,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .overview-project-card.risk-medium .overview-panel.after,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .overview-project-card.risk-high .overview-panel.after,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .overview-mini-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .overview-note,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .role-brief-block,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .mini-stat,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .scenario-note {
    background: var(--insight-surface-soft);
    border-color: var(--insight-border);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .overview-project-card.risk-low,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .overview-project-card.risk-medium,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .overview-project-card.risk-high {
    border-color: var(--insight-border);
    box-shadow: 0 16px 36px rgba(3, 14, 25, 0.22);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .overview-project-card.needs-attention {
    border-color: #f59e0b;
    box-shadow: 0 16px 36px rgba(245, 158, 11, .12);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .overview-note {
    color: var(--insight-muted);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .compare-table th,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .data-table th {
    background: var(--insight-surface-soft);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .compare-table td,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .data-table td,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .compare-table th,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .data-table th {
    border-bottom-color: var(--insight-border);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-chip {
    background: var(--insight-surface-soft);
    border-color: var(--insight-border);
    color: var(--insight-text);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .overview-tag,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .delta-neutral {
    background: var(--insight-surface);
    border-color: var(--insight-border);
    color: var(--insight-muted);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .empty-state {
    border-color: var(--insight-border);
}

@media (max-width: 1200px) {
    .metric-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .metric-grid,
    .mini-stat-grid {
        grid-template-columns: 1fr;
    }

    .heatmap {
        grid-template-columns: 70px repeat(3, 1fr);
    }

    .insights-hero {
        padding: 1.5rem;
    }

    .insights-hero h1 {
        font-size: 1.55rem;
    }

    .overview-project-head {
        flex-direction: column;
    }

    .overview-project-head .overview-project-badges {
        align-self: flex-start;
        justify-content: flex-start;
    }

    .overview-controls {
        grid-template-columns: 1fr;
    }

    .overview-search-row {
        grid-template-columns: 1fr;
    }

}
</style>

<div class="container-fluid px-4 py-4">
    <div class="insights-hero">
        <span class="badge-tone info mb-3">Project Insights</span>
        <h1><?php echo htmlspecialchars($currentView === 'statistics' ? $projectTitle : 'Project Insights Overview'); ?></h1>
        <p>
            <?php if ($currentView === 'statistics'): ?>
                <?php echo htmlspecialchars(getRoleDisplayName($userRole)); ?> lens: <?php echo htmlspecialchars($roleProfile['hero_summary']); ?>
            <?php else: ?>
                Project activity is monitored continuously. Open a project to review the latest generated insights and response signals.
            <?php endif; ?>
        </p>
        <div class="project-meta">
            <?php if ($currentView === 'statistics'): ?>
                <div class="project-chip"><strong>Client:</strong> <?php echo htmlspecialchars($projectClient); ?></div>
                <div class="project-chip"><strong>Status:</strong> <?php echo htmlspecialchars($projectStatus); ?></div>
                <div class="project-chip"><strong>Complexity:</strong> <?php echo htmlspecialchars($projectComplexity); ?></div>
            <?php else: ?>
                <div class="project-chip"><strong>Projects:</strong> <?php echo count($projects); ?></div>
                <div class="project-chip"><strong>Role:</strong> <?php echo htmlspecialchars(getRoleDisplayName($userRole)); ?></div>
                <div class="project-chip"><strong>Focus:</strong> Delay, confidence, and delivery signals</div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($currentView === 'statistics'): ?>
    <div class="insight-toolbar">
        <div class="toolbar-button" aria-live="polite">
            <i class="fas fa-arrows-rotate"></i>
            Auto-refreshing from project activity
        </div>

        <?php if ($currentView === 'statistics'): ?>
            <a class="toolbar-button primary" href="<?php echo htmlspecialchars($overviewUrl); ?>">
                <i class="fas fa-layer-group"></i>
                Back to Overview
            </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($currentView === 'statistics'): ?>
    <div class="row g-4 mb-4">
        <div class="col-12">
            <div class="metric-grid mb-0">
                <?php foreach ($roleMetricCards as $card): ?>
                    <div class="metric-card">
                        <i class="fas fa-<?php echo htmlspecialchars($card['icon']); ?> icon tone-<?php echo htmlspecialchars($card['tone']); ?>"></i>
                        <div class="label"><?php echo htmlspecialchars($card['label']); ?></div>
                        <div class="value tone-<?php echo htmlspecialchars($card['tone']); ?>">
                            <?php echo htmlspecialchars($card['value']); ?><span><?php echo htmlspecialchars($card['suffix']); ?></span>
                        </div>
                        <div class="meta"><?php echo htmlspecialchars($card['meta']); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-xl-7">
            <div class="section-card">
                <div class="card-header">
                    <h5><i class="fas fa-lightbulb"></i> Important Project Signals</h5>
                </div>
                <div class="card-body">
                    <div class="insight-list">
                        <?php foreach ($topInsights as $insight): ?>
                            <div class="insight-item">
                                <div class="d-flex justify-content-between gap-2 align-items-start">
                                    <h6><?php echo htmlspecialchars($insight['title']); ?></h6>
                                    <span class="badge-tone <?php echo htmlspecialchars($insight['band']['tone']); ?>"><?php echo htmlspecialchars($insight['band']['label']); ?></span>
                                </div>
                                <p><?php echo htmlspecialchars($insight['detail']); ?></p>
                                <small><?php echo htmlspecialchars($insight['action']); ?></small>
                                <div class="insight-actions">
                                    <a class="insight-link" href="<?php echo htmlspecialchars((string) ($insight['url'] ?? $statisticsBaseUrl)); ?>">
                                        <i class="fas fa-arrow-right"></i>
                                        <?php echo htmlspecialchars((string) ($insight['label'] ?? 'Open Related Page')); ?>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-5">
            <div class="section-card">
                <div class="card-header">
                    <h5><i class="fas fa-gauge-high"></i> Current Health</h5>
                </div>
                <div class="card-body">
                    <div class="mini-stat-grid mb-4">
                        <div class="mini-stat">
                            <div class="label">Forecast Delay</div>
                            <div class="value"><?php echo number_format($insights['metrics']['forecast_delay_days'], 1); ?> days</div>
                            <span class="badge-tone <?php echo $insights['metrics']['forecast_delay_days'] > 0 ? 'warning' : 'success'; ?>"><?php echo $insights['metrics']['forecast_delay_days'] > 0 ? 'Needs Attention' : 'On Track'; ?></span>
                        </div>
                        <div class="mini-stat">
                            <div class="label">Resource Efficiency</div>
                            <div class="value"><?php echo number_format($insights['metrics']['resource_efficiency'], 1); ?>%</div>
                            <span class="badge-tone <?php echo htmlspecialchars($insights['bands']['resource_efficiency']['tone']); ?>"><?php echo htmlspecialchars($insights['bands']['resource_efficiency']['label']); ?></span>
                        </div>
                        <div class="mini-stat">
                            <div class="label">Open Tasks</div>
                            <div class="value"><?php echo (int) $insights['metrics']['open_tasks']; ?></div>
                            <span class="subtext"><?php echo (int) $insights['metrics']['blocked_tasks']; ?> blocked, <?php echo (int) $insights['metrics']['overdue_tasks']; ?> overdue</span>
                        </div>
                        <div class="mini-stat">
                            <div class="label">Decision Confidence</div>
                            <div class="value"><?php echo number_format($insights['metrics']['decision_confidence'], 1); ?>%</div>
                            <span class="badge-tone <?php echo htmlspecialchars($insights['bands']['decision_confidence']['tone']); ?>"><?php echo htmlspecialchars($insights['bands']['decision_confidence']['label']); ?></span>
                        </div>
                    </div>

                    <table class="compare-table">
                        <tbody>
                            <tr>
                                <td>Completed Tasks</td>
                                <td><?php echo (int) $insights['metrics']['completed_tasks']; ?> / <?php echo (int) $insights['metrics']['total_tasks']; ?></td>
                            </tr>
                            <tr>
                                <td>Assigned Members</td>
                                <td><?php echo (int) $insights['metrics']['assigned_members']; ?> / <?php echo (int) $insights['metrics']['required_members']; ?></td>
                            </tr>
                            <tr>
                                <td>Recent Git Updates</td>
                                <td><?php echo (int) $insights['metrics']['recent_git_updates']; ?></td>
                            </tr>
                            <?php if ($canViewBudget): ?>
                                <tr>
                                    <td>Budget Health</td>
                                    <td><?php echo number_format($insights['metrics']['budget_health'], 1); ?>%</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <?php if (false): ?>

    <div class="row g-4 mb-4">
        <div class="col-xl-7">
            <div class="section-card">
                <div class="card-header">
                    <h5><i class="fas fa-scale-balanced"></i> Decision Impact Analysis</h5>
                </div>
                <div class="card-body">
                    <div class="mini-stat-grid mb-4">
                        <?php if ($canViewBudget): ?>
                            <div class="mini-stat">
                                <div class="label">Budget Impact</div>
                                <div class="value"><?php echo number_format($simulation['deltas']['costDelta'], 2); ?></div>
                                <span class="delta-pill <?php echo $simulation['deltas']['costDelta'] < 0 ? 'delta-positive' : ($simulation['deltas']['costDelta'] > 0 ? 'delta-negative' : 'delta-neutral'); ?>">
                                    <?php echo $simulation['deltas']['costDelta'] < 0 ? 'Savings' : ($simulation['deltas']['costDelta'] > 0 ? 'Additional cost' : 'No change'); ?>
                                </span>
                            </div>
                        <?php endif; ?>
                        <div class="mini-stat">
                            <div class="label">Timeline Impact</div>
                            <div class="value"><?php echo number_format($simulation['deltas']['delayDelta'], 1); ?> days</div>
                            <span class="delta-pill <?php echo $simulation['deltas']['delayDelta'] < 0 ? 'delta-positive' : ($simulation['deltas']['delayDelta'] > 0 ? 'delta-negative' : 'delta-neutral'); ?>">
                                <?php echo $simulation['deltas']['delayDelta'] < 0 ? 'Delay reduced' : ($simulation['deltas']['delayDelta'] > 0 ? 'Delay increased' : 'No change'); ?>
                            </span>
                        </div>
                        <div class="mini-stat">
                            <div class="label">Risk Shift</div>
                            <div class="value"><?php echo number_format($simulation['deltas']['riskDelta'], 1); ?></div>
                            <span class="delta-pill <?php echo $simulation['deltas']['riskDelta'] < 0 ? 'delta-positive' : ($simulation['deltas']['riskDelta'] > 0 ? 'delta-negative' : 'delta-neutral'); ?>">
                                <?php echo $simulation['deltas']['riskDelta'] < 0 ? 'Risk reduced' : ($simulation['deltas']['riskDelta'] > 0 ? 'Risk increased' : 'No change'); ?>
                            </span>
                        </div>
                        <div class="mini-stat">
                            <div class="label">Productivity Shift</div>
                            <div class="value"><?php echo number_format($simulation['deltas']['productivityDelta'], 1); ?></div>
                            <span class="delta-pill <?php echo $simulation['deltas']['productivityDelta'] > 0 ? 'delta-positive' : ($simulation['deltas']['productivityDelta'] < 0 ? 'delta-negative' : 'delta-neutral'); ?>">
                                <?php echo $simulation['deltas']['productivityDelta'] > 0 ? 'Improvement' : ($simulation['deltas']['productivityDelta'] < 0 ? 'Drop' : 'No change'); ?>
                            </span>
                        </div>
                    </div>

                    <table class="compare-table">
                        <thead>
                            <tr>
                                <th>Measure</th>
                                <th>Before</th>
                                <th>After</th>
                                <th>Band</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Forecast Delay</td>
                                <td><?php echo number_format($simulation['before']['forecast_delay_days'], 1); ?> days</td>
                                <td><?php echo number_format($simulation['after']['forecast_delay_days'], 1); ?> days</td>
                                <td><span class="badge-tone <?php echo htmlspecialchars($simulation['risk_band']['tone']); ?>"><?php echo htmlspecialchars($simulation['risk_band']['label']); ?></span></td>
                            </tr>
                            <tr>
                                <td>Risk Exposure</td>
                                <td><?php echo number_format($simulation['before']['risk_score'], 1); ?></td>
                                <td><?php echo number_format($simulation['after']['risk_score'], 1); ?></td>
                                <td><span class="badge-tone <?php echo htmlspecialchars($simulation['risk_band']['tone']); ?>"><?php echo htmlspecialchars($simulation['risk_band']['label']); ?></span></td>
                            </tr>
                            <tr>
                                <td>Resource Efficiency</td>
                                <td><?php echo number_format($simulation['before']['resource_efficiency'], 1); ?>%</td>
                                <td><?php echo number_format($simulation['after']['resource_efficiency'], 1); ?>%</td>
                                <td><span class="badge-tone <?php echo htmlspecialchars(di_band_for_score($simulation['after']['resource_efficiency'])['tone']); ?>"><?php echo htmlspecialchars(di_band_for_score($simulation['after']['resource_efficiency'])['label']); ?></span></td>
                            </tr>
                            <tr>
                                <td>Decision Confidence</td>
                                <td><?php echo number_format($simulation['before']['confidence_score'], 1); ?>%</td>
                                <td><?php echo number_format($simulation['after']['confidence_score'], 1); ?>%</td>
                                <td><span class="badge-tone <?php echo htmlspecialchars($simulation['confidence_band']['tone']); ?>"><?php echo htmlspecialchars($simulation['confidence_band']['label']); ?></span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-xl-5">
            <div class="section-card" id="risk-heatmap">
                <div class="card-header">
                    <h5><i class="fas fa-chart-pie"></i> Before vs After Profile</h5>
                </div>
                <div class="card-body">
                    <canvas id="decisionRadarChart" style="height: 320px;"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-12">
            <div class="section-card role-brief-card">
                <div class="card-header">
                    <h5><i class="fas fa-crosshairs"></i> Role Decision Brief</h5>
                </div>
                <div class="card-body">
                    <div class="role-brief-grid">
                        <div>
                            <span class="role-brief-eyebrow">
                                <i class="fas fa-user-shield"></i>
                                <?php echo htmlspecialchars(getRoleDisplayName($userRole)); ?>
                            </span>
                            <h6><?php echo htmlspecialchars($roleProfile['decision_question']); ?></h6>
                            <p><?php echo htmlspecialchars($roleProfile['focus']); ?></p>
                        </div>

                        <div class="role-brief-block">
                            <span class="role-brief-label">Important Aspects</span>
                            <ul class="role-brief-list">
                                <?php foreach ($roleProfile['priority_items'] as $item): ?>
                                    <li><?php echo htmlspecialchars($item); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>

                        <div class="role-brief-block">
                            <span class="role-brief-label">Recommended Decisions</span>
                            <ul class="role-brief-list">
                                <?php foreach ($roleProfile['next_actions'] as $item): ?>
                                    <li><?php echo htmlspecialchars($item); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-7">
            <div class="section-card">
                <div class="card-header">
                    <h5><i class="fas fa-route"></i> Strategic Decision Comparison Module</h5>
                </div>
                <div class="card-body">
                    <table class="compare-table">
                        <thead>
                            <tr>
                                <th>Strategy</th>
                                <?php if ($canViewBudget): ?>
                                    <th>Cost Delta</th>
                                <?php endif; ?>
                                <th>Delay After</th>
                                <th>Risk After</th>
                                <th>Confidence After</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($strategyComparisons as $strategy): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($strategy['simulation']['config']['label']); ?></strong><br>
                                        <span class="subtext"><?php echo number_format($strategy['simulation']['value'], 0); ?> <?php echo htmlspecialchars($strategy['simulation']['config']['unit_label']); ?></span>
                                    </td>
                                    <?php if ($canViewBudget): ?>
                                        <td><?php echo number_format($strategy['simulation']['deltas']['costDelta'], 2); ?></td>
                                    <?php endif; ?>
                                    <td><?php echo number_format($strategy['simulation']['after']['forecast_delay_days'], 1); ?> days</td>
                                    <td><?php echo number_format($strategy['simulation']['after']['risk_score'], 1); ?></td>
                                    <td><?php echo number_format($strategy['simulation']['after']['confidence_score'], 1); ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-xl-5">
            <div class="section-card">
                <div class="card-header">
                    <h5><i class="fas fa-chart-column"></i> Strategy Confidence vs Risk</h5>
                </div>
                <div class="card-body">
                    <canvas id="strategyChart" style="height: 320px;"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-xl-6">
            <div class="section-card">
                <div class="card-header">
                    <h5><i class="fas fa-triangle-exclamation"></i> Risk Probability & Severity Engine</h5>
                </div>
                <div class="card-body">
                    <div class="mini-stat-grid mb-4">
                        <div class="mini-stat">
                            <div class="label">Probability</div>
                            <div class="value"><?php echo number_format($insights['metrics']['risk_probability'], 1); ?></div>
                            <span class="badge-tone <?php echo htmlspecialchars($insights['bands']['risk_probability']['tone']); ?>"><?php echo htmlspecialchars($insights['bands']['risk_probability']['label']); ?></span>
                        </div>
                        <div class="mini-stat">
                            <div class="label">Severity / Impact</div>
                            <div class="value"><?php echo number_format($insights['metrics']['risk_impact'], 1); ?></div>
                            <span class="badge-tone <?php echo htmlspecialchars($insights['bands']['risk_impact']['tone']); ?>"><?php echo htmlspecialchars($insights['bands']['risk_impact']['label']); ?></span>
                        </div>
                    </div>

                    <div class="heatmap mb-3">
                        <div></div>
                        <?php foreach ($heatmapColumns as $column): ?>
                            <div class="heatmap-label"><?php echo htmlspecialchars($column['label']); ?></div>
                        <?php endforeach; ?>

                        <?php foreach ($heatmapRows as $row): ?>
                            <div class="heatmap-label"><?php echo htmlspecialchars($row['label']); ?></div>
                            <?php foreach ($heatmapColumns as $column): ?>
                                <?php
                                $toneClass = $row['index'] === 3 || $column['index'] === 3 ? 'heat-high' : ($row['index'] === 2 || $column['index'] === 2 ? 'heat-medium' : 'heat-low');
                                $active = $row['index'] === (int) $insights['bands']['impact_heat']['index'] && $column['index'] === (int) $insights['bands']['probability_heat']['index'];
                                ?>
                                <div class="heat-cell <?php echo $toneClass; ?> <?php echo $active ? 'active' : ''; ?>">
                                    <span><?php echo $active ? 'Current' : 'Zone'; ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>

                    <div class="subtext">
                        Risk heatmap is based on weighted probability and impact scores from overdue work, blocked flow, stalled git activity, planning pressure<?php echo $canViewBudget ? ', budget exposure, and complexity.' : ' and complexity.'; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-6">
            <div class="section-card">
                <div class="card-header">
                    <h5><i class="fas fa-lightbulb"></i> <?php echo htmlspecialchars($roleProfile['signal_title']); ?></h5>
                </div>
                <div class="card-body">
                    <div class="insight-list">
                        <?php foreach ($topInsights as $insight): ?>
                            <div class="insight-item">
                                <div class="d-flex justify-content-between gap-2 align-items-start">
                                    <h6><?php echo htmlspecialchars($insight['title']); ?></h6>
                                    <span class="badge-tone <?php echo htmlspecialchars($insight['band']['tone']); ?>"><?php echo htmlspecialchars($insight['band']['label']); ?></span>
                                </div>
                                <p><?php echo htmlspecialchars($insight['detail']); ?></p>
                                <small><?php echo htmlspecialchars($insight['action']); ?></small>
                                <div class="insight-actions">
                                    <a class="insight-link" href="<?php echo htmlspecialchars((string) ($insight['url'] ?? $statisticsBaseUrl)); ?>">
                                        <i class="fas fa-arrow-right"></i>
                                        <?php echo htmlspecialchars((string) ($insight['label'] ?? 'Open Insights')); ?>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="<?php echo $canViewBudget ? 'col-xl-6' : 'col-12'; ?>">
            <div class="section-card" id="deadline-analyzer">
                <div class="card-header">
                    <h5><i class="fas fa-hourglass-half"></i> Predictive Deadline Slippage Analyzer</h5>
                </div>
                <div class="card-body">
                    <table class="compare-table">
                        <tbody>
                            <tr>
                                <td>Planned Value</td>
                                <td><?php echo number_format($insights['metrics']['planned_value'], 2); ?></td>
                                <td class="subtext">Work expected by this point in the timeline</td>
                            </tr>
                            <tr>
                                <td>Earned Value</td>
                                <td><?php echo number_format($insights['metrics']['earned_value'], 2); ?></td>
                                <td class="subtext">Work actually completed by the team</td>
                            </tr>
                            <tr>
                                <td>Schedule Variance</td>
                                <td><?php echo number_format($insights['metrics']['schedule_variance'], 2); ?></td>
                                <td class="subtext">EV - PV, using estimated work days as the planned baseline</td>
                            </tr>
                            <tr>
                                <td>Projected Remaining Days</td>
                                <td><?php echo number_format($insights['metrics']['projected_remaining_days'], 1); ?> days</td>
                                <td class="subtext">Calculated from earned work velocity</td>
                            </tr>
                            <tr>
                                <td>Forecast Delay</td>
                                <td><?php echo number_format($insights['metrics']['forecast_delay_days'], 1); ?> days</td>
                                <td class="subtext">Positive value means the current pace misses the timeline</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php if ($canViewBudget): ?>
            <div class="col-xl-6">
                <div class="section-card" id="budget-engine">
                    <div class="card-header">
                        <h5><i class="fas fa-coins"></i> Budget Burn Rate Insight Engine</h5>
                    </div>
                    <div class="card-body">
                        <table class="compare-table">
                            <tbody>
                                <tr>
                                    <td>Total Budget Baseline</td>
                                    <td><?php echo number_format($insights['metrics']['budget_total'], 2); ?></td>
                                    <td class="subtext"><?php echo htmlspecialchars($insights['metrics']['budget_baseline_source']); ?></td>
                                </tr>
                                <tr>
                                    <td>Monthly Burn</td>
                                    <td><?php echo number_format($insights['metrics']['monthly_burn'], 2); ?></td>
                                    <td class="subtext">Infrastructure plus modeled developer cost</td>
                                </tr>
                                <tr>
                                    <td>Budget Consumed</td>
                                    <td><?php echo number_format($insights['metrics']['budget_consumed_ratio'], 1); ?>%</td>
                                    <td class="subtext">Modeled consumption based on elapsed timeline</td>
                                </tr>
                                <tr>
                                    <td>Budget Health</td>
                                    <td><?php echo number_format($insights['metrics']['budget_health'], 1); ?>%</td>
                                    <td class="subtext">Inverse signal of burn pressure</td>
                                </tr>
                                <tr>
                                    <td>Overspend Forecast</td>
                                    <td><?php echo number_format($insights['metrics']['overspend_forecast'], 2); ?></td>
                                    <td class="subtext">Projected overrun if current burn continues</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-xl-6">
            <div class="section-card" id="resource-efficiency">
                <div class="card-header">
                    <h5><i class="fas fa-users-gear"></i> Resource Efficiency Intelligence</h5>
                </div>
                <div class="card-body">
                    <div class="mini-stat-grid mb-4">
                        <div class="mini-stat">
                            <div class="label">Efficiency Score</div>
                            <div class="value"><?php echo number_format($insights['metrics']['resource_efficiency'], 1); ?>%</div>
                            <span class="badge-tone <?php echo htmlspecialchars($insights['bands']['resource_efficiency']['tone']); ?>"><?php echo htmlspecialchars($insights['bands']['resource_efficiency']['label']); ?></span>
                        </div>
                        <div class="mini-stat">
                            <div class="label">Git Momentum</div>
                            <div class="value"><?php echo number_format($insights['metrics']['git_momentum'], 1); ?>%</div>
                            <span class="subtext"><?php echo (int) $insights['metrics']['recent_git_updates']; ?> recent updates across active work</span>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Member</th>
                                    <th>Role</th>
                                    <th>Active</th>
                                    <th>Completed</th>
                                    <th>Overdue</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($workloadRows as $row): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                                        <td><?php echo htmlspecialchars(strtoupper((string) $row['role'])); ?></td>
                                        <td><?php echo (int) $row['active_tasks']; ?></td>
                                        <td><?php echo (int) $row['completed_tasks']; ?></td>
                                        <td><?php echo (int) $row['overdue_tasks']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-6">
            <div class="section-card">
                <div class="card-header">
                    <h5><i class="fas fa-diagram-project"></i> Dependency Conflict Detection</h5>
                </div>
                <div class="card-body">
                    <div class="mini-stat-grid mb-4">
                        <div class="mini-stat">
                            <div class="label">Testing Risk</div>
                            <div class="value"><?php echo number_format($insights['metrics']['testing_risk'], 1); ?></div>
                            <span class="badge-tone <?php echo htmlspecialchars($insights['bands']['testing_risk']['tone']); ?>"><?php echo htmlspecialchars($insights['bands']['testing_risk']['label']); ?></span>
                        </div>
                        <div class="mini-stat">
                            <div class="label">Deployment Risk</div>
                            <div class="value"><?php echo number_format($insights['metrics']['deployment_risk'], 1); ?></div>
                            <span class="badge-tone <?php echo htmlspecialchars($insights['bands']['deployment_risk']['tone']); ?>"><?php echo htmlspecialchars($insights['bands']['deployment_risk']['label']); ?></span>
                        </div>
                        <div class="mini-stat">
                            <div class="label">Client Satisfaction Risk</div>
                            <div class="value"><?php echo number_format($insights['metrics']['client_risk'], 1); ?></div>
                            <span class="badge-tone <?php echo htmlspecialchars($insights['bands']['client_risk']['tone']); ?>"><?php echo htmlspecialchars($insights['bands']['client_risk']['label']); ?></span>
                        </div>
                        <div class="mini-stat">
                            <div class="label">Impact Chain Score</div>
                            <div class="value"><?php echo number_format($insights['metrics']['impact_chain_score'], 1); ?></div>
                            <span class="badge-tone <?php echo htmlspecialchars($insights['bands']['impact_chain_score']['tone']); ?>"><?php echo htmlspecialchars($insights['bands']['impact_chain_score']['label']); ?></span>
                        </div>
                    </div>

                    <div class="scenario-note">
                        This version uses derived propagation scores because the current schema has no explicit task dependency graph yet. The chain score is built from blocked work, review backlog, stalled implementation, deadline pressure<?php echo $canViewBudget ? ', budget strain, and client-facing delivery risk.' : ' and client-facing delivery risk.'; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-xl-7">
            <div class="section-card" id="kpi-monitoring">
                <div class="card-header">
                    <h5><i class="fas fa-gauge-high"></i> <?php echo htmlspecialchars($roleProfile['kpi_title']); ?></h5>
                </div>
                <div class="card-body">
                    <div class="scenario-note mb-3"><?php echo htmlspecialchars($roleProfile['kpi_note']); ?></div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>KPI</th>
                                <th>Target</th>
                                <th>Actual</th>
                                <th>Variance</th>
                                <th>Signal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($roleKpiRows as $kpi): ?>
                                <?php $varianceTone = $kpi['deviation'] >= 0 ? 'delta-positive' : 'delta-negative'; ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($kpi['label']); ?></td>
                                    <td><?php echo number_format($kpi['target'], 1); ?>%</td>
                                    <td><?php echo number_format($kpi['actual'], 1); ?>%</td>
                                    <td><span class="delta-pill <?php echo $varianceTone; ?>"><?php echo number_format($kpi['deviation'], 1); ?></span></td>
                                    <td><?php echo htmlspecialchars($kpi['direction'] === 'lower' ? 'Lower is better' : 'Higher is better'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-xl-5">
            <div class="section-card">
                <div class="card-header">
                    <h5><i class="fas fa-thumbs-up"></i> Stakeholder Decision Confidence Score</h5>
                </div>
                <div class="card-body">
                    <div class="mini-stat-grid mb-4">
                        <div class="mini-stat">
                            <div class="label">Confidence Score</div>
                            <div class="value"><?php echo number_format($insights['metrics']['decision_confidence'], 1); ?>%</div>
                            <span class="badge-tone <?php echo htmlspecialchars($insights['bands']['decision_confidence']['tone']); ?>"><?php echo htmlspecialchars($insights['bands']['decision_confidence']['label']); ?></span>
                        </div>
                        <div class="mini-stat">
                            <div class="label">Schedule Stability</div>
                            <div class="value"><?php echo number_format($insights['metrics']['schedule_stability'], 1); ?>%</div>
                            <span class="badge-tone <?php echo htmlspecialchars($insights['bands']['schedule_stability']['tone']); ?>"><?php echo htmlspecialchars($insights['bands']['schedule_stability']['label']); ?></span>
                        </div>
                    </div>

                    <table class="compare-table">
                        <tbody>
                            <tr>
                                <td>Timeline Stability Weight</td>
                                <td>30%</td>
                                <td><?php echo number_format($insights['metrics']['schedule_stability'], 1); ?></td>
                            </tr>
                            <?php if ($canViewBudget): ?>
                                <tr>
                                    <td>Budget Health Weight</td>
                                    <td>25%</td>
                                    <td><?php echo number_format($insights['metrics']['budget_health'], 1); ?></td>
                                </tr>
                            <?php endif; ?>
                            <tr>
                                <td>Risk Control Weight</td>
                                <td>20%</td>
                                <td><?php echo number_format(100 - $insights['metrics']['risk_exposure'], 1); ?></td>
                            </tr>
                            <tr>
                                <td>Resource Efficiency Weight</td>
                                <td>25%</td>
                                <td><?php echo number_format($insights['metrics']['resource_efficiency'], 1); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php else: ?>
    <div class="overview-controls">
        <div class="overview-control-card">
            <label class="overview-control-label" for="overviewSearch">Search Projects</label>
            <div class="overview-search-row">
                <input
                    type="text"
                    id="overviewSearch"
                    class="overview-search-input"
                    placeholder="Search by project, client, status, or timeline"
                >
                <button type="button" class="overview-search-button" id="overviewSearchButton">
                    <i class="fas fa-search"></i>
                    Search
                </button>
            </div>
        </div>
        <div class="overview-control-card">
            <label class="overview-control-label" for="overviewSort">Sort Projects</label>
            <select id="overviewSort" class="overview-sort-select">
                <option value="date">Date</option>
                <option value="alphabetical">Alphabetical</option>
                <option value="risk">Risk Level</option>
            </select>
        </div>
    </div>

    <div class="overview-results-note" id="overviewResultsNote">
        Showing <?php echo count($projects); ?> project card(s).
        <?php if ($attentionProjectCount > 0): ?>
            <?php echo $attentionProjectCount; ?> need<?php echo $attentionProjectCount === 1 ? 's' : ''; ?> attention.
        <?php endif; ?>
        Click a project to expand its full insight.
    </div>

    <div class="overview-card-grid">
        <?php foreach ($projects as $project): ?>
            <?php
            $projectId = (int) $project['id'];
            $overviewInsight = $overviewInsightsByProject[$projectId] ?? null;
            $projectStatsUrl = BASE_URL . 'modules/insights/decision-center.php?project_id=' . $projectId . '&view=statistics';
            $riskLevel = $overviewInsight['before']['risk_level'] ?? 'Low';
            $riskClass = $riskLevel === 'High' ? 'delay-risk-high' : ($riskLevel === 'Medium' ? 'delay-risk-medium' : 'delay-risk-low');
            $riskOrder = $riskLevel === 'High' ? 3 : ($riskLevel === 'Medium' ? 2 : 1);
            $attentionIssueCount = (int) ($attentionProjectsById[$projectId] ?? 0);
            $needsAttention = $attentionIssueCount > 0;
            $createdAtTs = !empty($project['created_at']) ? (strtotime((string) $project['created_at']) ?: 0) : 0;
            $searchBlob = strtolower(trim(
                $project['name']
                . ' '
                . ($project['client_name'] ?? '')
                . ' '
                . ucwords(str_replace('_', ' ', (string) ($project['status'] ?? '')))
                . ' '
                . delayInsightsTimelineLabel((string) ($project['timeline_start'] ?? ''), (string) ($project['timeline_end'] ?? ''))
            ));
            ?>
            <details
                class="overview-project-card js-overview-card <?php echo $riskLevel === 'High' ? 'risk-high' : ($riskLevel === 'Medium' ? 'risk-medium' : 'risk-low'); ?> <?php echo $needsAttention ? 'needs-attention' : ''; ?>"
                data-name="<?php echo htmlspecialchars(strtolower((string) $project['name'])); ?>"
                data-date="<?php echo (int) $createdAtTs; ?>"
                data-risk="<?php echo (int) $riskOrder; ?>"
                data-attention="<?php echo $needsAttention ? '1' : '0'; ?>"
                data-search="<?php echo htmlspecialchars($searchBlob); ?>"
            >
                <summary>
                    <div class="overview-project-summary">
                        <div class="overview-project-head">
                            <div>
                                <h5><?php echo htmlspecialchars($project['name']); ?></h5>
                                <div class="overview-project-meta">
                                    Client: <?php echo htmlspecialchars($project['client_name']); ?> |
                                    Status: <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) $project['status']))); ?> |
                                    Timeline: <?php echo htmlspecialchars(delayInsightsTimelineLabel((string) ($project['timeline_start'] ?? ''), (string) ($project['timeline_end'] ?? ''))); ?>
                                </div>
                            </div>
                            <div class="overview-project-badges">
                                <?php if ($needsAttention): ?>
                                    <span
                                        class="project-attention-badge"
                                        title="<?php echo $attentionIssueCount; ?> blocked, overdue, failed, or revision task<?php echo $attentionIssueCount === 1 ? '' : 's'; ?>"
                                    >
                                        <i class="fas fa-circle-exclamation"></i>
                                        Needs Attention (<?php echo $attentionIssueCount; ?>)
                                    </span>
                                <?php endif; ?>
                                <span class="delay-risk-badge <?php echo $riskClass; ?>">
                                    <i class="fas fa-signal"></i>
                                    <?php echo htmlspecialchars($riskLevel); ?> Delay Risk
                                </span>
                            </div>
                        </div>
                        <div class="overview-summary">Important delivery signals for this project.</div>
                    </div>
                    <span class="overview-project-toggle" aria-hidden="true">
                        <i class="fas fa-chevron-down"></i>
                    </span>
                </summary>

                <div class="overview-project-body">
                    <?php if ($overviewInsight !== null): ?>
                        <div class="overview-metric-grid mb-3">
                            <div class="overview-mini-card">
                                <span>Delay Chance</span>
                                <strong><?php echo (int) $overviewInsight['before']['delay_probability']; ?>%</strong>
                            </div>
                            <div class="overview-mini-card">
                                <span>Success Chance</span>
                                <strong><?php echo (int) $overviewInsight['before']['success_probability']; ?>%</strong>
                            </div>
                        </div>
                        <p class="overview-summary"><?php echo htmlspecialchars($overviewInsight['after']['summary'] ?: $overviewInsight['before']['summary']); ?></p>
                        <?php if (!empty($overviewInsight['after']['reasons'])): ?>
                            <div class="overview-tags">
                                <?php foreach (array_slice($overviewInsight['after']['reasons'], 0, 2) as $reason): ?>
                                    <span class="overview-tag"><?php echo htmlspecialchars($reason); ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="overview-summary">Insight details are not available yet for this project.</p>
                    <?php endif; ?>

                    <div class="overview-footer">
                        <a class="toolbar-button primary" href="<?php echo htmlspecialchars($projectStatsUrl); ?>">
                            <i class="fas fa-arrows-rotate"></i>
                            Live Insights
                        </a>
                    </div>
                </div>
            </details>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php echo count($projects) === 0 ? '<div class="empty-state mt-4"><i class="fas fa-inbox fa-2x mb-3"></i><div>No accessible projects were found for this role.</div></div>' : ''; ?>
</div>

<?php if (false): ?>
<div class="modal fade" id="projectActionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 24px; border: 1px solid #e9ecef;">
            <form method="GET">
                <div class="modal-header border-0 pb-0">
                    <div>
                        <h5 class="modal-title fw-semibold">Project Action</h5>
                        <p class="text-muted small mb-0" id="projectActionModalProject">Set the action details before opening the full statistics view.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body pt-3">
                    <input type="hidden" name="project_id" id="actionProjectId" value="<?php echo (int) $selectedProjectId; ?>">
                    <input type="hidden" name="view" value="statistics">

                    <div class="mb-3">
                        <label class="form-label">Action</label>
                        <select class="form-select" name="scenario" id="actionScenario" required>
                            <option value="" selected disabled>Select an action</option>
                            <?php foreach ($actionCatalog as $actionKey => $actionItem): ?>
                                <option
                                    value="<?php echo htmlspecialchars($actionKey); ?>"
                                    data-default-value="<?php echo htmlspecialchars((string) $actionItem['default_value']); ?>"
                                    data-min="<?php echo htmlspecialchars((string) $actionItem['min']); ?>"
                                    data-max="<?php echo htmlspecialchars((string) $actionItem['max']); ?>"
                                    data-unit="<?php echo htmlspecialchars($actionItem['unit_label']); ?>"
                                >
                                    <?php echo htmlspecialchars($actionItem['label']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Value</label>
                        <input type="number" class="form-control" name="scenario_value" id="actionValue" step="1" required>
                        <div class="action-modal-note mt-2" id="actionValueNote">Choose an action first to see the needed value range.</div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-insight px-4">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
const radarData = <?php echo json_encode($radarChart, JSON_UNESCAPED_UNICODE); ?>;
const strategyData = <?php echo json_encode($comparisonChart, JSON_UNESCAPED_UNICODE); ?>;
const actionCatalog = <?php echo json_encode($actionCatalog, JSON_UNESCAPED_UNICODE); ?>;
const insightDarkMode =
    document.documentElement.getAttribute('data-theme') === 'dark' ||
    document.body.getAttribute('data-theme') === 'dark' ||
    document.body.classList.contains('dark-mode');
const insightChartTextColor = insightDarkMode ? '#9db6cf' : '#666';
const insightChartGridColor = insightDarkMode ? 'rgba(157, 182, 207, 0.18)' : 'rgba(0, 0, 0, 0.08)';
const insightStrategyGridColor = insightDarkMode ? insightChartGridColor : 'rgba(0, 0, 0, 0.06)';

const actionModalEl = document.getElementById('projectActionModal');
const actionScenarioEl = document.getElementById('actionScenario');
const actionValueEl = document.getElementById('actionValue');
const actionProjectIdEl = document.getElementById('actionProjectId');
const actionValueNoteEl = document.getElementById('actionValueNote');
const actionProjectLabelEl = document.getElementById('projectActionModalProject');
const actionModal = actionModalEl && window.bootstrap ? new bootstrap.Modal(actionModalEl) : null;

function syncActionValueMeta() {
    if (!actionScenarioEl || !actionValueEl || !actionValueNoteEl) {
        return;
    }

    const selectedOption = actionScenarioEl.options[actionScenarioEl.selectedIndex];
    if (!selectedOption || !selectedOption.value) {
        actionValueEl.value = '';
        actionValueEl.removeAttribute('min');
        actionValueEl.removeAttribute('max');
        actionValueNoteEl.textContent = 'Choose an action first to see the needed value range.';
        return;
    }

    const defaultValue = selectedOption.dataset.defaultValue || '';
    const min = selectedOption.dataset.min || '';
    const max = selectedOption.dataset.max || '';
    const unit = selectedOption.dataset.unit || 'units';

    actionValueEl.value = defaultValue;
    actionValueEl.min = min;
    actionValueEl.max = max;
    actionValueNoteEl.textContent = `Enter a value between ${min} and ${max} ${unit}.`;
}

document.querySelectorAll('.open-action-modal').forEach((button) => {
    button.addEventListener('click', () => {
        if (!actionModal || !actionScenarioEl || !actionProjectIdEl || !actionProjectLabelEl) {
            return;
        }

        const projectId = button.getAttribute('data-project-id') || '<?php echo (int) $selectedProjectId; ?>';
        const projectName = button.getAttribute('data-project-name') || '<?php echo htmlspecialchars($projectTitle, ENT_QUOTES); ?>';
        const scenario = button.getAttribute('data-scenario') || '';

        actionProjectIdEl.value = projectId;
        actionProjectLabelEl.textContent = `Project: ${projectName}`;
        actionScenarioEl.value = scenario;
        syncActionValueMeta();
        actionModal.show();
    });
});

if (actionScenarioEl) {
    actionScenarioEl.addEventListener('change', syncActionValueMeta);
}

const overviewGridEl = document.querySelector('.overview-card-grid');
const overviewSearchEl = document.getElementById('overviewSearch');
const overviewSearchButtonEl = document.getElementById('overviewSearchButton');
const overviewSortEl = document.getElementById('overviewSort');
const overviewResultsNoteEl = document.getElementById('overviewResultsNote');

function applyOverviewFilters() {
    if (!overviewGridEl) {
        return;
    }

    const cards = Array.from(overviewGridEl.querySelectorAll('.js-overview-card'));
    const query = (overviewSearchEl?.value || '').trim().toLowerCase();
    const sortMode = overviewSortEl?.value || 'date';

    cards.forEach((card) => {
        const haystack = (card.getAttribute('data-search') || '').toLowerCase();
        const matches = query === '' || haystack.includes(query);
        card.style.display = matches ? '' : 'none';
    });

    const visibleCards = cards.filter((card) => card.style.display !== 'none');
    visibleCards.sort((left, right) => {
        if (sortMode === 'alphabetical') {
            return (left.getAttribute('data-name') || '').localeCompare(right.getAttribute('data-name') || '');
        }

        if (sortMode === 'risk') {
            const riskDiff = Number(right.getAttribute('data-risk') || '0') - Number(left.getAttribute('data-risk') || '0');
            if (riskDiff !== 0) {
                return riskDiff;
            }
            return (left.getAttribute('data-name') || '').localeCompare(right.getAttribute('data-name') || '');
        }

        const dateDiff = Number(right.getAttribute('data-date') || '0') - Number(left.getAttribute('data-date') || '0');
        if (dateDiff !== 0) {
            return dateDiff;
        }
        return (left.getAttribute('data-name') || '').localeCompare(right.getAttribute('data-name') || '');
    });

    visibleCards.forEach((card) => overviewGridEl.appendChild(card));

    if (overviewResultsNoteEl) {
        const attentionCount = visibleCards.filter((card) => card.getAttribute('data-attention') === '1').length;
        const attentionText = attentionCount > 0
            ? ` ${attentionCount} ${attentionCount === 1 ? 'needs' : 'need'} attention.`
            : '';
        overviewResultsNoteEl.textContent = `Showing ${visibleCards.length} project card(s).${attentionText}`;
    }
}

if (overviewSearchEl) {
    overviewSearchEl.addEventListener('input', applyOverviewFilters);
}

if (overviewSearchButtonEl) {
    overviewSearchButtonEl.addEventListener('click', applyOverviewFilters);
}

if (overviewSortEl) {
    overviewSortEl.addEventListener('change', applyOverviewFilters);
}

document.querySelectorAll('.overview-project-card').forEach((card) => {
    card.addEventListener('toggle', () => {
        if (!card.open) {
            return;
        }

        document.querySelectorAll('.overview-project-card[open]').forEach((otherCard) => {
            if (otherCard !== card) {
                otherCard.open = false;
            }
        });
    });
});

applyOverviewFilters();

const radarChartEl = document.getElementById('decisionRadarChart');
if (radarChartEl) {
    new Chart(radarChartEl, {
        type: 'radar',
        data: {
            labels: radarData.labels,
            datasets: [
                {
                    label: 'Before',
                    data: radarData.before,
                    backgroundColor: 'rgba(31, 99, 146, 0.15)',
                    borderColor: '#1f6392',
                    pointBackgroundColor: '#1f6392'
                },
                {
                    label: 'After',
                    data: radarData.after,
                    backgroundColor: 'rgba(16, 185, 129, 0.18)',
                    borderColor: '#10b981',
                    pointBackgroundColor: '#10b981'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                r: {
                    min: 0,
                    max: 100,
                    ticks: {
                        stepSize: 20,
                        backdropColor: 'transparent',
                        color: insightChartTextColor
                    },
                    pointLabels: {
                        color: insightChartTextColor
                    },
                    grid: {
                        color: insightChartGridColor
                    },
                    angleLines: {
                        color: insightChartGridColor
                    }
                }
            },
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        color: insightChartTextColor
                    }
                }
            }
        }
    });
}

const strategyChartEl = document.getElementById('strategyChart');
if (strategyChartEl) {
    new Chart(strategyChartEl, {
        type: 'bar',
        data: {
            labels: strategyData.labels,
            datasets: [
                {
                    label: 'Confidence After',
                    data: strategyData.confidence,
                    backgroundColor: '#1f6392',
                    borderRadius: 10
                },
                {
                    label: 'Risk After',
                    data: strategyData.risk,
                    backgroundColor: '#f59e0b',
                    borderRadius: 10
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        color: insightChartTextColor
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100,
                    ticks: {
                        color: insightChartTextColor
                    },
                    grid: {
                        color: insightStrategyGridColor
                    }
                },
                x: {
                    ticks: {
                        color: insightChartTextColor
                    },
                    grid: {
                        display: false
                    }
                }
            }
        }
    });
}
</script>

<?php include '../../includes/footer.php'; ?>
