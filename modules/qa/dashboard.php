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

<?php if ($qaEvortixPayload): ?>
<style>
body.qa-evortix-page .chat-bot-speech.evortix-qa-advisor {
    top: auto;
    right: calc(100% + 8px);
    bottom: 22px;
    width: min(390px, calc(100vw - 180px));
    min-width: 310px;
    max-height: min(500px, calc(100vh - 100px));
    padding: 0.9rem;
    overflow: hidden;
    pointer-events: auto;
    transform: none;
}

body.qa-evortix-page .chat-bot-float.is-speaking .chat-bot-speech.evortix-qa-advisor {
    animation: none;
    opacity: 1;
    transform: none;
    visibility: visible;
}

body.qa-evortix-page .chat-bot-speech.evortix-qa-advisor::after {
    top: auto;
    bottom: 48px;
    transform: rotate(45deg);
}

.evortix-qa-head,
.evortix-qa-title-row,
.evortix-qa-actions,
.evortix-qa-test {
    display: flex;
    align-items: center;
}

.evortix-qa-head,
.evortix-qa-title-row {
    justify-content: space-between;
    gap: 0.65rem;
}

.evortix-qa-eyebrow {
    color: #157baa;
    font-size: 0.6rem;
    font-weight: 850;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.chat-bot-speech .evortix-qa-title {
    min-height: 0;
    padding: 0;
    color: #083a5d;
    font-size: 0.95rem;
    line-height: 1.3;
}

.evortix-qa-close {
    display: grid;
    place-items: center;
    flex: 0 0 28px;
    width: 28px;
    height: 28px;
    border: 0;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.72);
    color: #116c9e;
}

.evortix-qa-copy {
    margin: 0.45rem 0 0;
    color: #3f6e8d;
    font-size: 0.69rem;
    line-height: 1.42;
}

.evortix-qa-summary {
    display: grid;
    grid-template-columns: 0.8fr 1.2fr;
    gap: 0.45rem;
    margin-top: 0.6rem;
}

.evortix-qa-panel {
    padding: 0.55rem 0.6rem;
    border: 1px solid rgba(8, 123, 185, 0.18);
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.68);
}

.evortix-qa-panel span,
.evortix-qa-test small {
    color: #4c7691;
    font-size: 0.58rem;
    font-weight: 750;
}

.evortix-qa-panel strong {
    display: block;
    margin-top: 0.18rem;
    color: #083a5d;
    font-size: 0.76rem;
    line-height: 1.25;
}

.evortix-qa-risk-high strong { color: #b42318; }
.evortix-qa-risk-medium strong { color: #b25e09; }
.evortix-qa-risk-low strong { color: #087443; }

.evortix-qa-tests {
    display: grid;
    gap: 0.35rem;
    max-height: 210px;
    margin-top: 0.6rem;
    padding-right: 0.2rem;
    overflow-y: auto;
    overscroll-behavior: contain;
}

.evortix-qa-test {
    align-items: flex-start;
    gap: 0.45rem;
    padding: 0.46rem 0.5rem;
    border: 1px solid rgba(8, 123, 185, 0.14);
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.56);
    color: #164f73;
    font-size: 0.65rem;
    line-height: 1.35;
}

.evortix-qa-test i {
    margin-top: 0.12rem;
    color: #1492c6;
}

.evortix-qa-test.is-added i {
    color: #149657;
}

.evortix-qa-verdict-reason {
    margin-top: 0.5rem;
    padding: 0.5rem 0.6rem;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.55);
    color: #356b8d;
    font-size: 0.64rem;
    line-height: 1.4;
}

.evortix-qa-actions {
    justify-content: flex-end;
    flex-wrap: wrap;
    gap: 0.35rem;
    margin-top: 0.6rem;
}

.evortix-qa-actions button {
    min-height: 32px;
    padding: 0.38rem 0.65rem;
    border: 1px solid rgba(31, 99, 146, 0.24);
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.78);
    color: #175f8f;
    font-size: 0.64rem;
    font-weight: 800;
}

.evortix-qa-actions button.is-primary {
    border-color: #1877b1;
    background: #1877b1;
    color: #fff;
}

@media (max-width: 600px) {
    body.qa-evortix-page .chat-bot-speech.evortix-qa-advisor {
        right: calc(100% + 4px);
        bottom: 18px;
        width: min(285px, calc(100vw - 132px));
        min-width: 225px;
    }

    .evortix-qa-summary {
        grid-template-columns: 1fr;
    }
}
</style>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const qaEvortixBase = <?php echo json_encode($qaEvortixPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    if (!qaEvortixBase) return;
    document.body.classList.add('qa-evortix-page');

    const escapeQaHtml = (value) => {
        const node = document.createElement('div');
        node.textContent = String(value ?? '');
        return node.innerHTML;
    };
    const qaStateKey = `evortix.qa.advisor.v1.${qaEvortixBase.task_id}.${qaEvortixBase.version}`;
    let checklistChangeTimer = null;

    const currentQaGuidance = () => {
        const counts = { total: 0, passed: 0, pending: 0, failed: 0, needs_revision: 0 };
        const issueDetails = [];
        document.querySelectorAll('select[name^="item_status["]').forEach((select) => {
            const status = String(select.value || 'pending');
            counts.total++;
            if (Object.prototype.hasOwnProperty.call(counts, status)) counts[status]++;
            if (status === 'failed' || status === 'needs_revision') {
                const itemRow = select.closest('.border');
                issueDetails.push({
                    status,
                    check: String(itemRow?.querySelector('.fw-semibold')?.textContent || 'Checklist issue').trim(),
                    actual: String(itemRow?.querySelector('input[name^="item_notes["]')?.value || '').trim(),
                });
            }
        });

        let verdict = 'Continue Testing';
        let reason = `${counts.pending} checklist item${counts.pending === 1 ? '' : 's'} still pending.`;
        if (counts.failed > 0) {
            verdict = 'Failed';
            reason = 'At least one checklist item failed.';
        } else if (counts.needs_revision > 0) {
            verdict = 'Needs Revision';
            reason = 'The checklist contains items that require developer revision.';
        } else if (counts.total > 0 && counts.pending === 0) {
            verdict = 'Passed';
            reason = 'Every recorded checklist item passed.';
        }

        const defectLines = issueDetails.map((issue, issueIndex) =>
            `${issueIndex + 1}. ${issue.check}\nActual: ${issue.actual || 'Add the observed result before submitting this decision.'}`
        ).join('\n');
        const severity = String(qaEvortixBase.risk?.level || 'Medium');
        const draft = verdict === 'Failed'
            ? `QA DEFECT REPORT\nFeature: ${qaEvortixBase.task_title}\nSeverity: ${severity}\n\nFailed checks:\n${defectLines}\n\nExpected: The feature should satisfy the approved requirement and pass the listed checks.\nSteps to reproduce: Repeat each failed checklist scenario using the recorded inputs and environment.\nDeveloper action: Correct the failed behavior, run focused regression tests, and resubmit for QA.`
            : verdict === 'Needs Revision'
                ? `QA REVISION REPORT\nFeature: ${qaEvortixBase.task_title}\nSeverity: ${severity}\n\nIssues requiring revision:\n${defectLines}\n\nExpected: The feature should satisfy the approved requirement without the recorded issue.\nDeveloper action: Address each item, verify related regression paths, and resubmit for QA.`
                : verdict === 'Passed'
                    ? `QA passed this feature. All ${counts.total} recorded checks passed, including the focused regression coverage.`
                    : `QA testing is still in progress. Complete the remaining ${counts.pending} pending check${counts.pending === 1 ? '' : 's'} before making the final QA decision.`;

        const baseIssues = Number(qaEvortixBase.counts?.failed || 0) + Number(qaEvortixBase.counts?.needs_revision || 0);
        const currentIssues = counts.failed + counts.needs_revision;
        const issueRiskPoints = (issueCount) => issueCount > 0 ? Math.min(25, 10 + (issueCount * 5)) : 0;
        const liveRiskScore = Math.max(
            0,
            Math.min(100, Number(qaEvortixBase.risk?.score || 0) - issueRiskPoints(baseIssues) + issueRiskPoints(currentIssues))
        );
        const riskReasons = (qaEvortixBase.risk?.reasons || [])
            .filter((riskReason) => !String(riskReason).includes('current checklist issue'));
        if (currentIssues > 0) {
            riskReasons.push(`${currentIssues} current checklist issue${currentIssues === 1 ? '' : 's'}`);
        }
        const risk = {
            ...(qaEvortixBase.risk || {}),
            score: liveRiskScore,
            level: liveRiskScore >= 60 ? 'High' : (liveRiskScore >= 30 ? 'Medium' : 'Low'),
            reasons: riskReasons,
        };

        return { ...qaEvortixBase, counts, risk, recommended_verdict: verdict, verdict_reason: reason, draft_comment: draft };
    };

    const showQaEvortix = (trigger = 'manual_request') => {
        const payload = currentQaGuidance();
        const bot = document.getElementById('chat-bot-float');
        const speech = bot?.querySelector('.chat-bot-speech');
        if (!bot || !speech) {
            window.setTimeout(() => showQaEvortix(trigger), 300);
            return;
        }

        const missingSet = new Set((payload.missing_tests || []).map((test) => String(test).toLowerCase()));
        const testMarkup = (payload.suggested_tests || []).map((test) => {
            const isMissing = missingSet.has(String(test).toLowerCase());
            return `
                <div class="evortix-qa-test ${isMissing ? '' : 'is-added'}">
                    <i class="fas ${isMissing ? 'fa-flask' : 'fa-circle-check'}" aria-hidden="true"></i>
                    <div>${escapeQaHtml(test)}<small class="d-block">${isMissing ? 'Suggested test' : 'Already in checklist'}</small></div>
                </div>`;
        }).join('');
        const triggerCopy = {
            task_opened: 'I reviewed this task because it was opened for QA.',
            checklist_changed: 'I updated the guidance because a checklist result changed.',
            manual_request: 'Here is the latest QA guidance you requested.',
        }[trigger] || 'Here is the latest QA guidance.';
        const riskClass = `evortix-qa-risk-${String(payload.risk?.level || 'Low').toLowerCase()}`;

        speech.className = 'chat-bot-speech evortix-qa-advisor';
        speech.hidden = false;
        speech.setAttribute('aria-hidden', 'false');
        speech.style.pointerEvents = 'auto';
        speech.innerHTML = `
            <div class="evortix-qa-head">
                <div>
                    <div class="evortix-qa-eyebrow">QA recommendation</div>
                    <strong class="evortix-qa-title">${escapeQaHtml(payload.task_title)}</strong>
                </div>
                <button type="button" class="evortix-qa-close" data-qa-evortix-action="close" aria-label="Close Evortix recommendation"><i class="fas fa-xmark"></i></button>
            </div>
            <p class="evortix-qa-copy">${escapeQaHtml(triggerCopy)} QA keeps control of the final decision.</p>
            <div class="evortix-qa-summary">
                <div class="evortix-qa-panel ${riskClass}">
                    <span>Testing risk</span>
                    <strong>${escapeQaHtml(payload.risk?.level || 'Low')} · ${Number(payload.risk?.score || 0)}/100</strong>
                </div>
                <div class="evortix-qa-panel">
                    <span>Recommended verdict</span>
                    <strong>${escapeQaHtml(payload.recommended_verdict)}</strong>
                </div>
            </div>
            <div class="evortix-qa-verdict-reason">${escapeQaHtml(payload.verdict_reason)} ${escapeQaHtml((payload.risk?.reasons || []).join(' · '))}</div>
            <div class="evortix-qa-tests">${testMarkup}</div>
            <div class="evortix-qa-actions">
                <button type="button" data-qa-evortix-action="draft">Use draft comment</button>
                ${(payload.missing_tests || []).length > 0
                    ? `<button type="button" class="is-primary" data-qa-evortix-action="apply-tests">Add ${payload.missing_tests.length} suggested test${payload.missing_tests.length === 1 ? '' : 's'}</button>`
                    : '<button type="button" class="is-primary" data-qa-evortix-action="close">Checklist ready</button>'}
            </div>`;

        bot.hidden = false;
        bot.setAttribute('aria-hidden', 'false');
        bot.classList.remove('is-returning-bed', 'is-preparing-speech');
        bot.classList.add('is-speaking');
        window.chatBotFloatRobot?.wake?.();
        window.chatBotFloatRobot?.setEmotion?.('thinking', 0);
        try { window.localStorage.setItem(qaStateKey, trigger); } catch (error) {}
    };

    document.getElementById('askEvortixQa')?.addEventListener('click', () => showQaEvortix('manual_request'));
    document.querySelectorAll('select[name^="item_status["]').forEach((select) => {
        select.addEventListener('change', () => {
            window.clearTimeout(checklistChangeTimer);
            checklistChangeTimer = window.setTimeout(() => showQaEvortix('checklist_changed'), 350);
        });
    });
    document.querySelectorAll('input[name^="item_notes["]').forEach((input) => {
        input.addEventListener('input', () => {
            window.clearTimeout(checklistChangeTimer);
            checklistChangeTimer = window.setTimeout(() => showQaEvortix('checklist_changed'), 700);
        });
    });

    document.addEventListener('click', async (event) => {
        const actionButton = event.target.closest('[data-qa-evortix-action]');
        if (!actionButton) return;
        const action = actionButton.getAttribute('data-qa-evortix-action');
        if (action === 'close') {
            const speech = document.querySelector('#chat-bot-float .chat-bot-speech');
            if (speech) {
                speech.hidden = true;
                speech.setAttribute('aria-hidden', 'true');
            }
            return;
        }
        if (action === 'draft') {
            const comment = document.querySelector('textarea[name="qa_comment"]');
            if (comment) {
                comment.value = currentQaGuidance().draft_comment;
                comment.dispatchEvent(new Event('input', { bubbles: true }));
                comment.scrollIntoView({ behavior: 'smooth', block: 'center' });
                comment.focus();
                window.appToast?.('Evortix QA draft', 'The recommended QA comment is ready for your review.', 'info');
            }
            return;
        }
        if (action === 'apply-tests') {
            actionButton.disabled = true;
            actionButton.textContent = 'Adding tests...';
            try {
                const response = await fetch(window.location.pathname + window.location.search, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: new URLSearchParams({
                        task_id: String(qaEvortixBase.task_id),
                        apply_evortix_qa_suggestions: '1',
                    }),
                });
                const result = await response.json();
                if (!response.ok || !result.success) throw new Error(result.message || 'Unable to add the suggested tests.');
                window.appToast?.('Evortix QA checklist', result.message, 'success');
                window.setTimeout(() => {
                    window.location.href = `${window.location.pathname}?task_id=${encodeURIComponent(qaEvortixBase.task_id)}`;
                }, 500);
            } catch (error) {
                actionButton.disabled = false;
                actionButton.textContent = 'Try adding tests again';
                window.appToast?.('Evortix QA checklist', error.message || 'Unable to add the suggested tests.', 'danger');
            }
        }
    });

    let alreadyShown = false;
    try { alreadyShown = window.localStorage.getItem(qaStateKey) !== null; } catch (error) {}
    if (!alreadyShown) window.setTimeout(() => showQaEvortix('task_opened'), 1400);
});
</script>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>
