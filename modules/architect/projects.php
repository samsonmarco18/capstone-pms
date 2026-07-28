<?php
require_once '../../includes/auth.php';
require_once '../../includes/methodology-roadmap.php';
requireRole('architect');

$conn = getConnection();
$user = getCurrentUser();
methodologyRoadmapEnsureSchema($conn);
$flashSuccess = trim((string) ($_SESSION['success'] ?? ''));
$flashError = trim((string) ($_SESSION['error'] ?? ''));
$submittedProjectId = max(0, (int) ($_GET['submitted'] ?? 0));
unset($_SESSION['success'], $_SESSION['error']);

function architectProjectNextStep(array $project): array
{
    $projectId = (int) ($project['id'] ?? 0);
    $featureCount = (int) ($project['feature_count'] ?? 0);
    $techCount = (int) ($project['tech_count'] ?? 0);
    $allocationCount = (int) ($project['allocation_count'] ?? 0);
    $roadmapCount = (int) ($project['roadmap_count'] ?? 0);
    $documentCount = (int) ($project['document_count'] ?? 0);
    $expertAssignmentCount = (int) ($project['expert_assignment_count'] ?? 0);

    if (trim((string) ($project['workflow_status'] ?? '')) === 'architect_submitted') {
        $isAwaitingSulReview = $expertAssignmentCount === 0
            && trim((string) ($project['status'] ?? '')) === 'in_progress';
        return [
            'url' => 'summary.php?id=' . $projectId,
            'label' => 'View Submitted Summary',
            'step' => $isAwaitingSulReview ? 'Awaiting SUL review' : 'Handoff complete',
        ];
    }

    if ($featureCount <= 0) {
        return ['url' => 'project-review.php?id=' . $projectId, 'label' => 'Start Review', 'step' => 'Step 1 of 7'];
    }
    if ($techCount <= 0) {
        return ['url' => 'tech-recommendation.php?id=' . $projectId, 'label' => 'Continue Technology', 'step' => 'Step 3 of 7'];
    }
    if ($allocationCount <= 0) {
        return ['url' => 'developer-allocation.php?id=' . $projectId, 'label' => 'Continue Developers', 'step' => 'Step 4 of 7'];
    }
    if ($roadmapCount <= 0) {
        return ['url' => '../roadmap/methodology.php?project_id=' . $projectId, 'label' => 'Continue Roadmap', 'step' => 'Step 5 of 7'];
    }
    if ($documentCount <= 0) {
        return ['url' => 'upload-diagram.php?id=' . $projectId, 'label' => 'Continue Documents', 'step' => 'Step 6 of 7'];
    }

    return ['url' => 'summary.php?id=' . $projectId, 'label' => 'Open Summary', 'step' => 'Step 7 of 7'];
}

// Get all projects assigned to this architect
$projects = $conn->query("
    SELECT p.*, c.name as client_name,
           (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id AND t.task_type = 'feature') AS feature_count,
           (SELECT COUNT(*) FROM tech_recommendations tr WHERE tr.project_id = p.id) AS tech_count,
           (SELECT COUNT(*) FROM developer_allocations da WHERE da.project_id = p.id) AS allocation_count,
           (SELECT COUNT(*) FROM methodology_phases mp WHERE mp.project_id = p.id) AS roadmap_count,
           (SELECT COUNT(*) FROM architecture_documents ad WHERE ad.project_id = p.id) AS document_count,
           (SELECT COUNT(*) FROM tasks ta WHERE ta.project_id = p.id AND ta.task_type = 'feature' AND ta.assigned_to IS NOT NULL AND ta.assigned_to != 0) AS expert_assignment_count
    FROM projects p 
    JOIN clients c ON p.client_id = c.id 
    WHERE p.architect_id = {$user['id']} OR p.architect_id IS NULL
    ORDER BY p.created_at DESC
");

// Get statistics
$totalProjects = $projects->num_rows;
$pendingProjects = 0;
$inProgressProjects = 0;
$completedProjects = 0;
$reviewProjects = 0;
$awaitingSulProjects = 0;

$projects->data_seek(0);
while($project = $projects->fetch_assoc()) {
    $isAwaitingSulReview = trim((string) ($project['workflow_status'] ?? '')) === 'architect_submitted'
        && (int) ($project['expert_assignment_count'] ?? 0) === 0
        && trim((string) ($project['status'] ?? '')) === 'in_progress';
    if($project['status'] == 'pending') $pendingProjects++;
    elseif($project['status'] == 'in_progress' && !$isAwaitingSulReview) $inProgressProjects++;
    elseif($project['status'] == 'completed') $completedProjects++;
    elseif($project['status'] == 'review') $reviewProjects++;
    if ($isAwaitingSulReview) $awaitingSulProjects++;
}
$projects->data_seek(0);

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<style>
:root {
    --theme-primary: #1f6392;
    --theme-dark: #184f73;
    --theme-light: #3a7ca3;
    --gray-50: #f8f9fa;
    --gray-100: #f1f3f5;
    --gray-200: #e9ecef;
    --gray-300: #dee2e6;
    --gray-600: #6c757d;
    --gray-900: #212529;
    --shadow-sm: 0 2px 4px rgba(0,0,0,0.02), 0 1px 2px rgba(0,0,0,0.03);
    --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03);
}

.card {
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.card:hover {
    transform: translateY(-2px);
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.08) !important;
}

/* Page Header */
.page-header-modern {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    border-radius: 24px;
    padding: 2rem;
    margin-bottom: 2rem;
    position: relative;
    overflow: hidden;
}

.page-header-modern::before {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 300px;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1));
    pointer-events: none;
}

.page-header-modern h2 {
    font-size: 1.75rem;
    font-weight: 600;
    margin-bottom: 0.5rem;
    color: white;
}

.page-header-modern h2 i {
    color: white;
}

.page-header-modern p {
    opacity: 0.9;
    margin-bottom: 0;
    font-size: 0.9rem;
    color: rgba(255, 255, 255, 0.9);
}

.workflow-flash {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1.5rem;
    padding: 1rem 1.1rem;
    border: 1px solid;
    border-radius: 16px;
    box-shadow: var(--shadow-sm);
}

.workflow-flash__icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    width: 42px;
    height: 42px;
    border-radius: 50%;
    color: white;
}

.workflow-flash__content {
    flex: 1 1 auto;
    min-width: 0;
}

.workflow-flash h5 {
    margin: 0 0 0.2rem;
    font-size: 0.95rem;
    font-weight: 700;
}

.workflow-flash p {
    margin: 0;
    font-size: 0.82rem;
    line-height: 1.45;
}

.workflow-flash.success {
    background: #ecfdf5;
    border-color: #a7f3d0;
    color: #065f46;
}

.workflow-flash.success .workflow-flash__icon {
    background: #10b981;
    box-shadow: 0 6px 16px rgba(16, 185, 129, 0.22);
}

.workflow-flash.error {
    background: #fff1f2;
    border-color: #fecdd3;
    color: #9f1239;
}

.workflow-flash.error .workflow-flash__icon {
    background: #e11d48;
}

.workflow-flash__action {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    flex: 0 0 auto;
    min-height: 38px;
    padding: 0.5rem 0.8rem;
    border: 1px solid rgba(5, 150, 105, 0.3);
    border-radius: 9px;
    background: white;
    color: #047857;
    font-size: 0.76rem;
    font-weight: 600;
    text-decoration: none;
}

.workflow-flash__action:hover {
    background: #d1fae5;
    color: #065f46;
}

/* Stats Row */
.stats-row {
    display: flex;
    gap: 1rem;
    margin-bottom: 2rem;
    flex-wrap: wrap;
}

.stat-card-modern {
    background: white;
    border-radius: 20px;
    padding: 1.25rem 1.5rem;
    flex: 1;
    min-width: 180px;
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--gray-200);
    transition: all 0.2s ease;
    position: relative;
}

.stat-card-modern:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
}

.stat-card-modern .stat-icon {
    position: absolute;
    right: 1rem;
    top: 1rem;
    opacity: 0.65;
    font-size: 1.6rem;
    color: var(--theme-primary);
}

.stat-card-modern .stat-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    font-weight: 600;
    color: var(--gray-600);
    letter-spacing: 0.5px;
    margin-bottom: 0.5rem;
}

.stat-card-modern .stat-number {
    font-size: 2rem;
    font-weight: 700;
    color: var(--theme-primary);
    line-height: 1;
}

/* Main Card */
.main-card {
    background: white;
    border-radius: 18px;
    border: 1px solid var(--gray-200);
    overflow: hidden;
    box-shadow: 0 12px 30px rgba(15, 42, 64, 0.06);
}

.card-header-modern {
    padding: 1.15rem 1.5rem;
    background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
    border-bottom: 1px solid var(--gray-200);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
}

.card-header-modern h5 {
    font-size: 1rem;
    font-weight: 700;
    color: var(--gray-900);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.card-header-modern h5 i {
    display: inline-grid;
    place-items: center;
    width: 34px;
    height: 34px;
    border-radius: 10px;
    background: #eaf4f9;
    color: var(--theme-primary);
    font-size: 0.88rem;
}

.directory-tools {
    display: flex;
    align-items: flex-end;
    gap: 1.25rem;
    min-width: 0;
}

.project-search {
    position: relative;
    display: block;
    width: clamp(250px, 30vw, 390px);
}

.project-search > i {
    position: absolute;
    left: 0.15rem;
    top: 50%;
    color: #668096;
    font-size: 0.86rem;
    transform: translateY(-50%);
    pointer-events: none;
}

.project-search input {
    width: 100%;
    min-height: 40px;
    padding: 0.5rem 1.9rem 0.5rem 1.65rem;
    border: 0;
    border-bottom: 1px solid #cbd8e3;
    border-radius: 0;
    outline: 0;
    background: transparent;
    color: #23384c;
    font-size: 0.84rem;
    transition: border-color 0.18s ease, box-shadow 0.18s ease;
}

.project-search input::placeholder {
    color: #8a9aaa;
}

.project-search input:focus {
    border-color: var(--theme-primary);
    box-shadow: 0 2px 0 rgba(31, 99, 146, 0.12);
}

.project-status-filter {
    position: relative;
    display: block;
    flex: 0 0 180px;
    width: 180px;
}

.project-status-filter > i {
    position: absolute;
    left: 0.15rem;
    top: 50%;
    z-index: 1;
    color: #668096;
    font-size: 0.82rem;
    transform: translateY(-50%);
    pointer-events: none;
}

.project-status-filter select {
    width: 100%;
    min-height: 40px;
    padding: 0.5rem 1.75rem 0.5rem 1.55rem;
    border: 0;
    border-bottom: 1px solid #cbd8e3;
    border-radius: 0;
    outline: 0;
    background-color: transparent;
    color: #41566b;
    font-size: 0.8rem;
    cursor: pointer;
    transition: border-color 0.18s ease, box-shadow 0.18s ease;
}

.project-status-filter select:focus {
    border-color: var(--theme-primary);
    box-shadow: 0 2px 0 rgba(31, 99, 146, 0.12);
}

.directory-count {
    flex: 0 0 auto;
    padding-bottom: 0.55rem;
    color: #718096;
    font-size: 0.76rem;
    white-space: nowrap;
}

/* Table Styles */
.table-container {
    padding: 0;
    overflow-x: auto;
}

.project-table {
    width: 100%;
    min-width: 880px;
    table-layout: fixed;
    border-collapse: collapse;
}

.project-table thead {
    background: var(--gray-50);
}

.project-table th {
    padding: 0.78rem 1.25rem;
    text-align: left;
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.045em;
    color: var(--gray-600);
    border-bottom: 1px solid var(--gray-200);
}

.project-table td {
    padding: 1rem 1.25rem;
    vertical-align: middle;
    border-bottom: 1px solid var(--gray-200);
    color: #41566b;
    font-size: 0.84rem;
}

.project-table tbody tr {
    transition: background 0.18s ease, box-shadow 0.18s ease;
}

.project-table tbody tr:not(.project-search-empty):hover {
    background: #f6fbfe;
    box-shadow: inset 3px 0 0 #4b94ba;
}

.project-table tbody tr:last-child td {
    border-bottom: 0;
}

.project-table th:nth-child(1) { width: 29%; }
.project-table th:nth-child(2) { width: 20%; }
.project-table th:nth-child(3) { width: 18%; }
.project-table th:nth-child(4) { width: 33%; }

.project-cell {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    min-width: 0;
}

.project-mark {
    display: inline-grid;
    place-items: center;
    flex: 0 0 38px;
    width: 38px;
    height: 38px;
    border-radius: 11px;
    background: #edf6fb;
    color: var(--theme-primary);
    font-size: 0.88rem;
}

.project-cell-copy {
    min-width: 0;
}

.project-cell-copy small {
    display: block;
    margin-top: 0.2rem;
    color: #8091a2;
    font-size: 0.7rem;
}

.client-name {
    line-height: 1.45;
}

.project-table tr.is-submitted-highlight {
    background: #f0fdf4;
    box-shadow: inset 4px 0 0 #10b981;
}

.project-name {
    font-weight: 700;
    color: var(--gray-900);
    line-height: 1.35;
    overflow-wrap: anywhere;
}

/* Badges */
.badge-status {
    display: inline-block;
    max-width: 100%;
    padding: 0.35rem 0.7rem;
    border-radius: 20px;
    background: #edf3f7;
    color: #3f6179;
    font-size: 0.68rem;
    font-weight: 650;
    line-height: 1.25;
    text-align: center;
}

.badge-status.requirements_gathering {
    background: #e0f2fe;
    color: #0369a1;
}

.badge-status.pending {
    background: #fef3c7;
    color: #d97706;
}

.badge-status.review {
    background: #dbeafe;
    color: #2563eb;
}

.badge-status.in_progress {
    background: #e0e7ff;
    color: #4f46e5;
}

.badge-status.awaiting_sul_review {
    background: #e0f2fe;
    color: #0369a1;
}

.badge-status.completed {
    background: #d1fae5;
    color: #059669;
}

/* Action Button */
.btn-review {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    color: #fff !important;
    border: none;
    min-height: 36px;
    padding: 0.42rem 0.78rem;
    border-radius: 9px;
    font-size: 0.72rem;
    font-weight: 600;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    text-decoration: none;
}

.btn-review i {
    color: inherit !important;
}

.btn-review:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(31, 99, 146, 0.3);
    color: #fff !important;
}

.btn-secondary-action {
    background: white;
    color: var(--theme-primary);
    border: 1px solid rgba(31, 99, 146, 0.2);
    min-height: 36px;
    padding: 0.42rem 0.75rem;
    border-radius: 9px;
    font-size: 0.72rem;
    font-weight: 600;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    text-decoration: none;
}

.btn-secondary-action:hover {
    background: var(--gray-50);
    color: var(--theme-dark);
}

.project-action-buttons {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.45rem;
}

.progress-meta {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.3rem 0.55rem;
    color: var(--gray-600);
    margin-top: 0.55rem;
    font-size: 0.67rem;
}

.progress-meta span {
    display: inline-flex;
    align-items: baseline;
    gap: 0.2rem;
    white-space: nowrap;
}

.progress-meta span + span {
    padding-left: 0.55rem;
    border-left: 1px solid #d8e2eb;
}

.progress-meta strong {
    color: #334e65;
    font-weight: 700;
}

.progress-meta .progress-step {
    color: var(--theme-primary);
    font-weight: 650;
}

.project-search-empty td {
    padding: 2.4rem 1rem;
    color: #718096;
    text-align: center;
}

.project-search-empty i {
    display: block;
    margin-bottom: 0.55rem;
    color: #8fb3ca;
    font-size: 1.4rem;
}

.project-table tr[hidden] {
    display: none;
}

/* Empty State */
.empty-state-modern {
    text-align: center;
    padding: 4rem 2rem;
}

.empty-state-modern i {
    font-size: 4rem;
    color: var(--gray-300);
    margin-bottom: 1rem;
}

.empty-state-modern h4 {
    color: var(--gray-900);
    margin-bottom: 0.5rem;
}

.empty-state-modern p {
    color: var(--gray-600);
}

/* Responsive */
@media (max-width: 1100px) {
    .directory-tools {
        width: 100%;
    }

    .project-search {
        flex: 1 1 auto;
        width: auto;
    }
}

@media (max-width: 768px) {
    .page-header-modern {
        padding: 1.5rem;
    }
    
    .stats-row {
        flex-direction: column;
    }
    
    .stat-card-modern {
        min-width: auto;
    }
    
    .card-header-modern {
        flex-direction: column;
        align-items: stretch;
    }

    .directory-tools {
        align-items: stretch;
        flex-direction: column;
        gap: 0.45rem;
    }

    .project-search {
        width: 100%;
    }

    .project-status-filter {
        flex-basis: auto;
        width: 100%;
    }

    .directory-count {
        padding: 0;
    }

    .workflow-flash {
        align-items: flex-start;
        flex-wrap: wrap;
    }

    .workflow-flash__action {
        width: 100%;
        justify-content: center;
    }
    
    .project-table th,
    .project-table td {
        padding: 0.75rem 1rem;
    }
}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #page-content-wrapper {
    background: #06111e;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .stat-card-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .main-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card {
    background: #071321 !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
    box-shadow: 0 16px 36px rgba(3, 14, 25, 0.26) !important;
    color: #d7e4f2 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card-header-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-table thead,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-table tr:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .bg-white {
    background: #0a1b2c !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card-header-modern h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-name,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .stat-number,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) td {
    color: #d7e4f2 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .stat-label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-table th,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .text-muted,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) small {
    color: #9db6cf !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-table th,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-table td {
    border-color: rgba(31, 99, 146, 0.24) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .workflow-flash.success {
    background: #08251f;
    border-color: rgba(52, 211, 153, 0.38);
    color: #a7f3d0;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .workflow-flash.error {
    background: #2b1118;
    border-color: rgba(251, 113, 133, 0.38);
    color: #fecdd3;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .workflow-flash__action {
    background: #0a1b2c;
    border-color: rgba(52, 211, 153, 0.38);
    color: #a7f3d0;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-table tr.is-submitted-highlight {
    background: #08251f;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-search input {
    border-color: #29435a;
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-status-filter select {
    border-color: #29435a;
    background-color: #0a1b2c;
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-search input::placeholder,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .directory-count {
    color: #8ea8c0;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-mark {
    background: #102f45;
    color: #7fc3e8;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .progress-meta span + span {
    border-color: #29435a;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .progress-meta strong {
    color: #d7e4f2;
}
</style>

<div class="container-fluid px-4 py-4">
    <!-- Page Header -->
    <div class="page-header-modern">
        <div>
            <h2>
                <i class="fas fa-clipboard-list me-2"></i> 
                Projects for Technical Evaluation
            </h2>
            <p>Review and evaluate project requirements, provide technical recommendations</p>
        </div>
    </div>

    <?php if ($flashSuccess !== ''): ?>
        <div class="workflow-flash success" role="status">
            <span class="workflow-flash__icon"><i class="fas fa-paper-plane" aria-hidden="true"></i></span>
            <div class="workflow-flash__content">
                <h5>Sent to Service Unit Leader</h5>
                <p><?php echo htmlspecialchars($flashSuccess); ?></p>
            </div>
            <?php if ($submittedProjectId > 0): ?>
                <a href="summary.php?id=<?php echo $submittedProjectId; ?>" class="workflow-flash__action"><i class="fas fa-eye" aria-hidden="true"></i> View submitted summary</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($flashError !== ''): ?>
        <div class="workflow-flash error" role="alert">
            <span class="workflow-flash__icon"><i class="fas fa-circle-exclamation" aria-hidden="true"></i></span>
            <div class="workflow-flash__content">
                <h5>Submission needs attention</h5>
                <p><?php echo htmlspecialchars($flashError); ?></p>
            </div>
        </div>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="stats-row">
        <div class="stat-card-modern">
            <i class="fas fa-project-diagram stat-icon"></i>
            <div class="stat-label">Total Projects</div>
            <div class="stat-number"><?php echo $totalProjects; ?></div>
        </div>
        <div class="stat-card-modern">
            <i class="fas fa-clock stat-icon"></i>
            <div class="stat-label">Pending Review</div>
            <div class="stat-number" style="color: #f59e0b;"><?php echo $pendingProjects; ?></div>
        </div>
        <div class="stat-card-modern">
            <i class="fas fa-spinner stat-icon"></i>
            <div class="stat-label">In Progress</div>
            <div class="stat-number" style="color: #3b82f6;"><?php echo $inProgressProjects; ?></div>
        </div>
        <div class="stat-card-modern">
            <i class="fas fa-paper-plane stat-icon"></i>
            <div class="stat-label">Awaiting SUL Review</div>
            <div class="stat-number" style="color: #0284c7;"><?php echo $awaitingSulProjects; ?></div>
        </div>
        <div class="stat-card-modern">
            <i class="fas fa-check-circle stat-icon"></i>
            <div class="stat-label">Completed</div>
            <div class="stat-number" style="color: #10b981;"><?php echo $completedProjects; ?></div>
        </div>
    </div>

    <!-- Main Content Card -->
    <div class="main-card">
        <div class="card-header-modern">
            <div class="directory-heading">
                <h5>
                    <i class="fas fa-list"></i>
                    Project Directory
                </h5>
            </div>
            <div class="directory-tools">
                <label class="project-search" for="projectDirectorySearch">
                    <i class="fas fa-search" aria-hidden="true"></i>
                    <input type="search" id="projectDirectorySearch" placeholder="Search project, client, or status" autocomplete="off">
                    <span class="visually-hidden">Search projects</span>
                </label>
                <label class="project-status-filter" for="projectStatusFilter">
                    <i class="fas fa-filter" aria-hidden="true"></i>
                    <select id="projectStatusFilter" aria-label="Filter projects by status">
                        <option value="">All statuses</option>
                    </select>
                </label>
                <span class="directory-count" id="projectDirectoryCount" aria-live="polite">Showing <?php echo $totalProjects; ?> projects</span>
            </div>
        </div>
        
        <!-- Projects Table -->
        <div class="table-container">
            <?php if($totalProjects > 0): ?>
            <table class="project-table">
                <thead>
                    <tr>
                        <th>Project Name</th>
                        <th>Client</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                <tbody id="projectDirectoryBody">
                    <?php while($project = $projects->fetch_assoc()): ?>
                    <?php
                        $isAwaitingSul = trim((string) ($project['workflow_status'] ?? '')) === 'architect_submitted'
                            && (int) ($project['expert_assignment_count'] ?? 0) === 0
                            && trim((string) ($project['status'] ?? '')) === 'in_progress';
                        $statusClass = $isAwaitingSul
                            ? 'awaiting_sul_review'
                            : trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower(trim((string) ($project['status'] ?? 'pending')))), '_');
                        $statusLabel = $isAwaitingSul ? 'Awaiting SUL Review' : ucfirst(str_replace('_', ' ', (string) ($project['status'] ?? 'pending')));
                    ?>
                    <tr class="project-directory-row <?php echo $submittedProjectId === (int) $project['id'] ? 'is-submitted-highlight' : ''; ?>" data-status="<?php echo htmlspecialchars((string) $statusClass); ?>" data-status-label="<?php echo htmlspecialchars($statusLabel); ?>">
                        <td>
                            <div class="project-cell">
                                <span class="project-mark" aria-hidden="true"><i class="fas fa-folder"></i></span>
                                <div class="project-cell-copy">
                                    <div class="project-name"><?php echo htmlspecialchars($project['name']); ?></div>
                                    <small>Technical evaluation</small>
                                </div>
                            </div>
                        </td>
                        <td><span class="client-name"><?php echo htmlspecialchars($project['client_name']); ?></span></td>
                        <td>
                            <span class="badge-status <?php echo htmlspecialchars((string) $statusClass); ?>">
                                <?php echo htmlspecialchars($statusLabel); ?>
                            </span>
                        </td>
                        <td>
                            <div class="project-action-buttons">
                            <?php $nextStep = architectProjectNextStep($project); ?>
                            <a href="<?php echo htmlspecialchars($nextStep['url']); ?>" class="btn-review">
                                <i class="fas fa-eye"></i> <?php echo htmlspecialchars($nextStep['label']); ?>
                            </a>
                            <a href="../version-control/repositories.php?project_id=<?php echo $project['id']; ?>" class="btn-secondary-action">
                                <i class="fas fa-code-branch"></i> Version Control
                            </a>
                            </div>
                            <div class="progress-meta">
                                <span>Features <strong><?php echo (int) $project['feature_count']; ?></strong></span>
                                <span>Tech <strong><?php echo (int) $project['tech_count']; ?></strong></span>
                                <span>Devs <strong><?php echo (int) $project['allocation_count']; ?></strong></span>
                                <span>Docs <strong><?php echo (int) $project['document_count']; ?></strong></span>
                                <span class="progress-step"><?php echo htmlspecialchars($nextStep['step']); ?></span>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                    <tr class="project-search-empty" id="projectDirectoryEmpty" hidden>
                        <td colspan="4">
                            <i class="fas fa-search" aria-hidden="true"></i>
                            No projects match the current filters.
                        </td>
                    </tr>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state-modern">
                <i class="fas fa-folder-open"></i>
                <h4>No Projects Found</h4>
                <p>No projects have been assigned for technical evaluation yet</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(() => {
    const searchInput = document.getElementById('projectDirectorySearch');
    const statusFilter = document.getElementById('projectStatusFilter');
    const countLabel = document.getElementById('projectDirectoryCount');
    const emptyRow = document.getElementById('projectDirectoryEmpty');
    const projectRows = Array.from(document.querySelectorAll('#projectDirectoryBody .project-directory-row'));

    if (!searchInput || !statusFilter || !countLabel || projectRows.length === 0) {
        return;
    }

    const availableStatuses = new Map();
    projectRows.forEach((row) => {
        const statusValue = row.dataset.status || '';
        const statusLabel = row.dataset.statusLabel || '';
        if (statusValue && statusLabel) {
            availableStatuses.set(statusValue, statusLabel);
        }
    });

    Array.from(availableStatuses.entries())
        .sort((first, second) => first[1].localeCompare(second[1]))
        .forEach(([value, label]) => {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = label;
            statusFilter.appendChild(option);
        });

    const updateDirectory = () => {
        const query = searchInput.value.trim().toLocaleLowerCase();
        const selectedStatus = statusFilter.value;
        let visibleCount = 0;

        projectRows.forEach((row) => {
            const matchesSearch = query === '' || row.textContent.toLocaleLowerCase().includes(query);
            const matchesStatus = selectedStatus === '' || row.dataset.status === selectedStatus;
            const matches = matchesSearch && matchesStatus;
            row.hidden = !matches;
            if (matches) {
                visibleCount += 1;
            }
        });

        const projectWord = visibleCount === 1 ? 'project' : 'projects';
        countLabel.textContent = query === '' && selectedStatus === ''
            ? `Showing ${visibleCount} ${projectWord}`
            : `${visibleCount} of ${projectRows.length} projects`;

        if (emptyRow) {
            emptyRow.hidden = visibleCount !== 0;
        }
    };

    searchInput.addEventListener('input', updateDirectory);
    statusFilter.addEventListener('change', updateDirectory);
    updateDirectory();
})();
</script>

<?php include '../../includes/footer.php'; ?>
