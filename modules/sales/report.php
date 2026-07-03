<?php
require_once '../../includes/auth.php';
requireRole('sales');

$conn = getConnection();
$user = getCurrentUser();

// Get clients with projects
$clients = $conn->query("
    SELECT DISTINCT c.* 
    FROM clients c
    JOIN projects p ON c.id = p.client_id
    ORDER BY c.name
");

$selectedClient = isset($_GET['client_id']) ? $_GET['client_id'] : 0;
$reportData = null;
$technologies = null;
$developerCost = null;
$resources = null;
$otherFees = [];

function toMoney($value): float {
    return is_numeric($value) ? (float) $value : 0.0;
}

if ($selectedClient) {
    // Get project for this client
    $project = $conn->query("
        SELECT * FROM projects 
        WHERE client_id = $selectedClient 
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
        
        // Calculate developer cost from the Architect Roadmap planned headcount.
        $roadmapDeveloperRows = [];
        $roadmapHeadcountResult = $conn->query("
            SELECT MAX(COALESCE(developer_count, 0)) AS required_count
            FROM methodology_phases
            WHERE project_id = {$project['id']}
        ");
        $roadmapHeadcount = $roadmapHeadcountResult
            ? max(0, (int) ($roadmapHeadcountResult->fetch_assoc()['required_count'] ?? 0))
            : 0;
        if ($roadmapHeadcount > 0) {
            $roadmapDeveloperRows[] = [
                'role' => 'Roadmap Architect Headcount',
                'required_count' => $roadmapHeadcount,
            ];
        }

        // Estimated $500 per day per developer.
        $developerCost = 0;
        foreach ($roadmapDeveloperRows as $alloc) {
            $developerCost += $alloc['required_count'] * 500 * 30; // Monthly cost
        }
        
        // Get resource costs from tech recommendations
        $resourceCost = 0;
        $technologies->data_seek(0);
        while($tech = $technologies->fetch_assoc()) {
            $resourceCost += $tech['estimated_cost'];
        }
        
        $reportData = [
            'project' => $project,
            'technology_cost' => $resourceCost,
            'developer_cost' => $developerCost,
            'resource_cost' => $resourceCost,
            'developer_rows' => $roadmapDeveloperRows,
        ];
    }
}

// Save report
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_report'])) {
    $projectId = (int) ($_POST['project_id'] ?? 0);
    $clientId = (int) ($_POST['client_id'] ?? 0);
    $technologyCost = toMoney($_POST['technology_cost'] ?? 0);
    $developerCost = toMoney($_POST['developer_cost'] ?? 0);
    $resourceCost = toMoney($_POST['resource_cost'] ?? 0);
    $totalCost = $technologyCost + $developerCost + $resourceCost;
    $cleanOtherFees = [];

    if (isset($_POST['other_fees_list']) && is_array($_POST['other_fees_list'])) {
        foreach ($_POST['other_fees_list'] as $fee) {
            $feeName = trim((string) ($fee['name'] ?? ''));
            $feeCost = toMoney($fee['cost'] ?? 0);

            if ($feeName === '' && $feeCost <= 0) {
                continue;
            }

            $cleanOtherFees[] = [
                'name' => $feeName,
                'cost' => $feeCost,
            ];
            $totalCost += $feeCost;
        }
    }

    $otherFees = json_encode($cleanOtherFees);
    
    $stmt = $conn->prepare("
        INSERT INTO sales_reports (project_id, client_id, technology_cost, developer_cost, resource_cost, other_fees, total_cost, created_by) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("iidddsdi", $projectId, $clientId, $technologyCost, $developerCost, $resourceCost, $otherFees, $totalCost, $user['id']);
    
    if ($stmt->execute()) {
        $reportId = $stmt->insert_id;

        $clientStmt = $conn->prepare("SELECT name, email FROM clients WHERE id = ? LIMIT 1");
        $clientStmt->bind_param("i", $clientId);
        $clientStmt->execute();
        $clientData = $clientStmt->get_result()->fetch_assoc();
        $clientStmt->close();

        $receiptNumber = 'ERNI-' . date('Ymd') . '-' . str_pad((string) $reportId, 5, '0', STR_PAD_LEFT);
        $amountDue = $technologyCost + $developerCost + $resourceCost;
        $dueDate = date('Y-m-d', strtotime('+30 days'));

        $receiptStmt = $conn->prepare("
            INSERT INTO receipts (report_id, receipt_number, client_name, client_email, amount_due, due_date, status, generated_by)
            VALUES (?, ?, ?, ?, ?, ?, 'draft', ?)
        ");
        $clientName = $clientData['name'] ?? '';
        $clientEmail = $clientData['email'] ?? '';
        $receiptStmt->bind_param("isssdsi", $reportId, $receiptNumber, $clientName, $clientEmail, $amountDue, $dueDate, $user['id']);
        $receiptStmt->execute();
        $receiptId = $receiptStmt->insert_id;
        $receiptStmt->close();

        logActivity($user['id'], 'create_report', 'sales_reports', $reportId, null, $_POST);
        logActivity($user['id'], 'create_receipt', 'receipts', $receiptId, null, [
            'report_id' => $reportId,
            'receipt_number' => $receiptNumber,
        ]);

        $_SESSION['success'] = "Report saved and receipt generated successfully";
        header('Location: receipt.php?receipt_id=' . $receiptId . '&report_id=' . $reportId);
        exit();
    }
}

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
}

/* Page Header */
.page-header-modern {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-primary-dark) 100%);
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

/* Client Sidebar Card */
.client-card {
    background: white;
    border-radius: 24px;
    border: none;
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    position: sticky;
    top: 20px;
}

.client-card .card-header {
    padding: 1.25rem 1.5rem;
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
}

.client-item {
    display: block;
    padding: 0.75rem 1rem;
    margin: 0.25rem 0;
    border-radius: 12px;
    color: var(--gray-700);
    text-decoration: none;
    transition: all 0.2s;
}

.client-item:hover {
    background: var(--gray-50);
    color: var(--theme-primary);
}

.client-item.active {
    background: linear-gradient(135deg, rgba(31, 99, 146, 0.1) 0%, rgba(31, 99, 146, 0.05) 100%);
    color: var(--theme-primary);
    font-weight: 500;
    border-left: none;
}

/* Main Content Cards */
.report-card {
    background: white;
    border-radius: 20px;
    border: 1px solid var(--gray-200);
    overflow: hidden;
    margin-bottom: 1.5rem;
    box-shadow: var(--shadow-sm);
}

.report-card .card-header {
    padding: 1rem 1.5rem;
    background: var(--gray-50);
    border-bottom: 1px solid var(--gray-200);
}

.report-card .card-header h5 {
    font-size: 0.95rem;
    font-weight: 600;
    color: var(--gray-900);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.report-card .card-header h5 i {
    color: var(--theme-primary);
}

.report-card .card-body {
    padding: 1.5rem;
}

/* Tables */
.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table thead {
    background: var(--gray-50);
}

.data-table th {
    padding: 0.75rem 1rem;
    text-align: left;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--gray-600);
    border-bottom: 1px solid var(--gray-200);
}

.data-table td {
    padding: 0.75rem 1rem;
    border-bottom: 1px solid var(--gray-200);
}

.data-table tr:last-child td {
    border-bottom: none;
}

.data-table tr.total-row {
    background: var(--gray-50);
    font-weight: 600;
}

/* Summary Table */
.summary-table {
    width: 100%;
    border-collapse: collapse;
}

.summary-table td {
    padding: 0.75rem;
    border-bottom: 1px solid var(--gray-200);
}

.summary-table tr:last-child td {
    border-bottom: none;
}

.summary-table .total-row {
    background: var(--gray-50);
    font-weight: 600;
    color: var(--theme-primary);
}

/* Buttons */
.btn-save {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
    border: none;
    padding: 0.75rem 2rem;
    border-radius: 40px;
    font-weight: 500;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.btn-save:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
    color: white;
}

.btn-secondary-modern {
    background: linear-gradient(135deg, var(--gray-600) 0%, var(--gray-700) 100%);
    color: white;
    border: none;
    padding: 0.5rem 1rem;
    border-radius: 10px;
    font-size: 0.8rem;
    transition: all 0.2s;
}

.btn-secondary-modern:hover {
    transform: translateY(-1px);
    color: white;
}

.btn-danger-modern {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: white;
    border: none;
    padding: 0.4rem 0.8rem;
    border-radius: 8px;
    font-size: 0.75rem;
    transition: all 0.2s;
}

.btn-danger-modern:hover {
    transform: translateY(-1px);
    color: white;
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

/* Responsive */
@media (max-width: 768px) {
    .page-header-modern {
        padding: 1.5rem;
    }
    
    .client-card {
        margin-bottom: 1.5rem;
        position: static;
    }
}
</style>

<div class="container-fluid px-4 py-4">
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

    <div class="row g-4">
        <!-- Client Sidebar -->
        <div class="col-md-3">
            <div class="client-card">
                <div class="card-header">
                    <h5>
                        <i class="fas fa-building"></i>
                        Select Client
                    </h5>
                </div>
                <div class="client-list">
                    <?php if($clients->num_rows > 0): ?>
                        <?php while($client = $clients->fetch_assoc()): ?>
                        <a href="?client_id=<?php echo $client['id']; ?>" 
                           class="client-item <?php echo $selectedClient == $client['id'] ? 'active' : ''; ?>">
                            <i class="fas fa-user-circle me-2"></i>
                            <?php echo htmlspecialchars($client['name']); ?>
                        </a>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="text-center py-4 text-muted">
                            <i class="fas fa-users fa-2x mb-2"></i>
                            <p class="small mb-0">No clients with projects</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="col-md-9">
            <?php if($reportData && $technologies): ?>
            <form method="POST" id="reportForm">
                <input type="hidden" name="project_id" value="<?php echo $reportData['project']['id']; ?>">
                <input type="hidden" name="client_id" value="<?php echo $selectedClient; ?>">
                
                <!-- Project Information -->
                <div class="report-card">
                    <div class="card-header">
                        <h5>
                            <i class="fas fa-info-circle"></i>
                            Project Information
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-2">
                                <small class="text-muted d-block">Project Name</small>
                                <strong><?php echo htmlspecialchars($reportData['project']['name']); ?></strong>
                            </div>
                            <div class="col-md-6 mb-2">
                                <small class="text-muted d-block">Timeline</small>
                                <strong><?php echo date('M d, Y', strtotime($reportData['project']['timeline_start'])); ?> - <?php echo date('M d, Y', strtotime($reportData['project']['timeline_end'])); ?></strong>
                            </div>
                            <div class="col-12">
                                <small class="text-muted d-block">Description</small>
                                <p class="mb-0"><?php echo htmlspecialchars($reportData['project']['description']); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
                
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
                                    $techTotal = 0;
                                    $technologies->data_seek(0);
                                    while($tech = $technologies->fetch_assoc()): 
                                        $techTotal += $tech['estimated_cost'];
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($tech['tech_name']); ?></td>
                                        <td><?php echo htmlspecialchars($tech['category']); ?></td>
                                        <td class="text-end">$<?php echo number_format($tech['estimated_cost'], 2); ?></td>
                                    </tr>
                                    <?php endwhile; ?>
                                    <tr class="total-row">
                                        <td colspan="2"><strong>Total Technology Cost</strong></td>
                                        <td class="text-end"><strong>$<?php echo number_format($techTotal, 2); ?></strong></td>
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
                        <small class="text-muted d-block mb-3">Based on Architect Roadmap headcount and daily rates ($500/day per developer)</small>
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
                                    $devTotal = 0;
                                    if (empty($roadmapDeveloperRows)):
                                    ?>
                                    <tr>
                                        <td colspan="3" class="text-muted text-center">No Roadmap Architect headcount has been entered yet.</td>
                                    </tr>
                                    <?php
                                    else:
                                    foreach($roadmapDeveloperRows as $alloc):
                                        $cost = $alloc['required_count'] * 500 * 30;
                                        $devTotal += $cost;
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($alloc['role']); ?></td>
                                        <td class="text-center"><?php echo $alloc['required_count']; ?></td>
                                        <td class="text-end">$<?php echo number_format($cost, 2); ?></td>
                                    </tr>
                                    <?php endforeach; endif; ?>
                                    <tr class="total-row">
                                        <td colspan="2"><strong>Total Developer Cost</strong></td>
                                        <td class="text-end"><strong>$<?php echo number_format($devTotal, 2); ?></strong></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <input type="hidden" name="developer_cost" value="<?php echo $devTotal; ?>">
                    </div>
                </div>
                
                <!-- Resource Costs -->
                <div class="report-card">
                    <div class="card-header">
                        <h5>
                            <i class="fas fa-server"></i>
                            Resource Costs
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info bg-light border-0 p-3 rounded-3">
                            <i class="fas fa-info-circle me-2 text-primary"></i>
                            <strong>Total Resource Cost:</strong> $<?php echo number_format($techTotal, 2); ?>/month
                        </div>
                        <input type="hidden" name="resource_cost" value="<?php echo $techTotal; ?>">
                    </div>
                </div>
                
                <!-- Other Fees -->
                <div class="report-card">
                    <div class="card-header">
                        <h5>
                            <i class="fas fa-plus-circle"></i>
                            Other Fees (Optional)
                        </h5>
                    </div>
                    <div class="card-body">
                        <div id="otherFeesContainer">
                            <div class="other-fee-item row g-2 mb-2">
                                <div class="col-md-6">
                                    <input type="text" name="other_fees_list[0][name]" class="form-control" placeholder="Fee Name" style="border-radius: 10px;">
                                </div>
                                <div class="col-md-4">
                                    <input type="number" name="other_fees_list[0][cost]" class="form-control fee-cost" placeholder="Cost ($)" step="0.01" onchange="updateSummary()" style="border-radius: 10px;">
                                </div>
                                <div class="col-md-2">
                                    <button type="button" class="btn-danger-modern w-100" onclick="removeFee(this)">
                                        <i class="fas fa-trash"></i> Remove
                                    </button>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn-secondary-modern mt-2" onclick="addFee()">
                            <i class="fas fa-plus"></i> Add Another Fee
                        </button>
                        <small class="text-muted d-block mt-2"><i class="fas fa-info-circle me-1"></i>Note: Other fees will not appear on the receipt</small>
                    </div>
                </div>
                
                <!-- Cost Summary -->
                <div class="report-card">
                    <div class="card-header">
                        <h5>
                            <i class="fas fa-calculator"></i>
                            Cost Summary
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-8 offset-md-4">
                                <table class="summary-table">
                                    <tr>
                                        <td>Technology Costs:</td>
                                        <td class="text-end">$<span id="summaryTech"><?php echo number_format($techTotal, 2); ?></span></td>
                                    </tr>
                                    <tr>
                                        <td>Developer Costs:</td>
                                        <td class="text-end">$<span id="summaryDev"><?php echo number_format($devTotal, 2); ?></span></td>
                                    </tr>
                                    <tr>
                                        <td>Resource Costs:</td>
                                        <td class="text-end">$<span id="summaryResource"><?php echo number_format($techTotal, 2); ?></span></td>
                                    </tr>
                                    <tr id="otherFeesSummary" style="display: none;">
                                        <td>Other Fees:</td>
                                        <td class="text-end">$<span id="summaryOther">0.00</span></td>
                                    </tr>
                                    <tr class="total-row">
                                        <td><strong>Total Monthly Cost:</strong></td>
                                        <td class="text-end"><strong>$<span id="summaryTotal"><?php echo number_format($techTotal + $devTotal + $techTotal, 2); ?></span></strong></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="text-end mt-4">
                    <button type="submit" name="save_report" class="btn-save">
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
                <p>Choose a client from the sidebar to generate a sales report</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
let feeIndex = 1;

function addFee() {
    const container = document.getElementById('otherFeesContainer');
    const newFee = `
        <div class="other-fee-item row g-2 mb-2">
            <div class="col-md-6">
                <input type="text" name="other_fees_list[${feeIndex}][name]" class="form-control" placeholder="Fee Name" style="border-radius: 10px;">
            </div>
            <div class="col-md-4">
                <input type="number" name="other_fees_list[${feeIndex}][cost]" class="form-control fee-cost" placeholder="Cost ($)" step="0.01" onchange="updateSummary()" style="border-radius: 10px;">
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
    const tech = parseFloat(document.querySelector('input[name="technology_cost"]')?.value) || 0;
    const dev = parseFloat(document.querySelector('input[name="developer_cost"]')?.value) || 0;
    const resource = parseFloat(document.querySelector('input[name="resource_cost"]')?.value) || 0;
    
    let otherTotal = 0;
    document.querySelectorAll('.fee-cost').forEach(input => {
        otherTotal += parseFloat(input.value) || 0;
    });
    
    const total = tech + dev + resource + otherTotal;
    
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

// Initialize
setTimeout(updateSummary, 100);
</script>

<?php include '../../includes/footer.php'; ?>
