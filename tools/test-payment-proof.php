<?php
declare(strict_types=1);
// No email is sent. --database uses connection-local TEMPORARY tables only.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/payment-proof.php';

$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    $checks++;
}
function rejects(callable $callback, string $message): void {
    try { $callback(); } catch (RuntimeException $e) { check(true, $message); return; }
    check(false, $message);
}

check(paymentProofAmount('1234.56') === 1234.56, 'exact cent amount');
foreach (['0', '-1', '1.234', '1e5', 'NaN', '10000000000', [], ''] as $bad) {
    rejects(fn () => paymentProofAmount($bad), 'reject invalid amount');
}
rejects(fn () => paymentProofReference(''), 'reference required');
rejects(fn () => paymentProofReference(str_repeat('a', 151)), 'reference bounded');
check(paymentProofReference(' BANK-123 ') === 'BANK-123', 'reference normalization');
paymentProofCheckCsrf(paymentProofCsrfToken());
rejects(fn () => paymentProofCheckCsrf('wrong'), 'CSRF required');
rejects(fn () => paymentProofCheckCsrf([]), 'CSRF rejects arrays');
foreach (['sent', 'viewed', 'overdue'] as $status) {
    check(paymentProofCanSubmit(['status' => $status, 'payment_proof_status' => 'none']), 'unpaid accepts initial proof');
    check(paymentProofCanSubmit(['status' => $status, 'payment_proof_status' => 'rejected']), 'rejected allows correction');
    check(!paymentProofCanSubmit(['status' => $status, 'payment_proof_status' => 'pending']), 'pending disallows replacement');
}
foreach (['paid', 'draft'] as $status) check(!paymentProofCanSubmit(['status' => $status]), 'paid/draft cannot submit');
$receipt = ['amount_due' => 500, 'payment_proof_amount' => 500, 'payment_proof_reference' => 'BANK-123', 'payment_proof_status' => 'pending'];
paymentProofValidateApproval($receipt, 500, 'BANK-123', true);
rejects(fn () => paymentProofValidateApproval($receipt, 500, 'BANK-123', false), 'bank confirmation required');
rejects(fn () => paymentProofValidateApproval($receipt, 499, 'BANK-123', true), 'wrong received amount');
rejects(fn () => paymentProofValidateApproval($receipt, 500, 'WRONG', true), 'wrong transaction reference');
rejects(fn () => paymentProofValidateApproval(array_merge($receipt, ['payment_proof_amount' => 400]), 500, 'BANK-123', true), 'submitted amount mismatch');
$file = tempnam(sys_get_temp_dir(), 'erni-proof-test-');
try {
    file_put_contents($file, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a8ZkAAAAASUVORK5CYII='));
    $upload = ['error' => UPLOAD_ERR_OK, 'tmp_name' => $file, 'name' => 'proof.png'];
    check(paymentProofValidateFile($upload)['mime'] === 'image/png', 'valid PNG');
    rejects(fn () => paymentProofValidateFile(array_merge($upload, ['name' => 'proof.pdf'])), 'extension/MIME mismatch');
    file_put_contents($file, '<?php echo "not an image";');
    rejects(fn () => paymentProofValidateFile($upload), 'disguised executable');
    file_put_contents($file, str_repeat('x', 5 * 1024 * 1024 + 1));
    clearstatcache(true, $file);
    rejects(fn () => paymentProofValidateFile($upload), 'file limit');
} finally { unlink($file); }

if (in_array('--database', $argv, true)) {
    $conn = getConnection();
    if (storageUsesDatabase()) throw new RuntimeException('Database integration test requires filesystem upload configuration.');
    // These names shadow production tables for this connection; they disappear on disconnect.
    if (!$conn->query("CREATE TEMPORARY TABLE receipts (
        id INT PRIMARY KEY, receipt_number VARCHAR(50), amount_due DECIMAL(12,2), amount_paid DECIMAL(12,2) DEFAULT 0,
        status VARCHAR(20), payment_proof_path VARCHAR(500), payment_proof_name VARCHAR(255),
        payment_token_hash VARCHAR(64), payment_token_expires_at TIMESTAMP NULL,
        payment_proof_status VARCHAR(20) DEFAULT 'none', payment_proof_reference VARCHAR(150),
        payment_proof_amount DECIMAL(12,2), payment_proof_submitted_at TIMESTAMP NULL,
        payment_review_notes TEXT, payment_verified_by INT, payment_verified_at TIMESTAMP NULL)")) {
        throw new RuntimeException('Unable to create the isolated receipt test table.');
    }
    if (!$conn->query("CREATE TEMPORARY TABLE audit_logs (
        user_id INT, action VARCHAR(100), table_name VARCHAR(50), record_id INT, old_data TEXT, new_data TEXT,
        ip_address VARCHAR(100), user_agent TEXT, status VARCHAR(30), description TEXT)")) {
        throw new RuntimeException('Unable to create the isolated audit test table.');
    }
    $conn->query("INSERT INTO receipts (id, receipt_number, amount_due, status, payment_proof_path, payment_proof_name, payment_proof_status, payment_proof_reference, payment_proof_amount)
        VALUES (1, 'TEST-001', 500, 'sent', 'uploads/test-proof.png', 'proof.png', 'pending', 'BANK-123', 500)");
    $url = paymentProofCreateLink($conn, 1);
    parse_str(parse_url($url, PHP_URL_QUERY), $query);
    $token = $query['token'];
    check(paymentProofFindReceipt($conn, 1, $token) !== null, 'valid token');
    check(paymentProofFindReceipt($conn, 2, $token) === null, 'token scoped to receipt');
    check(paymentProofFindReceipt($conn, 1, str_repeat('0', 64)) === null, 'wrong token');
    $stored = $conn->query('SELECT payment_token_hash FROM receipts WHERE id = 1')->fetch_assoc();
    check($stored['payment_token_hash'] === hash('sha256', $token) && $stored['payment_token_hash'] !== $token, 'only token hash stored');
    $conn->query("UPDATE receipts SET payment_token_expires_at = '2000-01-01 00:00:00' WHERE id = 1");
    check(paymentProofFindReceipt($conn, 1, $token) === null, 'expired token');
    $input = ['review_proof_path' => 'uploads/test-proof.png', 'verified_amount' => '500', 'verified_reference' => 'BANK-123', 'bank_confirmed' => '1', 'review_notes' => 'Matched bank record'];
    rejects(fn () => paymentProofReview($conn, 1, 7, array_merge($input, ['review_proof_path' => 'old-proof.png']), [], true), 'stale proof rejection');
    rejects(fn () => paymentProofReview($conn, 1, 7, array_merge($input, ['review_notes' => '']), [], false), 'rejection needs reason');
    paymentProofReview($conn, 1, 7, array_merge($input, ['review_notes' => 'Reference does not appear in bank record']), [], false);
    $row = $conn->query('SELECT * FROM receipts WHERE id = 1')->fetch_assoc();
    check($row['status'] === 'sent' && $row['payment_proof_status'] === 'rejected' && (float) $row['amount_paid'] === 0.0, 'rejected stays unpaid');
    check($row['payment_verified_at'] === null, 'rejection is not verification');
    $conn->query("UPDATE receipts SET payment_proof_status = 'pending' WHERE id = 1");
    rejects(fn () => paymentProofReview($conn, 1, 7, ['review_proof_path' => $input['review_proof_path']], [], true), 'checkbox remains mandatory');
    paymentProofReview($conn, 1, 7, ['review_proof_path' => $input['review_proof_path'], 'bank_confirmed' => '1'], [], true);
    $row = $conn->query('SELECT * FROM receipts WHERE id = 1')->fetch_assoc();
    check($row['status'] === 'paid' && $row['payment_proof_status'] === 'verified' && (float) $row['amount_paid'] === 500.0, 'approval marks paid');
    check((int) $row['payment_verified_by'] === 7 && $row['payment_verified_at'] !== null, 'reviewer and time recorded');
    rejects(fn () => paymentProofReview($conn, 1, 7, $input, [], true), 'duplicate approval prevented');
    check((int) $conn->query('SELECT COUNT(*) AS n FROM audit_logs')->fetch_assoc()['n'] === 2, 'only successful decisions audited');
}
echo "PASS: {$checks} payment proof checks. No emails sent.\n";
