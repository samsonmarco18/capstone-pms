<?php
require_once '../../includes/auth.php';
require_once '../../includes/decision-insights.php';

requireRole(['admin', 'sales', 'architect', 'sul', 'expert']);

$conn = getConnection();
$user = getCurrentUser();
$projects = di_load_accessible_projects($conn, $user);
$userRole = (string) ($user['role'] ?? 'guest');
$canViewBudget = $userRole !== 'sul';
$scenarioCatalog = di_scenario_catalog();
if (!$canViewBudget) {
    unset($scenarioCatalog['budget_cut']);
}

$selectedProjectId = (int) ($_GET['project_id'] ?? ($projects[0]['id'] ?? 0));
$requestedScenario = (string) ($_GET['scenario'] ?? 'add_developers');
$defaultScenario = array_key_first($scenarioCatalog) ?: 'add_developers';
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
        'meta' => 'Modeled budget usage',
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

.insights-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    background:
        radial-gradient(circle at top right, rgba(255, 255, 255, 0.16), transparent 28%),
        radial-gradient(circle at bottom left, rgba(255, 255, 255, 0.12), transparent 24%);
    pointer-events: none;
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

.filter-card .card-body {
    padding: 1.4rem;
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

.metric-card .meta {
    font-size: 0.8rem;
    color: var(--gray-600);
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
}
</style>

<div class="container-fluid px-4 py-4">
    <div class="insights-hero">
        <span class="badge-tone info mb-3">Decision-Driven Insights Center</span>
        <h1><?php echo htmlspecialchars($projectTitle); ?></h1>
        <p>Formula-based analysis for decision impact, risk exposure, schedule slippage, resource efficiency, KPI deviations, <?php echo $canViewBudget ? 'budget burn, ' : ''; ?>confidence scoring, and scenario simulation.</p>
        <div class="project-meta">
            <div class="project-chip"><strong>Client:</strong> <?php echo htmlspecialchars($projectClient); ?></div>
            <div class="project-chip"><strong>Status:</strong> <?php echo htmlspecialchars($projectStatus); ?></div>
            <div class="project-chip"><strong>Complexity:</strong> <?php echo htmlspecialchars($projectComplexity); ?></div>
            <div class="project-chip"><strong>Timeline:</strong> <?php echo (int) $insights['metrics']['timeline_total_days']; ?> days</div>
        </div>
    </div>

    <div class="filter-card">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-lg-5">
                    <label class="form-label">Project</label>
                    <select name="project_id" class="form-select">
                        <?php foreach ($projects as $project): ?>
                            <option value="<?php echo (int) $project['id']; ?>" <?php echo $selectedProjectId === (int) $project['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($project['name'] . ' - ' . $project['client_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-3">
                    <label class="form-label">Scenario</label>
                    <select name="scenario" class="form-select">
                        <?php foreach ($scenarioCatalog as $key => $catalogItem): ?>
                            <option value="<?php echo htmlspecialchars($key); ?>" <?php echo $scenarioType === $key ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($catalogItem['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-2">
                    <label class="form-label">Value</label>
                    <input
                        type="number"
                        name="scenario_value"
                        class="form-control"
                        value="<?php echo htmlspecialchars((string) $scenarioValue); ?>"
                        min="<?php echo (float) $scenarioConfig['min']; ?>"
                        max="<?php echo (float) $scenarioConfig['max']; ?>"
                        step="1"
                    >
                </div>
                <div class="col-lg-2">
                    <button type="submit" class="btn btn-insight w-100">Recalculate</button>
                </div>
            </form>
            <div class="scenario-note mt-3">
                Current simulation uses <strong><?php echo htmlspecialchars($scenarioConfig['label']); ?></strong> with a magnitude of
                <strong><?php echo number_format($simulation['value'], 0); ?></strong> <?php echo htmlspecialchars($scenarioConfig['unit_label']); ?>.
                This module reads existing project, task, estimation, git, and allocation data<?php echo $canViewBudget ? ', including cost tables.' : '.'; ?>
            </div>
        </div>
    </div>

    <div class="metric-grid">
        <?php foreach ($metricCards as $card): ?>
            <div class="metric-card">
                <div class="icon tone-<?php echo htmlspecialchars($card['tone']); ?>">
                    <i class="fas fa-<?php echo htmlspecialchars($card['icon']); ?>"></i>
                </div>
                <div class="label"><?php echo htmlspecialchars($card['label']); ?></div>
                <div class="value tone-<?php echo htmlspecialchars($card['tone']); ?>">
                    <?php echo htmlspecialchars($card['value']); ?><?php echo htmlspecialchars($card['suffix']); ?>
                </div>
                <div class="meta"><?php echo htmlspecialchars($card['meta']); ?></div>
            </div>
        <?php endforeach; ?>
    </div>

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
            <div class="section-card">
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
                    <h5><i class="fas fa-lightbulb"></i> Top Decision Signals</h5>
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
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="<?php echo $canViewBudget ? 'col-xl-6' : 'col-12'; ?>">
            <div class="section-card">
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
                <div class="section-card">
                    <div class="card-header">
                        <h5><i class="fas fa-coins"></i> Budget Burn Rate Insight Engine</h5>
                    </div>
                    <div class="card-body">
                        <table class="compare-table">
                            <tbody>
                                <tr>
                                    <td>Total Budget Baseline</td>
                                    <td><?php echo number_format($insights['metrics']['budget_total'], 2); ?></td>
                                    <td class="subtext">Latest sales report or modeled project budget floor</td>
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
            <div class="section-card">
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
            <div class="section-card">
                <div class="card-header">
                    <h5><i class="fas fa-gauge-high"></i> KPI Deviation Monitoring System</h5>
                </div>
                <div class="card-body">
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
                            <?php foreach ($kpiRows as $kpi): ?>
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

    <?php echo count($projects) === 0 ? '<div class="empty-state mt-4"><i class="fas fa-inbox fa-2x mb-3"></i><div>No accessible projects were found for this role.</div></div>' : ''; ?>
</div>

<script>
const radarData = <?php echo json_encode($radarChart, JSON_UNESCAPED_UNICODE); ?>;
const strategyData = <?php echo json_encode($comparisonChart, JSON_UNESCAPED_UNICODE); ?>;

new Chart(document.getElementById('decisionRadarChart'), {
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
                    backdropColor: 'transparent'
                },
                grid: {
                    color: 'rgba(0, 0, 0, 0.08)'
                },
                angleLines: {
                    color: 'rgba(0, 0, 0, 0.08)'
                }
            }
        },
        plugins: {
            legend: {
                position: 'bottom'
            }
        }
    }
});

new Chart(document.getElementById('strategyChart'), {
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
                position: 'bottom'
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                max: 100,
                grid: {
                    color: 'rgba(0, 0, 0, 0.06)'
                }
            },
            x: {
                grid: {
                    display: false
                }
            }
        }
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
