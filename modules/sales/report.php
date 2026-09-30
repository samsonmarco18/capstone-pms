<?php
require_once '../../includes/auth.php';
require_once '../../includes/sales-billing.php';
require_once '../../includes/payment-proof.php';
requireRole('sales');

$conn = getConnection();
$user = getCurrentUser();
salesBillingEnsureSchema($conn);

// Get clients with projects
$clients = $conn->query("
    SELECT DISTINCT c.* 
    FROM clients c
    JOIN projects p ON c.id = p.client_id
    ORDER BY c.name
");

$selectedClient = (int) ($_GET['client_id'] ?? $_POST['client_id'] ?? 0);
$selectedProjectId = (int) ($_GET['project_id'] ?? $_POST['project_id'] ?? 0);
$reportData = null;
$technologies = null;
$developerCost = null;
$resources = null;
$otherFees = [];

if ($selectedClient) {
    // Get project for this client
    $project = $conn->query("
        SELECT * FROM projects 
        WHERE client_id = $selectedClient AND ($selectedProjectId = 0 OR id = $selectedProjectId)
        ORDER BY created_at DESC 
        LIMIT 1
    ")->fetch_assoc();
    
    if ($project) {
        // Get technology costs from architect recommendations
        $technologies = $conn->query("
            SELECT tr.*, t.name as tech_name, t.category 
            FROM tech_recommendations tr
            JOIN technologies t ON tr.tech_id = t.id
            WHERE tr.project_id = {$project['id']}
        ");
        
        // Use the same daily-rate pricing inputs as Expert Estimation.
        $pricing = projectEstimationProjectPricing($conn, (int) $project['id']);
        $roadmapDeveloperRows = [];
        foreach ($pricing['developers'] as $developer) {
            $roadmapDeveloperRows[] = [
                'role' => $developer['department'] . ' - ' . $developer['developer_type'],
                'required_count' => 1,
                'cost' => projectPricingCalculate([$developer], [])['development_cost'],
            ];
        }
        $developerCost = $pricing['development_cost'];
        $resourceCost = 0;
        
        $reportData = [
            'project' => $project,
            'technology_cost' => $pricing['technology_cost'],
            'developer_cost' => $developerCost,
            'resource_cost' => $resourceCost,
            'developer_rows' => $roadmapDeveloperRows,
        ];
    }
}

// Save the report and receipt atomically; only previously unbilled costs are charged.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_report'])) {
    try {
        paymentProofCheckCsrf($_POST['csrf_token'] ?? null);
        $fees = $_POST['other_fees_list'] ?? [];
        if (!is_array($fees)) throw new RuntimeException('Invalid billable additions.');
        $expectedBase = [];
        foreach (['technology_cost', 'developer_cost', 'resource_cost'] as $key) $expectedBase[$key] = salesBillingCents($_POST[$key] ?? 0);
        $created = salesBillingCreateReport($conn, (int) ($_POST['project_id'] ?? 0), (int) ($_POST['client_id'] ?? 0),
            (int) $user['id'], (string) ($_POST['billing_request_key'] ?? ''), $fees, $expectedBase);
        $_SESSION['success'] = 'Report and receipt created for new charges only. Previously billed costs were excluded.';
        header('Location: receipt.php?receipt_id=' . $created['receipt_id'] . '&report_id=' . $created['report_id']);
        exit;
    } catch (Throwable $e) {
        $reportError = $e instanceof RuntimeException ? $e->getMessage() : 'Unable to create the report. Please try again.';
    }
}
$billingBaseline = $reportData ? salesBillingHistory($conn, (int) $reportData['project']['id']) : salesBillingBaseline([]);
$billingRequestKey = bin2hex(random_bytes(32));
$previouslyBilled = (array_sum($billingBaseline['base']) + array_sum(array_column($billingBaseline['additions'], 'cents'))) / 100;
$billingFees = is_array($_POST['other_fees_list'] ?? null) ? array_values(array_filter($_POST['other_fees_list'], 'is_array')) : [];
if (!$billingFees) $billingFees = [['name' => '', 'cost' => '']];

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<style>
:root {
    --theme-primary: #1f6392;
    --theme-primary-dark: #0f4a6e;
    --theme-primary-light: #3a7ca3;
    --gray-50: #f8f9fa;
    --gray-100: #f1f3f5;
    --gray-200: #e9ecef;
    --gray-300: #dee2e6;
    --gray-600: #6c757d;
    --gray-900: #212529;
    --shadow-sm: 0 2px 4px rgba(0,0,0,0.02), 0 1px 2px rgba(0,0,0,0.03);
    --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03);
    --report-border: #dbe6ee;
    --report-surface-soft: #f6fafc;
    --report-text: #17324d;
}

.sales-report-shell {
    --report-card-surface: #ffffff;
    max-width: 1600px;
    margin: 0 auto;
}

/* Page Header */
.page-header-modern {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-primary-dark) 100%);
    border-radius: 18px;
    padding: 1.45rem 1.65rem;
    margin-bottom: 1.25rem;
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
    font-size: 1.55rem;
    font-weight: 700;
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

/* Client Sidebar Card */
.client-card {
    background: white;
    border-radius: 18px;
    border: 1px solid var(--report-border);
    overflow: hidden;
    box-shadow: 0 10px 28px rgba(31, 99, 146, 0.08);
    position: sticky;
    top: 1rem;
}

.client-card .card-header {
    padding: 1rem 1.1rem;
    background: white;
    border-bottom: 1px solid var(--gray-200);
}

.client-card .card-header h5 {
    font-size: 1rem;
    font-weight: 600;
    color: var(--gray-900);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.client-card .card-header h5 i {
    color: var(--theme-primary);
}

.client-list {
    padding: 0.5rem;
    max-height: calc(100vh - 15rem);
    min-height: 13rem;
    overflow-y: auto;
    overscroll-behavior: contain;
    scrollbar-color: rgba(31, 99, 146, 0.28) transparent;
    scrollbar-width: thin;
}

.client-list::-webkit-scrollbar {
    width: 6px;
}

.client-list::-webkit-scrollbar-track {
    background: transparent;
}

.client-list::-webkit-scrollbar-thumb {
    border-radius: 999px;
    background: rgba(31, 99, 146, 0.24);
}

.client-item {
    display: flex;
    align-items: center;
    gap: 0.7rem;
    min-width: 0;
    padding: 0.65rem 0.75rem;
    margin: 0.25rem 0;
    border: 1px solid transparent;
    border-radius: 10px;
    color: #37526b;
    text-decoration: none;
    transition: background-color 0.18s ease, border-color 0.18s ease, color 0.18s ease;
}

.client-item__icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    flex: 0 0 2rem;
    border-radius: 9px;
    background: #edf6fb;
    color: var(--theme-primary);
}

.client-item__name {
    overflow: hidden;
    font-size: 0.9rem;
    font-weight: 600;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.client-item:hover {
    border-color: #d8e8f1;
    background: #f6fafc;
    color: var(--theme-primary);
}

.client-item.active {
    border-color: #c7dfec;
    background: linear-gradient(135deg, #edf7fc 0%, #e3f1f8 100%);
    color: var(--theme-primary);
}

.client-item.active .client-item__icon {
    background: var(--theme-primary);
    color: #fff;
}

/* Main Content Cards */
.report-card {
    background: white;
    border-radius: 16px;
    border: 1px solid var(--report-border);
    overflow: hidden;
    margin-bottom: 1rem;
    box-shadow: 0 8px 24px rgba(31, 99, 146, 0.06);
}

#page-content-wrapper .sales-report-shell .client-card.app-3d-surface,
#page-content-wrapper .sales-report-shell .report-card.app-3d-surface {
    border-color: var(--report-border) !important;
    border-radius: 16px;
    background: var(--report-card-surface) !important;
    box-shadow: 0 8px 24px rgba(31, 99, 146, 0.07) !important;
    transform: none !important;
}

#page-content-wrapper .sales-report-shell .client-card.app-3d-surface:hover,
#page-content-wrapper .sales-report-shell .report-card.app-3d-surface:hover {
    border-color: #cadde8 !important;
    box-shadow: 0 10px 28px rgba(31, 99, 146, 0.09) !important;
    transform: none !important;
}

#page-content-wrapper .sales-report-shell .report-card .table-responsive {
    box-shadow: none;
}

.report-card .card-header {
    padding: 0.95rem 1.1rem 0.45rem;
    background: white;
    border-bottom: 0;
}

.report-card .card-header h5 {
    font-size: 0.92rem;
    font-weight: 700;
    color: var(--report-text);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.report-card .card-header h5 i {
    color: var(--theme-primary);
}

.report-card .card-body {
    padding: 0.75rem 1.1rem 1.1rem;
}

.report-cost-grid,
.report-support-grid {
    display: grid;
    gap: 1rem;
    margin-bottom: 1rem;
}

.report-cost-grid > .report-card,
.report-support-grid > .report-card {
    min-width: 0;
    margin-bottom: 0;
}

@media (min-width: 1400px) {
    .report-cost-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .report-support-grid {
        grid-template-columns: 1fr;
        align-items: start;
    }
}

.project-info-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.75rem;
}

.project-info-item {
    min-width: 0;
    padding: 0.8rem 0.9rem;
    border: 1px solid #e5edf2;
    border-radius: 11px;
    background: var(--report-surface-soft);
}

.project-info-item--wide {
    grid-column: 1 / -1;
}

.project-info-item small {
    margin-bottom: 0.25rem;
    color: #71859a !important;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

.project-info-item strong,
.project-info-item p {
    color: var(--report-text);
    font-size: 0.92rem;
    line-height: 1.5;
}

/* Tables */
.report-card .table-responsive {
    border: 1px solid #e3ebf0;
    border-radius: 12px;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table thead {
    background: var(--gray-50);
}

.data-table th {
    padding: 0.7rem 0.85rem;
    text-align: left;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--gray-600);
    border-bottom: 1px solid var(--gray-200);
}

.data-table td {
    padding: 0.72rem 0.85rem;
    border-bottom: 1px solid var(--gray-200);
    color: #37526b;
    font-size: 0.88rem;
}

.data-table tr:last-child td {
    border-bottom: none;
}

.data-table tr.total-row {
    background: #f1f7fa;
    font-weight: 600;
    color: var(--report-text);
}

/* Summary Table */
.summary-table {
    width: 100%;
    border-collapse: collapse;
}

.summary-table td {
    padding: 0.72rem 0.85rem;
    border-bottom: 1px solid var(--gray-200);
    color: #37526b;
    font-size: 0.9rem;
}

.summary-table tr:last-child td {
    border-bottom: none;
}

.summary-table .total-row {
    background: #eaf5fa;
    font-weight: 700;
    color: var(--theme-primary);
}

.summary-wrap {
    overflow: hidden;
    border: 1px solid #e3ebf0;
    border-radius: 12px;
}

.report-resource-summary {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    margin: 0;
    padding: 0.85rem 1rem !important;
    border: 1px solid #dceaf2 !important;
    border-radius: 11px !important;
    background: #f2f8fb !important;
    color: #37526b;
}

.other-fee-item {
    align-items: center;
    margin: 0 0 0.65rem !important;
    padding: 0;
    border: 0;
    border-radius: 0;
    background: transparent;
}

.other-fee-item .form-control {
    min-height: 42px;
    border-color: #d3e1ea;
    border-radius: 9px !important;
    color: var(--report-text);
    font-size: 0.88rem;
    box-shadow: none;
}

.other-fee-item .form-control:focus {
    border-color: rgba(31, 99, 146, 0.58);
    box-shadow: 0 0 0 3px rgba(31, 99, 146, 0.1);
}

.other-fees-note {
    display: flex !important;
    align-items: center;
    gap: 0.4rem;
    margin-top: 0.75rem !important;
    color: #71859a !important;
    font-size: 0.78rem;
}

/* Buttons */
.btn-save {
    min-height: 44px;
    background: linear-gradient(135deg, #1f6392 0%, #155579 100%);
    color: white;
    border: none;
    padding: 0.7rem 1.2rem;
    border-radius: 10px;
    font-size: 0.88rem;
    font-weight: 700;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.btn-save:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 18px rgba(31, 99, 146, 0.24);
    color: white;
}

.btn-secondary-modern {
    min-height: 40px;
    background: #fff;
    color: var(--theme-primary);
    border: 1px solid #bcd5e4;
    padding: 0.55rem 0.85rem;
    border-radius: 9px;
    font-size: 0.8rem;
    font-weight: 700;
    transition: all 0.2s;
}

.btn-secondary-modern:hover {
    transform: translateY(-1px);
    border-color: var(--theme-primary);
    background: #edf6fb;
    color: var(--theme-primary-dark);
}

.btn-danger-modern {
    width: 100%;
    min-height: 42px;
    background: #fff7f7;
    color: #dc3545;
    border: 1px solid #f1b8be;
    padding: 0.5rem 0.75rem;
    border-radius: 9px;
    font-size: 0.78rem;
    font-weight: 700;
    transition: all 0.2s;
}

.btn-danger-modern:hover {
    transform: translateY(-1px);
    border-color: #dc3545;
    background: #fff0f1;
    color: #bd2635;
}

.report-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 0.65rem;
    margin-top: 1rem;
    padding: 0.9rem;
    border: 1px solid var(--report-border);
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.92);
    box-shadow: 0 8px 24px rgba(31, 99, 146, 0.06);
}

.report-actions [data-generic-save-draft] {
    min-height: 44px;
    margin-left: 0 !important;
    padding: 0.65rem 1rem;
    border-color: #bcd5e4;
    border-radius: 10px;
    color: var(--theme-primary);
    font-size: 0.86rem;
    font-weight: 700;
}

.report-actions [data-generic-draft-status] {
    margin-left: 0 !important;
}

/* Consolidated report surface */
.report-layout {
    display: grid;
    gap: 1rem;
}

.client-selector-wrap,
.report-content {
    min-width: 0;
}

.client-selector-wrap {
    display: flex;
    justify-content: flex-end;
    padding: 0.7rem 1.25rem;
    border-bottom: 1px solid var(--report-border);
    background: #f8fbfd;
}

.report-client-filter {
    display: flex;
    align-items: center;
    gap: 0.85rem;
    width: min(100%, 32rem);
    padding: 0;
}

.report-client-filter__label {
    display: flex;
    align-items: center;
    gap: 0.7rem;
    margin: 0;
    color: var(--report-text);
}

.report-client-filter__label small,
.report-client-filter__label strong {
    display: block;
}

.report-client-filter__label small {
    margin-bottom: 0.1rem;
    color: #7a8da0;
    font-size: 0.66rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.report-client-filter__label strong {
    font-size: 0.88rem;
    font-weight: 600;
}

.report-client-filter__icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    border-radius: 9px;
    background: #eaf5fa;
    color: var(--theme-primary);
}

.report-client-filter__control {
    position: relative;
    flex: 1;
    min-width: 0;
}

.report-client-filter__control .form-select {
    min-height: 42px;
    padding: 0.55rem 2.2rem 0.55rem 0.85rem;
    border: 0;
    border-bottom: 1px solid #bfd3df;
    border-radius: 0;
    background-color: transparent;
    background-image: none;
    color: var(--report-text);
    font-size: 0.86rem;
    font-weight: 500;
    line-height: 1.4;
    box-shadow: none;
}

.report-client-filter__control .form-select option {
    font-weight: 400;
}

.report-client-filter__control .form-select:focus {
    border-color: var(--theme-primary);
    box-shadow: 0 2px 0 rgba(31, 99, 146, 0.14);
}

.report-client-filter__control > i {
    position: absolute;
    top: 50%;
    right: 0.3rem;
    color: #6f8799;
    font-size: 0.75rem;
    pointer-events: none;
    transform: translateY(-50%);
}

#page-content-wrapper .sales-report-shell .client-selector-bar.app-3d-surface {
    position: static;
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    align-items: center;
    border-radius: 16px;
    background: var(--report-card-surface) !important;
    transform: none !important;
}

#page-content-wrapper .sales-report-shell .client-selector-bar.app-3d-surface:hover {
    transform: none !important;
}

.client-selector-bar .card-header {
    align-self: stretch;
    display: flex;
    align-items: center;
    padding: 0.8rem 1rem;
    border: 0;
    border-right: 1px solid var(--report-border);
    white-space: nowrap;
}

.client-selector-bar .client-list {
    display: flex;
    gap: 0.45rem;
    min-height: 0;
    max-height: none;
    padding: 0.55rem 0.75rem;
    overflow-x: auto;
    overflow-y: hidden;
}

.client-selector-bar .client-item {
    flex: 0 0 auto;
    max-width: 15rem;
    margin: 0;
    padding: 0.5rem 0.7rem;
}

.client-selector-bar .client-item__icon {
    width: 1.8rem;
    height: 1.8rem;
    flex-basis: 1.8rem;
}

.report-workspace {
    display: flex;
    flex-direction: column;
    overflow: hidden;
    border: 1px solid var(--report-border);
    border-radius: 18px;
    background: var(--report-card-surface);
    box-shadow: 0 10px 30px rgba(31, 99, 146, 0.08);
}

.report-form {
    display: flex;
    flex-direction: column;
}

.report-workspace .report-card--project {
    order: 1;
}

.report-workspace .report-card--summary {
    order: 2;
}

.report-workspace .report-breakdown {
    order: 3;
}

.report-workspace .report-support-grid {
    order: 4;
}

.report-workspace .report-actions {
    order: 5;
}

#page-content-wrapper .sales-report-shell .report-workspace .report-card.app-3d-surface,
#page-content-wrapper .sales-report-shell .report-workspace .report-card.app-3d-surface:hover {
    margin: 0;
    border: 0 !important;
    border-radius: 0;
    background: transparent !important;
    box-shadow: none !important;
    transform: none !important;
}

.report-workspace .report-card .card-header {
    padding: 1rem 1.25rem 0.35rem;
    background: transparent;
}

.report-workspace .report-card .card-body {
    padding: 0.65rem 1.25rem 1.1rem;
}

.report-overview {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem 2rem;
}

.report-overview__kicker {
    display: block;
    margin-bottom: 0.18rem;
    color: #72869a;
    font-size: 0.67rem;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.report-overview h3 {
    margin: 0;
    color: var(--report-text);
    font-size: 1.15rem;
    font-weight: 600;
}

.report-overview__timeline {
    flex: 0 0 auto;
    text-align: right;
}

.report-overview__timeline small,
.report-overview__timeline strong {
    display: block;
}

.report-overview__timeline small {
    margin-bottom: 0.18rem;
    color: #72869a;
    font-size: 0.67rem;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.report-overview__timeline strong {
    color: var(--report-text);
    font-size: 0.84rem;
    font-weight: 600;
}

.report-overview__description {
    margin: 0.75rem 0 0;
    padding-top: 0.65rem;
    border-top: 1px solid #e6edf2;
    color: #536b82;
    font-size: 0.84rem;
    line-height: 1.55;
}

.report-overview__description-label {
    margin-right: 0.45rem;
    color: #72869a;
    font-size: 0.67rem;
    font-weight: 600;
    letter-spacing: 0.075em;
    text-transform: uppercase;
}

.report-breakdown {
    border-top: 1px solid var(--report-border);
}

.report-breakdown > summary {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.95rem 1.25rem;
    color: var(--report-text);
    cursor: pointer;
    list-style: none;
}

.report-breakdown > summary::-webkit-details-marker {
    display: none;
}

.report-breakdown__icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2.1rem;
    height: 2.1rem;
    flex: 0 0 2.1rem;
    border-radius: 9px;
    background: #edf6fb;
    color: var(--theme-primary);
}

.report-breakdown__copy {
    min-width: 0;
}

.report-breakdown__copy strong,
.report-breakdown__copy small {
    display: block;
}

.report-breakdown__copy strong {
    font-size: 0.9rem;
    font-weight: 600;
}

.report-breakdown__copy small {
    margin-top: 0.1rem;
    color: #71859a;
    font-size: 0.74rem;
}

.report-breakdown__chevron {
    margin-left: auto;
    color: #71859a;
    transition: transform 0.18s ease;
}

.report-breakdown[open] .report-breakdown__chevron {
    transform: rotate(180deg);
}

.report-breakdown .report-cost-grid {
    gap: 1.75rem;
    padding: 0 1.25rem 1rem;
    border-top: 1px solid var(--report-border);
}

.report-breakdown .report-card .card-header {
    padding: 0.85rem 0 0.3rem;
    background: transparent !important;
}

.report-breakdown .report-card .card-body {
    padding: 0.35rem 0 0;
}

#page-content-wrapper .sales-report-shell .report-breakdown .report-card .table-responsive {
    overflow-x: auto;
    overflow-y: hidden;
    border: 0 !important;
    border-radius: 0 !important;
    box-shadow: none;
}

.report-breakdown .data-table thead {
    background: transparent;
}

.report-breakdown .data-table th {
    padding: 0.55rem 0;
    border-bottom-color: #dfe8ee;
    font-size: 0.66rem;
}

.report-breakdown .data-table td {
    padding: 0.62rem 0;
    border-bottom-color: #e8eef2;
    font-size: 0.84rem;
}

.report-breakdown .data-table th + th,
.report-breakdown .data-table td + td {
    padding-left: 1rem;
}

.report-breakdown .data-table tr.total-row {
    border-top: 1px solid #d5e1e8;
    background: transparent;
}

.report-breakdown .data-table tr.total-row td {
    padding-top: 0.7rem;
    padding-bottom: 0.15rem;
    border-bottom: 0;
}

.report-breakdown__resource {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.75rem 1.25rem;
    border-top: 1px solid var(--report-border);
    color: #536b82;
    font-size: 0.82rem;
}

.report-breakdown__resource span {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
}

.report-breakdown__resource i {
    color: var(--theme-primary);
}

.report-breakdown__resource strong {
    color: var(--report-text);
    font-weight: 600;
}

.report-workspace .report-cost-grid {
    position: relative;
    gap: 2rem;
    margin: 0;
    border-top: 1px solid var(--report-border);
}

@media (min-width: 1400px) {
    .report-workspace .report-cost-grid::before {
        content: "";
        position: absolute;
        top: 0.75rem;
        bottom: 0.75rem;
        left: 50%;
        width: 1px;
        background: var(--report-border);
        pointer-events: none;
        transform: translateX(-50%);
    }
}

.report-workspace .report-support-grid {
    gap: 0;
    margin: 0;
    border-top: 1px solid var(--report-border);
}

#page-content-wrapper .sales-report-shell .report-workspace .report-card--summary.app-3d-surface {
    border-top: 1px solid var(--report-border) !important;
}

.report-workspace .report-card--summary .card-body {
    padding-top: 0.8rem;
}

.report-summary-title {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    margin-bottom: 0.75rem;
}

.report-summary-title__icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    flex: 0 0 2rem;
    border-radius: 9px;
    background: #eaf5fa;
    color: var(--theme-primary);
}

.report-summary-title strong,
.report-summary-title small {
    display: block;
}

.report-summary-title strong {
    color: var(--report-text);
    font-size: 0.9rem;
    font-weight: 600;
}

.report-summary-title small {
    margin-top: 0.08rem;
    color: #71859a;
    font-size: 0.72rem;
}

.report-workspace .summary-wrap {
    overflow: visible;
    border: 0;
}

.report-workspace .summary-table {
    display: block;
}

.report-workspace .summary-table tbody {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 0.7rem;
}

.report-workspace .summary-table tr {
    display: flex;
    min-width: 0;
    min-height: 4.8rem;
    flex-direction: column;
    justify-content: space-between;
    padding: 0.7rem 0.8rem;
    border: 1px solid #e1eaf0;
    border-radius: 11px;
    background: #f7fafc;
}

.report-workspace .summary-table td {
    display: block;
    padding: 0;
    border: 0;
}

.report-workspace .summary-table td:first-child {
    color: #6f8295;
    font-size: 0.69rem;
    font-weight: 600;
    letter-spacing: 0.055em;
    text-transform: uppercase;
}

.report-workspace .summary-table td:last-child {
    margin-top: 0.5rem;
    color: var(--report-text);
    font-size: 1rem;
    font-weight: 700;
    text-align: left !important;
}

.report-workspace .summary-table .total-row {
    border-color: #1f6392;
    background: linear-gradient(135deg, #1f6392 0%, #155579 100%);
}

.report-workspace .summary-table .total-row td {
    color: #fff;
}

.report-fees {
    order: 4 !important;
}

.report-fees__body {
    border-top: 1px solid var(--report-border);
}

.report-fees .report-card .card-body {
    padding: 0.9rem 1.25rem 1rem;
}

.report-workspace .report-actions {
    margin: 0;
    padding: 0.85rem 1.25rem;
    border: 0;
    border-top: 1px solid var(--report-border);
    border-radius: 0;
    background: #f7fafc;
    box-shadow: none;
}

/* Alert */
.alert-modern {
    background: var(--gray-50);
    border-radius: 16px;
    padding: 1.5rem;
    text-align: center;
    border: 1px solid var(--gray-200);
}

.alert-modern i {
    font-size: 2.5rem;
    color: var(--gray-400);
    margin-bottom: 0.75rem;
}

.alert-modern h4 {
    font-size: 1rem;
    color: var(--gray-600);
    margin-bottom: 0.5rem;
}

.alert-modern p {
    font-size: 0.85rem;
    color: var(--gray-500);
    margin: 0;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #page-content-wrapper {
    background: #06111e;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .sales-report-shell {
    --report-card-surface: #071321;
    --report-border: rgba(31, 99, 146, 0.24);
    --report-surface-soft: #0a1b2c;
    --report-text: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .report-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .alert-modern {
    background: #071321;
    border-color: rgba(31, 99, 146, 0.24);
    box-shadow: 0 16px 36px rgba(3, 14, 25, 0.26);
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern {
    border: 1px solid rgba(31, 99, 146, 0.18);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern::before {
    background: linear-gradient(90deg, transparent, rgba(31, 99, 146, 0.12));
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-card .card-header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .report-card .card-header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .data-table thead,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .data-table tr.total-row,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .summary-table .total-row,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-item:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-item.active,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .alert.alert-info {
    background: #0a1b2c !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-card .card-header h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .report-card .card-header h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .alert-modern h4,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .report-card strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .report-card p,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .summary-table td {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-item,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .data-table th,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .text-muted,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .alert-modern i,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .alert-modern p {
    color: #9db6cf !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-card .card-header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .report-card,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .report-card .card-header,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .data-table th,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .data-table td,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .summary-table td,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .alert-modern {
    border-color: rgba(31, 99, 146, 0.24);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-control,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-select {
    background: #071321;
    border-color: rgba(31, 99, 146, 0.24);
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-control:focus,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-select:focus {
    background: #0a1b2c;
    border-color: rgba(31, 99, 146, 0.45);
    color: #d7e4f2;
    box-shadow: 0 0 0 3px rgba(31, 99, 146, 0.12);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .form-control::placeholder {
    color: rgba(157, 182, 207, 0.65);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-item__icon,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-info-item,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .report-resource-summary,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .report-actions {
    border-color: rgba(138, 210, 255, 0.12) !important;
    background: #0a1b2c !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-item.active .client-item__icon {
    background: #1f6392 !important;
    color: #fff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-info-item strong,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .project-info-item p,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .report-resource-summary,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .data-table td {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .report-card .table-responsive,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .summary-wrap {
    border-color: rgba(138, 210, 255, 0.12);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .data-table tr.total-row,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .summary-table .total-row {
    background: rgba(31, 99, 146, 0.18);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-secondary-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .report-actions [data-generic-save-draft] {
    border-color: rgba(140, 199, 255, 0.3);
    background: rgba(31, 99, 146, 0.12);
    color: #8cc7ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-danger-modern {
    border-color: rgba(244, 114, 125, 0.34);
    background: rgba(220, 53, 69, 0.12);
    color: #ff9ca6;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .report-client-filter {
    background: transparent;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-selector-wrap {
    border-color: rgba(138, 210, 255, 0.12);
    background: #081725;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .report-client-filter__icon,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .report-breakdown__icon,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .report-summary-title__icon {
    background: rgba(31, 99, 146, 0.2);
    color: #8cc7ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .report-workspace .project-info-item--wide,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .report-workspace .summary-table tr,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .report-overview__description,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .report-breakdown__resource {
    border-color: rgba(138, 210, 255, 0.12);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .report-workspace .summary-table tr {
    background: #0a1b2c;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .report-workspace .summary-table td:first-child,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .report-breakdown__copy small,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .report-overview__description-label {
    color: #90aeca;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .report-breakdown__resource,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .report-overview__description {
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .report-breakdown__resource strong {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .report-breakdown .data-table tr.total-row {
    background: transparent;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .report-workspace .summary-table .total-row {
    border-color: #2f83b9;
    background: linear-gradient(135deg, #1f6392 0%, #155579 100%);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .report-workspace .summary-table .total-row td {
    color: #fff;
}

/* Responsive */
@media (max-width: 991.98px) {
    .page-header-modern {
        padding: 1.5rem;
    }
    
    .client-card {
        margin-bottom: 0;
        position: static;
    }

    #page-content-wrapper .sales-report-shell .client-selector-bar.app-3d-surface {
        grid-template-columns: 1fr;
    }

    .client-selector-bar .card-header {
        border-right: 0;
        border-bottom: 1px solid var(--report-border);
    }

    .report-workspace .summary-table tbody {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 575.98px) {
    .sales-report-shell {
        padding-right: 0.8rem !important;
        padding-left: 0.8rem !important;
    }

    .client-selector-wrap {
        padding-right: 0.85rem;
        padding-left: 0.85rem;
    }

    .page-header-modern {
        padding: 1.2rem;
    }

    .page-header-modern h2 {
        font-size: 1.3rem;
    }

    .report-client-filter {
        align-items: stretch;
        flex-direction: column;
        gap: 0.65rem;
        width: 100%;
    }

    .report-overview {
        flex-direction: column;
    }

    .report-overview__timeline {
        text-align: left;
    }

    .report-workspace .summary-table tbody {
        grid-template-columns: 1fr;
    }

    .report-card .card-body {
        padding-right: 0.85rem;
        padding-left: 0.85rem;
    }

    .report-actions {
        align-items: stretch;
        flex-direction: column;
    }

    .report-actions .btn-save,
    .report-actions [data-generic-save-draft] {
        width: 100%;
    }
}
</style>

<div class="container-fluid px-4 py-4 sales-report-shell">
    <!-- Page Header -->
    <div class="page-header-modern">
        <div>
            <h2>
                <i class="fas fa-file-invoice-dollar me-2"></i> 
                Sales Report
            </h2>
            <p>Generate detailed cost reports and receipts for clients</p>
        </div>
    </div>

    <div class="report-layout">
        <div class="report-workspace">
        <!-- Client Selector -->
        <div class="client-selector-wrap">
            <form method="GET" class="report-client-filter">
                <label for="reportClientSelect" class="report-client-filter__label">
                    <span class="report-client-filter__icon" aria-hidden="true"><i class="fas fa-building"></i></span>
                    <strong>Client</strong>
                </label>
                <div class="report-client-filter__control">
                    <select name="client_id" id="reportClientSelect" class="form-select" onchange="this.form.submit()" <?php echo $clients->num_rows === 0 ? 'disabled' : ''; ?>>
                        <option value="">Choose a client</option>
                        <?php if($clients->num_rows > 0): ?>
                            <?php while($client = $clients->fetch_assoc()): ?>
                                <option value="<?php echo $client['id']; ?>" <?php echo $selectedClient == $client['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($client['name']); ?>
                                </option>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </select>
                    <i class="fas fa-chevron-down" aria-hidden="true"></i>
                </div>
                <noscript><button type="submit" class="btn-secondary-modern">View Report</button></noscript>
            </form>
        </div>
        
        <!-- Main Content -->
        <div class="report-content">
            <?php if($reportData && $technologies): ?>
            <form method="POST" id="reportForm" class="report-form" data-generic-draft-disabled="true" data-app-loading data-loading-title="Creating receipt" data-loading-text="Checking billing history and generating your report and receipt. Please wait.">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(paymentProofCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="billing_request_key" value="<?php echo htmlspecialchars($billingRequestKey, ENT_QUOTES, 'UTF-8'); ?>">
                <?php if (!empty($reportError)): ?><div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($reportError); ?></div><?php endif; ?>
                <div class="alert alert-info">Previously billed project costs: <strong>₱<?php echo number_format($previouslyBilled, 2); ?></strong>. This report charges only new project costs and billable additions. Existing paid or unpaid charges will not be billed again.
                    <?php if ($billingBaseline['additions']): ?><ul class="mb-0 mt-2"><?php foreach ($billingBaseline['additions'] as $billedAddition): ?><li><?php echo htmlspecialchars($billedAddition['name']); ?>: ₱<?php echo number_format($billedAddition['cents'] / 100, 2); ?> already billed</li><?php endforeach; ?></ul><?php endif; ?>
                </div>
                <input type="hidden" name="project_id" value="<?php echo $reportData['project']['id']; ?>">
                <input type="hidden" name="client_id" value="<?php echo $selectedClient; ?>">
                
                <!-- Project Information -->
                <div class="report-card report-card--project">
                    <div class="card-body">
                        <div class="report-overview">
                            <div>
                                <span class="report-overview__kicker">Project Report</span>
                                <h3><?php echo htmlspecialchars($reportData['project']['name']); ?></h3>
                            </div>
                            <div class="report-overview__timeline">
                                <small>Timeline</small>
                                <strong><?php echo date('M d, Y', strtotime($reportData['project']['timeline_start'])); ?> – <?php echo date('M d, Y', strtotime($reportData['project']['timeline_end'])); ?></strong>
                            </div>
                        </div>
                        <p class="report-overview__description"><span class="report-overview__description-label">Description</span><?php echo htmlspecialchars($reportData['project']['description']); ?></p>
                    </div>
                </div>
                
                <details class="report-breakdown">
                    <summary>
                        <span class="report-breakdown__icon" aria-hidden="true"><i class="fas fa-receipt"></i></span>
                        <span class="report-breakdown__copy">
                            <strong>Cost Breakdown</strong>
                            <small>View technology and developer cost details</small>
                        </span>
                        <i class="fas fa-chevron-down report-breakdown__chevron" aria-hidden="true"></i>
                    </summary>
                <div class="report-cost-grid">
                <!-- Technology Costs -->
                <div class="report-card">
                    <div class="card-header">
                        <h5>
                            <i class="fas fa-microchip"></i>
                            Technology Costs
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Technology</th>
                                        <th>Category</th>
                                        <th class="text-end">Monthly Cost</th>
                                    </thead>
                                <tbody>
                                    <?php 
                                    $techTotal = $reportData['technology_cost'];
                                    $technologies->data_seek(0);
                                    while($tech = $technologies->fetch_assoc()): 
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($tech['tech_name']); ?></td>
                                        <td><?php echo htmlspecialchars($tech['category']); ?></td>
                                        <td class="text-end">&#8369;<?php echo number_format($tech['estimated_cost'], 2); ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                    <tr class="total-row">
                                        <td colspan="2"><strong>Total Technology Cost</strong></td>
                                        <td class="text-end"><strong>&#8369;<?php echo number_format($techTotal, 2); ?></strong></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <input type="hidden" name="technology_cost" value="<?php echo $techTotal; ?>">
                    </div>
                </div>

                <!-- Developer Costs -->
                <div class="report-card">
                    <div class="card-header">
                        <h5>
                            <i class="fas fa-code"></i>
                            Developer Costs
                        </h5>
                    </div>
                    <div class="card-body">
                        <small class="text-muted d-block mb-3">Based on Architect Roadmap headcount and daily rates (PHP 500/day per developer)</small>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Role</th>
                                        <th class="text-center">Number of Developers</th>
                                        <th class="text-end">Monthly Cost</th>
                                    </thead>
                                <tbody>
                                    <?php
                                    $roadmapDeveloperRows = $reportData['developer_rows'] ?? [];
                                    $devTotal = $reportData['developer_cost'];
                                    if (empty($roadmapDeveloperRows)):
                                    ?>
                                    <tr>
                                        <td colspan="3" class="text-muted text-center">No Roadmap Architect headcount has been entered yet.</td>
                                    </tr>
                                    <?php
                                    else:
                                    foreach($roadmapDeveloperRows as $alloc):
                                        $cost = (float) $alloc['cost'];
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($alloc['role']); ?></td>
                                        <td class="text-center"><?php echo $alloc['required_count']; ?></td>
                                        <td class="text-end">&#8369;<?php echo number_format($cost, 2); ?></td>
                                    </tr>
                                    <?php endforeach; endif; ?>
                                    <tr class="total-row">
                                        <td colspan="2"><strong>Total Developer Cost</strong></td>
                                        <td class="text-end"><strong>&#8369;<?php echo number_format($devTotal, 2); ?></strong></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <input type="hidden" name="developer_cost" value="<?php echo $devTotal; ?>">
                    </div>
                </div>
                </div>
                <div class="report-breakdown__resource">
                    <span><i class="fas fa-server" aria-hidden="true"></i>Resource Costs</span>
                    <strong>&#8369;<?php echo number_format($reportData['resource_cost'], 2); ?>/month</strong>
                    <input type="hidden" name="resource_cost" value="<?php echo $reportData['resource_cost']; ?>">
                </div>
                </details>

                <details class="report-breakdown report-fees">
                    <summary>
                        <span class="report-breakdown__icon" aria-hidden="true"><i class="fas fa-plus"></i></span>
                        <span class="report-breakdown__copy">
                            <strong>Billable additions</strong>
                            <small>Add new work or services to this receipt</small>
                        </span>
                        <i class="fas fa-chevron-down report-breakdown__chevron" aria-hidden="true"></i>
                    </summary>
                    <div class="report-fees__body">
                    <div class="report-card">
                        <div class="card-body">
                        <div id="otherFeesContainer">
                            <?php foreach ($billingFees as $feeIndex => $fee): ?>
                            <div class="other-fee-item row g-2 mb-2">
                                <div class="col-md-6">
                                    <input type="text" name="other_fees_list[<?php echo $feeIndex; ?>][name]" value="<?php echo htmlspecialchars((string) ($fee['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" maxlength="150" class="form-control" placeholder="Addition description" style="border-radius: 10px;">
                                </div>
                                <div class="col-md-4">
                                    <input type="number" name="other_fees_list[<?php echo $feeIndex; ?>][cost]" value="<?php echo htmlspecialchars((string) ($fee['cost'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" min="0.01" class="form-control fee-cost" placeholder="Cost (PHP)" step="0.01" onchange="updateSummary()" style="border-radius: 10px;">
                                </div>
                                <div class="col-md-2">
                                    <button type="button" class="btn-danger-modern w-100" onclick="removeFee(this)">
                                        <i class="fas fa-trash"></i> Remove
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="btn-secondary-modern mt-2" onclick="addFee()">
                            <i class="fas fa-plus"></i> Add billable item
                        </button>
                        <small class="text-muted d-block mt-2 other-fees-note"><i class="fas fa-info-circle"></i>Add a description for new work. To increase an existing item, enter its same description and new total cost; only the increase is charged.</small>
                        </div>
                    </div>
                    </div>
                </details>
                
                <!-- Cost Summary -->
                <div class="report-card report-card--summary">
                    <div class="card-body">
                        <div class="report-summary-title">
                            <span class="report-summary-title__icon" aria-hidden="true"><i class="fas fa-calculator"></i></span>
                            <span>
                                <strong>New charges for this report</strong>
                                <small>Previously billed amounts are excluded</small>
                            </span>
                        </div>
                        <div class="summary-wrap">
                            <table class="summary-table">
                                <tr>
                                    <td>Technology Costs</td>
                                    <td class="text-end">&#8369;<span id="summaryTech"><?php echo number_format($techTotal, 2); ?></span></td>
                                </tr>
                                <tr>
                                    <td>Developer Costs</td>
                                    <td class="text-end">&#8369;<span id="summaryDev"><?php echo number_format($devTotal, 2); ?></span></td>
                                </tr>
                                <tr>
                                    <td>Resource Costs</td>
                                    <td class="text-end">&#8369;<span id="summaryResource"><?php echo number_format($techTotal, 2); ?></span></td>
                                </tr>
                                <tr id="otherFeesSummary" style="display: none;">
                                    <td>New billable additions</td>
                                    <td class="text-end">&#8369;<span id="summaryOther">0.00</span></td>
                                </tr>
                                <tr class="total-row">
                                    <td><strong>New receipt amount</strong></td>
                                    <td class="text-end"><strong>&#8369;<span id="summaryTotal"><?php echo number_format($techTotal + $devTotal + $techTotal, 2); ?></span></strong></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
                
                <div class="report-actions">
                    <p id="reportBillingNotice" class="text-muted mb-0" role="status"></p>
                    <button type="submit" name="save_report" id="saveReportButton" class="btn-save">
                        <i class="fas fa-save"></i> Save Report & Generate Receipt
                    </button>
                </div>
            </form>
            <?php elseif($selectedClient): ?>
            <div class="alert-modern">
                <i class="fas fa-folder-open"></i>
                <h4>No Project Found</h4>
                <p>No project exists for this client. Please create a project first.</p>
            </div>
            <?php else: ?>
            <div class="alert-modern">
                <i class="fas fa-hand-pointer"></i>
                <h4>Select a Client</h4>
                <p>Choose a client from the dropdown to generate a sales report.</p>
            </div>
            <?php endif; ?>
        </div>
        </div>
    </div>
</div>

<script>
let feeIndex = <?php echo count($billingFees); ?>;
const billingBaseline = <?php echo json_encode($billingBaseline, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

function addFee() {
    const container = document.getElementById('otherFeesContainer');
    const newFee = `
        <div class="other-fee-item row g-2 mb-2">
            <div class="col-md-6">
                <input type="text" name="other_fees_list[${feeIndex}][name]" maxlength="150" class="form-control" placeholder="Addition description" style="border-radius: 10px;">
            </div>
            <div class="col-md-4">
                <input type="number" name="other_fees_list[${feeIndex}][cost]" min="0.01" class="form-control fee-cost" placeholder="Cost (PHP)" step="0.01" onchange="updateSummary()" style="border-radius: 10px;">
            </div>
            <div class="col-md-2">
                <button type="button" class="btn-danger-modern w-100" onclick="removeFee(this)">
                    <i class="fas fa-trash"></i> Remove
                </button>
            </div>
        </div>
    `;
    container.insertAdjacentHTML('beforeend', newFee);
    feeIndex++;
}

function removeFee(button) {
    button.closest('.other-fee-item').remove();
    updateSummary();
}

function updateSummary() {
    const cents = value => {
        const parts = String(value || '0').trim().match(/^(\d+)(?:\.(\d{0,2}))?$/);
        return parts ? Number(parts[1]) * 100 + Number((parts[2] || '').padEnd(2, '0')) : 0;
    };
    const techCents = Math.max(0, cents(document.querySelector('input[name="technology_cost"]')?.value) - billingBaseline.base.technology_cost);
    const devCents = Math.max(0, cents(document.querySelector('input[name="developer_cost"]')?.value) - billingBaseline.base.developer_cost);
    const resourceCents = Math.max(0, cents(document.querySelector('input[name="resource_cost"]')?.value) - billingBaseline.base.resource_cost);
    const tech = techCents / 100;
    const dev = devCents / 100;
    const resource = resourceCents / 100;
    
    let otherCents = 0;
    document.querySelectorAll('.fee-cost').forEach(input => {
        const name = input.closest('.other-fee-item')?.querySelector('input[type="text"]')?.value.trim().replace(/\s+/g, ' ').toLowerCase() || '';
        const prior = Number(billingBaseline.additions[name]?.cents || 0);
        otherCents += Math.max(0, cents(input.value) - prior);
    });
    
    const priorDirectCents = billingBaseline.base.technology_cost + billingBaseline.base.developer_cost;
    const nextDirectCents = priorDirectCents + techCents + devCents;
    const total = (Math.floor((nextDirectCents * 132 + 50) / 100) - Math.floor((priorDirectCents * 132 + 50) / 100)
        + resourceCents + otherCents) / 100;
    const otherTotal = otherCents / 100;
    
    const saveButton = document.getElementById('saveReportButton');
    if (saveButton) saveButton.disabled = total <= 0;
    const notice = document.getElementById('reportBillingNotice');
    if (notice) notice.textContent = total <= 0 ? 'No new charges. Add a billable item before creating another receipt.' : 'Only these new charges will appear on the receipt.';

    document.getElementById('summaryTech').textContent = tech.toFixed(2);
    document.getElementById('summaryDev').textContent = dev.toFixed(2);
    document.getElementById('summaryResource').textContent = resource.toFixed(2);
    document.getElementById('summaryTotal').textContent = total.toFixed(2);
    
    if (otherTotal > 0) {
        document.getElementById('otherFeesSummary').style.display = 'table-row';
        document.getElementById('summaryOther').textContent = otherTotal.toFixed(2);
    } else {
        document.getElementById('otherFeesSummary').style.display = 'none';
    }
}

// Keep the preview in sync as Sales types, including changed addition descriptions.
document.getElementById('reportForm')?.addEventListener('input', updateSummary);
if (document.getElementById('reportForm')) updateSummary();
</script>

<?php include '../../includes/footer.php'; ?>
