<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/payment-proof.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
$conn = getConnection();
salesReceiptEnsureSchema($conn);
$id = (int) ($_GET['receipt'] ?? 0);
$token = is_string($_GET['token'] ?? null) ? $_GET['token'] : '';
$receipt = paymentProofFindReceipt($conn, $id, $token);
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        paymentProofCheckCsrf($_POST['csrf_token'] ?? null);
        if (!$receipt) throw new RuntimeException('This link is invalid or expired. Ask Sales to send a new billing email.');
        paymentProofSubmit($conn, $id, $token, $_FILES['payment_proof'] ?? [], $_POST['payment_amount'] ?? '', $_POST['payment_reference'] ?? '');
        header('Location: payment-proof.php?receipt=' . $id . '&token=' . rawurlencode($token), true, 303);
        exit;
    } catch (Throwable $e) {
        $error = $e instanceof RuntimeException ? $e->getMessage() : 'Unable to submit proof. Please try again or contact Sales.';
        $receipt = paymentProofFindReceipt($conn, $id, $token);
    }
}
if (!$receipt) http_response_code(404);
$escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Submit payment proof | ERNI</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#eef3f8;color:#173047;font:16px/1.6 Arial,sans-serif}main{max-width:660px;margin:40px auto;padding:30px;background:#fff;border:1px solid #d5e1eb;border-radius:16px}h1{font-size:26px;line-height:1.3}h2{font-size:17px;color:#1f6392}label{display:block;margin:18px 0 5px;font-weight:bold}input,button{font:inherit;width:100%;padding:12px;border:1px solid #a8bbc9;border-radius:8px}button{margin-top:24px;background:#1f6392;color:white;cursor:pointer;font-weight:bold}.notice{padding:14px;background:#edf6ff;border-left:4px solid #1f6392;overflow-wrap:anywhere}.error{background:#fff0ee;border-color:#b52d26}.muted{font-size:14px;color:#506276}dl{display:grid;grid-template-columns:1fr 1fr;gap:8px}dd{margin:0;overflow-wrap:anywhere}@media(max-width:700px){main{margin:16px;padding:22px}}
</style></head><body><main>
<h2>ERNI Development Center Philippines, Inc.</h2><h1>Submit payment proof</h1>
<?php if ($error !== ''): ?><p class="notice error" role="alert"><?= $escape($error) ?></p><?php endif; ?>
<?php if (!$receipt): ?>
<p class="notice error">This link is invalid or expired. Please ask Sales to send a new billing email.</p>
<?php else: ?>
<dl><dt>Receipt</dt><dd><?= $escape($receipt['receipt_number']) ?></dd><dt>Amount due</dt><dd>₱<?= number_format((float) $receipt['amount_due'], 2) ?></dd></dl>
<?php if ($receipt['status'] === 'paid'): ?>
<p class="notice">Sales has verified your payment. Thank you.</p>
<?php elseif ($receipt['payment_proof_status'] === 'pending'): ?>
<p class="notice" role="status">Your proof has been received and is awaiting Sales verification. You do not need to submit it again.</p>
<p>Submitted file: <?= $escape($receipt['payment_proof_name']) ?></p>
<p class="muted">Submitting a proof does not mark the receipt as paid. Sales will check the transaction and email you the result.</p>
<?php elseif (paymentProofCanSubmit($receipt)): ?>
<?php if ($receipt['payment_proof_status'] === 'rejected'): ?><p class="notice error">Sales requested a correction: <?= $escape($receipt['payment_review_notes']) ?></p><?php endif; ?>
<p>Upload your payment slip or bank confirmation. Sales will review the transaction before confirming payment.</p>
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf_token" value="<?= $escape(paymentProofCsrfToken()) ?>">
<input type="hidden" name="MAX_FILE_SIZE" value="5242880">
<label for="paymentAmount">Amount paid (PHP)</label><input type="number" id="paymentAmount" name="payment_amount" min="0.01" max="9999999999.99" step="0.01" required value="<?= $escape($_POST['payment_amount'] ?? number_format((float) $receipt['amount_due'], 2, '.', '')) ?>">
<label for="paymentReference">Bank / payment transaction reference</label><input id="paymentReference" name="payment_reference" maxlength="150" required value="<?= $escape($_POST['payment_reference'] ?? '') ?>">
<label for="paymentProof">Payment proof</label><input type="file" id="paymentProof" name="payment_proof" accept=".pdf,.png,.jpg,.jpeg,.webp" required aria-describedby="fileHelp">
<p class="muted" id="fileHelp">PDF, PNG, JPG, JPEG, or WEBP. Maximum 5 MB.</p>
<button type="submit">Send proof to Sales</button></form>
<?php else: ?><p class="notice">This receipt is not accepting proof yet. Please contact Sales.</p><?php endif; ?>
<?php endif; ?>
<p class="muted">Keep this link private. It expires 14 days after the billing email is sent.</p>
</main></body></html>
