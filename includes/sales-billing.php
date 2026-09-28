<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

function salesBillingEnsureSchema(mysqli|PgsqlMysqliCompatConnection $conn): void
{
    foreach (['billing_snapshot' => 'TEXT NULL', 'billing_request_key' => 'VARCHAR(64) NULL'] as $column => $definition) {
        if (!dbColumnExists($conn, 'sales_reports', $column) && !$conn->query("ALTER TABLE sales_reports ADD COLUMN {$column} {$definition}")) {
            throw new RuntimeException('Unable to prepare report billing history.');
        }
    }
}

function salesBillingCents(mixed $value): int
{
    if (!is_scalar($value) || !is_numeric($value) || !is_finite((float) $value) || (float) $value < 0 || (float) $value > 9999999999.99) {
        throw new RuntimeException('Costs must be valid, non-negative peso amounts.');
    }
    return (int) round((float) $value * 100);
}

function salesBillingCurrentCosts(mysqli|PgsqlMysqliCompatConnection $conn, int $projectId): array
{
    $tech = $conn->query("SELECT COALESCE(SUM(estimated_cost), 0) AS total FROM tech_recommendations WHERE project_id = {$projectId}")->fetch_assoc();
    $team = $conn->query("SELECT MAX(COALESCE(developer_count, 0)) AS total FROM methodology_phases WHERE project_id = {$projectId}")->fetch_assoc();
    return ['technology_cost' => salesBillingCents($tech['total']), 'developer_cost' => max(0, (int) ($team['total'] ?? 0)) * 1500000, 'resource_cost' => salesBillingCents($tech['total'])];
}

/** Snapshots are cumulative high-water marks; reductions never rebill old costs. */
function salesBillingBaseline(array $reports): array
{
    $baseline = ['base' => ['technology_cost' => 0, 'developer_cost' => 0, 'resource_cost' => 0], 'additions' => []];
    foreach ($reports as $report) {
        $snapshot = json_decode((string) ($report['billing_snapshot'] ?? ''), true);
        foreach ($baseline['base'] as $key => $value) {
            $billed = is_array($snapshot) ? (int) ($snapshot['base'][$key] ?? 0) : salesBillingCents($report[$key] ?? 0);
            $baseline['base'][$key] = max($value, $billed);
        }
        foreach (($snapshot['additions'] ?? []) as $key => $item) {
            if ((int) ($item['cents'] ?? 0) > (int) ($baseline['additions'][$key]['cents'] ?? 0)) $baseline['additions'][$key] = $item;
        }
    }
    return $baseline;
}

function salesBillingHistory(mysqli|PgsqlMysqliCompatConnection $conn, int $projectId): array
{
    $stmt = $conn->prepare('SELECT sr.* FROM sales_reports sr WHERE sr.project_id = ? AND EXISTS (SELECT 1 FROM receipts r WHERE r.report_id = sr.id)');
    if (!$stmt) throw new RuntimeException('Unable to read project billing history.');
    $stmt->bind_param('i', $projectId);
    if (!$stmt->execute()) throw new RuntimeException('Unable to read project billing history.');
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return salesBillingBaseline($rows);
}

function salesBillingDelta(array $current, array $baseline, array $fees): array
{
    $snapshot = $baseline;
    $delta = ['base' => [], 'additions' => [], 'total' => 0];
    foreach ($baseline['base'] as $key => $alreadyBilled) {
        $new = max(0, (int) ($current[$key] ?? 0) - $alreadyBilled);
        $delta['base'][$key] = $new;
        $delta['total'] += $new;
        $snapshot['base'][$key] = max($alreadyBilled, (int) ($current[$key] ?? 0));
    }
    $seen = [];
    foreach ($fees as $fee) {
        if (!is_array($fee)) throw new RuntimeException('Invalid billable addition.');
        $name = trim((string) ($fee['name'] ?? ''));
        $cents = salesBillingCents(($fee['cost'] ?? '') === '' ? 0 : $fee['cost']);
        if ($name === '' && $cents === 0) continue;
        if ($name === '' || strlen($name) > 150 || $cents === 0) throw new RuntimeException('Each addition needs a description and an amount greater than zero.');
        $key = strtolower(preg_replace('/\s+/u', ' ', $name) ?? $name);
        if (isset($seen[$key])) throw new RuntimeException('Use only one row per addition description.');
        $seen[$key] = true;
        $new = max(0, $cents - (int) ($baseline['additions'][$key]['cents'] ?? 0));
        if ($new > 0) {
            $delta['additions'][] = ['name' => $name, 'cost' => $new / 100];
            $delta['total'] += $new;
            $snapshot['additions'][$key] = ['name' => $name, 'cents' => $cents];
        }
    }
    if ($delta['total'] > 999999999999) throw new RuntimeException('The report total exceeds the supported amount.');
    return $delta + ['snapshot' => $snapshot];
}

function salesBillingCreateReport(mysqli|PgsqlMysqliCompatConnection $conn, int $projectId, int $clientId, int $actorId, string $requestKey, array $fees, ?array $expectedBase = null): array
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $requestKey)) throw new RuntimeException('Refresh the report form and try again.');
    $conn->begin_transaction();
    try {
        // Serialize report creation per project, including retries from another tab.
        $stmt = $conn->prepare('SELECT id, client_id FROM projects WHERE id = ? FOR UPDATE');
        if (!$stmt) throw new RuntimeException('Unable to load project.');
        $stmt->bind_param('i', $projectId);
        if (!$stmt->execute()) throw new RuntimeException('Unable to load project.');
        $project = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$project || (int) $project['client_id'] !== $clientId) throw new RuntimeException('The selected project does not belong to this client.');
        $stmt = $conn->prepare('SELECT sr.id AS report_id, r.id AS receipt_id FROM sales_reports sr JOIN receipts r ON r.report_id = sr.id WHERE sr.project_id = ? AND sr.billing_request_key = ?');
        $stmt->bind_param('is', $projectId, $requestKey);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($existing) { $conn->commit(); return $existing; }
        $baseline = salesBillingHistory($conn, $projectId);
        $current = salesBillingCurrentCosts($conn, $projectId);
        if ($expectedBase !== null && $expectedBase !== $current) throw new RuntimeException('The project costs changed while this form was open. Review the refreshed amounts before saving.');
        $delta = salesBillingDelta($current, $baseline, $fees);
        if ($delta['total'] <= 0) throw new RuntimeException('These project costs have already been billed. Add a new billable item or increase the project scope before creating another report.');
        $technology = $delta['base']['technology_cost'] / 100;
        $developers = $delta['base']['developer_cost'] / 100;
        $resources = $delta['base']['resource_cost'] / 100;
        $total = $delta['total'] / 100;
        $other = json_encode($delta['additions'], JSON_THROW_ON_ERROR);
        $snapshot = json_encode($delta['snapshot'], JSON_THROW_ON_ERROR);
        $prior = array_sum($baseline['base']) + array_sum(array_column($baseline['additions'], 'cents'));
        $notes = $prior > 0 ? 'Supplemental billing: only new costs and additions are charged. Previously billed costs are excluded.' : 'Project billing.';
        foreach ($delta['additions'] as $item) $notes .= "\n" . $item['name'] . ': PHP ' . number_format($item['cost'], 2);
        $stmt = $conn->prepare('INSERT INTO sales_reports (project_id, client_id, technology_cost, developer_cost, resource_cost, other_fees, total_cost, notes, created_by, billing_snapshot, billing_request_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        if (!$stmt) throw new RuntimeException('Unable to prepare report.');
        $stmt->bind_param('iidddsdsiss', $projectId, $clientId, $technology, $developers, $resources, $other, $total, $notes, $actorId, $snapshot, $requestKey);
        if (!$stmt->execute()) throw new RuntimeException('Unable to save report.');
        $reportId = (int) $stmt->insert_id;
        $stmt->close();
        $stmt = $conn->prepare('SELECT name, email FROM clients WHERE id = ?');
        $stmt->bind_param('i', $clientId);
        $stmt->execute();
        $client = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$client) throw new RuntimeException('Client not found.');
        $number = 'ERNI-' . date('Ymd') . '-' . str_pad((string) $reportId, 5, '0', STR_PAD_LEFT);
        $due = date('Y-m-d', strtotime('+30 days'));
        $stmt = $conn->prepare("INSERT INTO receipts (report_id, receipt_number, client_name, client_email, amount_due, due_date, status, generated_by) VALUES (?, ?, ?, ?, ?, ?, 'draft', ?)");
        if (!$stmt) throw new RuntimeException('Unable to prepare receipt.');
        $stmt->bind_param('isssdsi', $reportId, $number, $client['name'], $client['email'], $total, $due, $actorId);
        if (!$stmt->execute()) throw new RuntimeException('Unable to save receipt.');
        $receiptId = (int) $stmt->insert_id;
        $stmt->close();
        if (!logActivity($actorId, 'create_report', 'sales_reports', $reportId, null, ['project_id' => $projectId, 'total_cost' => $total, 'billing_snapshot' => $delta['snapshot']])
            || !logActivity($actorId, 'create_receipt', 'receipts', $receiptId, null, ['report_id' => $reportId, 'receipt_number' => $number])) {
            throw new RuntimeException('Unable to record the billing audit history.');
        }
        if (!$conn->commit()) throw new RuntimeException('Unable to save billing changes.');
        return ['report_id' => $reportId, 'receipt_id' => $receiptId];
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}
