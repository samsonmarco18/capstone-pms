<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/receipt-excel.php';

function salesReceiptEnsureSchema(mysqli|PgsqlMysqliCompatConnection $conn): void
{
    static $done = false;
    if ($done) return;
    $columns = [
        'payment_proof_path' => 'VARCHAR(500) NULL',
        'payment_proof_name' => 'VARCHAR(255) NULL',
        'payment_verified_by' => 'INT NULL',
        'payment_verified_at' => (DB_DRIVER === 'pgsql' ? 'TIMESTAMP' : 'DATETIME') . ' NULL',
        'email_sent_at' => (DB_DRIVER === 'pgsql' ? 'TIMESTAMP' : 'DATETIME') . ' NULL',
        'payment_token_hash' => 'VARCHAR(64) NULL',
        'payment_token_expires_at' => (DB_DRIVER === 'pgsql' ? 'TIMESTAMP' : 'DATETIME') . ' NULL',
        'payment_proof_status' => "VARCHAR(20) NOT NULL DEFAULT 'none'",
        'payment_proof_reference' => 'VARCHAR(150) NULL',
        'payment_proof_amount' => 'DECIMAL(12,2) NULL',
        'payment_proof_submitted_at' => (DB_DRIVER === 'pgsql' ? 'TIMESTAMP' : 'DATETIME') . ' NULL',
        'payment_review_notes' => 'TEXT NULL',
    ];
    foreach ($columns as $column => $definition) {
        if (!dbColumnExists($conn, 'receipts', $column) && !$conn->query("ALTER TABLE receipts ADD COLUMN {$column} {$definition}")) {
            throw new RuntimeException('Unable to prepare payment proof storage.');
        }
    }
    $done = true;
}

function paymentProofCsrfToken(): string
{
    return $_SESSION['payment_proof_csrf'] ??= bin2hex(random_bytes(32));
}

function paymentProofCheckCsrf(mixed $token): void
{
    if (!is_string($token) || !hash_equals(paymentProofCsrfToken(), $token)) {
        throw new RuntimeException('This form has expired. Refresh the page and try again.');
    }
}

function paymentProofAmount(mixed $value): float
{
    if (!is_scalar($value) || !preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', trim((string) $value)) || (float) $value <= 0) {
        throw new RuntimeException('Enter a payment amount greater than zero with up to two decimal places.');
    }
    return round((float) $value, 2);
}

function paymentProofReference(mixed $value): string
{
    $value = is_string($value) ? trim($value) : '';
    if ($value === '' || strlen($value) > 150) {
        throw new RuntimeException('Enter the bank or payment transaction reference (up to 150 characters).');
    }
    return $value;
}

function paymentProofValidateFile(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_file((string) ($file['tmp_name'] ?? ''))) {
        throw new RuntimeException('Please upload a payment proof file. If the upload failed, try a smaller file.');
    }
    $size = filesize($file['tmp_name']);
    if ($size === false || $size < 1 || $size > 5 * 1024 * 1024) {
        throw new RuntimeException('Payment proof must be between 1 byte and 5 MB.');
    }
    $name = basename(str_replace('\\', '/', (string) ($file['name'] ?? 'payment-proof')));
    if (strlen($name) > 255) throw new RuntimeException('The file name is too long. Please rename the file.');
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $types = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset($types[$extension]) || $mime !== $types[$extension]) {
        throw new RuntimeException('Upload a valid PDF, PNG, JPG, JPEG, or WEBP file.');
    }
    if ($extension !== 'pdf' && @getimagesize($file['tmp_name']) === false) {
        throw new RuntimeException('The uploaded image cannot be read.');
    }
    return ['name' => $name, 'extension' => $extension, 'mime' => $mime];
}

function salesReceiptSavePaymentProof(array $file): ?array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    $validated = paymentProofValidateFile($file);
    $target = storageBuildUploadTarget(bin2hex(random_bytes(16)) . '.' . $validated['extension'], 'payment-proofs/' . date('Y/m/d'));
    if (!move_uploaded_file($file['tmp_name'], $target['absolute_path'])) {
        throw new RuntimeException('Unable to save the payment proof. Please try again.');
    }
    try {
        storagePersistFile($target['relative_path'], $target['absolute_path'], $validated['name'], $validated['mime']);
    } catch (Throwable $e) {
        // The target was generated locally for this upload; remove an unrecorded file.
        if (is_file($target['absolute_path'])) unlink($target['absolute_path']);
        throw $e;
    }
    return ['name' => $validated['name'], 'path' => $target['relative_path']];
}

function paymentProofCreateLink(mysqli|PgsqlMysqliCompatConnection $conn, int $receiptId): string
{
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $expirySql = DB_DRIVER === 'pgsql' ? "CURRENT_TIMESTAMP + INTERVAL '14 days'" : 'DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 14 DAY)';
    $stmt = $conn->prepare("UPDATE receipts SET payment_token_hash = ?, payment_token_expires_at = {$expirySql} WHERE id = ? AND status <> 'paid'");
    if (!$stmt) throw new RuntimeException('Unable to create the payment submission link.');
    $stmt->bind_param('si', $hash, $receiptId);
    if (!$stmt->execute() || $conn->affected_rows !== 1) throw new RuntimeException('Unable to create the payment submission link.');
    $stmt->close();
    return rtrim(BASE_URL, '/') . '/payment-proof.php?receipt=' . $receiptId . '&token=' . $token;
}

function paymentProofFindReceipt(mysqli|PgsqlMysqliCompatConnection $conn, int $id, string $token, bool $lock = false): ?array
{
    if ($id <= 0 || !preg_match('/^[a-f0-9]{64}$/D', $token)) return null;
    $hash = hash('sha256', $token);
    $stmt = $conn->prepare('SELECT * FROM receipts WHERE id = ? AND payment_token_hash = ? AND payment_token_expires_at > CURRENT_TIMESTAMP' . ($lock ? ' FOR UPDATE' : ''));
    if (!$stmt) throw new RuntimeException('Unable to load the payment request.');
    $stmt->bind_param('is', $id, $hash);
    if (!$stmt->execute()) throw new RuntimeException('Unable to load the payment request.');
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function paymentProofCanSubmit(array $receipt): bool
{
    return in_array($receipt['status'], ['sent', 'viewed', 'overdue'], true)
        && in_array($receipt['payment_proof_status'] ?? 'none', ['none', 'rejected'], true);
}

function paymentProofNotifySales(mysqli|PgsqlMysqliCompatConnection $conn, array $receipt): void
{
    $sales = $conn->query("SELECT id FROM users WHERE role = 'sales' AND status = 'active'");
    if (!$sales) return;
    while ($user = $sales->fetch_assoc()) {
        createNotification((int) $user['id'], 'Payment proof awaiting verification',
            'The client submitted proof for receipt ' . $receipt['receipt_number'] . '. Review the proof and confirm the transaction against your payment records.',
            'approval', 'receipts', (int) $receipt['id'], '/modules/sales/receipt.php?receipt_id=' . (int) $receipt['id']);
    }
}

function paymentProofSubmit(mysqli|PgsqlMysqliCompatConnection $conn, int $id, string $token, array $file, mixed $amountInput, mixed $referenceInput): void
{
    $amount = paymentProofAmount($amountInput);
    $reference = paymentProofReference($referenceInput);
    paymentProofValidateFile($file);
    if (storageUsesDatabase()) storageEnsureBlobSchema($conn);
    $proof = null;
    $conn->begin_transaction();
    try {
        $receipt = paymentProofFindReceipt($conn, $id, $token, true);
        if (!$receipt || !paymentProofCanSubmit($receipt)) {
            throw new RuntimeException('This request is no longer accepting proof. Refresh the page to see its current status.');
        }
        $proof = salesReceiptSavePaymentProof($file);
        if (!$proof) throw new RuntimeException('Please choose a payment proof file.');
        $stmt = $conn->prepare("UPDATE receipts SET payment_proof_path = ?, payment_proof_name = ?, payment_proof_reference = ?, payment_proof_amount = ?, payment_proof_status = 'pending', payment_proof_submitted_at = NOW(), payment_review_notes = NULL, payment_verified_by = NULL, payment_verified_at = NULL WHERE id = ?");
        if (!$stmt) throw new RuntimeException('Unable to save payment proof.');
        $stmt->bind_param('sssdi', $proof['path'], $proof['name'], $reference, $amount, $id);
        if (!$stmt->execute()) throw new RuntimeException('Unable to save payment proof.');
        $stmt->close();
        if (!logActivity(null, 'submit_payment_proof', 'receipts', $id,
            ['payment_proof_path' => $receipt['payment_proof_path'], 'payment_proof_status' => $receipt['payment_proof_status']],
            ['payment_proof_path' => $proof['path'], 'payment_proof_name' => $proof['name'], 'reference' => $reference, 'amount' => $amount], 'success', 'Client submitted proof using the receipt email link.')) {
            throw new RuntimeException('Unable to record the payment submission. Please try again.');
        }
        if (!$conn->commit()) throw new RuntimeException('Unable to save the payment submission.');
    } catch (Throwable $e) {
        $conn->rollback();
        if ($proof) storageDeleteFile($proof['path']);
        throw $e;
    }
    // The durable pending proof remains visible even if notification delivery fails.
    try { paymentProofNotifySales($conn, $receipt); }
    catch (Throwable $e) { error_log('Payment proof notification failed: ' . $e->getMessage()); }
}

function paymentProofValidateApproval(array $receipt, float $receivedAmount, string $reference, bool $confirmed): void
{
    if (!$confirmed) throw new RuntimeException('Confirm that you checked the transaction against the bank or payment provider record.');
    if (abs($receivedAmount - (float) $receipt['amount_due']) >= 0.005) {
        throw new RuntimeException('The verified amount must match the receipt total before it can be marked paid.');
    }
    if (($receipt['payment_proof_status'] ?? '') === 'pending'
        && (abs($receivedAmount - (float) $receipt['payment_proof_amount']) >= 0.005
            || $reference !== (string) $receipt['payment_proof_reference'])) {
        throw new RuntimeException('The submitted amount or reference does not match your payment record. Reject the proof and request a correction.');
    }
}

function paymentProofReview(mysqli|PgsqlMysqliCompatConnection $conn, int $id, int $reviewerId, array $input, array $file, bool $approve): void
{
    $notes = trim((string) ($input['review_notes'] ?? ''));
    if (strlen($notes) > 2000 || (!$approve && $notes === '')) throw new RuntimeException('Provide a reason for rejection (up to 2,000 characters).');
    if (storageUsesDatabase()) storageEnsureBlobSchema($conn);
    $newProof = null;
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('SELECT * FROM receipts WHERE id = ? FOR UPDATE');
        if (!$stmt) throw new RuntimeException('Unable to load receipt.');
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) throw new RuntimeException('Unable to load receipt.');
        $receipt = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$receipt || !in_array($receipt['status'], ['sent', 'viewed', 'overdue'], true)) {
            throw new RuntimeException('This receipt is not awaiting payment verification.');
        }
        if (!hash_equals((string) ($receipt['payment_proof_path'] ?? ''), (string) ($input['review_proof_path'] ?? ''))) {
            throw new RuntimeException('The payment proof has changed. Reload and review the latest submission.');
        }
        $pending = ($receipt['payment_proof_status'] ?? '') === 'pending';
        if (!$approve) {
            if (!$pending) throw new RuntimeException('There is no pending client proof to reject.');
            $stmt = $conn->prepare("UPDATE receipts SET payment_proof_status = 'rejected', payment_review_notes = ?, payment_verified_by = NULL, payment_verified_at = NULL WHERE id = ?");
            if (!$stmt) throw new RuntimeException('Unable to reject payment proof.');
            $stmt->bind_param('si', $notes, $id);
        } else {
            // Submitted proof is already the source of truth; Sales confirms it once.
            $amount = paymentProofAmount($pending ? $receipt['payment_proof_amount'] : ($input['verified_amount'] ?? ''));
            $reference = paymentProofReference($pending ? $receipt['payment_proof_reference'] : ($input['verified_reference'] ?? ''));
            paymentProofValidateApproval($receipt, $amount, $reference, ($input['bank_confirmed'] ?? '') === '1');
            if (!$pending) {
                $newProof = salesReceiptSavePaymentProof($file);
                if (!$newProof) throw new RuntimeException('Attach the proof received by Sales before approving payment.');
            }
            $proofPath = $pending ? $receipt['payment_proof_path'] : $newProof['path'];
            $proofName = $pending ? $receipt['payment_proof_name'] : $newProof['name'];
            $stmt = $conn->prepare("UPDATE receipts SET status = 'paid', amount_paid = ?, payment_proof_path = ?, payment_proof_name = ?, payment_proof_reference = ?, payment_proof_amount = ?, payment_proof_status = 'verified', payment_review_notes = ?, payment_verified_by = ?, payment_verified_at = NOW(), payment_proof_submitted_at = COALESCE(payment_proof_submitted_at, NOW()) WHERE id = ?");
            if (!$stmt) throw new RuntimeException('Unable to verify payment proof.');
            $stmt->bind_param('dsssdsii', $amount, $proofPath, $proofName, $reference, $amount, $notes, $reviewerId, $id);
        }
        if (!$stmt->execute()) throw new RuntimeException('Unable to save the review decision.');
        $stmt->close();
        if (!logActivity($reviewerId, $approve ? 'verify_payment_receipt' : 'reject_payment_proof', 'receipts', $id,
            ['payment_proof_path' => $receipt['payment_proof_path'], 'payment_proof_status' => $receipt['payment_proof_status']],
            ['notes' => $notes, 'payment_proof_path' => $approve ? $proofPath : $receipt['payment_proof_path'], 'amount' => $approve ? $amount : $receipt['payment_proof_amount'], 'reference' => $approve ? $reference : $receipt['payment_proof_reference']])) {
            throw new RuntimeException('Unable to record the review decision.');
        }
        if (!$conn->commit()) throw new RuntimeException('Unable to save the review decision.');
    } catch (Throwable $e) {
        $conn->rollback();
        if ($newProof) storageDeleteFile($newProof['path']);
        throw $e;
    }
}

function paymentProofEmailReview(array $receipt, bool $approved, string $notes): array
{
    $email = (string) (($receipt['client_email'] ?? '') ?: ($receipt['fallback_client_email'] ?? ''));
    $company = 'ERNI Development Center Philippines, Inc.';
    $message = $approved
        ? 'Sales has verified your payment. Your official paid receipt is attached as an Excel file.'
        : 'Sales could not verify your payment proof. Reason: ' . $notes . "\nPlease use the submission link in your billing email to upload corrected proof. If it has expired, contact Sales for a new link.";
    $text = $company . "\nReceipt #" . $receipt['receipt_number'] . "\n\n" . $message;
    $attachments = $approved ? [[
        'name' => 'paid_receipt_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $receipt['receipt_number']) . '.xlsx',
        'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'content' => salesReceiptRenderExcel($receipt, true),
    ]] : [];
    return sendSystemEmail($email, $company . ($approved ? ' Payment verified #' : ' Payment proof needs correction #') . $receipt['receipt_number'],
        '<div style="font-family:Arial,sans-serif;line-height:1.6">' . nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')) . '</div>', $text, $attachments);
}
