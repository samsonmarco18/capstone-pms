<?php
require_once '../../includes/auth.php';
requireRole('sales');

$conn = getConnection();
$user = getCurrentUser();

$reportId = (int) ($_GET['report_id'] ?? 0);
$receiptId = (int) ($_GET['receipt_id'] ?? 0);
$clientId = (int) ($_GET['client_id'] ?? 0);
$contextClientId = $clientId;

// Get receipts for client or specific report
if ($receiptId) {
    $receipts = $conn->query("
        SELECT r.*, sr.total_cost, c.name as client_name 
        FROM receipts r 
        JOIN sales_reports sr ON r.report_id = sr.id 
        JOIN clients c ON sr.client_id = c.id 
        WHERE r.id = $receiptId
        ORDER BY r.created_at DESC
    ");
} elseif ($reportId) {
    $receipts = $conn->query("
        SELECT r.*, sr.total_cost, c.name as client_name 
        FROM receipts r 
        JOIN sales_reports sr ON r.report_id = sr.id 
        JOIN clients c ON sr.client_id = c.id 
        WHERE r.id = $reportId OR sr.id = $reportId
        ORDER BY r.created_at DESC
    ");
} elseif ($clientId) {
    $receipts = $conn->query("
        SELECT r.*, sr.total_cost, c.name as client_name 
        FROM receipts r 
        JOIN sales_reports sr ON r.report_id = sr.id 
        JOIN clients c ON sr.client_id = c.id 
        WHERE c.id = $clientId
        ORDER BY r.created_at DESC
    ");
} else {
    $receipts = $conn->query("
        SELECT r.*, sr.total_cost, c.name as client_name 
        FROM receipts r 
        JOIN sales_reports sr ON r.report_id = sr.id 
        JOIN clients c ON sr.client_id = c.id 
        ORDER BY r.created_at DESC
    ");
}

// Get client name for header
$clientName = '';
if ($contextClientId === 0 && ($receiptId || $reportId)) {
    $contextQuery = $conn->query("
        SELECT sr.client_id, c.name
        FROM sales_reports sr
        JOIN clients c ON sr.client_id = c.id
        " . ($receiptId
            ? "JOIN receipts r ON r.report_id = sr.id WHERE r.id = $receiptId"
            : "WHERE sr.id = $reportId") . "
        LIMIT 1
    ");
    $contextRow = $contextQuery ? $contextQuery->fetch_assoc() : null;
    $contextClientId = (int) ($contextRow['client_id'] ?? 0);
    $clientName = $contextRow['name'] ?? '';
}
if ($contextClientId) {
    $client = $conn->query("SELECT name FROM clients WHERE id = $contextClientId")->fetch_assoc();
    $clientName = $client['name'] ?? 'All Clients';
}
$reportCreateUrl = 'report.php' . ($contextClientId > 0 ? '?client_id=' . $contextClientId : '');
$clientReceiptsUrl = 'receipt.php' . ($contextClientId > 0 ? '?client_id=' . $contextClientId : '');

// Get statistics
$totalReceipts = $conn->query("SELECT COUNT(*) as count FROM receipts")->fetch_assoc()['count'];
$totalAmount = $conn->query("SELECT SUM(amount_due) as total FROM receipts")->fetch_assoc()['total'];
$paidReceipts = $conn->query("SELECT COUNT(*) as count FROM receipts WHERE status = 'paid'")->fetch_assoc()['count'];

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

.stat-card-modern .stat-sub {
    font-size: 0.7rem;
    color: var(--gray-600);
    margin-top: 0.25rem;
}

/* Main Card */
.main-card {
    background: white;
    border-radius: 24px;
    border: 1px solid var(--gray-200);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
}

.card-header-modern {
    padding: 1.5rem 2rem;
    background: #ffffff;
    border-bottom: 1px solid var(--gray-200);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
}

.card-header-modern h5 {
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--gray-900);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.card-header-modern h5 i {
    color: var(--theme-primary);
}

.card-header-modern .btn-group {
    display: flex;
    gap: 0.5rem;
}

/* Table Styles */
.table-container {
    padding: 0;
    overflow-x: auto;
}

.receipt-table {
    width: 100%;
    border-collapse: collapse;
}

.receipt-table thead {
    background: var(--gray-50);
}

.receipt-table th {
    padding: 1rem 1.5rem;
    text-align: left;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--gray-600);
    border-bottom: 1px solid var(--gray-200);
}

.receipt-table td {
    padding: 1rem 1.5rem;
    vertical-align: middle;
    border-bottom: 1px solid var(--gray-200);
}

.receipt-table tr:hover {
    background: var(--gray-50);
}

.receipt-number {
    font-weight: 600;
    color: var(--gray-900);
    font-family: monospace;
    font-size: 0.9rem;
}

.client-name {
    font-weight: 500;
    color: var(--gray-800);
}

/* Badges */
.badge-status {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 600;
}

.badge-status.paid {
    background: #d1fae5;
    color: #059669;
}

.badge-status.sent {
    background: #dbeafe;
    color: #2563eb;
}

.badge-status.draft {
    background: #fef3c7;
    color: #d97706;
}

.badge-status.overdue {
    background: #fee2e2;
    color: #dc2626;
}

/* Action Buttons */
.action-buttons {
    display: flex;
    gap: 0.5rem;
}

.btn-icon-sm {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
    cursor: pointer;
    border: none;
    font-size: 0.8rem;
}

.btn-icon-sm.download {
    background: #e9ecef;
    color: #10b981;
}

.btn-icon-sm.download:hover {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
    transform: translateY(-1px);
}

.btn-icon-sm.view {
    background: #e9ecef;
    color: var(--theme-primary);
}

.btn-icon-sm.view:hover {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-primary-dark) 100%);
    color: white;
    transform: translateY(-1px);
}

/* Button Styles */
.btn-secondary-modern {
    background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);
    color: white;
    text-decoration: none;
    border: none;
    padding: 0.5rem 1rem;
    border-radius: 10px;
    font-size: 0.8rem;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.btn-secondary-modern:hover {
    transform: translateY(-1px);
    color: white;
}

.btn-primary-modern {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-primary-dark) 100%);
    color: white;
    text-decoration: none;
    border: none;
    padding: 0.5rem 1rem;
    border-radius: 10px;
    font-size: 0.8rem;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.btn-primary-modern:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(31, 99, 146, 0.3);
    color: white;
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
    margin-bottom: 1.5rem;
}

/* Responsive */
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
    
    .card-header-modern .btn-group {
        justify-content: center;
    }
    
    .receipt-table th,
    .receipt-table td {
        padding: 0.75rem 1rem;
    }
    
    .action-buttons {
        flex-direction: column;
        gap: 0.25rem;
    }
    
    .btn-icon-sm {
        width: 28px;
        height: 28px;
    }
}
</style>

<div class="container-fluid px-4 py-4">
    <!-- Page Header -->
    <div class="page-header-modern">
        <div>
            <h2>
                <i class="fas fa-receipt me-2"></i> 
                Receipts
            </h2>
            <?php if ($clientName): ?>
                <p>Client: <?php echo htmlspecialchars($clientName); ?></p>
            <?php else: ?>
                <p>Manage and track all generated receipts for client payments</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="stats-row">
        <div class="stat-card-modern">
            <i class="fas fa-receipt stat-icon"></i>
            <div class="stat-label">Total Receipts</div>
            <div class="stat-number"><?php echo $totalReceipts; ?></div>
        </div>
        <div class="stat-card-modern">
            <i class="fas fa-dollar-sign stat-icon"></i>
            <div class="stat-label">Total Amount</div>
            <div class="stat-number">$<?php echo number_format($totalAmount ?? 0, 0); ?></div>
        </div>
        <div class="stat-card-modern">
            <i class="fas fa-check-circle stat-icon"></i>
            <div class="stat-label">Paid Receipts</div>
            <div class="stat-number" style="color: #10b981;"><?php echo $paidReceipts; ?></div>
            <div class="stat-sub"><?php echo $totalReceipts > 0 ? round(($paidReceipts / $totalReceipts) * 100) : 0; ?>% completion</div>
        </div>
    </div>

    <!-- Main Content Card -->
    <div class="main-card">
        <div class="card-header-modern">
            <h5>
                <i class="fas fa-list"></i>
                Generated Receipts
            </h5>
            <div class="btn-group">
                <a href="receipt.php" class="btn-secondary-modern">
                    <i class="fas fa-list"></i> All Receipts
                </a>
                <?php if ($contextClientId): ?>
                    <a href="<?php echo htmlspecialchars($clientReceiptsUrl); ?>" class="btn-secondary-modern">
                        <i class="fas fa-building"></i> This Client
                    </a>
                <?php endif; ?>
                <a href="<?php echo htmlspecialchars($reportCreateUrl); ?>" class="btn-primary-modern">
                    <i class="fas fa-plus"></i> Create New Report
                </a>
            </div>
        </div>
        
        <!-- Receipts Table -->
        <div class="table-container">
            <?php if($receipts->num_rows > 0): ?>
            <table class="receipt-table">
                <thead>
                    <tr>
                        <th>Receipt #</th>
                        <th>Client</th>
                        <th>Date</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </thead>
                <tbody>
                    <?php while($receipt = $receipts->fetch_assoc()): ?>
                    <tr>
                        <td>
                            <span class="receipt-number">#<?php echo htmlspecialchars($receipt['receipt_number']); ?></span>
                        </td>
                        <td>
                            <div class="client-name"><?php echo htmlspecialchars($receipt['client_name']); ?></div>
                        </td>
                        <td><?php echo date('M d, Y', strtotime($receipt['created_at'])); ?></td>
                        <td class="fw-semibold">$<?php echo number_format($receipt['amount_due'], 2); ?></td>
                        <td>
                            <span class="badge-status <?php echo $receipt['status']; ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $receipt['status'])); ?>
                            </span>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <a href="?download=1&receipt_id=<?php echo (int) $receipt['id']; ?>" class="btn-icon-sm download" target="_blank">
                                    <i class="fas fa-download"></i>
                                </a>
                                <button class="btn-icon-sm view" onclick="viewReceipt(<?php echo $receipt['id']; ?>)">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state-modern">
                <i class="fas fa-receipt"></i>
                <h4>No Receipts Generated Yet</h4>
                <p>Create a sales report to generate receipts for your clients</p>
                
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function viewReceipt(id) {
    window.open(`../../api/get-receipt-details.php?id=${id}`, '_blank');
}
</script>

<?php include '../../includes/footer.php'; ?>
