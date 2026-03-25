<?php
require_once '../../includes/auth.php';
requireRole('sales');

$conn = getConnection();
$user = getCurrentUser();

$reportId = (int) ($_GET['report_id'] ?? 0);
$receiptId = (int) ($_GET['receipt_id'] ?? 0);
$clientId = (int) ($_GET['client_id'] ?? 0);

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

// Handle PDF generation
if (isset($_GET['download']) && ($receiptId || $reportId)) {
    $receiptFilter = $receiptId
        ? "r.id = $receiptId"
        : "sr.id = $reportId";

    $receipt = $conn->query("
        SELECT r.*, sr.*, p.name as project_name, c.name as client_name, c.email, c.contact_person, c.phone, c.address 
        FROM receipts r 
        JOIN sales_reports sr ON r.report_id = sr.id 
        JOIN projects p ON sr.project_id = p.id 
        JOIN clients c ON sr.client_id = c.id 
        WHERE $receiptFilter
    ")->fetch_assoc();

    if ($receipt) {
        $html = '<!DOCTYPE html>
<html><head><style>
body { font-family: Arial, sans-serif; margin: 40px; }
.header { text-align: center; margin-bottom: 40px; border-bottom: 3px solid #0d556b; padding-bottom: 20px; }
.company { font-size: 24px; font-weight: bold; color: #0d556b; }
.details { display: flex; justify-content: space-between; margin: 20px 0; }
table { width: 100%; border-collapse: collapse; margin: 20px 0; }
th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
th { background-color: #f8f9fa; font-weight: bold; }
.total { font-size: 20px; font-weight: bold; color: #0d556b; }
.footer { margin-top: 40px; text-align: center; font-size: 12px; }
</style></head><body>
<div class="header">
<div class="company">ERNI Development Center Philippines Inc.</div>
<div>Receipt # ' . addslashes($receipt['receipt_number']) . '</div>
<div>Date: ' . date('F d, Y') . '</div>
</div>
<div class="details">
<div><strong>Bill To:</strong><br>' . addslashes($receipt['client_name']) . '<br>' . addslashes($receipt['email']) . '<br>' . addslashes($receipt['phone']) . '<br>' . addslashes($receipt['address']) . '</div>
<div><strong>Project:</strong><br>' . addslashes($receipt['project_name']) . '</div>
</div>
<table><thead><tr><th>Description</th><th>Quantity</th><th>Rate</th><th>Amount</th></tr></thead><tbody>';

        // Tech
        $techQuery = $conn->query("SELECT t.name, tr.service_type, tr.estimated_cost, tr.quantity FROM tech_recommendations tr JOIN technologies t ON tr.tech_id = t.id WHERE tr.project_id = {$receipt['project_id']}");
        while ($tech = $techQuery->fetch_assoc()) {
            $amount = $tech['estimated_cost'] * ($tech['quantity'] ?: 1);
            $html .= '<tr><td>' . addslashes($tech['name']) . ' (' . $tech['service_type'] . ')</td><td>' . ($tech['quantity'] ?: 1) . '</td><td>$' . number_format($tech['estimated_cost'], 2) . '</td><td>$' . number_format($amount, 2) . '</td></tr>';
        }

        // Dev
        $allocQuery = $conn->query("SELECT role, required_count FROM developer_allocations WHERE project_id = {$receipt['project_id']}");
        while ($alloc = $allocQuery->fetch_assoc()) {
            $monthlyCost = $alloc['required_count'] * 500 * 22;
            $html .= '<tr><td>' . addslashes($alloc['role']) . ' (Monthly)</td><td>' . $alloc['required_count'] . '</td><td>$11,000</td><td>$' . number_format($monthlyCost, 2) . '</td></tr>';
        }

        $html .= '<tr class="total"><td colspan="3">TOTAL AMOUNT DUE</td><td>$' . number_format($receipt['total_cost'], 2) . '</td></tr></tbody></table>
<div class="footer">Thank you for your business! ERNI Development Center Philippines Inc. Terms: Net 30 days</div></body></html>';

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="receipt_' . $receipt['receipt_number'] . '.pdf"');
        
        // Simple server-side PDF (requires TCPDF/mPDF, or use browser print)
        // For now, output HTML for jsPDF/browser
        echo $html;
        exit;
    }
}

include '../../includes/header.php';
include '../../includes/sidebar.php';
include '../../includes/navbar.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h2>Receipts</h2>
        <?php if ($clientId): ?>
            <p class="text-muted">Client: <?php 
                $client = $conn->query("SELECT name FROM clients WHERE id = $clientId")->fetch_assoc();
                echo htmlspecialchars($client['name'] ?? 'All Clients');
            ?></p>
        <?php endif; ?>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between">
                <h5>Generated Receipts</h5>
                <div>
                    <a href="receipt.php" class="btn btn-secondary btn-sm">All Receipts</a>
                    <?php if ($clientId): ?>
                        <a href="receipt.php" class="btn btn-primary btn-sm">This Client</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <?php if($receipts->num_rows > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Receipt #</th>
                                    <th>Client</th>
                                    <th>Date</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($receipt = $receipts->fetch_assoc()): ?>
                                <tr>
                                    <td><strong>#<?php echo htmlspecialchars($receipt['receipt_number']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($receipt['client_name']); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($receipt['created_at'])); ?></td>
                                    <td>$<?php echo number_format($receipt['amount_due'], 2); ?></td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo $receipt['status'] == 'paid' ? 'success' : 
                                                ($receipt['status'] == 'sent' ? 'info' : 
                                                ($receipt['status'] == 'overdue' ? 'danger' : 'warning'));
                                        ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $receipt['status'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="?download=1&receipt_id=<?php echo (int) $receipt['id']; ?>" class="btn btn-sm btn-success" target="_blank">
                                            <i class="fas fa-download"></i> Download PDF
                                        </a>
                                        <button class="btn btn-sm btn-info" onclick="viewReceipt(<?php echo $receipt['id']; ?>)">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="fas fa-receipt fa-3x text-muted mb-3"></i>
                        <h5>No receipts generated yet</h5>
                        <p class="text-muted">Create a sales report to generate receipts</p>
                        <a href="report.php" class="btn btn-primary">Create Report</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function viewReceipt(id) {
    window.open(`../../api/get-receipt-details.php?id=${id}`, '_blank');
}
</script>

<?php include '../../includes/footer.php'; ?>

