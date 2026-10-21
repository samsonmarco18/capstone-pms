<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/project-pricing.php';
require_once __DIR__ . '/../includes/project-estimation.php';

function checkPricing(mixed $actual, mixed $expected): void
{
    if ($actual !== $expected) {
        throw new RuntimeException(var_export($actual, true) . ' != ' . var_export($expected, true));
    }
}

checkPricing(projectPricingCalculate([
    ['daily_rate' => '2500.00', 'estimated_days' => 20],
    ['daily_rate' => '2000.00', 'estimated_days' => 15],
], ['5000.00', '3000.00', '2000.00']), [
    'development_cost' => '80000.00',
    'technology_cost' => '10000.00',
    'direct_cost' => '90000.00',
    'overhead' => '9000.00',
    'subtotal' => '99000.00',
    'profit' => '19800.00',
    'final_project_price' => '118800.00',
]);
checkPricing(projectPricingCalculate([], [])['final_project_price'], '0.00');
checkPricing(projectPricingCalculate([
    ['daily_rate' => '1234.56', 'estimated_days' => '2.50'],
], ['0.10', '0.20'])['final_project_price'], '4074.44');
checkPricing(projectPricingCalculate([], ['0.05'])['final_project_price'], '0.07');
checkPricing(projectEstimationDailyRate('Junior Dev'), '961.54');
checkPricing(projectEstimationDailyRate('Professional Dev'), '2307.69');
checkPricing(projectEstimationDailyRate('Senior Dev'), '3076.92');
$normalized = projectEstimationNormalizeRows([
    'Backend' => [1 => ['developer_type' => 'Senior Dev', 'estimated_days' => 999, 'daily_rate' => 1], 2 => ['developer_type' => 'Junior Dev']],
], [['department' => 'Backend', 'required_count' => 2, 'estimated_days_by_position' => [20, 15]]]);
checkPricing(projectPricingCalculate($normalized, ['100.00'])['final_project_price'], '100401.18');
checkPricing($normalized[0]['estimated_days'], 20);
foreach (['-1', 'invalid', '1e3', '0.001', '100000000000'] as $invalid) {
    try {
        projectPricingCalculate([], [$invalid]);
        throw new RuntimeException('Invalid cost accepted: ' . $invalid);
    } catch (InvalidArgumentException $expected) {
    }
}
echo "Project pricing tests passed.\n";
