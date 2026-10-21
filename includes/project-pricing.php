<?php
declare(strict_types=1);

/** Parse a non-negative decimal without binary floating-point arithmetic. */
function projectPricingScaled(int|string $value, int $scale): int
{
    if (!preg_match('/^([0-9]+)(?:\.([0-9]+))?$/D', trim((string) $value), $parts)) {
        throw new InvalidArgumentException('Pricing inputs must be non-negative decimals.');
    }
    $whole = ltrim($parts[1], '0') ?: '0';
    if (strlen($whole) > 10) {
        throw new InvalidArgumentException('Pricing input exceeds the supported amount.');
    }
    $fraction = $parts[2] ?? '';
    if (strlen(rtrim($fraction, '0')) > $scale) {
        throw new InvalidArgumentException('Pricing input has too many decimal places.');
    }
    return (int) $whole * (10 ** $scale) + (int) substr(str_pad($fraction, $scale, '0'), 0, $scale);
}

function projectPricingMoney(int $cents): string
{
    return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
}

/**
 * Each developer supplies daily_rate and estimated_days; services are decimal amounts.
 * Integer fixed-point arithmetic preserves fractions through the 1.10 × 1.20
 * calculation, rounding half up only when returning two-decimal money values.
 */
function projectPricingCalculate(array $developers, array $services): array
{
    $development = 0;
    foreach ($developers as $developer) {
        $rate = projectPricingScaled($developer['daily_rate'], 2);
        $days = projectPricingScaled($developer['estimated_days'], 2);
        if ($days > 1000000 || $rate > 99999999999) {
            throw new InvalidArgumentException('Developer estimate exceeds the supported amount.');
        }
        $development += $rate * $days;
        if ($development > 99999999999900) {
            throw new InvalidArgumentException('Development cost exceeds the supported amount.');
        }
    }
    $technology = 0;
    foreach ($services as $service) {
        $technology += projectPricingScaled($service, 2) * 100;
        if ($technology > 99999999999900) {
            throw new InvalidArgumentException('Technology cost exceeds the supported amount.');
        }
    }
    $direct = $development + $technology;
    $rounded = static fn(int $numerator, int $denominator): string =>
        projectPricingMoney(intdiv($numerator + intdiv($denominator, 2), $denominator));

    return [
        'development_cost' => $rounded($development, 100),
        'technology_cost' => $rounded($technology, 100),
        'direct_cost' => $rounded($direct, 100),
        'overhead' => $rounded($direct, 1000),
        'subtotal' => $rounded($direct * 11, 1000),
        'profit' => $rounded($direct * 22, 10000),
        'final_project_price' => $rounded($direct * 132, 10000),
    ];
}
