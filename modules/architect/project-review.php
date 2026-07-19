<?php
require_once '../../includes/auth.php';
require_once '../../includes/project-workflow.php';
require_once '../../includes/project-template.php';
requireRole('architect');

$conn = getConnection();
$user = getCurrentUser();
projectWorkflowEnsureSchema($conn);
projectTemplateEnsureSchema($conn);
$projectId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$hasSalesBudget = dbColumnExists($conn, 'projects', 'sales_budget');

function projectReviewParseRequestedFeatures(?string $rawFeatures): array
{
    $raw = trim((string) $rawFeatures);
    if ($raw === '') {
        return [];
    }

    $items = [];
    $seen = [];
    foreach ((preg_split('/\R/', $raw) ?: []) as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }

        if (preg_match('/^(additional requirements|budget)\s*:/i', $line)) {
            break;
        }

        $line = trim((string) preg_replace('/^\s*(?:[-*]|\d+[\.)])\s*/', '', $line));
        if ($line === '') {
            continue;
        }

        $normalized = strtolower(preg_replace('/\s+/', ' ', $line));
        if (isset($seen[$normalized])) {
            continue;
        }

        $seen[$normalized] = true;
        $items[] = $line;
    }

    return $items;
}

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

$requestedFeatureItems = projectReviewParseRequestedFeatures($project['requested_features'] ?? '');
$projectTemplate = projectTemplateResolveForProject($conn, $project);
$salesAttachments = [];
if (!empty($project['sales_attachments'])) {
    $decodedAttachments = json_decode((string) $project['sales_attachments'], true);
    if (is_array($decodedAttachments)) {
        $salesAttachments = $decodedAttachments;
    }
}
$additionalRequirementsText = '';
if (preg_match('/Additional Requirements:\s*(.*?)(?:\R{2,}Budget:|\z)/is', (string) ($project['requested_features'] ?? ''), $matches)) {
    $additionalRequirementsText = trim((string) ($matches[1] ?? ''));
}
$timelineDays = null;
if (!empty($project['timeline_start']) && !empty($project['timeline_end'])) {
    $start = strtotime((string) $project['timeline_start']);
    $end = strtotime((string) $project['timeline_end']);
    if ($start !== false && $end !== false && $end >= $start) {
        $timelineDays = (int) floor(($end - $start) / 86400) + 1;
    }
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

.sticky-project-panel {
    position: sticky;
    top: 1rem;
}

.sticky-project-panel.is-minimized .card-body {
    display: none;
}

.sticky-panel-toggle {
    border: 0;
    background: transparent;
    color: var(--theme-primary);
    font-weight: 700;
    font-size: 0.8rem;
}

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

.requested-feature-list {
    display: grid;
    gap: 0.65rem;
    margin: 0;
    padding: 0;
    list-style: none;
    white-space: normal;
}

.requested-feature-list li {
    display: flex;
    gap: 0.55rem;
    align-items: flex-start;
    padding: 0.7rem 0.8rem;
    background: #ffffff;
    border: 1px solid var(--gray-200);
    border-radius: 10px;
}

.requested-feature-list i {
    color: var(--theme-primary);
    margin-top: 0.18rem;
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

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .breadcrumb-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .progress-indicator {
    background: #071321 !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
    box-shadow: 0 16px 36px rgba(3, 14, 25, 0.26) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .breadcrumb-modern .breadcrumb-item a {
    color: #7cc0ee !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .breadcrumb-modern .breadcrumb-item a:hover {
    background: rgba(31, 99, 146, 0.16) !important;
    color: #ffffff !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .breadcrumb-modern .breadcrumb-item.active,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .breadcrumb-modern .breadcrumb-item::before {
    color: #9db6cf !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .detail-row,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .content-box,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .requested-feature-list li {
    background: #0a1b2c !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .section-title {
    color: #d7e4f2 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-control,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-select,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) textarea {
    background: #071321 !important;
    border-color: rgba(31, 99, 146, 0.24) !important;
    color: #d7e4f2 !important;
}

/* Project Overview: compact document-style summary. */
.project-overview-card {
    border-color: #d6e4ed;
    border-radius: 14px;
    box-shadow: 0 12px 28px rgba(15, 76, 129, 0.07);
}

.project-overview-card > .card-header {
    padding: 0.85rem 1.15rem;
    background: #f5f9fc;
}

.project-overview-card > .card-body {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1px;
    padding: 0 !important;
    background: #e1ebf1;
}

.project-overview-card .detail-row {
    display: grid;
    grid-template-columns: minmax(130px, 180px) minmax(0, 1fr);
    align-items: start;
    justify-content: initial;
    gap: 0.85rem;
    padding: 0.72rem 1.15rem;
    border: 0;
    background: #ffffff;
}

.project-overview-card .detail-label {
    color: #64788a;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.035em;
    line-height: 1.45;
    text-transform: uppercase;
}

.project-overview-card .detail-value {
    min-width: 0;
    color: #17324d;
    font-size: 0.84rem;
    font-weight: 500;
    line-height: 1.5;
    text-align: left;
    overflow-wrap: anywhere;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-overview-card .detail-row {
    background: #0a1b2c !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-overview-card > .card-body {
    background: rgba(138, 210, 255, 0.12) !important;
}

/* Requested Features: compact cards that use the available width efficiently. */
.requested-features-card {
    border-color: #d6e4ed;
    border-radius: 14px;
    box-shadow: 0 12px 28px rgba(15, 76, 129, 0.07);
}

.requested-features-card > .card-body {
    padding: 1rem !important;
    background: #f7fafc;
}

.requested-features-card .requested-features-content {
    padding: 0;
    border: 0;
    background: transparent;
}

.requested-features-card .requested-feature-list {
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 0.65rem;
}

.requested-features-card .requested-feature-list li {
    align-items: center;
    min-height: 46px;
    gap: 0.5rem;
    padding: 0.62rem 0.72rem;
    border-color: #dce7ef;
    border-radius: 9px;
    box-shadow: 0 4px 12px rgba(15, 76, 129, 0.035);
}

.requested-features-card .requested-feature-list i {
    flex: 0 0 auto;
    margin-top: 0;
    font-size: 0.78rem;
}

.requested-features-card .requested-feature-list span {
    min-width: 0;
    color: #2f465b;
    font-size: 0.8rem;
    font-weight: 500;
    line-height: 1.35;
    overflow-wrap: anywhere;
}

.requested-features-card .additional-requirements-panel {
    margin-top: 0.85rem;
    padding: 0.8rem 0.85rem;
    border: 1px solid #dce7ef;
    border-radius: 9px;
    background: #ffffff;
    color: #40586d;
    font-size: 0.82rem;
    line-height: 1.5;
}

.requested-features-card .additional-requirements-panel .section-title {
    margin: 0 0 0.35rem;
    color: #174769;
    font-size: 0.72rem;
    letter-spacing: 0.035em;
    text-transform: uppercase;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .requested-features-card > .card-body {
    background: #071321 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .requested-features-card .requested-features-content {
    border: 0 !important;
    background: transparent !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .requested-features-card .requested-feature-list span {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .requested-features-card .additional-requirements-panel {
    border-color: rgba(138, 210, 255, 0.15);
    background: #0a1b2c;
    color: #b8cadd;
}

/* Architect recommendations: structured review sheet with readable value rows. */
.architect-recommendations-card {
    border-color: #d6e4ed;
    border-radius: 14px;
    box-shadow: 0 12px 28px rgba(15, 76, 129, 0.07);
}

.architect-recommendations-card > .card-header {
    padding: 0.9rem 1.15rem;
    background: linear-gradient(135deg, #0d6786 0%, #0a5570 100%);
    border-bottom: 0;
}

.architect-recommendations-card > .card-header h5,
.architect-recommendations-card > .card-header h5 i {
    color: #ffffff;
}

.architect-recommendations-body {
    display: grid;
    gap: 0.9rem;
    padding: 1rem !important;
    background: #f7fafc;
}

.architect-review-section {
    overflow: hidden;
    border: 1px solid #dce7ef;
    border-radius: 10px;
    background: #ffffff;
}

.architect-review-section-title {
    display: flex;
    align-items: center;
    gap: 0.48rem;
    margin: 0;
    padding: 0.58rem 0.85rem;
    border-bottom: 1px solid #dce7ef;
    background: #eef6fb;
    color: #174769;
    font-size: 0.74rem;
    font-weight: 750;
    letter-spacing: 0.035em;
    text-transform: uppercase;
}

.architect-review-section-title i {
    color: #0d6786;
    font-size: 0.72rem;
}

.architect-review-rows {
    display: grid;
}

.architect-recommendations-card .detail-row {
    display: grid;
    grid-template-columns: minmax(190px, 230px) minmax(0, 1fr);
    align-items: start;
    justify-content: initial;
    gap: 1.25rem;
    padding: 0.7rem 0.85rem;
    border-bottom: 1px solid #e4edf3;
    background: #ffffff;
}

.architect-recommendations-card .detail-row:nth-child(even) {
    background: #ffffff;
}

.architect-recommendations-card .detail-row:last-child {
    border-bottom: 0;
}

.architect-recommendations-card .detail-label {
    color: #64788a;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.035em;
    line-height: 1.45;
    text-transform: uppercase;
}

.architect-recommendations-card .detail-value {
    min-width: 0;
    color: #17324d;
    font-size: 0.84rem;
    font-weight: 500;
    line-height: 1.55;
    text-align: left;
    overflow-wrap: anywhere;
    white-space: pre-line;
}

.architect-guidance {
    padding: 0.8rem;
    background: #ffffff;
}

.architect-recommendations-card .architect-guidance .content-box,
.architect-recommendations-card--empty .content-box {
    padding: 0.8rem 0.9rem;
    border-color: #dce7ef;
    border-radius: 8px;
    background: #f7fafc;
    color: #40586d;
    font-size: 0.82rem;
    line-height: 1.55;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .architect-recommendations-card > .card-header {
    background: linear-gradient(135deg, #0b5878 0%, #083f58 100%) !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .architect-recommendations-body {
    background: #071321 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .architect-review-section {
    border-color: rgba(138, 210, 255, 0.15);
    background: #0a1b2c;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .architect-review-section-title {
    border-bottom-color: rgba(138, 210, 255, 0.15);
    background: #0d2236;
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .architect-review-section-title i {
    color: #8fd3ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .architect-recommendations-card .detail-row {
    border-bottom-color: rgba(138, 210, 255, 0.12) !important;
    background: #0a1b2c !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .architect-recommendations-card .detail-row:nth-child(even) {
    background: #0b1f32 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .architect-recommendations-card .detail-value {
    color: #d7e4f2 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .architect-guidance {
    background: #0a1b2c;
}

@media (max-width: 768px) {
    .project-overview-card > .card-body {
        grid-template-columns: minmax(0, 1fr);
    }

    .project-overview-card .detail-row {
        grid-template-columns: minmax(0, 1fr);
        gap: 0.22rem;
        padding: 0.7rem 0.85rem;
    }

    .architect-recommendations-card .detail-row {
        grid-template-columns: minmax(0, 1fr);
        gap: 0.22rem;
        padding: 0.7rem 0.75rem;
    }

    .architect-recommendations-body {
        padding: 0.75rem !important;
    }
}

@media (max-width: 1199.98px) {
    .requested-features-card .requested-feature-list {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

@media (max-width: 991.98px) {
    .requested-features-card .requested-feature-list {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 575.98px) {
    .requested-features-card .requested-feature-list {
        grid-template-columns: minmax(0, 1fr);
    }

    .requested-features-card > .card-body {
        padding: 0.75rem !important;
    }
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
        <a class="progress-step" href="../roadmap/methodology.php?project_id=<?php echo $projectId; ?>"><div class="step-number">5</div><div class="step-label">Roadmap</div></a>
        <div class="progress-line"></div>
        <a class="progress-step" href="upload-diagram.php?id=<?php echo $projectId; ?>"><div class="step-number">6</div><div class="step-label">Documents</div></a>
        <div class="progress-line"></div>
        <a class="progress-step" href="summary.php?id=<?php echo $projectId; ?>"><div class="step-number">7</div><div class="step-label">Summary</div></a>
    </div>

    <div class="row g-4">
        <!-- Main Column -->
        <div class="col-12">
            <div class="info-card project-overview-card">
                <div class="card-header">
                    <h5><i class="fas fa-info-circle"></i> Project Overview</h5>
                </div>
                <div class="card-body">
                    <div class="detail-row">
                        <div class="detail-label">Project Name</div>
                        <div class="detail-value"><?php echo htmlspecialchars($project['name']); ?></div>
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
                    <div class="detail-row">
                        <div class="detail-label">Planning Basis</div>
                        <div class="detail-value">
                            <?php
                            $basisParts = [];
                            $basisParts[] = count($requestedFeatureItems) . ' requested feature' . (count($requestedFeatureItems) === 1 ? '' : 's');
                            if ($timelineDays !== null) {
                                $basisParts[] = $timelineDays . ' day' . ($timelineDays === 1 ? '' : 's');
                            }
                            echo htmlspecialchars(implode(' | ', $basisParts));
                            ?>
                        </div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Generated Complexity</div>
                        <div class="detail-value"><?php echo htmlspecialchars(ucwords(strtolower((string) ($project['complexity_level'] ?? 'Medium')))); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Priority</div>
                        <div class="detail-value"><?php echo htmlspecialchars(ucwords(strtolower((string) ($project['priority'] ?? 'medium')))); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Budget</div>
                        <div class="detail-value">
                            <?php
                            echo $hasSalesBudget && !empty($project['sales_budget'])
                                ? '₱' . number_format((float) $project['sales_budget'], 2)
                                : 'Not provided';
                            ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (is_array($projectTemplate)): ?>
            <div class="info-card architect-recommendations-card">
                <div class="card-header">
                    <h5><i class="fas fa-lightbulb"></i> Template-Based Architect Recommendations</h5>
                </div>
                <div class="card-body architect-recommendations-body">
                    <section class="architect-review-section" aria-labelledby="architectFoundationHeading">
                        <h6 class="architect-review-section-title" id="architectFoundationHeading">
                            <i class="fas fa-clipboard-list" aria-hidden="true"></i> Project Foundation
                        </h6>
                        <div class="architect-review-rows">
                            <div class="detail-row">
                                <div class="detail-label">Template Match</div>
                                <div class="detail-value"><?php echo htmlspecialchars((string) ($projectTemplate['project_type'] ?? 'Matched template')); ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Recommended Methodology</div>
                                <div class="detail-value"><?php echo htmlspecialchars((string) ($projectTemplate['methodology'] ?? 'Not specified')); ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Estimated Workload</div>
                                <div class="detail-value"><?php echo htmlspecialchars((string) ($projectTemplate['workload_estimate'] ?? ($projectTemplate['duration_label'] ?? 'Not specified'))); ?></div>
                            </div>
                        </div>
                    </section>

                    <section class="architect-review-section" aria-labelledby="architectDeliveryHeading">
                        <h6 class="architect-review-section-title" id="architectDeliveryHeading">
                            <i class="fas fa-users" aria-hidden="true"></i> Delivery Plan
                        </h6>
                        <div class="architect-review-rows">
                            <div class="detail-row">
                                <div class="detail-label">Developer Roles / Team</div>
                                <div class="detail-value"><?php echo htmlspecialchars((string) ($projectTemplate['team_composition'] ?? 'Not specified')); ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Milestones / Phases</div>
                                <div class="detail-value"><?php echo htmlspecialchars((string) ($projectTemplate['suggested_milestones'] ?? ($projectTemplate['default_project_phases'] ?? 'Not specified'))); ?></div>
                            </div>
                        </div>
                    </section>

                    <section class="architect-review-section" aria-labelledby="architectTechnicalHeading">
                        <h6 class="architect-review-section-title" id="architectTechnicalHeading">
                            <i class="fas fa-sitemap" aria-hidden="true"></i> Technical Architecture
                        </h6>
                        <div class="architect-review-rows">
                            <div class="detail-row">
                                <div class="detail-label">Technology Stack</div>
                                <div class="detail-value"><?php echo htmlspecialchars((string) ($projectTemplate['tech_stack'] ?? 'Not specified')); ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Infrastructure</div>
                                <div class="detail-value"><?php echo htmlspecialchars(trim((string) ($projectTemplate['hosting_platform'] ?? '') . ' | ' . (string) ($projectTemplate['deployment_environment'] ?? '') . ' | ' . (string) ($projectTemplate['database_technology'] ?? ''), ' |') ?: 'Not specified'); ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Authentication</div>
                                <div class="detail-value"><?php echo htmlspecialchars((string) ($projectTemplate['auth_method'] ?? 'Not specified')); ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Integrations</div>
                                <div class="detail-value"><?php echo htmlspecialchars((string) ($projectTemplate['integrations'] ?? 'Confirm during technical planning.')); ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Third-Party Services / APIs</div>
                                <div class="detail-value"><?php echo htmlspecialchars((string) ($projectTemplate['third_party_services'] ?? 'Confirm during technical planning.')); ?></div>
                            </div>
                        </div>
                    </section>

                    <section class="architect-review-section" aria-labelledby="architectQualityHeading">
                        <h6 class="architect-review-section-title" id="architectQualityHeading">
                            <i class="fas fa-shield-alt" aria-hidden="true"></i> Quality &amp; Operations
                        </h6>
                        <div class="architect-review-rows">
                            <div class="detail-row">
                                <div class="detail-label">Security Requirements</div>
                                <div class="detail-value"><?php echo htmlspecialchars((string) ($projectTemplate['security_requirements'] ?? 'Confirm during technical planning.')); ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Testing / Documentation</div>
                                <div class="detail-value"><?php echo htmlspecialchars(trim((string) ($projectTemplate['testing_requirements'] ?? '') . ' ' . (string) ($projectTemplate['documentation_requirements'] ?? '')) ?: 'Not specified'); ?></div>
                            </div>
                            <div class="detail-row">
                                <div class="detail-label">Backup / Support</div>
                                <div class="detail-value"><?php echo htmlspecialchars(trim((string) ($projectTemplate['backup_recovery_strategy'] ?? '') . ' ' . (string) ($projectTemplate['maintenance_support'] ?? '')) ?: 'Not specified'); ?></div>
                            </div>
                        </div>
                    </section>

                    <section class="architect-review-section" aria-labelledby="architectGuidanceHeading">
                        <h6 class="architect-review-section-title" id="architectGuidanceHeading">
                            <i class="fas fa-compass" aria-hidden="true"></i> Architect Guidance
                        </h6>
                        <div class="architect-guidance">
                            <div class="content-box">
                                <?php echo htmlspecialchars(trim((string) ($projectTemplate['architecture_notes'] ?? '') . "\n" . (string) ($projectTemplate['risk_assessment'] ?? '') . "\n" . (string) ($projectTemplate['implementation_notes'] ?? '')) ?: 'Use this template as a starting point and customize it according to client requirements.'); ?>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
            <?php else: ?>
            <div class="info-card architect-recommendations-card architect-recommendations-card--empty">
                <div class="card-header">
                    <h5><i class="fas fa-lightbulb"></i> Template-Based Architect Recommendations</h5>
                </div>
                <div class="card-body architect-recommendations-body">
                    <section class="architect-review-section" aria-labelledby="architectTemplateAvailabilityHeading">
                        <h6 class="architect-review-section-title" id="architectTemplateAvailabilityHeading">
                            <i class="fas fa-search" aria-hidden="true"></i> Template Availability
                        </h6>
                        <div class="architect-guidance">
                            <div class="content-box">
                                No matching project template was found. Complete the technical planning manually; once finalized, this project type can be learned as a new template.
                            </div>
                        </div>
                    </section>
                </div>
            </div>
            <?php endif; ?>

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

            <div class="info-card requested-features-card">
                <div class="card-header">
                    <h5><i class="fas fa-list-check"></i> Requested Features</h5>
                </div>
                <div class="card-body">
                    <div class="content-box requested-features-content">
                        <?php if (count($requestedFeatureItems) > 0): ?>
                            <ul class="requested-feature-list">
                                <?php foreach ($requestedFeatureItems as $featureItem): ?>
                                    <li>
                                        <i class="fas fa-check-circle"></i>
                                        <span><?php echo htmlspecialchars($featureItem); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            No requested features provided.
                        <?php endif; ?>
                        <?php if ($additionalRequirementsText !== ''): ?>
                            <div class="additional-requirements-panel">
                                <div class="section-title">Additional Requirements</div>
                                <div><?php echo htmlspecialchars($additionalRequirementsText); ?></div>
                            </div>
                        <?php endif; ?>
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

    </div>
    <?php endif; ?>
</div>

<?php include '../../includes/footer.php'; ?>
