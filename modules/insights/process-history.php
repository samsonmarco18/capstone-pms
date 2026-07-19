<?php
require_once '../../includes/auth.php';
require_once '../../includes/process-history.php';

requireRole(['admin', 'sales', 'architect', 'sul', 'qa', 'expert', 'developer']);

$conn = getConnection();
$user = getCurrentUser();
methodologyRoadmapEnsureSchema($conn);
$projects = di_load_accessible_projects($conn, $user);
$projectNames = [];
foreach ($projects as $project) {
    $projectNames[(int) ($project['id'] ?? 0)] = (string) ($project['name'] ?? 'Project');
}

$accessibleProjectIds = array_keys($projectNames);
$selectedProjectId = (int) ($_GET['project_id'] ?? 0);
if ($selectedProjectId > 0 && !isset($projectNames[$selectedProjectId])) {
    $selectedProjectId = 0;
}

$allowedSorts = ['project_asc', 'project_desc', 'recent', 'longest_gap', 'longest_task_duration'];
$sort = (string) ($_GET['sort'] ?? 'recent');
if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'recent';
}
$activeTab = (string) ($_GET['tab'] ?? 'movements');
if (!in_array($activeTab, ['movements', 'durations'], true)) {
    $activeTab = 'movements';
}

$events = processHistoryLoadEvents($conn, $accessibleProjectIds, $selectedProjectId);
$taskDurations = processHistoryLoadTaskDurations($conn, $accessibleProjectIds, $selectedProjectId);
$taskDurations = processHistorySortTasks($taskDurations, $projectNames, $sort);
if ($events === [] && $taskDurations !== []) {
    $events = processHistoryEventsFromTaskDurations($taskDurations);
}
$events = array_merge(
    $events,
    processHistoryLoadProjectLifecycleEvents($conn, $accessibleProjectIds, $selectedProjectId, $events)
);
$events = processHistoryRecalculateEventGaps($events);
$events = processHistorySortEvents($events, $projectNames, $sort);
$summary = processHistorySummary($events);
$taskSummary = processHistoryTaskSummary($taskDurations);
$slowestEvent = $summary['slowest_event'];
$slowestTask = $taskSummary['slowest_task'];

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<style>
.process-hero {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    position: relative;
    margin-bottom: 1rem;
    padding: 1.2rem 5rem 1.35rem 1.35rem;
    border-radius: 8px;
    background: #1f6392;
    color: #fff;
    box-shadow: 0 12px 28px rgba(31, 99, 146, 0.16);
}
.process-hero h2 {
    margin: 0;
    color: #fff;
    font-size: 1.35rem;
    font-weight: 800;
}
.process-hero h2 i {
    color: #fff;
}
.process-hero p {
    margin: 0.35rem 0 0;
    max-width: 780px;
    color: rgba(255, 255, 255, 0.86);
    font-size: 0.9rem;
}
.process-hero.page-help-host .page-header-help-btn {
    position: absolute !important;
    top: 16px;
    right: 16px;
    margin: 0 !important;
    border-color: rgba(255, 255, 255, 0.9);
    background: rgba(255, 255, 255, 0.94);
    color: #15567f;
    box-shadow: 0 8px 20px rgba(5, 32, 55, 0.22);
}
.process-hero.page-help-host .page-header-help-btn:hover,
.process-hero.page-help-host .page-header-help-btn:focus-visible {
    background: #fff;
    color: #0f496e;
}
.process-filter-card,
.process-tab-shell {
    background: #fff;
    border: 1px solid #dfe8f1;
    border-radius: 16px;
    box-shadow: 0 12px 30px rgba(15, 42, 64, 0.06);
}
.process-filter-card { padding: 1.05rem; margin-bottom: 1rem; }
.process-filter-card .row { min-width: 0; }
.process-filter-card .form-select {
    min-width: 0;
    min-height: 46px;
    border-color: #d8e4ee;
    border-radius: 8px;
    color: #172033;
    text-overflow: ellipsis;
}
.process-filter-card .form-select:focus {
    border-color: #1f6392;
    box-shadow: 0 0 0 0.16rem rgba(31, 99, 146, 0.14);
}
.process-filter-card label { color: #64748b; font-size: 0.72rem; font-weight: 800; text-transform: uppercase; }
.process-tabs {
    display: flex;
    gap: 0.25rem;
    margin-bottom: 1rem;
    padding: 0.35rem;
    width: fit-content;
    max-width: 100%;
    border: 1px solid #dfe8f1;
    border-radius: 14px;
    background: #fff;
    overflow-x: auto;
    box-shadow: 0 8px 22px rgba(15, 42, 64, 0.05);
}
.process-tabs .nav-link {
    border: 0;
    border-radius: 10px;
    background: transparent;
    color: #475569;
    font-weight: 650;
    min-height: 46px;
    padding: 0.65rem 0.9rem;
    white-space: nowrap;
    transition: color 0.18s ease, background 0.18s ease, box-shadow 0.18s ease;
}
.process-tabs .nav-link:hover {
    background: #f2f7fb;
    color: #155f88;
}
.process-tabs .nav-link.active {
    background: #e8f3f9;
    color: #155f88;
    box-shadow: inset 0 0 0 1px #cde2ef;
}
.process-tabs .badge {
    min-width: 30px;
    padding: 0.38rem 0.5rem;
    border-radius: 999px;
    background: #fff !important;
    color: #31516a !important;
    font-weight: 650;
}
.process-tab-shell { overflow: hidden; }
.process-tab-head {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    padding: 1.15rem 1.3rem;
    border-bottom: 1px solid #e5edf5;
    background: linear-gradient(180deg, #fff 0%, #fbfdff 100%);
}
.process-tab-head > div { min-width: 0; }
.process-tab-head h5 {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    margin: 0;
    color: #172033;
    font-size: 1rem;
    font-weight: 700;
}
.process-tab-head h5 i {
    display: inline-grid;
    place-items: center;
    width: 34px;
    height: 34px;
    margin-right: 0 !important;
    border-radius: 10px;
    background: #e8f3f9;
    color: #17658e;
    font-size: 0.88rem;
}
.process-tab-head p { margin: 0.35rem 0 0 2.65rem; color: #64748b; font-size: 0.82rem; overflow-wrap: anywhere; }
.process-metric-strip {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 0;
    padding: 0.65rem 1.2rem;
    border-bottom: 1px solid #eef3f7;
    background: #fff;
}
.process-metric {
    display: grid;
    grid-template-columns: 42px minmax(0, 1fr);
    align-items: center;
    gap: 0.75rem;
    min-width: 0;
    padding: 0.75rem 1rem;
    border: 0;
    background: transparent;
}
.process-metric + .process-metric { border-left: 1px solid #e5edf5; }
.process-metric-icon {
    display: inline-grid;
    place-items: center;
    width: 42px;
    height: 42px;
    border-radius: 12px;
    background: #edf6fb;
    color: #17658e;
    font-size: 0.95rem;
}
.process-metric-copy { min-width: 0; }
.process-metric-copy > span { display: block; color: #64748b; font-size: 0.66rem; font-weight: 700; letter-spacing: 0.035em; text-transform: uppercase; }
.process-metric strong { display: block; margin-top: 0.12rem; color: #172033; font-size: 1.08rem; font-weight: 700; line-height: 1.25; overflow-wrap: anywhere; }
.process-metric small { display: block; margin-top: 0.14rem; color: #64748b; font-size: 0.76rem; overflow-wrap: anywhere; }
.process-list-header {
    display: grid;
    grid-template-columns: minmax(128px, 0.55fr) minmax(0, 1.7fr) minmax(220px, 0.95fr) minmax(150px, 0.62fr);
    gap: 1rem;
    padding: 0.72rem 1.35rem;
    border-bottom: 1px solid #e5edf5;
    background: #f5f9fc;
    color: #61758a;
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.035em;
    text-transform: uppercase;
}
.process-scroll-list {
    display: grid;
    gap: 0.7rem;
    max-height: min(680px, 64vh);
    overflow-y: auto;
    overflow-x: hidden;
    padding: 1rem;
    background: #f6f9fc;
    scrollbar-gutter: stable;
}
.process-event {
    display: grid;
    grid-template-columns: minmax(128px, 0.55fr) minmax(0, 1.7fr) minmax(220px, 0.95fr) minmax(150px, 0.62fr);
    gap: 1rem;
    align-items: center;
    position: relative;
    min-width: 0;
    padding: 1rem 1rem 1rem 1.15rem;
    border: 1px solid #e2eaf1;
    border-radius: 14px;
    background: #fff;
    box-shadow: 0 5px 14px rgba(15, 42, 64, 0.035);
    transition: border-color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease;
}
.process-event::before {
    content: "";
    position: absolute;
    top: 14px;
    bottom: 14px;
    left: 0;
    width: 3px;
    border-radius: 0 3px 3px 0;
    background: #4b94ba;
    opacity: 0;
    transform: scaleY(0.45);
    transition: opacity 0.18s ease, transform 0.18s ease;
}
.process-event:hover {
    background: #f6fbfe;
    border-color: #cbdde9;
    box-shadow: 0 9px 22px rgba(15, 42, 64, 0.07);
    transform: translateY(-1px);
}
.process-event:hover::before {
    opacity: 1;
    transform: scaleY(1);
}
.process-event-time {
    min-width: 0;
    color: #64748b;
    font-size: 0.78rem;
    overflow-wrap: anywhere;
}
.process-event-time strong { display: block; color: #172033; font-size: 0.84rem; font-weight: 700; }
.process-event-time span { display: block; margin-top: 0.2rem; font-size: 0.74rem; }
.process-event-main { min-width: 0; }
.process-event-title { display: flex; align-items: center; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.42rem; }
.process-event-title strong { min-width: 0; color: #172033; font-size: 0.91rem; font-weight: 700; overflow-wrap: anywhere; }
.process-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    max-width: 100%;
    padding: 0.25rem 0.48rem;
    border-radius: 999px;
    background: #edf6fb;
    color: #1f6392;
    font-size: 0.68rem;
    font-weight: 650;
    white-space: normal;
    overflow-wrap: anywhere;
    line-height: 1.25;
}
.process-pill.planning { background: #e0f2fe; color: #0369a1; }
.process-pill.roadmap { background: #ede9fe; color: #6d28d9; }
.process-pill.estimation { background: #fef3c7; color: #92400e; }
.process-pill.handoff,
.process-pill.task { background: #dbeafe; color: #1d4ed8; }
.process-pill.approval { background: #dcfce7; color: #166534; }
.process-pill.revision { background: #fee2e2; color: #b91c1c; }
.process-pill.version { background: #e2e8f0; color: #334155; }
.process-pill.release { background: #cffafe; color: #0e7490; }
.process-meta { display: flex; flex-direction: column; gap: 0.42rem; color: #64748b; font-size: 0.78rem; }
.process-meta span {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    min-width: 0;
    max-width: 100%;
    padding: 0;
    border: 0;
    border-radius: 0;
    background: transparent;
    overflow-wrap: anywhere;
    word-break: break-word;
}
.process-meta i {
    width: 16px;
    margin-right: 0 !important;
    color: #4f7d9b;
    text-align: center;
}
.process-status-flow { margin-top: 0.45rem; color: #475569; font-size: 0.82rem; overflow-wrap: anywhere; }
.process-note {
    margin-top: 0.55rem;
    padding: 0;
    border: 0;
    border-radius: 0;
    background: transparent;
    color: #64748b;
    font-size: 0.8rem;
    overflow-wrap: anywhere;
}
.process-duration {
    min-width: 0;
    padding-left: 1rem;
    border-left: 1px solid #e5edf5;
    text-align: right;
    overflow-wrap: anywhere;
}
.process-duration strong { display: block; margin-top: 0.14rem; color: #172033; font-size: 0.96rem; font-weight: 700; }
.process-duration span { color: #64748b; font-size: 0.66rem; font-weight: 700; letter-spacing: 0.03em; text-transform: uppercase; overflow-wrap: anywhere; }
.process-duration small { display: block; margin-top: 0.15rem; color: #7b8b9b; font-size: 0.72rem; overflow-wrap: anywhere; }
.process-duration.is-slow strong { color: #b45309; }
.process-empty { padding: 2rem; text-align: center; color: #64748b; }
.process-empty i { display: block; margin-bottom: 0.7rem; color: #1f6392; font-size: 1.8rem; }
.developer-duration-header {
    display: grid;
    grid-template-columns: minmax(230px, 1.35fr) minmax(150px, 0.85fr) minmax(150px, 0.85fr) minmax(150px, 0.85fr) minmax(150px, 0.8fr) minmax(140px, 0.75fr);
    gap: 1rem;
    padding: 0.72rem 1.35rem;
    border-bottom: 1px solid #e5edf5;
    background: #f5f9fc;
    color: #61758a;
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.035em;
    text-transform: uppercase;
}
.developer-duration-list {
    display: grid;
    gap: 0.7rem;
    padding: 1rem;
}
.developer-duration-card {
    display: grid;
    grid-template-columns: minmax(230px, 1.35fr) minmax(150px, 0.85fr) minmax(150px, 0.85fr) minmax(150px, 0.85fr) minmax(150px, 0.8fr) minmax(140px, 0.75fr);
    align-items: center;
    gap: 1rem;
    position: relative;
    min-width: 0;
    padding: 1rem 1rem 1rem 1.15rem;
    border: 1px solid #e2eaf1;
    border-radius: 14px;
    background: #fff;
    box-shadow: 0 5px 14px rgba(15, 42, 64, 0.035);
    transition: border-color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease;
}
.developer-duration-card::before {
    content: "";
    position: absolute;
    top: 14px;
    bottom: 14px;
    left: 0;
    width: 3px;
    border-radius: 0 3px 3px 0;
    background: #4b94ba;
    opacity: 0;
    transform: scaleY(0.45);
    transition: opacity 0.18s ease, transform 0.18s ease;
}
.developer-duration-card:hover {
    background: #f6fbfe;
    border-color: #cbdde9;
    box-shadow: 0 9px 22px rgba(15, 42, 64, 0.07);
    transform: translateY(-1px);
}
.developer-duration-card:hover::before {
    opacity: 1;
    transform: scaleY(1);
}
.task-duration-name { min-width: 0; }
.task-duration-name strong { display: block; color: #172033; font-size: 0.91rem; font-weight: 700; overflow-wrap: anywhere; }
.task-duration-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-top: 0.35rem;
    color: #64748b;
    font-size: 0.76rem;
}
.task-duration-meta span {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    min-width: 0;
    overflow-wrap: anywhere;
}
.task-duration-name .process-pill {
    margin-top: 0.45rem;
}
.task-duration-muted { color: #64748b; font-size: 0.76rem; overflow-wrap: anywhere; }
.task-duration-value {
    min-width: 0;
    color: #172033;
    overflow-wrap: anywhere;
}
.task-duration-value strong { display: block; color: #172033; font-size: 0.88rem; font-weight: 650; overflow-wrap: anywhere; }
.task-duration-value small {
    display: none;
    color: #64748b;
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    overflow-wrap: anywhere;
}
.task-duration-value span {
    display: block;
    margin-top: 0.15rem;
    color: #64748b;
    font-size: 0.76rem;
}
.task-duration-value .task-duration-late { color: #b91c1c !important; font-weight: 800; }
.task-duration-value .task-duration-ontrack { color: #166534 !important; font-weight: 800; }
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-filter-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-tab-shell,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-hero,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-tabs,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-metric,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-event,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-event-time,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-duration,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .developer-duration-card { background: #0d1b2a; border-color: #20364a; }
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-event {
    background: #0d1b2a;
}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-hero h2 {
    color: #fff;
}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-hero h2 i {
    color: #fff;
}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-hero p {
    color: rgba(255, 255, 255, 0.86);
}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-filter-card .form-select {
    color: #90aeca;
}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-filter-card .form-select {
    background: #102235;
    border-color: #20364a;
}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-tabs .nav-link {
    color: #b9cee2;
}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-tabs .nav-link:hover {
    background: #10283c;
    color: #d9ebf8;
}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-tabs .nav-link.active {
    color: #fff;
    background: #174d70;
    box-shadow: inset 0 0 0 1px #28688e;
}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-tab-head { background: #0d1b2a; border-color: #20364a; }
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-tab-head h5 i,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-metric-icon {
    background: #102f45;
    color: #7fc3e8;
}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-metric-strip { background: #0d1b2a; border-color: #20364a; }
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-metric {
    background: transparent;
}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-metric + .process-metric,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-duration {
    border-color: #20364a;
}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-list-header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .developer-duration-header {
    background: #081727;
    border-color: #20364a;
    color: #90aeca;
}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-scroll-list {
    background: #081727;
}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-event:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .developer-duration-card:hover {
    background: #102235;
    border-color: #2b506e;
}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-duration-meta,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-duration-value span,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-duration-value small,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-duration-muted {
    color: #90aeca;
}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-metric strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-hero h2,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-tab-head h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-duration-name strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .task-duration-value strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-event-time strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-event-title strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .process-duration strong { color: #e7f0f8; }
@media (max-width: 1200px) {
    .process-list-header,
    .developer-duration-header { display: none; }
    .process-event { grid-template-columns: minmax(0, 1fr); gap: 0.7rem; }
    .developer-duration-card { grid-template-columns: minmax(0, 1fr); gap: 0.7rem; }
    .process-duration {
        padding-top: 0.75rem;
        padding-left: 0;
        border-top: 1px solid #e5edf5;
        border-left: 0;
        text-align: left;
    }
    .process-meta { flex-direction: row; flex-wrap: wrap; }
    .task-duration-value small { display: block; margin-bottom: 0.15rem; }
}
@media (max-width: 900px) {
    .process-event { grid-template-columns: 1fr; }
    .developer-duration-card { grid-template-columns: 1fr; }
    .process-duration { text-align: left; }
}
@media (max-width: 767.98px) {
    .process-tabs {
        width: 100%;
    }
    .process-tabs .nav-item {
        flex: 1 0 auto;
    }
    .process-tabs .nav-link {
        width: 100%;
    }
    .process-tab-head {
        padding: 1rem;
    }
    .process-tab-head p {
        margin-left: 0;
    }
    .process-metric-strip {
        grid-template-columns: minmax(0, 1fr);
        padding: 0.4rem 1rem;
    }
    .process-metric + .process-metric {
        border-top: 1px solid #e5edf5;
        border-left: 0;
    }
    .process-scroll-list,
    .developer-duration-list {
        padding: 0.75rem;
    }
}
</style>

<div class="container-fluid px-4 py-4">
        <section class="process-hero" data-page-header>
            <div>
                <h2><i class="fas fa-clock-rotate-left me-2"></i>Process History</h2>
                <p>Track project movements from planning to release, including task start-to-finish duration, approvals, revisions, QA decisions, and completion timing.</p>
            </div>
        </section>

        <form class="process-filter-card" method="GET" id="processHistoryFilter">
            <input type="hidden" name="tab" id="processHistoryActiveTab" value="<?php echo htmlspecialchars($activeTab); ?>">
            <div class="row g-3 align-items-end">
                <div class="col-md-6">
                    <label for="project_id" class="form-label">Project</label>
                    <select class="form-select" id="project_id" name="project_id" onchange="this.form.submit()">
                        <option value="0">All accessible projects</option>
                        <?php foreach ($projects as $project): ?>
                            <?php $projectId = (int) ($project['id'] ?? 0); ?>
                            <option value="<?php echo $projectId; ?>" <?php echo $selectedProjectId === $projectId ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars((string) ($project['name'] ?? 'Project')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="sort" class="form-label">Sort</label>
                    <select class="form-select" id="sort" name="sort" onchange="this.form.submit()">
                        <option value="recent" <?php echo $sort === 'recent' ? 'selected' : ''; ?>>Most recent movement first</option>
                        <option value="longest_gap" <?php echo $sort === 'longest_gap' ? 'selected' : ''; ?>>Longest duration gap first</option>
                        <option value="longest_task_duration" <?php echo $sort === 'longest_task_duration' ? 'selected' : ''; ?>>Longest developer duration first</option>
                        <option value="project_asc" <?php echo $sort === 'project_asc' ? 'selected' : ''; ?>>Project A-Z, latest first</option>
                        <option value="project_desc" <?php echo $sort === 'project_desc' ? 'selected' : ''; ?>>Project Z-A, latest first</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <div class="text-muted small pb-2">Changes apply automatically.</div>
                </div>
            </div>
        </form>

        <ul class="nav process-tabs" id="processHistoryTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo $activeTab === 'movements' ? 'active' : ''; ?>" id="movements-tab" data-bs-toggle="tab" data-bs-target="#movements-panel" type="button" role="tab" aria-controls="movements-panel" aria-selected="<?php echo $activeTab === 'movements' ? 'true' : 'false'; ?>">
                    <i class="fas fa-route me-1"></i> Latest Movements
                    <span class="badge bg-light text-dark ms-1"><?php echo (int) $summary['event_count']; ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo $activeTab === 'durations' ? 'active' : ''; ?>" id="developer-duration-tab" data-bs-toggle="tab" data-bs-target="#developer-duration-panel" type="button" role="tab" aria-controls="developer-duration-panel" aria-selected="<?php echo $activeTab === 'durations' ? 'true' : 'false'; ?>">
                    <i class="fas fa-users-gear me-1"></i> Developer Durations
                    <span class="badge bg-light text-dark ms-1"><?php echo (int) $taskSummary['task_count']; ?></span>
                </button>
            </li>
        </ul>

        <div class="tab-content">
            <section class="tab-pane fade <?php echo $activeTab === 'movements' ? 'show active' : ''; ?> process-tab-shell" id="movements-panel" role="tabpanel" aria-labelledby="movements-tab">
                <div class="process-tab-head">
                    <div>
                        <h5><i class="fas fa-route me-2"></i>Latest Process Movements</h5>
                        <p>Newest actions are shown first. Each card includes the project, actor, item, status movement, and waiting time.</p>
                    </div>
                </div>
                <div class="process-metric-strip">
                    <div class="process-metric">
                        <span class="process-metric-icon" aria-hidden="true"><i class="fas fa-route"></i></span>
                        <div class="process-metric-copy">
                            <span>Movements</span>
                            <strong><?php echo (int) $summary['event_count']; ?></strong>
                            <small>Filtered timeline entries</small>
                        </div>
                    </div>
                    <div class="process-metric">
                        <span class="process-metric-icon" aria-hidden="true"><i class="fas fa-diagram-project"></i></span>
                        <div class="process-metric-copy">
                            <span>Projects shown</span>
                            <strong><?php echo (int) $summary['project_count']; ?></strong>
                            <small><?php echo $selectedProjectId > 0 ? 'Selected project only' : 'Across accessible projects'; ?></small>
                        </div>
                    </div>
                    <div class="process-metric">
                        <span class="process-metric-icon" aria-hidden="true"><i class="fas fa-hourglass-half"></i></span>
                        <div class="process-metric-copy">
                            <span>Longest gap</span>
                            <strong><?php echo $slowestEvent ? htmlspecialchars(processHistoryFormatDuration((int) $slowestEvent['gap_seconds'])) : 'None'; ?></strong>
                            <small><?php echo $slowestEvent ? htmlspecialchars($projectNames[(int) $slowestEvent['project_id']] ?? 'Project') : 'No movement gap yet'; ?></small>
                        </div>
                    </div>
                </div>
                <?php if (!$events): ?>
                    <div class="process-empty">
                        <i class="fas fa-folder-open"></i>
                        <strong>No process movements found.</strong>
                        <div>Try another project or complete a workflow action first.</div>
                    </div>
                <?php else: ?>
                    <div class="process-list-header" aria-hidden="true">
                        <span>Date &amp; time</span>
                        <span>Movement</span>
                        <span>Context</span>
                        <span>Since previous</span>
                    </div>
                    <div class="process-scroll-list">
                        <?php foreach ($events as $event): ?>
                        <?php
                            $projectName = $projectNames[(int) $event['project_id']] ?? 'Project';
                            $gapSeconds = $event['gap_seconds'];
                            $isSlow = $gapSeconds !== null && (int) $gapSeconds >= 172800;
                            $createdAt = !empty($event['created_at']) ? strtotime((string) $event['created_at']) : false;
                        ?>
                        <article class="process-event">
                            <div class="process-event-time">
                                <strong><?php echo $createdAt ? htmlspecialchars(date('M d, Y', $createdAt)) : 'No date'; ?></strong>
                                <span><?php echo $createdAt ? htmlspecialchars(date('h:i A', $createdAt)) : 'No time'; ?></span>
                            </div>
                            <div class="process-event-main">
                                <div class="process-event-title">
                                    <span class="process-pill <?php echo htmlspecialchars((string) $event['tone']); ?>"><?php echo htmlspecialchars((string) $event['category']); ?></span>
                                    <strong><?php echo htmlspecialchars((string) $event['label']); ?></strong>
                                </div>
                                <?php if ($event['from_status'] !== '' || $event['to_status'] !== ''): ?>
                                    <div class="process-status-flow">
                                        <i class="fas fa-arrow-right-arrow-left me-1"></i>
                                        <?php echo htmlspecialchars($event['from_status'] !== '' ? $event['from_status'] : 'Status'); ?>
                                        <i class="fas fa-arrow-right mx-1"></i>
                                        <?php echo htmlspecialchars($event['to_status'] !== '' ? $event['to_status'] : 'Updated'); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($event['description'] !== ''): ?>
                                    <div class="process-note"><?php echo htmlspecialchars((string) $event['description']); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="process-meta">
                                <span><i class="fas fa-diagram-project me-1"></i><?php echo htmlspecialchars($projectName); ?></span>
                                <span><i class="fas fa-user me-1"></i><?php echo htmlspecialchars((string) $event['actor']); ?><?php echo !empty($event['role']) ? ' (' . htmlspecialchars(ucfirst((string) $event['role'])) . ')' : ''; ?></span>
                                <span><i class="fas fa-tag me-1"></i><?php echo htmlspecialchars((string) $event['item']); ?></span>
                            </div>
                            <div class="process-duration <?php echo $isSlow ? 'is-slow' : ''; ?>">
                                <span>Since previous</span>
                                <strong><?php echo htmlspecialchars(processHistoryFormatDuration($gapSeconds)); ?></strong>
                                <small><?php echo htmlspecialchars(processHistoryFormatDuration((int) $event['since_start_seconds'])); ?> since first step</small>
                            </div>
                        </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="tab-pane fade <?php echo $activeTab === 'durations' ? 'show active' : ''; ?> process-tab-shell" id="developer-duration-panel" role="tabpanel" aria-labelledby="developer-duration-tab">
                <div class="process-tab-head">
                    <div>
                        <h5><i class="fas fa-users-gear me-2"></i>Developer Task Start-To-Finish Duration</h5>
                        <p>One row per developer assignment. Total duration includes planning time; developer duration starts from the first known developer movement.</p>
                    </div>
                </div>
                <div class="process-metric-strip">
                    <div class="process-metric">
                        <span class="process-metric-icon" aria-hidden="true"><i class="fas fa-list-check"></i></span>
                        <div class="process-metric-copy">
                            <span>Developer task rows</span>
                            <strong><?php echo (int) $taskSummary['task_count']; ?></strong>
                            <small>Developer-specific assignments</small>
                        </div>
                    </div>
                    <div class="process-metric">
                        <span class="process-metric-icon" aria-hidden="true"><i class="fas fa-circle-check"></i></span>
                        <div class="process-metric-copy">
                            <span>Completed developer work</span>
                            <strong><?php echo (int) $taskSummary['completed_count']; ?></strong>
                            <small>Average: <?php echo $taskSummary['average_completed_seconds'] !== null ? htmlspecialchars(processHistoryFormatDuration((int) $taskSummary['average_completed_seconds'])) : 'No completed work yet'; ?></small>
                        </div>
                    </div>
                    <div class="process-metric">
                        <span class="process-metric-icon" aria-hidden="true"><i class="fas fa-stopwatch"></i></span>
                        <div class="process-metric-copy">
                            <span>Longest developer duration</span>
                            <strong><?php echo $slowestTask ? htmlspecialchars(processHistoryFormatDuration((int) $slowestTask['workflow_seconds'])) : 'None'; ?></strong>
                            <small><?php echo $slowestTask ? htmlspecialchars((string) $slowestTask['assigned_to'] . ' - ' . (string) $slowestTask['title']) : 'No tasks found'; ?></small>
                        </div>
                    </div>
                </div>
                <?php if (!$taskDurations): ?>
                    <div class="process-empty">
                        <i class="fas fa-list"></i>
                        <strong>No developer task durations found.</strong>
                        <div>Choose another project or assign developers to workflow tasks first.</div>
                    </div>
                <?php else: ?>
                    <div class="developer-duration-header" aria-hidden="true">
                        <span>Assignment</span>
                        <span>Planning start</span>
                        <span>Developer start</span>
                        <span><?php echo $taskSummary['completed_count'] > 0 ? 'Completed / current' : 'Current'; ?></span>
                        <span>Durations</span>
                        <span>Deadline</span>
                    </div>
                    <div class="process-scroll-list developer-duration-list">
                        <?php foreach ($taskDurations as $task): ?>
                            <?php
                                $projectName = $projectNames[(int) $task['project_id']] ?? 'Project';
                                $planningStartTs = processHistoryTimestamp($task['planning_start_at'] ?? null);
                                $developerStartTs = processHistoryTimestamp($task['developer_started_at'] ?? null);
                                $completedTs = processHistoryTimestamp($task['completed_at'] ?? null);
                                $deadlineTs = processHistoryTimestamp($task['deadline'] ?? null);
                                $displayEndTs = $completedTs ?? time();
                                $lateSeconds = (int) ($task['late_seconds'] ?? 0);
                                $isCompleted = !empty($task['is_completed']);
                            ?>
                            <article class="developer-duration-card">
                                <div class="task-duration-name">
                                    <strong><?php echo htmlspecialchars((string) $task['title']); ?></strong>
                                    <div class="task-duration-meta">
                                        <span><i class="fas fa-user"></i><?php echo htmlspecialchars((string) $task['assigned_to']); ?></span>
                                        <span><i class="fas fa-diagram-project"></i><?php echo htmlspecialchars($projectName); ?></span>
                                    </div>
                                    <span class="process-pill <?php echo $isCompleted ? 'approval' : 'task'; ?>"><?php echo htmlspecialchars((string) $task['status_label']); ?></span>
                                </div>
                                <div class="task-duration-value">
                                    <small>Planning start</small>
                                    <strong><?php echo $planningStartTs ? htmlspecialchars(date('M d, Y h:i A', $planningStartTs)) : 'No movement yet'; ?></strong>
                                </div>
                                <div class="task-duration-value">
                                    <small>Developer start</small>
                                    <strong><?php echo $developerStartTs ? htmlspecialchars(date('M d, Y h:i A', $developerStartTs)) : 'Not started'; ?></strong>
                                </div>
                                <div class="task-duration-value">
                                    <small><?php echo $isCompleted ? 'Completed' : 'Current'; ?></small>
                                    <strong><?php echo $completedTs ? htmlspecialchars(date('M d, Y h:i A', $completedTs)) : htmlspecialchars(date('M d, Y h:i A', $displayEndTs)); ?></strong>
                                </div>
                                <div class="task-duration-value">
                                    <small>Durations</small>
                                    <strong><?php echo htmlspecialchars(processHistoryFormatDuration($task['workflow_seconds'])); ?></strong>
                                    <span>Work: <?php echo htmlspecialchars(processHistoryFormatDuration($task['work_seconds'])); ?></span>
                                </div>
                                <div class="task-duration-value">
                                    <small>Deadline</small>
                                    <strong>
                                        <?php if (!$deadlineTs): ?>
                                            <span class="task-duration-muted">No deadline</span>
                                        <?php elseif ($lateSeconds > 0): ?>
                                            <span class="task-duration-late"><?php echo htmlspecialchars(processHistoryFormatDuration($lateSeconds)); ?> late</span>
                                        <?php elseif ($isCompleted): ?>
                                            <span class="task-duration-ontrack">On time</span>
                                        <?php else: ?>
                                            <span class="task-duration-muted">Due <?php echo htmlspecialchars(date('M d, Y', $deadlineTs)); ?></span>
                                        <?php endif; ?>
                                    </strong>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
</div>

<script>
document.querySelectorAll('#processHistoryTabs [data-bs-toggle="tab"]').forEach((tab) => {
    tab.addEventListener('shown.bs.tab', (event) => {
        const activeInput = document.getElementById('processHistoryActiveTab');
        if (!activeInput) {
            return;
        }
        activeInput.value = event.target.id === 'developer-duration-tab' ? 'durations' : 'movements';
    });
});
</script>

<?php include '../../includes/footer.php'; ?>
