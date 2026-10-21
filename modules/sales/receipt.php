<?php
require_once '../../includes/auth.php';
require_once '../../includes/file-storage.php';
require_once '../../includes/receipt-excel.php';
require_once '../../includes/payment-proof.php';
requireRole('sales');

$conn = getConnection();
$user = getCurrentUser();

$reportId = (int) ($_GET['report_id'] ?? 0);
$receiptId = (int) ($_GET['receipt_id'] ?? 0);
$clientId = (int) ($_GET['client_id'] ?? 0);
$contextClientId = $clientId;

function salesReceiptStatusLabel(string $status): string
{
    return match ($status) {
        'draft' => 'For Review',
        'sent' => 'Sent to Client',
        default => ucfirst(str_replace('_', ' ', $status)),
    };
}

function salesReceiptRedirect(int $receiptId, int $clientId = 0): string
{
    if ($receiptId > 0) {
        return 'receipt.php?receipt_id=' . $receiptId;
    }

    return 'receipt.php' . ($clientId > 0 ? '?client_id=' . $clientId : '');
}

function salesReceiptFetchForDelivery(mysqli|PgsqlMysqliCompatConnection $conn, int $receiptId): ?array
{
    $stmt = $conn->prepare("
        SELECT r.*, sr.total_cost, sr.notes, p.name AS project_name,
               c.name AS fallback_client_name, c.email AS fallback_client_email
        FROM receipts r
        JOIN sales_reports sr ON r.report_id = sr.id
        JOIN projects p ON sr.project_id = p.id
        JOIN clients c ON sr.client_id = c.id
        WHERE r.id = ?
        LIMIT 1
    ");

    if ($stmt === false) {
        return null;
    }

    $stmt->bind_param('i', $receiptId);
    $stmt->execute();
    $receipt = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $receipt ?: null;
}

function salesReceiptDownload(mysqli|PgsqlMysqliCompatConnection $conn, int $receiptId): void
{
    $receipt = salesReceiptFetchForDelivery($conn, $receiptId);
    if (!$receipt) {
        http_response_code(404);
        exit('Receipt not found.');
    }

    $receiptNumber = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) ($receipt['receipt_number'] ?? 'receipt'));
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="receipt_' . $receiptNumber . '.xlsx"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo salesReceiptRenderExcel($receipt, (string) ($receipt['status'] ?? '') === 'paid');
    exit;
}

function salesReceiptSendToClient(mysqli|PgsqlMysqliCompatConnection $conn, int $receiptId): array
{
    $receipt = salesReceiptFetchForDelivery($conn, $receiptId);
    if (!$receipt) {
        return ['success' => false, 'error' => 'Receipt not found.'];
    }

    $clientEmail = (string) (($receipt['client_email'] ?? '') ?: ($receipt['fallback_client_email'] ?? ''));
    if (!filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Client email is missing or invalid.'];
    }

    if (!in_array($receipt['status'], ['draft', 'sent', 'viewed', 'overdue'], true) || ($receipt['payment_proof_status'] ?? '') === 'pending') {
        return ['success' => false, 'error' => 'This receipt is paid or already has payment proof awaiting review.'];
    }
    try {
        $paymentLink = paymentProofCreateLink($conn, $receiptId);
    } catch (Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
    $receiptExcel = salesReceiptRenderExcel($receipt, false);
    $receiptNumber = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) ($receipt['receipt_number'] ?? 'receipt'));
    $subject = 'ERNI Development Center Philippines, Inc. Billing Receipt #' . (string) ($receipt['receipt_number'] ?? '');
    $body = '<div style="font-family:Arial,sans-serif;line-height:1.6;color:#1f2937;">'
        . '<h2 style="color:#1f6392;">Billing receipt for review</h2>'
        . '<p>Good day,</p>'
        . '<p>Please review the attached billing receipt. Use the button below to submit your payment proof directly to Sales.</p>'
        . '<p><a href="' . htmlspecialchars($paymentLink, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;padding:12px 20px;background:#1f6392;color:#fff;text-decoration:none;border-radius:8px;">Submit payment proof</a></p>'
        . '<p>This private link is valid for 14 days. No account is required. Upload a PDF or image (maximum 5 MB) with the amount paid and transaction reference.</p>'
        . '<p>Sales will verify the payment first before issuing the official paid receipt.</p>'
        . '<p>Thank you.<br>ERNI Development Center Philippines, Inc.</p>'
        . '</div>';
    $text = "Please review the attached billing receipt. Submit your payment proof to Sales: {$paymentLink}\n\nThis private link expires in 14 days. Sales will verify the transaction before confirming payment.\n\nERNI Development Center Philippines, Inc.";

    $result = sendSystemEmail(
        $clientEmail,
        $subject,
        $body,
        $text,
        [[
            'name' => 'billing_receipt_' . $receiptNumber . '.xlsx',
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'content' => $receiptExcel,
        ]]
    );
    if (!$result['success']) {
        // A failed resend must not invalidate the link the client already has.
        $newHash = hash('sha256', substr($paymentLink, strrpos($paymentLink, '=') + 1));
        $oldHash = $receipt['payment_token_hash'] ?? null;
        $oldExpiry = $receipt['payment_token_expires_at'] ?? null;
        $restore = $conn->prepare('UPDATE receipts SET payment_token_hash = ?, payment_token_expires_at = ? WHERE id = ? AND payment_token_hash = ?');
        if ($restore) {
            $restore->bind_param('ssis', $oldHash, $oldExpiry, $receiptId, $newHash);
            $restore->execute();
            $restore->close();
        }
    }
    return $result;
}

salesReceiptEnsureSchema($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['save_receipt_review']) || isset($_POST['finalize_receipt']) || isset($_POST['verify_payment']) || isset($_POST['reject_payment']))) {
    try {
        paymentProofCheckCsrf($_POST['csrf_token'] ?? null);
    } catch (RuntimeException $e) {
        $_SESSION['error'] = $e->getMessage();
        header('Location: receipt.php');
        exit;
    }
    $postedReceiptId = (int) ($_POST['receipt_id'] ?? 0);

    if ($postedReceiptId <= 0) {
        $_SESSION['error'] = 'Receipt not found.';
        header('Location: ' . salesReceiptRedirect(0, $clientId));
        exit;
    }

    if (isset($_POST['finalize_receipt'])) {
        $reportIdForReceipt = 0;
        $reportStmt = $conn->prepare('SELECT report_id FROM receipts WHERE id = ? LIMIT 1');
        if ($reportStmt !== false) {
            $reportStmt->bind_param('i', $postedReceiptId);
            $reportStmt->execute();
            $reportIdForReceipt = (int) (($reportStmt->get_result()->fetch_assoc()['report_id'] ?? 0));
            $reportStmt->close();
        }

        $emailResult = salesReceiptSendToClient($conn, $postedReceiptId);
        if (!$emailResult['success']) {
            $_SESSION['error'] = 'Receipt was not finalized because email failed: ' . (string) ($emailResult['error'] ?? 'Unknown mail error.');
            header('Location: ' . salesReceiptRedirect($postedReceiptId, $clientId));
            exit;
        }

        $stmt = $conn->prepare("UPDATE receipts SET status = CASE WHEN status = 'draft' THEN 'sent' ELSE status END, email_sent_at = NOW() WHERE id = ? AND status <> 'paid'");
        if ($stmt !== false) {
            $stmt->bind_param('i', $postedReceiptId);
            $stmt->execute();
            $stmt->close();
        }
        if ($reportIdForReceipt > 0) {
            $reportUpdateStmt = $conn->prepare("UPDATE sales_reports SET status = 'invoiced' WHERE id = ?");
            if ($reportUpdateStmt !== false) {
                $reportUpdateStmt->bind_param('i', $reportIdForReceipt);
                $reportUpdateStmt->execute();
                $reportUpdateStmt->close();
            }
        }
        $_SESSION['success'] = 'Receipt finalized and emailed to the client for payment confirmation.';
        header('Location: ' . salesReceiptRedirect($postedReceiptId, $clientId));
        exit;
    }

    if (isset($_POST['verify_payment']) || isset($_POST['reject_payment'])) {
        try {
            $approved = isset($_POST['verify_payment']);
            paymentProofReview($conn, $postedReceiptId, (int) $user['id'], $_POST, $_FILES['payment_proof'] ?? [], $approved);
            $_SESSION['success'] = $approved
                ? 'Payment verified against the payment record. The official paid receipt is available.'
                : 'Payment proof rejected. The client can submit corrected proof using their billing email link.';
            try {
                $reviewedReceipt = salesReceiptFetchForDelivery($conn, $postedReceiptId);
                $result = paymentProofEmailReview($reviewedReceipt, $approved, trim((string) ($_POST['review_notes'] ?? '')));
                if (!$result['success']) $_SESSION['error'] = 'The review was saved, but the client email could not be delivered. Please contact the client.';
            } catch (Throwable $e) {
                error_log('Payment review email failed: ' . $e->getMessage());
                $_SESSION['error'] = 'The review was saved, but the client email could not be delivered. Please contact the client.';
            }
        } catch (Throwable $e) {
            $_SESSION['error'] = $e->getMessage();
        }
        header('Location: ' . salesReceiptRedirect($postedReceiptId, $clientId));
        exit;
    }

    $clientNameInput = trim((string) ($_POST['client_name'] ?? ''));
    $clientEmailInput = trim((string) ($_POST['client_email'] ?? ''));
    $amountDueInput = str_replace(',', '', trim((string) ($_POST['amount_due'] ?? '')));
    $dueDateInput = trim((string) ($_POST['due_date'] ?? ''));
    $statusInput = trim((string) ($_POST['status'] ?? 'draft'));
    $allowedStatuses = ['draft', 'sent', 'viewed', 'overdue'];

    if ($clientNameInput === '' || !is_numeric($amountDueInput) || (float) $amountDueInput < 0 || !in_array($statusInput, $allowedStatuses, true)) {
        $_SESSION['error'] = 'Please check the receipt details before saving.';
        header('Location: ' . salesReceiptRedirect($postedReceiptId, $clientId));
        exit;
    }

    $amountDue = (float) $amountDueInput;
    $dueDate = $dueDateInput !== '' ? $dueDateInput : null;
    $stmt = $conn->prepare("
        UPDATE receipts
        SET client_name = ?, client_email = ?, amount_due = ?, due_date = ?, status = ?,
            payment_token_hash = NULL, payment_token_expires_at = NULL
        WHERE id = ? AND status <> 'paid' AND payment_proof_status <> 'pending'
    " );
    if ($stmt !== false) {
        $stmt->bind_param('ssdssi', $clientNameInput, $clientEmailInput, $amountDue, $dueDate, $statusInput, $postedReceiptId);
        if (!$stmt->execute() || $conn->affected_rows !== 1) {
            $_SESSION['error'] = 'No changes saved. Paid receipts and receipts with pending payment proof cannot be edited.';
        } else {
            $_SESSION['success'] = 'Receipt review details saved. Send a new billing email so the client receives the updated receipt and submission link.';
        }
        $stmt->close();
    }

    header('Location: ' . salesReceiptRedirect($postedReceiptId, $clientId));
    exit;
}

if (isset($_GET['download']) && $receiptId > 0) {
    salesReceiptDownload($conn, $receiptId);
}

// Get receipts for client or specific report
$allowedReceiptPageSizes = [10, 25, 50];
$receiptPageSize = isset($_GET['per_page']) ? (int) $_GET['per_page'] : 10;
if (!in_array($receiptPageSize, $allowedReceiptPageSizes, true)) {
    $receiptPageSize = 10;
}
$receiptCurrentPage = max(1, isset($_GET['page']) ? (int) $_GET['page'] : 1);
$receiptWhereSql = '';
$pendingOnly = ($_GET['payment_review'] ?? '') === 'pending';

if ($receiptId) {
    $receiptWhereSql = " WHERE r.id = $receiptId";
} elseif ($reportId) {
    $receiptWhereSql = " WHERE (r.id = $reportId OR sr.id = $reportId)";
} elseif ($clientId) {
    $receiptWhereSql = " WHERE c.id = $clientId";
}
if ($pendingOnly) {
    $receiptWhereSql .= ($receiptWhereSql === '' ? ' WHERE ' : ' AND ') . "r.payment_proof_status = 'pending' AND r.status <> 'paid'";
}

$receiptCountResult = $conn->query("
    SELECT COUNT(*) AS total
    FROM receipts r
    JOIN sales_reports sr ON r.report_id = sr.id
    JOIN clients c ON sr.client_id = c.id
    {$receiptWhereSql}
");
$totalFilteredReceipts = 0;
if ($receiptCountResult) {
    $receiptCountRow = $receiptCountResult->fetch_assoc();
    $totalFilteredReceipts = (int) ($receiptCountRow['total'] ?? 0);
}

$receiptTotalPages = max(1, (int) ceil($totalFilteredReceipts / $receiptPageSize));
$receiptCurrentPage = min($receiptCurrentPage, $receiptTotalPages);
$receiptPageOffset = ($receiptCurrentPage - 1) * $receiptPageSize;
$receipts = $conn->query("
    SELECT r.*, sr.total_cost, COALESCE(NULLIF(r.client_name, ''), c.name) AS display_client_name
    FROM receipts r
    JOIN sales_reports sr ON r.report_id = sr.id
    JOIN clients c ON sr.client_id = c.id
    {$receiptWhereSql}
    ORDER BY CASE WHEN r.payment_proof_status = 'pending' THEN 0 ELSE 1 END, r.created_at DESC
    LIMIT {$receiptPageSize} OFFSET {$receiptPageOffset}
");
$receiptRowsShown = $receipts ? (int) $receipts->num_rows : 0;
$receiptPageStart = $totalFilteredReceipts > 0 ? $receiptPageOffset + 1 : 0;
$receiptPageEnd = min($receiptPageOffset + $receiptRowsShown, $totalFilteredReceipts);

$buildReceiptPageUrl = static function (int $page, ?int $pageSize = null) use ($receiptId, $reportId, $clientId, $receiptPageSize, $pendingOnly): string {
    $params = [];
    if ($pendingOnly) $params['payment_review'] = 'pending';
    if ($receiptId > 0) {
        $params['receipt_id'] = $receiptId;
    } elseif ($reportId > 0) {
        $params['report_id'] = $reportId;
    } elseif ($clientId > 0) {
        $params['client_id'] = $clientId;
    }

    $params['per_page'] = $pageSize ?? $receiptPageSize;
    if ($page > 1) {
        $params['page'] = $page;
    }

    return 'receipt.php?' . http_build_query($params) . '#generatedReceipts';
};

if ($receiptTotalPages <= 5) {
    $receiptPaginationPages = range(1, $receiptTotalPages);
} else {
    $receiptPaginationStart = max(1, min($receiptCurrentPage - 2, $receiptTotalPages - 4));
    $receiptPaginationPages = range($receiptPaginationStart, $receiptPaginationStart + 4);
}

// Get client name for header
$clientName = '';
$contextProjectId = 0;
if ($contextClientId === 0 && ($receiptId || $reportId)) {
    $contextQuery = $conn->query("
        SELECT sr.client_id, sr.project_id, c.name
        FROM sales_reports sr
        JOIN clients c ON sr.client_id = c.id
        " . ($receiptId
            ? "JOIN receipts r ON r.report_id = sr.id WHERE r.id = $receiptId"
            : "WHERE sr.id = $reportId") . "
        LIMIT 1
    ");
    $contextRow = $contextQuery ? $contextQuery->fetch_assoc() : null;
    $contextProjectId = (int) ($contextRow['project_id'] ?? 0);
    $contextClientId = (int) ($contextRow['client_id'] ?? 0);
    $clientName = $contextRow['name'] ?? '';
}
if ($contextClientId) {
    $client = $conn->query("SELECT name FROM clients WHERE id = $contextClientId")->fetch_assoc();
    $clientName = $client['name'] ?? 'All Clients';
}
$reportCreateUrl = 'report.php' . ($contextClientId > 0 ? '?client_id=' . $contextClientId : '');
if ($contextClientId > 0 && $contextProjectId > 0) $reportCreateUrl .= '&project_id=' . $contextProjectId;
$clientReceiptsUrl = 'receipt.php' . ($contextClientId > 0 ? '?client_id=' . $contextClientId : '');

// Get statistics
$totalReceipts = $conn->query("SELECT COUNT(*) as count FROM receipts")->fetch_assoc()['count'];
$totalAmount = $conn->query("SELECT SUM(amount_due) as total FROM receipts")->fetch_assoc()['total'];
$paidReceipts = $conn->query("SELECT COUNT(*) as count FROM receipts WHERE status = 'paid'")->fetch_assoc()['count'];
$pendingProofs = $conn->query("SELECT COUNT(*) AS count FROM receipts WHERE payment_proof_status = 'pending' AND status <> 'paid'")->fetch_assoc()['count'];

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
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 0.5rem;
}

/* Table Styles */
.table-container {
    padding: 0;
    overflow-x: auto;
}

#page-content-wrapper .receipt-list-card.receipt-list-card {
    overflow: visible !important;
}

.receipt-list-card .table-container {
    overflow: visible;
    min-height: 0;
    background: #fff;
}

.receipt-list-card .table-container.is-empty {
    min-height: 16rem;
    display: grid;
    place-items: center;
    overflow: hidden;
}

.receipt-table {
    width: 100%;
    border-collapse: collapse;
}

.receipt-table thead {
    background: var(--gray-50);
}

.receipt-table th {
    padding: 0.9rem 0.85rem;
    text-align: left;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--gray-600);
    border-bottom: 1px solid var(--gray-200);
}

.receipt-table td {
    height: 72px;
    padding: 0.85rem;
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

.btn-icon-sm.edit {
    background: #e9ecef;
    color: var(--theme-primary);
}

.btn-icon-sm.edit:hover {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-primary-dark) 100%);
    color: white;
    transform: translateY(-1px);
}

.btn-icon-sm.finalize {
    background: #ecfdf5;
    color: #059669;
}

.btn-icon-sm.finalize:hover {
    background: #059669;
    color: white;
    transform: translateY(-1px);
}

.btn-icon-sm.verify {
    background: #eef6ff;
    color: var(--theme-primary);
}

.btn-icon-sm.verify:hover {
    background: var(--theme-primary);
    color: white;
    transform: translateY(-1px);
}

.action-buttons form {
    margin: 0;
}

.receipt-action-menu {
    display: inline-flex;
}

.receipt-actions-trigger {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    padding: 0;
    border: 1px solid #d2e1ea;
    border-radius: 10px;
    background: #f8fbfd;
    color: var(--theme-primary);
    transition: border-color 0.18s ease, background-color 0.18s ease, color 0.18s ease;
}

.receipt-actions-trigger:hover,
.receipt-actions-trigger:focus-visible,
.receipt-actions-trigger[aria-expanded="true"] {
    border-color: #a9c9da;
    background: #eaf5fa;
    color: var(--theme-primary-dark);
    outline: none;
}

.receipt-actions-menu {
    z-index: 1080;
    min-width: 13rem;
    padding: 0.4rem;
    border: 1px solid #dce7ee;
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 14px 34px rgba(31, 99, 146, 0.16);
}

.receipt-table th:last-child,
.receipt-table td:last-child {
    width: 1%;
    text-align: right;
    white-space: nowrap;
}

.receipt-actions-menu .dropdown-item {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    width: 100%;
    padding: 0.58rem 0.7rem;
    border: 0;
    border-radius: 8px;
    background: transparent;
    color: #29465f;
    font-size: 0.82rem;
    font-weight: 500;
    text-align: left;
}

.receipt-actions-menu .dropdown-item i {
    width: 1rem;
    color: var(--theme-primary);
    text-align: center;
}

.receipt-actions-menu .dropdown-item:hover,
.receipt-actions-menu .dropdown-item:focus {
    background: #edf6fb;
    color: #15567f;
}

.receipt-actions-menu form {
    margin: 0;
}

.receipt-pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.9rem 1.5rem;
    border-top: 1px solid var(--gray-200);
    border-radius: 0 0 24px 24px;
    background: #fff;
}

.receipt-pagination__meta,
.receipt-pagination__controls,
.receipt-pagination__pages {
    display: flex;
    align-items: center;
}

.receipt-pagination__meta {
    gap: 0.75rem;
    min-width: 0;
}

.receipt-pagination__summary {
    color: var(--gray-600);
    font-size: 0.78rem;
    white-space: nowrap;
}

.receipt-pagination__size {
    height: 36px;
    min-width: 94px;
    padding: 0 1.8rem 0 0.7rem;
    border: 1px solid var(--gray-300);
    border-radius: 9px;
    background-color: #fff;
    color: var(--gray-700, #495057);
    font: inherit;
    font-size: 0.78rem;
    font-weight: 600;
}

.receipt-pagination__controls {
    gap: 0.55rem;
}

.receipt-pagination__pages {
    overflow: hidden;
    border: 1px solid var(--gray-300);
    border-radius: 9px;
    background: #fff;
}

.receipt-pagination__page,
.receipt-pagination__step {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    height: 36px;
    color: var(--gray-700, #495057) !important;
    font-size: 0.78rem;
    font-weight: 600;
    text-decoration: none;
}

.receipt-pagination__page {
    min-width: 36px;
    padding: 0 0.55rem;
}

.receipt-pagination__page:hover,
.receipt-pagination__page.is-current {
    background: #edf5fa;
    color: var(--theme-primary) !important;
}

.receipt-pagination__page.is-current {
    box-shadow: inset 0 0 0 1px #cfe1ec;
    pointer-events: none;
}

.receipt-pagination__step {
    min-width: 76px;
    padding: 0 0.75rem;
    border: 1px solid var(--gray-300);
    border-radius: 9px;
    background: #fff;
}

.receipt-pagination__step:hover {
    border-color: #9bbdd1;
    color: var(--theme-primary) !important;
}

.receipt-pagination__step.is-disabled {
    background: var(--gray-50);
    color: #adb5bd !important;
    pointer-events: none;
}

.receipt-actions-menu .dropdown-divider {
    margin: 0.35rem 0;
    border-color: #e5edf2;
}

.card-header-modern .btn-receipt-nav {
    border: 1px solid #bfd7e5 !important;
    background: #edf6fb !important;
    color: #15567f !important;
    box-shadow: none !important;
}

.card-header-modern .btn-receipt-nav:hover,
.card-header-modern .btn-receipt-nav:focus-visible {
    border-color: #8fbbd1 !important;
    background: #dfeff7 !important;
    color: #0f4a6e !important;
}

.proof-link {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    margin-top: 0.35rem;
    font-size: 0.72rem;
    font-weight: 700;
    color: var(--theme-primary);
    text-decoration: none;
    max-width: 230px;
}
.proof-link span { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.proof-link i { flex: none; }
.receipt-proof-status { display: inline-flex; align-items: center; gap: 6px; padding: 4px 9px; border-radius: 7px; font-size: 11px; font-weight: 600; line-height: 1.4; background: #fff5da; color: #865b06; }
.receipt-proof-status.is-rejected { background: #fff0ef; color: #ac3934; }
.receipt-proof-status.is-verified { background: #e0f6eb; color: #176742; }
.receipt-review-empty { color: #748397; font-size: 12px; }
.receipt-table .receipt-status-cell, .receipt-table .receipt-proof-cell { white-space: nowrap; }
.receipt-table .receipt-proof-cell .proof-link { display: inline-flex; padding: 6px 9px; border: 1px solid #cbdde9; border-radius: 7px; font-size: 12px; white-space: nowrap; }
.receipt-table td:nth-child(3), .receipt-table td:nth-child(4) { white-space: nowrap; }
.receipt-table .proof-link { margin-top: 0; font-weight: 500; }

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

/* Verify Payment Modal */
#verifyPaymentModal .modal-dialog {
    width: calc(100% - 40px);
    max-width: 980px;
    margin: 20px auto;
    min-height: calc(100% - 40px);
}

#verifyPaymentModal .modal-content {
    border: 0;
    border-radius: 18px;
    box-shadow: 0 24px 60px rgba(15, 42, 64, 0.22);
    overflow: hidden;
}

#verifyPaymentModal .receipt-payment-modal__header {
    background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-primary-light) 100%) !important;
    border: 0;
    color: #fff;
    padding: 1rem 1.25rem;
}

#verifyPaymentModal .receipt-payment-modal__header .modal-title,
#verifyPaymentModal .receipt-payment-modal__header .modal-title i {
    color: #fff !important;
}

#verifyPaymentModal .receipt-payment-modal__header .btn-close {
    filter: brightness(0) invert(1);
    opacity: 0.9;
}

#verifyPaymentModal .modal-body {
    padding: 20px;
    max-height: none;
    overflow: visible;
}

#verifyPaymentModal .receipt-payment-modal__intro {
    color: var(--gray-600);
    font-size: 0.88rem;
    line-height: 1.55;
    margin: 0 0 14px;
}

#verifyPaymentModal .payment-review-layout { display: grid; grid-template-columns: minmax(0, .8fr) minmax(0, 1.2fr); gap: 24px; align-items: start; }
#verifyPaymentModal .payment-proof-panel { min-width: 0; padding: 14px; border: 1px solid #dce7ef; border-radius: 12px; background: #f5f9fc; }
#verifyPaymentModal .payment-proof-panel__heading { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 10px; font-size: 13px; font-weight: 700; }
#verifyPaymentModal .payment-proof-panel__heading small { color: #63778b; font-size: 11px; font-weight: 500; }
#verifyPaymentModal .payment-proof-preview { display: grid; grid-template: minmax(0, 1fr) / minmax(0, 1fr); place-items: center; height: clamp(150px, 27vh, 220px); overflow: hidden; border: 1px solid #d7e3ed; border-radius: 8px; background: #e9f0f6; color: #42627b; text-decoration: none; }
#verifyPaymentModal .payment-proof-preview img { width: 100%; height: 100%; min-height: 0; object-fit: contain; }
#verifyPaymentModal .payment-proof-placeholder { padding: 16px; text-align: center; font-size: 12px; }
#verifyPaymentModal .payment-proof-placeholder i { display: block; font-size: 28px; margin-bottom: 8px; }
#verifyPaymentModal .payment-proof-preview [hidden], #verifyPaymentModal [hidden] { display: none !important; }
#verifyPaymentModal #submittedProofLink { display: block; margin-top: 8px; color: #1f6392; font-size: 12px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
#verifyPaymentModal .payment-proof-meta { display: grid; grid-template-columns: auto minmax(0, 1fr); gap: 6px 12px; margin: 12px 0 0; font-size: 12px; }
#verifyPaymentModal .payment-proof-meta dt { color: #63778b; font-weight: 500; }
#verifyPaymentModal .payment-proof-meta dd { margin: 0; text-align: right; overflow-wrap: anywhere; }
#verifyPaymentModal .payment-review-fields { min-width: 0; display: grid; gap: 12px; }
#verifyPaymentModal .payment-review-fields__pair { display: grid; grid-template-columns: minmax(0, .8fr) minmax(0, 1.2fr); gap: 12px; }
#verifyPaymentModal .form-label { display: block; color: #334c62; font-size: 12px; font-weight: 600; margin-bottom: 5px; }
#verifyPaymentModal .form-control { min-height: 38px; padding: 8px 10px; font-size: 13px; border-radius: 8px; }
#verifyPaymentModal textarea.form-control { height: 64px; min-height: 64px; resize: none; }
#verifyPaymentModal .payment-bank-confirmation { display: flex; align-items: flex-start; gap: 9px; padding: 10px 12px; background: #edf5fb; border: 1px solid #d7e6f1; border-radius: 8px; }
#verifyPaymentModal .payment-bank-confirmation input { flex: none; margin: 3px 0 0; }
#verifyPaymentModal .payment-bank-confirmation label { color: #35546d; font-size: 12px; line-height: 1.5; }
#verifyPaymentModal #manualPaymentProof { margin-top: 12px; }
#verifyPaymentModal .form-text { font-size: 11px; margin-top: 5px; }
@media (max-width: 700px) {
    #verifyPaymentModal .modal-dialog { width: calc(100% - 24px); margin: 12px auto; min-height: calc(100% - 24px); }
    #verifyPaymentModal .payment-review-layout { grid-template-columns: 1fr; gap: 16px; }
    #verifyPaymentModal .payment-proof-preview { height: 150px; }
    #verifyPaymentModal .modal-body { padding: 16px; }
    #verifyPaymentModal .receipt-payment-modal__footer { flex-wrap: wrap; }
}

#verifyPaymentModal .receipt-payment-upload {
    align-items: stretch;
    background:
        linear-gradient(135deg, rgba(31, 99, 146, 0.055), rgba(255, 255, 255, 0.96)),
        #fff;
    border: 1px dashed rgba(31, 99, 146, 0.28);
    border-radius: 12px;
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    min-height: 48px;
    overflow: hidden;
    position: relative;
    transition: border-color 0.18s ease, box-shadow 0.18s ease;
}

#verifyPaymentModal .receipt-payment-upload:hover,
#verifyPaymentModal .receipt-payment-upload:focus-within {
    border-color: rgba(31, 99, 146, 0.58);
    box-shadow: 0 8px 20px rgba(31, 99, 146, 0.12);
}

#verifyPaymentModal .receipt-payment-upload__button {
    align-items: center;
    background: #e8f3fa;
    border-right: 1px solid rgba(31, 99, 146, 0.16);
    color: var(--theme-primary);
    cursor: pointer;
    display: inline-flex;
    font-size: 0.84rem;
    font-weight: 700;
    gap: 0.45rem;
    justify-content: center;
    margin: 0;
    padding: 0 0.9rem;
    transition: background 0.18s ease, color 0.18s ease;
    white-space: nowrap;
}

#verifyPaymentModal .receipt-payment-upload__button:hover {
    background: var(--theme-primary);
    color: #fff;
}

#verifyPaymentModal .receipt-payment-upload__summary {
    align-self: center;
    color: #17324d;
    font-size: 0.86rem;
    min-width: 0;
    overflow: hidden;
    padding: 0 0.9rem;
    text-overflow: ellipsis;
    white-space: nowrap;
}

#verifyPaymentModal .receipt-payment-upload__input {
    height: 1px;
    left: 0;
    opacity: 0;
    position: absolute;
    top: 0;
    width: 1px;
}

#verifyPaymentModal .receipt-payment-modal__footer {
    background: #f8fbfd;
    border-top: 1px solid var(--gray-200);
    gap: 0.55rem;
    padding: 0.9rem 1.25rem;
}

#verifyPaymentModal .receipt-payment-modal__footer .btn {
    border-radius: 10px;
    font-weight: 700;
    min-height: 42px;
    padding-inline: 1rem;
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

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #page-content-wrapper {
    background: #06111e;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .page-header-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .stat-card-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .main-card {
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

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card-header-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .receipt-table thead,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .receipt-table tr:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-icon-sm.download,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-icon-sm.view {
    background: #0a1b2c;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card-header-modern h5,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .receipt-number,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .client-name,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .empty-state-modern h4 {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .stat-card-modern .stat-label,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .stat-card-modern .stat-sub,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .receipt-table th,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .empty-state-modern i,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .empty-state-modern p {
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card-header-modern,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .receipt-table th,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .receipt-table td {
    border-color: rgba(31, 99, 146, 0.24);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-icon-sm.download {
    color: #10b981;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .btn-icon-sm.view {
    color: var(--theme-primary);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .receipt-actions-trigger,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .receipt-actions-menu {
    border-color: rgba(138, 210, 255, 0.16);
    background: #0a1b2c;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .receipt-actions-trigger {
    color: #8cc7ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .receipt-actions-menu .dropdown-item {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .receipt-actions-menu .dropdown-item:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .receipt-actions-menu .dropdown-item:focus {
    background: rgba(31, 99, 146, 0.2);
    color: #fff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .card-header-modern .btn-receipt-nav {
    border-color: rgba(140, 199, 255, 0.28) !important;
    background: rgba(31, 99, 146, 0.16) !important;
    color: #8cc7ff !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .receipt-list-card .table-container,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .receipt-pagination,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .receipt-pagination__pages,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .receipt-pagination__step,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .receipt-pagination__size {
    border-color: #334155;
    background: #172033;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .receipt-pagination__summary,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .receipt-pagination__page,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .receipt-pagination__step,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .receipt-pagination__size {
    color: #cbd5e1 !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .receipt-pagination__page:hover,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .receipt-pagination__page.is-current {
    background: rgba(31, 99, 146, 0.24);
    color: #8cc7ff !important;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #verifyPaymentModal .modal-content,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #verifyPaymentModal .modal-body {
    background: #071321;
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #verifyPaymentModal .receipt-payment-modal__intro,
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #verifyPaymentModal .form-text {
    color: #9db6cf;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #verifyPaymentModal .receipt-payment-upload {
    background: rgba(10, 27, 44, 0.72);
    border-color: rgba(138, 210, 255, 0.2);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #verifyPaymentModal .receipt-payment-upload__button {
    background: rgba(31, 99, 146, 0.24);
    border-color: rgba(138, 210, 255, 0.16);
    color: #8fd3ff;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #verifyPaymentModal .receipt-payment-upload__summary {
    color: #d7e4f2;
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #verifyPaymentModal .receipt-payment-modal__footer {
    background: #0a1b2c;
    border-color: rgba(138, 210, 255, 0.16);
}

:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #verifyPaymentModal :is(.form-label, .payment-proof-panel__heading) { color: #d7e4f2 !important; }
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #verifyPaymentModal :is(.payment-proof-panel, .payment-bank-confirmation) { background: #0c2032; border-color: #294359; }
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #verifyPaymentModal .payment-proof-preview { background: #071321; border-color: #294359; color: #9db6cf; }
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #verifyPaymentModal :is(.payment-proof-meta dt, .payment-proof-panel__heading small) { color: #9db6cf; }
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #verifyPaymentModal :is(.payment-bank-confirmation label, .payment-proof-meta dd) { color: #d7e4f2; }
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #verifyPaymentModal #submittedProofLink { color: #83cefa; }
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #verifyPaymentModal .form-control { background: #0c2032 !important; border-color: #294359 !important; color: #e3edf7 !important; }
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) #verifyPaymentModal .form-control::placeholder { color: #8aa3ba; opacity: 1; }
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .receipt-proof-status { background: #382e19; color: #f2cc7a; }
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .receipt-proof-status.is-rejected { background: #39232c; color: #ffb3b3; }
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .receipt-proof-status.is-verified { background: #143a30; color: #8de0bb; }
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .receipt-review-empty { color: #9db0c6; }
:is(html[data-theme="dark"], body[data-theme="dark"], body.dark-mode) .receipt-table .receipt-proof-cell .proof-link { border-color: #294359; background: #0c2032; }

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

    .receipt-list-card .table-container.has-items {
        overflow-x: auto;
    }

    .receipt-pagination {
        align-items: stretch;
        flex-direction: column;
        padding: 0.85rem 1rem;
    }

    .receipt-pagination__meta,
    .receipt-pagination__controls {
        justify-content: space-between;
    }

    .receipt-pagination__pages {
        max-width: 100%;
    }

    .receipt-pagination__step {
        min-width: 68px;
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
    <?php if (!empty($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars((string) $_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <?php if (!empty($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars((string) $_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

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
            <i class="fas fa-peso-sign stat-icon"></i>
            <div class="stat-label">Total Amount</div>
            <div class="stat-number">₱<?php echo number_format($totalAmount ?? 0, 0); ?></div>
        </div>
        <div class="stat-card-modern">
            <i class="fas fa-check-circle stat-icon"></i>
            <div class="stat-label">Paid Receipts</div>
            <div class="stat-number" style="color: #10b981;"><?php echo $paidReceipts; ?></div>
            <div class="stat-sub"><?php echo $totalReceipts > 0 ? round(($paidReceipts / $totalReceipts) * 100) : 0; ?>% completion</div>
        </div>
    </div>

    <!-- Main Content Card -->
    <div class="main-card receipt-list-card" id="generatedReceipts">
        <div class="card-header-modern">
            <h5>
                <i class="fas fa-list"></i>
                Generated Receipts
            </h5>
            <div class="btn-group">
                <a href="receipt.php?payment_review=pending" class="btn-secondary-modern btn-receipt-nav">Awaiting Verification (<?php echo (int) $pendingProofs; ?>)</a>
                <a href="receipt.php" class="btn-secondary-modern btn-receipt-nav">
                    <i class="fas fa-list"></i> All Receipts
                </a>
                <?php if ($contextClientId): ?>
                    <a href="<?php echo htmlspecialchars($clientReceiptsUrl); ?>" class="btn-secondary-modern btn-receipt-nav">
                        <i class="fas fa-building"></i> This Client
                    </a>
                <?php endif; ?>
                <a href="<?php echo htmlspecialchars($reportCreateUrl); ?>" class="btn-primary-modern">
                    <i class="fas fa-plus"></i> Create New Report
                </a>
            </div>
        </div>
        
        <!-- Receipts Table -->
        <div class="table-container <?php echo $receiptRowsShown > 0 ? 'has-items' : 'is-empty'; ?>">
            <?php if($receiptRowsShown > 0): ?>
            <table class="receipt-table">
                <thead>
                    <tr>
                        <th>Receipt #</th>
                        <th>Client</th>
                        <th>Date</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Payment review</th>
                        <th>Proof</th>
                        <th>Actions</th>
                    </thead>
                <tbody>
                    <?php while($receipt = $receipts->fetch_assoc()): ?>
                    <?php
                    $receiptStatus = (string) ($receipt['status'] ?? 'draft');
                    $receiptPayload = [
                        'id' => (int) $receipt['id'],
                        'receipt_number' => (string) ($receipt['receipt_number'] ?? ''),
                        'client_name' => (string) (($receipt['client_name'] ?? '') ?: ($receipt['display_client_name'] ?? '')),
                        'client_email' => (string) ($receipt['client_email'] ?? ''),
                        'amount_due' => number_format((float) ($receipt['amount_due'] ?? 0), 2, '.', ''),
                        'due_date' => (string) ($receipt['due_date'] ?? ''),
                        'status' => $receiptStatus,
                    ];
                    $proofPath = trim((string) ($receipt['payment_proof_path'] ?? ''));
                    $proofName = trim((string) ($receipt['payment_proof_name'] ?? ''));
                    ?>
                    <tr>
                        <td>
                            <span class="receipt-number">#<?php echo htmlspecialchars($receipt['receipt_number']); ?></span>
                        </td>
                        <td>
                            <div class="client-name"><?php echo htmlspecialchars((string) ($receipt['display_client_name'] ?? $receipt['client_name'] ?? '')); ?></div>
                        </td>
                        <td><?php echo !empty($receipt['created_at']) ? date('M d, Y', strtotime((string) $receipt['created_at'])) : 'No date'; ?></td>
                        <td class="fw-semibold">₱<?php echo number_format($receipt['amount_due'], 2); ?></td>
                        <td class="receipt-status-cell">
                            <span class="badge-status <?php echo htmlspecialchars($receiptStatus); ?>">
                                <?php echo htmlspecialchars(salesReceiptStatusLabel($receiptStatus)); ?>
                            </span>
                        </td>
                        <td class="receipt-status-cell">
                            <?php if ($receiptStatus === 'paid'): ?>
                                <span class="receipt-proof-status is-verified"><i class="fas fa-check" aria-hidden="true"></i>Verified</span>
                            <?php elseif (($receipt['payment_proof_status'] ?? '') === 'pending'): ?>
                                <span class="receipt-proof-status"><i class="fas fa-clock" aria-hidden="true"></i>Awaiting review</span>
                            <?php elseif (($receipt['payment_proof_status'] ?? '') === 'rejected'): ?>
                                <span class="receipt-proof-status is-rejected">Correction requested</span>
                            <?php else: ?>
                                <span class="receipt-review-empty">Not submitted</span>
                            <?php endif; ?>
                        </td>
                        <td class="receipt-proof-cell">
                            <?php if ($proofPath !== ''): ?>
                                <a class="proof-link" href="<?php echo htmlspecialchars(storagePublicUrl($proofPath)); ?>" target="_blank" rel="noopener" title="<?php echo htmlspecialchars($proofName, ENT_QUOTES, 'UTF-8'); ?>" data-image-title="<?php echo htmlspecialchars($proofName ?: 'Payment proof', ENT_QUOTES, 'UTF-8'); ?>">
                                    <i class="fas fa-eye" aria-hidden="true"></i><span>View proof</span>
                                </a>
                            <?php else: ?>
                                <span class="receipt-review-empty" aria-label="No proof attached">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="dropdown receipt-action-menu">
                                <button type="button"
                                        class="receipt-actions-trigger"
                                        data-bs-toggle="dropdown"
                                        data-bs-boundary="viewport"
                                        aria-expanded="false"
                                        aria-label="Receipt actions">
                                    <i class="fas fa-ellipsis-v" aria-hidden="true"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end receipt-actions-menu">
                                    <li>
                                        <button type="button" class="dropdown-item" onclick="viewReceipt(<?php echo (int) $receipt['id']; ?>)">
                                            <i class="fas fa-eye" aria-hidden="true"></i>
                                            View Receipt
                                        </button>
                                    </li>
                                    <?php if ($receiptStatus !== 'paid' && ($receipt['payment_proof_status'] ?? '') !== 'pending'): ?>
                                    <li>
                                        <button type="button" class="dropdown-item" onclick="editReceipt(<?php echo htmlspecialchars(json_encode($receiptPayload), ENT_QUOTES, 'UTF-8'); ?>)">
                                            <i class="fas fa-pen" aria-hidden="true"></i>
                                            Review and Edit
                                        </button>
                                    </li>
                                    <?php endif; ?>
                                    <li>
                                        <a href="?download=1&receipt_id=<?php echo (int) $receipt['id']; ?>" class="dropdown-item" target="_blank">
                                            <i class="fas fa-download" aria-hidden="true"></i>
                                            Download Excel Receipt
                                        </a>
                                    </li>
                                    <?php if ($receiptStatus !== 'paid' && ($receipt['payment_proof_status'] ?? '') !== 'pending'): ?>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <form method="POST" data-generic-draft-disabled="true" data-app-loading data-loading-title="Sending billing receipt" data-loading-text="Preparing the Excel receipt and sending the client email. Please wait.">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(paymentProofCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="receipt_id" value="<?php echo (int) $receipt['id']; ?>">
                                                <button type="submit" name="finalize_receipt" class="dropdown-item">
                                                    <i class="fas fa-paper-plane" aria-hidden="true"></i>
                                                    <?php echo $receiptStatus === 'draft' ? 'Send Billing Email' : 'Resend Billing Email / Renew Link'; ?>
                                                </button>
                                            </form>
                                        </li>
                                    <?php endif; ?>
                                    <?php if (in_array($receiptStatus, ['sent', 'viewed', 'overdue'], true)): ?>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <button type="button" class="dropdown-item" onclick="verifyPayment(<?php echo (int) $receipt['id']; ?>, <?php echo htmlspecialchars(json_encode((string) $receipt['receipt_number']), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode(['path' => $proofPath, 'url' => $proofPath !== '' ? storagePublicUrl($proofPath) : '', 'name' => $proofName, 'status' => $receipt['payment_proof_status'] ?? 'none', 'reference' => $receipt['payment_proof_reference'] ?? '', 'amount' => $receipt['payment_proof_amount'] ?? $receipt['amount_due'], 'notes' => $receipt['payment_review_notes'] ?? '']), ENT_QUOTES, 'UTF-8'); ?>)">
                                                <i class="fas fa-file-circle-check" aria-hidden="true"></i>
                                                Verify Payment
                                            </button>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state-modern">
                <i class="fas fa-receipt"></i>
                <h4><?php echo $pendingOnly ? 'No Payment Proof Awaiting Verification' : 'No Receipts Generated Yet'; ?></h4>
                <p><?php echo $pendingOnly ? 'New client submissions will appear here for Sales to review.' : 'Create a sales report to generate receipts for your clients'; ?></p>
                
            </div>
            <?php endif; ?>
        </div>
        <?php if ($totalFilteredReceipts > 0): ?>
        <div class="receipt-pagination" aria-label="Receipt pagination">
            <div class="receipt-pagination__meta">
                <span class="receipt-pagination__summary">
                    <?php echo $receiptPageStart; ?>&ndash;<?php echo $receiptPageEnd; ?> of <?php echo $totalFilteredReceipts; ?>
                </span>
                <label class="visually-hidden" for="receiptPageSize">Receipts per page</label>
                <select class="receipt-pagination__size" id="receiptPageSize" aria-label="Receipts per page">
                    <?php foreach ($allowedReceiptPageSizes as $pageSizeOption): ?>
                    <option
                        value="<?php echo htmlspecialchars($buildReceiptPageUrl(1, $pageSizeOption)); ?>"
                        <?php echo $pageSizeOption === $receiptPageSize ? 'selected' : ''; ?>
                    ><?php echo $pageSizeOption; ?> items</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <nav class="receipt-pagination__controls" aria-label="Receipt pages">
                <?php if ($receiptCurrentPage > 1): ?>
                    <a class="receipt-pagination__step" href="<?php echo htmlspecialchars($buildReceiptPageUrl($receiptCurrentPage - 1)); ?>">Previous</a>
                <?php else: ?>
                    <span class="receipt-pagination__step is-disabled" aria-disabled="true">Previous</span>
                <?php endif; ?>
                <span class="receipt-pagination__pages">
                    <?php foreach ($receiptPaginationPages as $pageNumber): ?>
                    <a
                        class="receipt-pagination__page<?php echo $pageNumber === $receiptCurrentPage ? ' is-current' : ''; ?>"
                        href="<?php echo htmlspecialchars($buildReceiptPageUrl($pageNumber)); ?>"
                        <?php echo $pageNumber === $receiptCurrentPage ? 'aria-current="page"' : ''; ?>
                    ><?php echo $pageNumber; ?></a>
                    <?php endforeach; ?>
                </span>
                <?php if ($receiptCurrentPage < $receiptTotalPages): ?>
                    <a class="receipt-pagination__step" href="<?php echo htmlspecialchars($buildReceiptPageUrl($receiptCurrentPage + 1)); ?>">Next</a>
                <?php else: ?>
                    <span class="receipt-pagination__step is-disabled" aria-disabled="true">Next</span>
                <?php endif; ?>
            </nav>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="editReceiptModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(paymentProofCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-receipt me-2 text-primary"></i>Review Receipt</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="receipt_id" id="edit_receipt_id">
                <div class="mb-3">
                    <label class="form-label">Receipt #</label>
                    <input type="text" id="edit_receipt_number" class="form-control" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label">Client Name</label>
                    <input type="text" name="client_name" id="edit_receipt_client_name" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Client Email</label>
                    <input type="email" name="client_email" id="edit_receipt_client_email" class="form-control">
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Amount Due (PHP)</label>
                        <input type="number" name="amount_due" id="edit_receipt_amount_due" class="form-control" min="0" step="0.01" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Due Date</label>
                        <input type="date" name="due_date" id="edit_receipt_due_date" class="form-control">
                    </div>
                </div>
                <div class="mt-3">
                    <label class="form-label">Status</label>
                    <select name="status" id="edit_receipt_status" class="form-select">
                        <option value="draft">For Review</option>
                        <option value="sent">Sent</option>
                        <option value="viewed">Viewed</option>
                        <option value="paid" disabled>Paid (Verified)</option>
                        <option value="overdue">Overdue</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" name="save_receipt_review" class="btn btn-primary">
                    <i class="fas fa-save me-1"></i> Save Review
                </button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="verifyPaymentModal" tabindex="-1" aria-hidden="true" aria-labelledby="verifyPaymentTitle">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" enctype="multipart/form-data" class="modal-content" data-app-loading data-loading-title="Verifying payment" data-loading-text="Saving your review and preparing the client confirmation. Please wait.">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(paymentProofCsrfToken(), ENT_QUOTES, 'UTF-8'); ?>">
            <div class="modal-header receipt-payment-modal__header">
                <h5 class="modal-title" id="verifyPaymentTitle"><i class="fas fa-file-circle-check me-2"></i>Verify Payment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="receipt_id" id="verify_receipt_id">
                <input type="hidden" name="review_proof_path" id="review_proof_path">
                <div class="payment-review-layout">
                    <section class="payment-proof-panel" aria-label="Payment proof preview">
                        <div class="payment-proof-panel__heading"><span>Payment proof</span><small id="proofPreviewHint">Click image to enlarge</small></div>
                        <a id="paymentProofPreview" class="payment-proof-preview" aria-label="Enlarge payment proof">
                            <img id="paymentProofThumbnail" alt="Payment proof" hidden>
                            <span id="paymentProofPlaceholder" class="payment-proof-placeholder"><i class="fas fa-image" aria-hidden="true"></i><span id="paymentProofPlaceholderText">Choose a file to preview</span></span>
                        </a>
                        <div id="submittedProofDetails" hidden>
                            <a id="submittedProofLink" target="_blank" rel="noopener">View submitted proof</a>
                            <dl class="payment-proof-meta">
                                <dt>Status</dt><dd id="submittedProofStatus"></dd>
                                <dt>Submitted amount</dt><dd id="submittedProofAmount"></dd>
                                <dt>Reference</dt><dd id="submittedProofReference"></dd>
                            </dl>
                        </div>
                        <div id="manualPaymentProof">
                            <label class="form-label" for="verifyPaymentProof">Attach payment proof</label>
                            <div class="receipt-payment-upload">
                                <label class="receipt-payment-upload__button" for="verifyPaymentProof"><i class="fas fa-paperclip" aria-hidden="true"></i><span>Choose File</span></label>
                                <span class="receipt-payment-upload__summary" id="verifyPaymentProofSummary">No file chosen</span>
                                <input id="verifyPaymentProof" type="file" name="payment_proof" class="receipt-payment-upload__input" accept=".pdf,.png,.jpg,.jpeg,.webp" required>
                            </div>
                            <div class="form-text">PDF, PNG, JPG or WEBP · Up to 5 MB</div>
                        </div>
                    </section>
                    <div>
                        <p class="receipt-payment-modal__intro">Match the proof with the actual bank or payment provider record before confirming payment.</p>
                        <div class="payment-review-fields">
                            <div><label class="form-label" for="verify_receipt_number">Receipt number</label><input type="text" id="verify_receipt_number" class="form-control" readonly></div>
                            <div class="payment-review-fields__pair" id="manualPaymentFields">
                                <div><label for="verifiedAmount" class="form-label">Amount received (PHP)</label><input class="form-control" type="number" id="verifiedAmount" name="verified_amount" min="0.01" step="0.01" placeholder="0.00" required></div>
                                <div><label for="verifiedReference" class="form-label">Verified transaction reference</label><input class="form-control" id="verifiedReference" name="verified_reference" maxlength="150" placeholder="Reference from payment record" required></div>
                            </div>
                            <div id="paymentRejectionNotes" hidden><label for="paymentReviewNotes" class="form-label">Reason for rejection</label><textarea class="form-control" id="paymentReviewNotes" name="review_notes" maxlength="2000" rows="2" placeholder="Explain why the proof needs correction"></textarea></div>
                            <div class="payment-bank-confirmation"><input class="form-check-input" type="checkbox" id="bankConfirmed" name="bank_confirmed" value="1" required><label for="bankConfirmed">I checked the actual payment record and confirmed this payment was received.</label></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer receipt-payment-modal__footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" name="reject_payment" id="rejectPaymentButton" class="btn btn-outline-danger" data-loading-title="Saving payment review" data-loading-text="Recording the rejection and notifying the client. Please wait." formnovalidate hidden onclick="if (document.getElementById('paymentRejectionNotes').hidden) { document.getElementById('paymentRejectionNotes').hidden = false; document.getElementById('paymentReviewNotes').focus(); return false; }">Reject proof</button>
                <button type="submit" name="verify_payment" class="btn btn-primary">
                    <i class="fas fa-check me-1"></i> Confirm
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function viewReceipt(id) {
    window.open(`../../api/get-receipt-details.php?id=${id}`, '_blank');
}

function showReceiptModal(modalId) {
    const modalElement = document.getElementById(modalId);
    if (!modalElement) return;

    // Theme containers create their own stacking contexts. Bootstrap modals
    // must be direct body children so their backdrop cannot cover the fields.
    if (modalElement.parentElement !== document.body) {
        document.body.appendChild(modalElement);
    }
    bootstrap.Modal.getOrCreateInstance(modalElement).show();
}

function editReceipt(receipt) {
    document.getElementById('edit_receipt_id').value = receipt.id || '';
    document.getElementById('edit_receipt_number').value = receipt.receipt_number || '';
    document.getElementById('edit_receipt_client_name').value = receipt.client_name || '';
    document.getElementById('edit_receipt_client_email').value = receipt.client_email || '';
    document.getElementById('edit_receipt_amount_due').value = receipt.amount_due || '0.00';
    document.getElementById('edit_receipt_due_date').value = receipt.due_date || '';
    document.getElementById('edit_receipt_status').value = receipt.status || 'draft';
    showReceiptModal('editReceiptModal');
}

let paymentProofObjectUrl = null;
let currentPaymentProof = {};

function updatePaymentProofPreview(url, name, isImage) {
    const preview = document.getElementById('paymentProofPreview');
    const thumbnail = document.getElementById('paymentProofThumbnail');
    const placeholder = document.getElementById('paymentProofPlaceholder');
    const placeholderText = document.getElementById('paymentProofPlaceholderText');
    const hint = document.getElementById('proofPreviewHint');
    thumbnail.onload = null;
    thumbnail.onerror = null;
    thumbnail.hidden = true;
    thumbnail.removeAttribute('src');
    placeholder.hidden = false;
    preview.removeAttribute('href');
    preview.removeAttribute('data-image-preview');
    preview.removeAttribute('target');
    preview.dataset.imageTitle = name || 'Payment proof';
    if (!url) {
        placeholderText.textContent = 'Choose a file to preview';
        hint.textContent = 'No file selected';
        return;
    }
    preview.href = url;
    preview.setAttribute('aria-label', isImage ? 'Enlarge payment proof' : 'Open payment proof PDF');
    if (isImage) {
        preview.setAttribute('data-image-preview', '');
        placeholderText.textContent = 'Loading preview…';
        hint.textContent = 'Click image to enlarge';
        thumbnail.onload = () => { thumbnail.hidden = false; placeholder.hidden = true; };
        thumbnail.onerror = () => { placeholderText.textContent = 'Preview unavailable. Click to open.'; };
        thumbnail.alt = name || 'Payment proof';
        thumbnail.src = url;
    } else {
        preview.target = '_blank';
        preview.rel = 'noopener';
        placeholderText.textContent = 'PDF document · Click to open';
        hint.textContent = 'PDF attachment';
    }
}

function receiptProofIsImage(proof) {
    return /\.(png|jpe?g|webp|gif|avif)$/i.test(proof.name || proof.path || '');
}

function verifyPayment(id, receiptNumber, proof = {}) {
    if (paymentProofObjectUrl) URL.revokeObjectURL(paymentProofObjectUrl);
    paymentProofObjectUrl = null;
    currentPaymentProof = proof;
    document.getElementById('verify_receipt_id').value = id || '';
    document.getElementById('verify_receipt_number').value = receiptNumber || '';
    const proofInput = document.getElementById('verifyPaymentProof');
    const proofSummary = document.getElementById('verifyPaymentProofSummary');
    if (proofInput) proofInput.value = '';
    if (proofSummary) proofSummary.textContent = 'No file chosen';
    const pending = proof.status === 'pending';
    document.getElementById('review_proof_path').value = proof.path || '';
    document.getElementById('submittedProofDetails').hidden = !proof.path;
    const link = document.getElementById('submittedProofLink');
    link.href = proof.url || '#';
    link.textContent = proof.name || 'View submitted proof';
    link.title = proof.name || 'View submitted proof';
    document.getElementById('submittedProofStatus').textContent = { pending: 'Awaiting verification', rejected: 'Correction requested', verified: 'Verified' }[proof.status] || 'Attached by Sales';
    document.getElementById('submittedProofAmount').textContent = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(proof.amount || 0));
    document.getElementById('submittedProofReference').textContent = proof.reference || 'Not supplied';
    updatePaymentProofPreview(proof.url, proof.name, receiptProofIsImage(proof));
    document.getElementById('manualPaymentProof').hidden = pending;
    document.getElementById('manualPaymentFields').hidden = pending;
    document.getElementById('verifiedAmount').required = !pending;
    document.getElementById('verifiedReference').required = !pending;
    document.getElementById('paymentRejectionNotes').hidden = true;
    proofInput.required = !pending;
    document.getElementById('verifiedAmount').value = '';
    document.getElementById('verifiedReference').value = '';
    document.getElementById('paymentReviewNotes').value = '';
    document.getElementById('bankConfirmed').checked = false;
    document.getElementById('rejectPaymentButton').hidden = !pending;
    showReceiptModal('verifyPaymentModal');
}

document.getElementById('verifyPaymentProof')?.addEventListener('change', function () {
    const summary = document.getElementById('verifyPaymentProofSummary');
    if (!summary) return;
    summary.textContent = this.files?.[0]?.name || 'No file chosen';
    if (paymentProofObjectUrl) URL.revokeObjectURL(paymentProofObjectUrl);
    paymentProofObjectUrl = null;
    const file = this.files?.[0];
    if (file) {
        paymentProofObjectUrl = URL.createObjectURL(file);
        updatePaymentProofPreview(paymentProofObjectUrl, file.name, file.type.startsWith('image/'));
    } else {
        updatePaymentProofPreview(currentPaymentProof.url, currentPaymentProof.name, receiptProofIsImage(currentPaymentProof));
    }
});

document.getElementById('verifyPaymentModal')?.addEventListener('hidden.bs.modal', () => {
    if (paymentProofObjectUrl) URL.revokeObjectURL(paymentProofObjectUrl);
    paymentProofObjectUrl = null;
    updatePaymentProofPreview('', '', false);
});

document.getElementById('receiptPageSize')?.addEventListener('change', function () {
    window.location.href = this.value;
});
</script>

<?php include '../../includes/footer.php'; ?>
