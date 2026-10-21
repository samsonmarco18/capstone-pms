<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/sales-billing.php';
$checks = 0;
function billingCheck(bool $ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: ' . $label); $checks++; }
function billingRejects(callable $fn, string $label): void { try { $fn(); } catch (RuntimeException $e) { billingCheck(true, $label); return; } billingCheck(false, $label); }
$base = ['technology_cost' => 10000, 'developer_cost' => 1500000, 'resource_cost' => 10000];
$empty = salesBillingBaseline([]);
billingCheck(salesBillingDelta(['technology_cost' => 1000000, 'developer_cost' => 8000000, 'resource_cost' => 0], $empty, [])['total'] === 11880000, 'required example final project price');
$first = salesBillingDelta($base, $empty, []);
billingCheck($first['total'] === 2003200, 'initial full report includes overhead and profit');
$paid = salesBillingBaseline([['technology_cost' => 100, 'developer_cost' => 15000, 'resource_cost' => 100]]);
billingCheck(salesBillingDelta($base, $paid, [])['total'] === 0, 'unchanged paid report blocked');
$extra = salesBillingDelta($base, $paid, [['name' => 'New export feature', 'cost' => '2500.50']]);
billingCheck($extra['total'] === 250050, 'only new addition charged');
$after = salesBillingBaseline([['billing_snapshot' => json_encode($extra['snapshot'])]]);
billingCheck(salesBillingDelta($base, $after, [['name' => ' NEW EXPORT FEATURE ', 'cost' => '2500.50']])['total'] === 0, 'same named addition cannot be charged twice');
billingCheck(salesBillingDelta($base, $after, [['name' => 'New export feature', 'cost' => '3000.50']])['total'] === 50000, 'increased item charges only difference');
billingCheck(salesBillingDelta(array_merge($base, ['developer_cost' => 3000000]), $after, [])['total'] === 1980000, 'new developers with overhead and profit only');
$lower = salesBillingDelta(array_merge($base, ['technology_cost' => 100]), $after, []);
billingCheck(salesBillingDelta($base, $lower['snapshot'], [])['total'] === 0, 'price reduction then restoration does not rebill');
billingRejects(fn () => salesBillingDelta($base, $paid, [['name' => 'Bad', 'cost' => -10]]), 'negative costs');
billingRejects(fn () => salesBillingDelta($base, $paid, [['name' => '', 'cost' => 10]]), 'missing description');
billingRejects(fn () => salesBillingDelta($base, $paid, [['name' => 'Same', 'cost' => 10], ['name' => 'same', 'cost' => 20]]), 'duplicate additions within report');

if (in_array('--database', $argv, true)) {
    $conn = getConnection();
    $queries = [
        'CREATE TEMPORARY TABLE projects (id INT PRIMARY KEY, client_id INT)',
        'CREATE TEMPORARY TABLE clients (id INT PRIMARY KEY, name VARCHAR(100), email VARCHAR(100))',
        'CREATE TEMPORARY TABLE technologies (id INT, name VARCHAR(100))',
        'CREATE TEMPORARY TABLE tech_recommendations (project_id INT, tech_id INT, estimated_cost DECIMAL(12,2))',
        'CREATE TEMPORARY TABLE methodology_phases (id INT, project_id INT, developer_count INT, sequence_no INT, name VARCHAR(100), start_date DATE, end_date DATE)',
        'CREATE TEMPORARY TABLE methodology_tasks (id INT, phase_id INT, developer_entity_key VARCHAR(80), start_date DATE, end_date DATE)',
        'CREATE TEMPORARY TABLE methodology_task_departments (project_id INT, methodology_task_id INT, department VARCHAR(100), required_count INT)',
        'CREATE TEMPORARY TABLE expert_project_estimations (id INT, project_id INT, allocation_json TEXT)',
        "CREATE TEMPORARY TABLE sales_reports (id INT AUTO_INCREMENT PRIMARY KEY, project_id INT, client_id INT, technology_cost DECIMAL(12,2), developer_cost DECIMAL(12,2), resource_cost DECIMAL(12,2), other_fees TEXT, total_cost DECIMAL(12,2), notes TEXT, created_by INT, billing_snapshot TEXT, billing_request_key VARCHAR(64))",
        "CREATE TEMPORARY TABLE receipts (id INT AUTO_INCREMENT PRIMARY KEY, report_id INT, receipt_number VARCHAR(50), client_name VARCHAR(100), client_email VARCHAR(100), amount_due DECIMAL(12,2), due_date DATE, status VARCHAR(20), generated_by INT)",
        'CREATE TEMPORARY TABLE audit_logs (user_id INT, action VARCHAR(100), table_name VARCHAR(50), record_id INT, old_data TEXT, new_data TEXT, ip_address VARCHAR(100), user_agent TEXT, status VARCHAR(30), description TEXT)',
    ];
    foreach ($queries as $sql) if (!$conn->query($sql)) throw new RuntimeException('Cannot create isolated test table.');
    $conn->query('INSERT INTO projects VALUES (1, 1)');
    $conn->query("INSERT INTO clients VALUES (1, 'Client', 'client@example.invalid')");
    $conn->query("INSERT INTO technologies VALUES (1, 'Hosting')");
    $conn->query('INSERT INTO tech_recommendations VALUES (1, 1, 100)');
    $conn->query("INSERT INTO methodology_phases VALUES (2, 1, 2, 2, 'Development', '2026-09-01', '2026-09-20')");
    $conn->query("INSERT INTO methodology_tasks VALUES (1, 2, 'dev-a', '2026-09-01', '2026-09-20'), (2, 2, 'dev-b', '2026-09-01', '2026-09-15')");
    $conn->query("INSERT INTO methodology_task_departments VALUES (1, 1, 'Backend', 1), (1, 2, 'Backend', 1)");
    $savedAllocation = json_encode([
        ['department' => 'Backend', 'position' => 1, 'developer_type' => 'Senior Dev'],
        ['department' => 'Backend', 'position' => 2, 'developer_type' => 'Junior Dev'],
    ]);
    $stmt = $conn->prepare('INSERT INTO expert_project_estimations VALUES (1, 1, ?)');
    $stmt->bind_param('s', $savedAllocation);
    $stmt->execute();
    $pricing = projectEstimationProjectPricing($conn, 1);
    billingCheck($pricing['development_cost'] === '75961.50', 'actual salary tiers divided by 26 and individual roadmap days');
    billingCheck($pricing['final_project_price'] === '100401.18', 'project formula uses technology once');
    billingRejects(fn () => salesBillingCreateReport($conn, 1, 1, 7, bin2hex(random_bytes(32)), [], ['technology_cost' => 0, 'developer_cost' => 0, 'resource_cost' => 0]), 'stale project costs require another review');
    $key = bin2hex(random_bytes(32));
    $first = salesBillingCreateReport($conn, 1, 1, 7, $key, []);
    $firstAmount = $conn->query('SELECT amount_due FROM receipts WHERE id = ' . $first['receipt_id'])->fetch_assoc();
    billingCheck($firstAmount['amount_due'] === '100401.18', 'receipt persists two-decimal project price');
    $retry = salesBillingCreateReport($conn, 1, 1, 7, $key, []);
    billingCheck((int) $retry['receipt_id'] === $first['receipt_id'], 'double submission returns existing receipt');
    billingRejects(fn () => salesBillingCreateReport($conn, 1, 1, 7, bin2hex(random_bytes(32)), []), 'unpaid report also blocks duplicate');
    $conn->query("UPDATE receipts SET status = 'paid'");
    billingRejects(fn () => salesBillingCreateReport($conn, 1, 1, 7, bin2hex(random_bytes(32)), []), 'paid duplicate rejected');
    $new = salesBillingCreateReport($conn, 1, 1, 7, bin2hex(random_bytes(32)), [['name' => 'New feature', 'cost' => 2500]]);
    $row = $conn->query('SELECT sr.total_cost, r.amount_due, sr.technology_cost FROM sales_reports sr JOIN receipts r ON r.report_id = sr.id WHERE sr.id = ' . $new['report_id'])->fetch_assoc();
    billingCheck((float) $row['total_cost'] === 2500.0 && (float) $row['amount_due'] === 2500.0 && (float) $row['technology_cost'] === 0.0, 'supplemental report and receipt contain only addition');
    billingRejects(fn () => salesBillingCreateReport($conn, 1, 1, 7, bin2hex(random_bytes(32)), [['name' => 'New feature', 'cost' => 2500]]), 'addition retry with new key rejected');
    billingRejects(fn () => salesBillingCreateReport($conn, 1, 2, 7, bin2hex(random_bytes(32)), []), 'client/project mismatch rejected');
    billingCheck((int) $conn->query('SELECT COUNT(*) AS n FROM sales_reports')->fetch_assoc()['n'] === 2, 'failed submissions do not leave reports');
    billingCheck((int) $conn->query('SELECT COUNT(*) AS n FROM receipts')->fetch_assoc()['n'] === 2, 'failed submissions do not leave receipts');
    billingCheck($conn->query('SELECT status FROM receipts WHERE id = ' . $first['receipt_id'])->fetch_assoc()['status'] === 'paid', 'original paid receipt unchanged');
}
echo "PASS: {$checks} billing checks.\n";
