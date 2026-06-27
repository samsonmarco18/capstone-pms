<?php
require_once '../../includes/auth.php';
requireRole('architect');

$conn = getConnection();
$user = getCurrentUser();
$projectId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$hasSalesBudget = dbColumnExists($conn, 'projects', 'sales_budget');

$project = $conn->query("
    SELECT p.*, c.name AS client_name, c.contact_person, c.email AS client_email,
           c.phone AS client_phone, c.address AS client_address, c.industry AS client_industry
    FROM projects p
    JOIN clients c ON p.client_id = c.id
    WHERE p.id = $projectId
")->fetch_assoc();

if (!$project) {
    header('Location: projects.php');
    exit();
}

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

.card { transition: transform 0.2s ease, box-shadow 0.2s ease; }
.card:hover { transform: translateY(-2px); box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.08) !important; }

/* Breadcrumb Styles */
.breadcrumb-modern {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    padding: 0.75rem 1rem;
    margin-bottom: 1.5rem;
    background: white;
    border-radius: 12px;
    border: 1px solid var(--gray-200);
    box-shadow: var(--shadow-sm);
}

.breadcrumb-modern .breadcrumb-item {
    display: inline-flex;
    align-items: center;
    font-size: 0.85rem;
}

.breadcrumb-modern .breadcrumb-item a {
    color: var(--theme-primary);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.25rem 0.5rem;
    border-radius: 6px;
    transition: all 0.2s;
}

.breadcrumb-modern .breadcrumb-item a:hover {
    background: var(--gray-100);
    color: var(--theme-dark);
}

.breadcrumb-modern .breadcrumb-item.active {
    color: var(--gray-600);
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.breadcrumb-modern .breadcrumb-item + .breadcrumb-item::before {
    content: "›";
    font-size: 1.2rem;
    font-weight: 600;
    color: var(--gray-400);
    margin: 0 0.25rem;
}

/* Progress Indicator */
.progress-indicator {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1.5rem;
    background: white;
    padding: 1rem 1.5rem;
    border-radius: 16px;
    border: 1px solid var(--gray-200);
}

.progress-step {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
    flex: 1;
    text-decoration: none;
    transition: transform 0.2s ease;
}

a.progress-step:hover {
    text-decoration: none;
    transform: translateY(-2px);
}

.progress-step .step-number {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--gray-200);
    color: var(--gray-600);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 0.85rem;
}

.progress-step .step-label {
    font-size: 0.7rem;
    font-weight: 500;
    color: var(--gray-600);
    text-align: center;
}

.progress-step.completed .step-number {
    background: #10b981;
    color: white;
}

.progress-step.completed .step-number i {
    font-size: 0.75rem;
}

.progress-step.active .step-number {
    background: var(--theme-primary);
    color: white;
    box-shadow: 0 0 0 3px rgba(31, 99, 146, 0.2);
}

.progress-step.active .step-label {
    color: var(--theme-primary);
    font-weight: 600;
}

.progress-line {
    flex: 0.5;
    height: 2px;
    background: var(--gray-200);
}

.progress-line.completed {
    background: #10b981;
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

/* Info Cards */
.info-card {
    background: white;
    border-radius: 20px;
    border: 1px solid var(--gray-200);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    margin-bottom: 1.5rem;
}

.info-card .card-header {
    padding: 1rem 1.5rem;
    background: var(--gray-50);
    border-bottom: 1px solid var(--gray-200);
}

.info-card .card-header h5 {
    font-size: 0.95rem;
    font-weight: 600;
    color: var(--gray-900);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.info-card .card-header h5 i { color: var(--theme-primary); }

.info-card .card-body { padding: 1.25rem 1.5rem; }

.detail-row {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.5rem 0;
    border-bottom: 1px solid var(--gray-200);
    font-size: 0.9rem;
}

.detail-row:last-child { border-bottom: none; }
.detail-label { color: var(--gray-600); font-weight: 600; }
.detail-value { color: var(--gray-900); text-align: right; }

.section-title {
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--gray-900);
    margin-bottom: 0.75rem;
}

.content-box {
    background: var(--gray-50);
    border: 1px solid var(--gray-200);
    border-radius: 12px;
    padding: 1rem;
    font-size: 0.9rem;
    color: var(--gray-900);
    white-space: pre-line;
}

.btn-primary-modern {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-dark) 100%);
    color: white;
    border: none;
    padding: 0.7rem 1.5rem;
    border-radius: 40px;
    font-weight: 500;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    text-decoration: none;
}

.btn-primary-modern:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(31, 99, 146, 0.3);
    color: white;
}

/* Responsive */
@media (max-width: 768px) {
    .page-header-modern { padding: 1.5rem; }
    .breadcrumb-modern { padding: 0.5rem 0.75rem; }
    .progress-indicator { padding: 0.75rem; }
    .progress-step .step-label { font-size: 0.6rem; }
    .progress-step .step-number { width: 24px; height: 24px; font-size: 0.7rem; }
    .detail-row { flex-direction: column; align-items: flex-start; }
    .detail-value { text-align: left; }
    .btn-primary-modern { width: 100%; justify-content: center; }
}
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #page-content-wrapper {
    background: #06111e;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .info-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .feature-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .phase-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .upload-area {
    background: #071321 !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
    box-shadow: 0 16px 36px rgba(3, 14, 25, 0.26) !important;
    color: #d7e4f2 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card-header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .info-card .card-header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .bg-white,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .detail-row,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-section {
    background: #0a1b2c !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .detail-value,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) h6 {
    color: #d7e4f2 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .detail-label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .text-muted,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) small {
    color: #9db6cf !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-control,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-select,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) textarea {
    background: #071321 !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
    color: #d7e4f2 !important;
}
</style>

<div class="container-fluid px-4 py-4">
    <?php if(!$project): ?>
        <div class="alert alert-warning">Project not found.</div>
    <?php else: ?>
    <!-- Page Header -->
    <div class="page-header-modern">
        <div>
            <h2>
                <i class="fas fa-clipboard-check me-2"></i> 
                Project Review
            </h2>
            <p>Project: <?php echo htmlspecialchars($project['name']); ?></p>
        </div>
    </div>

    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="breadcrumb-modern">
        <ol class="breadcrumb-modern" style="display: flex; flex-wrap: wrap; align-items: center; margin: 0; padding: 0; list-style: none;">
            <li class="breadcrumb-item"><a href="projects.php"><i class="fas fa-folder-open"></i> Projects</a></li>
            <li class="breadcrumb-item active"><i class="fas fa-clipboard-check"></i> Project Review</li>
        </ol>
    </nav>

    <!-- Progress Indicator -->
    <div class="progress-indicator">
        <a class="progress-step active" href="project-review.php?id=<?php echo $projectId; ?>"><div class="step-number">1</div><div class="step-label">Project Review</div></a>
        <div class="progress-line"></div>
        <a class="progress-step" href="features.php?id=<?php echo $projectId; ?>"><div class="step-number">2</div><div class="step-label">Features</div></a>
        <div class="progress-line"></div>
        <a class="progress-step" href="tech-recommendation.php?id=<?php echo $projectId; ?>"><div class="step-number">3</div><div class="step-label">Technology</div></a>
        <div class="progress-line"></div>
        <a class="progress-step" href="developer-allocation.php?id=<?php echo $projectId; ?>"><div class="step-number">4</div><div class="step-label">Developers</div></a>
        <div class="progress-line"></div>
        <a class="progress-step" href="upload-diagram.php?id=<?php echo $projectId; ?>"><div class="step-number">5</div><div class="step-label">Documents</div></a>
        <div class="progress-line"></div>
        <a class="progress-step" href="summary.php?id=<?php echo $projectId; ?>"><div class="step-number">6</div><div class="step-label">Summary</div></a>
    </div>

    <div class="row g-4">
        <!-- Main Column -->
        <div class="col-lg-8">
            <div class="info-card">
                <div class="card-header">
                    <h5><i class="fas fa-info-circle"></i> Project Overview</h5>
                </div>
                <div class="card-body">
                    <div class="detail-row">
                        <div class="detail-label">Project Name</div>
                        <div class="detail-value"><?php echo htmlspecialchars($project['name']); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Complexity</div>
                        <div class="detail-value"><?php echo ucfirst($project['complexity_level']); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Timeline</div>
                        <div class="detail-value">
                            <?php
                            if($project['timeline_start'] && $project['timeline_end']) {
                                echo date('M d, Y', strtotime((string) $project['timeline_start'])) . ' - ' .
                                     date('M d, Y', strtotime((string) $project['timeline_end']));
                            } else {
                                echo 'Not set';
                            }
                            ?>
                        </div>
                    </div>
                    <?php if ($hasSalesBudget && !empty($project['sales_budget'])): ?>
                    <div class="detail-row">
                        <div class="detail-label">Budget</div>
                        <div class="detail-value"><?php echo number_format((float) $project['sales_budget'], 2); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="info-card">
                <div class="card-header">
                    <h5><i class="fas fa-align-left"></i> Description</h5>
                </div>
                <div class="card-body">
                    <div class="content-box">
                        <?php echo $project['description'] ? htmlspecialchars($project['description']) : 'No description provided.'; ?>
                    </div>
                </div>
            </div>

            <div class="info-card">
                <div class="card-header">
                    <h5><i class="fas fa-list-check"></i> Requested Features</h5>
                </div>
                <div class="card-body">
                    <div class="content-box">
                        <?php echo $project['requested_features'] ? htmlspecialchars($project['requested_features']) : 'No requested features provided.'; ?>
                    </div>
                </div>
            </div>

            <div class="info-card">
                <div class="card-header">
                    <h5><i class="fas fa-sticky-note"></i> Meeting Notes / MOM</h5>
                </div>
                <div class="card-body">
                    <div class="section-title">Meeting Notes</div>
                    <div class="content-box" style="margin-bottom: 1rem;">
                        <?php echo $project['meeting_notes'] ? htmlspecialchars($project['meeting_notes']) : 'No meeting notes provided.'; ?>
                    </div>
                    <div class="section-title">Minutes of Meeting</div>
                    <div class="content-box">
                        <?php echo $project['mom'] ? htmlspecialchars($project['mom']) : 'No MOM provided.'; ?>
                    </div>
                </div>
            </div>
            
            <!-- Button placed inside the main column, aligned to the right -->
            <div class="mt-4 text-end">
                <a class="btn-primary-modern" href="features.php?id=<?php echo $projectId; ?>">
                    <i class="fas fa-arrow-right"></i> Proceed to Features
                </a>
            </div>
        </div>

        <!-- Sidebar Column -->
        <div class="col-lg-4">
            <div class="info-card">
                <div class="card-header">
                    <h5><i class="fas fa-building"></i> Client Details</h5>
                </div>
                <div class="card-body">
                    <div class="detail-row">
                        <div class="detail-label">Client</div>
                        <div class="detail-value"><?php echo htmlspecialchars($project['client_name']); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Contact</div>
                        <div class="detail-value"><?php echo htmlspecialchars($project['contact_person'] ?? ''); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Email</div>
                        <div class="detail-value"><?php echo htmlspecialchars($project['client_email'] ?? ''); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Phone</div>
                        <div class="detail-value"><?php echo htmlspecialchars($project['client_phone'] ?? ''); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Industry</div>
                        <div class="detail-value"><?php echo htmlspecialchars($project['client_industry'] ?? ''); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Address</div>
                        <div class="detail-value"><?php echo htmlspecialchars($project['client_address'] ?? ''); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include '../../includes/footer.php'; ?>
