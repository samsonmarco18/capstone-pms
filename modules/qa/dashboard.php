<?php
require_once '../../includes/auth.php';
require_once '../../includes/project-delay-insights.php';
requireRole('qa');

$conn = getConnection();
$user = getCurrentUser();

function qaDashboardCount($conn, string $sql): int
{
    $result = $conn->query($sql);
    if (!is_object($result) || !method_exists($result, 'fetch_assoc')) {
        return 0;
    }

    $row = $result->fetch_assoc() ?: [];
    return (int) ($row['count'] ?? 0);
}

function qaDashboardQuery($conn, string $sql)
{
    $result = $conn->query($sql);
    return is_object($result) && method_exists($result, 'fetch_assoc') ? $result : null;
}

$qaQueueCount = qaDashboardCount($conn, "SELECT COUNT(*) AS count FROM tasks WHERE status = 'qa_testing'");
$qaPassedCount = qaDashboardCount($conn, "SELECT COUNT(*) AS count FROM tasks WHERE status IN ('qa_passed', 'architect_review', 'completed') AND qa_reviewed_at IS NOT NULL");
$qaReturnedCount = qaDashboardCount($conn, "SELECT COUNT(*) AS count FROM tasks WHERE status = 'qa_failed' AND qa_reviewed_at IS NOT NULL");
$qaReviewedThisMonth = qaDashboardCount($conn, "
    SELECT COUNT(*) AS count
    FROM tasks
    WHERE qa_reviewed_at IS NOT NULL
      AND qa_reviewed_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
");
$qaReviewedTotal = max(0, $qaPassedCount + $qaReturnedCount);
$qaPassRate = $qaReviewedTotal > 0 ? (int) round(($qaPassedCount / $qaReviewedTotal) * 100) : 0;

$weeklyLabels = [];
$weeklyPassed = [];
$weeklyReturned = [];
$weekLookup = [];
$currentWeekStart = new DateTimeImmutable('monday this week');
for ($offset = 5; $offset >= 0; $offset--) {
    $weekStart = $currentWeekStart->modify("-{$offset} weeks");
    $key = $weekStart->format('Y-m-d');
    $weekLookup[$key] = count($weeklyLabels);
    $weeklyLabels[] = $weekStart->format('M j');
    $weeklyPassed[] = 0;
    $weeklyReturned[] = 0;
}

$weeklyResults = qaDashboardQuery($conn, "
    SELECT qa_reviewed_at, status
    FROM tasks
    WHERE qa_reviewed_at IS NOT NULL
      AND qa_reviewed_at >= DATE_SUB(CURDATE(), INTERVAL 42 DAY)
    ORDER BY qa_reviewed_at ASC
");
if ($weeklyResults) {
    while ($row = $weeklyResults->fetch_assoc()) {
        $reviewedAt = strtotime((string) ($row['qa_reviewed_at'] ?? ''));
        if ($reviewedAt === false) {
            continue;
        }
        $weekKey = date('Y-m-d', strtotime('monday this week', $reviewedAt));
        if (!isset($weekLookup[$weekKey])) {
            continue;
        }
        $index = $weekLookup[$weekKey];
        if ((string) ($row['status'] ?? '') === 'qa_failed') {
            $weeklyReturned[$index]++;
        } else {
            $weeklyPassed[$index]++;
        }
    }
}

$upcomingTests = qaDashboardQuery($conn, "
    SELECT t.id, t.title, t.deadline, p.name AS project_name, u.full_name AS developer_name
    FROM tasks t
    JOIN projects p ON p.id = t.project_id
    LEFT JOIN users u ON u.id = t.assigned_to
    WHERE t.status = 'qa_testing'
    ORDER BY
        CASE WHEN t.deadline IS NULL THEN 1 ELSE 0 END,
        t.deadline ASC,
        t.updated_at ASC
    LIMIT 3
");

$attentionItems = qaDashboardQuery($conn, "
    SELECT t.id, t.title, t.status, t.deadline, p.name AS project_name
    FROM tasks t
    JOIN projects p ON p.id = t.project_id
    WHERE
        (t.status = 'qa_testing' AND t.deadline IS NOT NULL AND t.deadline < CURDATE())
        OR t.status = 'qa_failed'
    ORDER BY
        CASE WHEN t.status = 'qa_testing' THEN 0 ELSE 1 END,
        COALESCE(t.deadline, CURDATE()) ASC,
        t.updated_at DESC
    LIMIT 5
");

$recentResults = qaDashboardQuery($conn, "
    SELECT t.id, t.title, t.status, t.qa_reviewed_at, p.name AS project_name, u.full_name AS developer_name
    FROM tasks t
    JOIN projects p ON p.id = t.project_id
    LEFT JOIN users u ON u.id = t.assigned_to
    WHERE t.qa_reviewed_at IS NOT NULL
    ORDER BY t.qa_reviewed_at DESC
    LIMIT 6
");

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<style>
.qa-overview {
    --qa-blue: #1f6392;
    --qa-navy: #17324d;
    --qa-green: #15945d;
    --qa-amber: #e39b11;
    --qa-red: #d83434;
    color: var(--qa-navy);
}
main.qa-overview.role-dashboard-shell > .row:first-child .card.card.role-dashboard-hero {
    border: 0;
    overflow: hidden;
    background:
        radial-gradient(circle at 88% 12%, rgba(65, 167, 216, .18), transparent 32%),
        linear-gradient(135deg, rgba(255, 255, 255, .98), rgba(239, 248, 253, .96)) !important;
}
main.qa-overview .role-dashboard-hero::before {
    content: "";
    position: absolute;
    inset: 0;
    z-index: 0;
    pointer-events: none;
    background:
        linear-gradient(90deg, transparent 0 78%, rgba(31, 99, 146, .055) 78% 78.15%, transparent 78.15%),
        linear-gradient(0deg, transparent 0 68%, rgba(31, 99, 146, .05) 68% 68.2%, transparent 68.2%);
    background-size: 150px 110px;
    opacity: .72;
}
.qa-overview-badge {
    display: inline-flex;
    align-items: center;
    gap: .45rem;
    padding: .38rem .72rem;
    border: 1px solid rgba(31,99,146,.14);
    border-radius: 999px;
    background: var(--qa-blue);
    color: #fff;
    font-size: .72rem;
    font-weight: 600;
}
.qa-overview .role-dashboard-hero h1,
.qa-overview .role-dashboard-hero p {
    color: var(--qa-navy);
}
.qa-overview .role-dashboard-hero p {
    color: #6d7f91;
}
.qa-hero-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .55rem;
    min-height: 44px;
    padding: .65rem 1rem;
    border: 1px solid var(--qa-blue);
    border-radius: 12px;
    background: var(--qa-blue);
    color: #fff;
    text-decoration: none;
    font-weight: 600;
    transition: transform .18s ease, background .18s ease;
}
.qa-hero-action:hover {
    color: #fff;
    background: #174f76;
    transform: translateY(-2px);
}
.qa-metrics {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 1rem;
}
.qa-metric {
    display: flex;
    align-items: center;
    gap: .9rem;
    min-height: 106px;
    padding: 1rem 1.1rem;
    border: 1px solid #dce8f0;
    border-radius: 18px;
    background: #fff;
    box-shadow: 0 8px 22px rgba(20,72,108,.06);
}
.qa-metric-icon,
.qa-section-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    width: 42px;
    height: 42px;
    border-radius: 12px;
    background: #eaf5fb;
    color: var(--qa-blue);
}
.qa-metric-copy {
    min-width: 0;
}
.qa-metric-label {
    color: #6d7c8b;
    font-size: .7rem;
    font-weight: 600;
    letter-spacing: .055em;
    text-transform: uppercase;
}
.qa-metric-value {
    margin-top: .12rem;
    color: var(--qa-navy);
    font-size: 1.75rem;
    font-weight: 650;
    line-height: 1;
}
.qa-metric-note {
    margin-top: .3rem;
    color: #7b8da0;
    font-size: .74rem;
}
.qa-panel {
    height: 100%;
    overflow: hidden;
    border: 1px solid #dce8f0;
    border-radius: 20px;
    background: #fff;
    box-shadow: 0 10px 26px rgba(20,72,108,.06);
}
.qa-panel-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 1rem 1.2rem;
    border-bottom: 1px solid #e2ebf1;
    background: #f9fcfe;
}
.qa-panel-title {
    display: flex;
    align-items: center;
    gap: .7rem;
    min-width: 0;
}
.qa-panel-title h2 {
    margin: 0;
    color: var(--qa-blue);
    font-size: 1rem;
    font-weight: 600;
}
.qa-panel-title p {
    margin: .16rem 0 0;
    color: #7a8b9b;
    font-size: .74rem;
}
.qa-panel-link {
    color: var(--qa-blue);
    font-size: .78rem;
    font-weight: 600;
    text-decoration: none;
    white-space: nowrap;
}
.qa-panel-link:hover {
    color: #154d70;
}
.qa-panel-body {
    padding: 1.15rem 1.2rem;
}
.qa-chart-shell {
    position: relative;
    height: 280px;
}
.qa-chart-shell--compact {
    height: 245px;
}
.qa-upcoming-list,
.qa-attention-list {
    display: grid;
}
.qa-upcoming-row,
.qa-attention-row {
    display: grid;
    grid-template-columns: 52px minmax(0, 1fr) auto;
    align-items: center;
    gap: .85rem;
    min-height: 78px;
    padding: .75rem 0;
    border-bottom: 1px solid #e5edf3;
}
.qa-upcoming-row:last-child,
.qa-attention-row:last-child {
    border-bottom: 0;
}
.qa-date {
    display: grid;
    place-items: center;
    align-content: center;
    width: 52px;
    height: 52px;
    border-radius: 12px;
    background: #eef6fb;
    color: var(--qa-blue);
}
.qa-date span {
    font-size: .62rem;
    font-weight: 600;
    letter-spacing: .06em;
    text-transform: uppercase;
}
.qa-date strong {
    font-size: 1.2rem;
    line-height: 1.05;
}
.qa-row-copy {
    min-width: 0;
}
.qa-row-copy strong {
    display: block;
    overflow: hidden;
    color: var(--qa-navy);
    font-size: .84rem;
    font-weight: 600;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.qa-row-copy span {
    display: block;
    margin-top: .2rem;
    overflow: hidden;
    color: #76899c;
    font-size: .72rem;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.qa-row-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border: 1px solid #cfe0eb;
    border-radius: 10px;
    color: var(--qa-blue);
    text-decoration: none;
}
.qa-row-action:hover {
    border-color: var(--qa-blue);
    color: var(--qa-blue);
    background: #eef7fc;
}
.qa-attention-row {
    grid-template-columns: 36px minmax(0, 1fr) auto;
}
.qa-attention-mark {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 34px;
    height: 34px;
    border-radius: 10px;
    background: #fff1e7;
    color: #d97706;
}
.qa-status-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: .38rem .68rem;
    border-radius: 999px;
    font-size: .68rem;
    font-weight: 600;
    white-space: nowrap;
}
.qa-status-pill--passed {
    background: #dcf8e8;
    color: #087e4d;
}
.qa-status-pill--returned {
    background: #fee8e8;
    color: #b52626;
}
.qa-empty {
    min-height: 210px;
    display: grid;
    place-items: center;
    align-content: center;
    gap: .55rem;
    color: #8191a0;
    text-align: center;
}
.qa-empty i {
    color: var(--qa-blue);
    font-size: 1.7rem;
    opacity: .58;
}
.qa-empty strong {
    color: var(--qa-navy);
    font-size: .88rem;
}
.qa-results {
    width: 100%;
    border-collapse: collapse;
}
.qa-results th {
    padding: .72rem .85rem;
    background: #f7fafc;
    color: #65778a;
    font-size: .66rem;
    font-weight: 600;
    letter-spacing: .05em;
    text-align: left;
    text-transform: uppercase;
}
.qa-results td {
    padding: .85rem;
    border-top: 1px solid #e4edf3;
    color: #53687d;
    font-size: .78rem;
    vertical-align: middle;
}
.qa-results td strong {
    display: block;
    color: var(--qa-navy);
    font-weight: 600;
}
.qa-insight-column {
    display: flex;
}
.qa-insight-column > * {
    width: 100%;
}
.qa-insight-column .delay-compact-card {
    height: 100%;
}
@media (max-width: 1199.98px) {
    .qa-metrics {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
@media (max-width: 767.98px) {
    .qa-overview {
        padding-left: .85rem !important;
        padding-right: .85rem !important;
    }
    .qa-metrics {
        grid-template-columns: 1fr;
    }
    .qa-chart-shell,
    .qa-chart-shell--compact {
        height: 230px;
    }
    .qa-panel-head {
        align-items: flex-start;
    }
    .qa-results th:nth-child(3),
    .qa-results td:nth-child(3) {
        display: none;
    }
}
html[data-theme="dark"] .qa-overview {
    color: #d7e7f4;
}
html[data-theme="dark"] main.qa-overview.role-dashboard-shell > .row:first-child .card.card.role-dashboard-hero {
    background:
        radial-gradient(circle at 88% 12%, rgba(76, 184, 255, .2), transparent 32%),
        linear-gradient(135deg, rgba(8, 25, 40, .98), rgba(12, 43, 65, .96)) !important;
}
html[data-theme="dark"] .qa-overview .role-dashboard-hero h1 {
    color: #eaf6ff;
}
html[data-theme="dark"] .qa-overview .role-dashboard-hero p {
    color: #a9bfd2;
}
html[data-theme="dark"] .qa-metric,
html[data-theme="dark"] .qa-panel {
    border-color: rgba(138,210,255,.12);
    background: rgba(9,24,38,.92);
    box-shadow: 0 12px 28px rgba(0,0,0,.28);
}
html[data-theme="dark"] .qa-panel-head,
html[data-theme="dark"] .qa-results th {
    border-color: rgba(138,210,255,.1);
    background: rgba(12,31,49,.96);
}
html[data-theme="dark"] .qa-metric-value,
html[data-theme="dark"] .qa-panel-title h2,
html[data-theme="dark"] .qa-row-copy strong,
html[data-theme="dark"] .qa-results td strong,
html[data-theme="dark"] .qa-empty strong {
    color: #d7e7f4;
}
html[data-theme="dark"] .qa-upcoming-row,
html[data-theme="dark"] .qa-attention-row,
html[data-theme="dark"] .qa-results td {
    border-color: rgba(138,210,255,.1);
}
</style>

<main class="container-fluid px-4 py-4 qa-overview role-dashboard-shell">
    <div class="row mb-4">
        <div class="col-12">
            <section class="card border-0 shadow-sm role-dashboard-hero qa-dashboard-hero">
                <div class="card-body p-4">
                    <div class="row align-items-center g-4">
                        <div class="col-lg-8 role-dashboard-hero-copy">
                            <span class="qa-overview-badge mb-3"><i class="fas fa-shield-halved"></i> QA Overview</span>
                            <h1 class="h3 mb-2">Quality assurance at a glance</h1>
                            <p class="mb-3">Monitor testing demand, pass trends, delivery risk, and work that needs attention.</p>
                            <a class="qa-hero-action" href="testing.php">
                                <i class="fas fa-clipboard-check"></i>
                                Open QA Testing
                            </a>
                        </div>
                        <div class="col-lg-4 d-none d-lg-flex justify-content-end">
                            <div class="role-3d-scene role-3d-scene--qa" aria-hidden="true">
                                <div class="role-3d-platform">
                                    <span class="role-3d-node role-3d-node--one"><i class="fas fa-bug"></i></span>
                                    <span class="role-3d-node role-3d-node--two"><i class="fas fa-magnifying-glass"></i></span>
                                    <span class="role-3d-orbit"></span>
                                    <span class="role-3d-core"><i class="fas fa-clipboard-check"></i></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <section class="qa-metrics mb-4" aria-label="QA summary">
        <article class="qa-metric">
            <span class="qa-metric-icon"><i class="fas fa-vial"></i></span>
            <div class="qa-metric-copy">
                <div class="qa-metric-label">Awaiting QA</div>
                <div class="qa-metric-value"><?php echo $qaQueueCount; ?></div>
                <div class="qa-metric-note">Ready for testing</div>
            </div>
        </article>
        <article class="qa-metric">
            <span class="qa-metric-icon"><i class="fas fa-circle-check"></i></span>
            <div class="qa-metric-copy">
                <div class="qa-metric-label">Passed</div>
                <div class="qa-metric-value"><?php echo $qaPassedCount; ?></div>
                <div class="qa-metric-note"><?php echo $qaPassRate; ?>% pass rate</div>
            </div>
        </article>
        <article class="qa-metric">
            <span class="qa-metric-icon"><i class="fas fa-rotate-left"></i></span>
            <div class="qa-metric-copy">
                <div class="qa-metric-label">Returned</div>
                <div class="qa-metric-value"><?php echo $qaReturnedCount; ?></div>
                <div class="qa-metric-note">Sent back for fixes</div>
            </div>
        </article>
        <article class="qa-metric">
            <span class="qa-metric-icon"><i class="fas fa-calendar-check"></i></span>
            <div class="qa-metric-copy">
                <div class="qa-metric-label">Reviewed This Month</div>
                <div class="qa-metric-value"><?php echo $qaReviewedThisMonth; ?></div>
                <div class="qa-metric-note">Recorded decisions</div>
            </div>
        </article>
    </section>

    <section class="row g-4 mb-4">
        <div class="col-xl-7">
            <article class="qa-panel">
                <header class="qa-panel-head">
                    <div class="qa-panel-title">
                        <span class="qa-section-icon"><i class="fas fa-chart-line"></i></span>
                        <div>
                            <h2>QA Decision Trend</h2>
                            <p>Passed and returned work over the last six weeks.</p>
                        </div>
                    </div>
                </header>
                <div class="qa-panel-body">
                    <div class="qa-chart-shell"><canvas id="qaDecisionTrend"></canvas></div>
                </div>
            </article>
        </div>
        <div class="col-xl-5">
            <article class="qa-panel">
                <header class="qa-panel-head">
                    <div class="qa-panel-title">
                        <span class="qa-section-icon"><i class="fas fa-calendar-days"></i></span>
                        <div>
                            <h2>Upcoming Testing</h2>
                            <p>The next three items in the QA queue.</p>
                        </div>
                    </div>
                    <a class="qa-panel-link" href="testing.php">View queue</a>
                </header>
                <div class="qa-panel-body">
                    <?php if ($upcomingTests && $upcomingTests->num_rows > 0): ?>
                        <div class="qa-upcoming-list">
                            <?php while ($task = $upcomingTests->fetch_assoc()):
                                $deadline = !empty($task['deadline']) ? strtotime((string) $task['deadline']) : false;
                            ?>
                                <div class="qa-upcoming-row">
                                    <div class="qa-date">
                                        <span><?php echo $deadline !== false ? date('M', $deadline) : 'No'; ?></span>
                                        <strong><?php echo $deadline !== false ? date('j', $deadline) : '—'; ?></strong>
                                    </div>
                                    <div class="qa-row-copy">
                                        <strong><?php echo htmlspecialchars((string) $task['title']); ?></strong>
                                        <span><?php echo htmlspecialchars((string) $task['project_name']); ?> · <?php echo htmlspecialchars((string) ($task['developer_name'] ?: 'Unassigned')); ?></span>
                                    </div>
                                    <a class="qa-row-action" href="testing.php?task_id=<?php echo (int) $task['id']; ?>" aria-label="Open test">
                                        <i class="fas fa-arrow-right"></i>
                                    </a>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="qa-empty">
                            <i class="fas fa-circle-check"></i>
                            <strong>QA queue is clear</strong>
                            <span>New items will appear after SUL review.</span>
                        </div>
                    <?php endif; ?>
                </div>
            </article>
        </div>
    </section>

    <section class="row g-4 mb-4">
        <div class="col-xl-5">
            <article class="qa-panel">
                <header class="qa-panel-head">
                    <div class="qa-panel-title">
                        <span class="qa-section-icon"><i class="fas fa-chart-bar"></i></span>
                        <div>
                            <h2>Testing Outcome</h2>
                            <p>Current QA workload and recorded decisions.</p>
                        </div>
                    </div>
                </header>
                <div class="qa-panel-body">
                    <div class="qa-chart-shell qa-chart-shell--compact"><canvas id="qaOutcomeChart"></canvas></div>
                </div>
            </article>
        </div>
        <div class="col-xl-7 qa-insight-column">
            <?php
            delayInsightsRenderCompactList($conn, $user, [
                'limit' => 200,
                'display' => 'portfolio_chart',
                'title' => 'Overall Project Risk',
                'subtitle' => 'Portfolio-wide delivery risk across projects currently visible to QA.',
                'show_empty' => true,
                'empty_title' => 'No project insights yet',
                'empty_message' => 'Delivery signals will appear when a project reaches the QA workflow.',
            ]);
            ?>
        </div>
    </section>

    <section class="row g-4">
        <div class="col-xl-5">
            <article class="qa-panel">
                <header class="qa-panel-head">
                    <div class="qa-panel-title">
                        <span class="qa-section-icon"><i class="fas fa-triangle-exclamation"></i></span>
                        <div>
                            <h2>Needs Attention</h2>
                            <p>Overdue tests and work returned for revision.</p>
                        </div>
                    </div>
                </header>
                <div class="qa-panel-body">
                    <?php if ($attentionItems && $attentionItems->num_rows > 0): ?>
                        <div class="qa-attention-list">
                            <?php while ($item = $attentionItems->fetch_assoc()): ?>
                                <div class="qa-attention-row">
                                    <span class="qa-attention-mark"><i class="fas fa-exclamation"></i></span>
                                    <div class="qa-row-copy">
                                        <strong><?php echo htmlspecialchars((string) $item['title']); ?></strong>
                                        <span><?php echo htmlspecialchars((string) $item['project_name']); ?></span>
                                    </div>
                                    <span class="qa-status-pill <?php echo (string) $item['status'] === 'qa_failed' ? 'qa-status-pill--returned' : 'qa-status-pill--returned'; ?>">
                                        <?php echo (string) $item['status'] === 'qa_failed' ? 'Returned' : 'Overdue'; ?>
                                    </span>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="qa-empty">
                            <i class="fas fa-shield-halved"></i>
                            <strong>No urgent QA issues</strong>
                            <span>Overdue tests and returned work will be listed here.</span>
                        </div>
                    <?php endif; ?>
                </div>
            </article>
        </div>
        <div class="col-xl-7">
            <article class="qa-panel">
                <header class="qa-panel-head">
                    <div class="qa-panel-title">
                        <span class="qa-section-icon"><i class="fas fa-clock-rotate-left"></i></span>
                        <div>
                            <h2>Recent QA Results</h2>
                            <p>The latest quality decisions across monitored projects.</p>
                        </div>
                    </div>
                </header>
                <?php if ($recentResults && $recentResults->num_rows > 0): ?>
                    <div class="table-responsive">
                        <table class="qa-results">
                            <thead>
                                <tr>
                                    <th>Task</th>
                                    <th>Result</th>
                                    <th>Developer</th>
                                    <th>Reviewed</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($result = $recentResults->fetch_assoc()):
                                    $isReturned = (string) $result['status'] === 'qa_failed';
                                    $reviewedAt = strtotime((string) ($result['qa_reviewed_at'] ?? ''));
                                ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars((string) $result['title']); ?></strong>
                                            <?php echo htmlspecialchars((string) $result['project_name']); ?>
                                        </td>
                                        <td>
                                            <span class="qa-status-pill <?php echo $isReturned ? 'qa-status-pill--returned' : 'qa-status-pill--passed'; ?>">
                                                <?php echo $isReturned ? 'Returned' : 'Passed'; ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars((string) ($result['developer_name'] ?: 'Unassigned')); ?></td>
                                        <td><?php echo $reviewedAt !== false ? date('M j, Y', $reviewedAt) : '—'; ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="qa-empty">
                        <i class="fas fa-inbox"></i>
                        <strong>No QA results yet</strong>
                        <span>Completed decisions will be recorded here.</span>
                    </div>
                <?php endif; ?>
            </article>
        </div>
    </section>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof Chart === 'undefined') return;

    const chartText = document.documentElement.getAttribute('data-theme') === 'dark' ? '#a9bfd2' : '#6f8091';
    const chartGrid = document.documentElement.getAttribute('data-theme') === 'dark' ? 'rgba(145,190,222,.10)' : 'rgba(31,99,146,.09)';
    Chart.defaults.color = chartText;
    Chart.defaults.font.family = 'inherit';

    const trendCanvas = document.getElementById('qaDecisionTrend');
    if (trendCanvas) {
        new Chart(trendCanvas, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($weeklyLabels); ?>,
                datasets: [
                    {
                        label: 'Passed',
                        data: <?php echo json_encode($weeklyPassed); ?>,
                        borderColor: '#15945d',
                        backgroundColor: 'rgba(21,148,93,.12)',
                        fill: true,
                        tension: .35,
                        borderWidth: 2,
                        pointRadius: 3
                    },
                    {
                        label: 'Returned',
                        data: <?php echo json_encode($weeklyReturned); ?>,
                        borderColor: '#d83434',
                        backgroundColor: 'rgba(216,52,52,.08)',
                        fill: true,
                        tension: .35,
                        borderWidth: 2,
                        pointRadius: 3
                    }
                ]
            },
            options: {
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { position: 'top', align: 'end', labels: { usePointStyle: true, boxWidth: 8 } } },
                scales: {
                    x: { grid: { display: false }, border: { display: false } },
                    y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: chartGrid }, border: { display: false } }
                }
            }
        });
    }

    const outcomeCanvas = document.getElementById('qaOutcomeChart');
    if (outcomeCanvas) {
        new Chart(outcomeCanvas, {
            type: 'bar',
            data: {
                labels: ['Awaiting QA', 'Passed', 'Returned'],
                datasets: [{
                    data: [<?php echo $qaQueueCount; ?>, <?php echo $qaPassedCount; ?>, <?php echo $qaReturnedCount; ?>],
                    backgroundColor: ['#4b8fbd', '#15945d', '#d83434'],
                    borderRadius: 9,
                    borderSkipped: false,
                    maxBarThickness: 44
                }]
            },
            options: {
                indexAxis: 'y',
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: chartGrid }, border: { display: false } },
                    y: { grid: { display: false }, border: { display: false } }
                }
            }
        });
    }
});
</script>

<?php include '../../includes/footer.php'; ?>
