<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/predictive_analytics.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$empty = wuc_pa_forecast([], 3, 'count');
$check($empty['available'] === false, 'Empty input must not produce a forecast.');

$steadySeries = [];
for ($month = 1; $month <= 6; $month++) {
    $steadySeries[] = [
        'period' => '2026-' . str_pad((string)$month, 2, '0', STR_PAD_LEFT),
        'value' => 10.0,
        'records' => 10,
    ];
}
$steady = wuc_pa_forecast($steadySeries, 3, 'count');
$check($steady['available'] === true, 'Steady history should produce a forecast.');
$check((float)$steady['expected_total'] === 30.0, 'A steady series of 10 should forecast 30 over three months.');
$check($steady['direction'] === 'stable', 'A steady series should be classified as stable.');

$risingSeries = [];
for ($month = 1; $month <= 6; $month++) {
    $risingSeries[] = [
        'period' => '2026-' . str_pad((string)$month, 2, '0', STR_PAD_LEFT),
        'value' => (float)($month * 5),
        'records' => $month * 5,
    ];
}
$rising = wuc_pa_forecast($risingSeries, 3, 'count');
$check($rising['direction'] === 'rising', 'Increasing history should be classified as rising.');
$check($rising['forecast'][2]['value'] >= $rising['forecast'][0]['value'], 'A rising projection should not reverse within the horizon.');
$check($rising['forecast'][0]['lower'] >= 0, 'Forecast ranges must never be negative.');

$sparse = wuc_pa_forecast([
    ['period' => '2026-06', 'value' => 4.0, 'records' => 4],
], 3, 'count');
$check($sparse['confidence_label'] === 'Very low', 'One month of history must be labelled very-low confidence.');

if ($failures !== []) {
    fwrite(STDERR, "Predictive analytics tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Predictive analytics tests passed.\n";
