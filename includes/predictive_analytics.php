<?php
declare(strict_types=1);

/**
 * Lightweight, explainable forecasting for the administrator analytics page.
 *
 * The model deliberately uses transparent arithmetic (recent weighted average
 * blended with a damped linear trend). It is suitable for operational planning,
 * not as a substitute for audited financial or admissions targets.
 */

require_once __DIR__ . '/schema_guard.php';

if (!function_exists('wuc_pa_bind_execute')) {
    function wuc_pa_bind_execute(mysqli_stmt $stmt, string $types, array $params): void
    {
        if ($params !== []) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
    }
}

if (!function_exists('wuc_pa_filter_sql')) {
    /** @return array{0: string, 1: string, 2: array<int, string>} */
    function wuc_pa_filter_sql(array $filters, string $studentAlias = 's'): array
    {
        $map = [
            'program' => 'program',
            'intake' => 'intake',
            'academic_year' => 'academic_year',
            'gender' => 'sex',
        ];
        $clauses = [];
        $params = [];
        foreach ($map as $key => $column) {
            $value = trim((string)($filters[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            $clauses[] = $studentAlias . '.`' . $column . '` = ?';
            $params[] = $value;
        }
        return [$clauses ? ' AND ' . implode(' AND ', $clauses) : '', str_repeat('s', count($params)), $params];
    }
}

if (!function_exists('wuc_pa_monthly_rows')) {
    /**
     * Run a prepared monthly aggregation and return normalized rows.
     *
     * @return array<int, array{period: string, value: float, records: int}>
     */
    function wuc_pa_monthly_rows(mysqli $db, string $sql, string $types, array $params): array
    {
        $rows = [];
        try {
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                return [];
            }
            wuc_pa_bind_execute($stmt, $types, $params);
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $period = (string)($row['period'] ?? '');
                if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
                    continue;
                }
                $rows[] = [
                    'period' => $period,
                    'value' => (float)($row['value'] ?? 0),
                    'records' => (int)($row['records'] ?? 0),
                ];
            }
            $stmt->close();
        } catch (Throwable $e) {
            error_log('Predictive analytics monthly query failed: ' . $e->getMessage());
        }
        return $rows;
    }
}

if (!function_exists('wuc_pa_dense_series')) {
    /**
     * Fill missing months after the first observed month. Leading zero months
     * are not invented because the installation may not have been live then.
     *
     * @return array<int, array{period: string, value: float, records: int}>
     */
    function wuc_pa_dense_series(array $rows, DateTimeImmutable $endExclusive): array
    {
        if ($rows === []) {
            return [];
        }
        $byMonth = [];
        foreach ($rows as $row) {
            $byMonth[$row['period']] = $row;
        }
        ksort($byMonth);
        $firstKey = (string)array_key_first($byMonth);
        $cursor = DateTimeImmutable::createFromFormat('!Y-m', $firstKey);
        if (!$cursor) {
            return [];
        }
        $series = [];
        while ($cursor < $endExclusive) {
            $key = $cursor->format('Y-m');
            $series[] = $byMonth[$key] ?? ['period' => $key, 'value' => 0.0, 'records' => 0];
            $cursor = $cursor->modify('+1 month');
        }
        return $series;
    }
}

if (!function_exists('wuc_pa_forecast')) {
    /**
     * Forecast a monthly series with uncertainty and an evidence score.
     *
     * @return array<string, mixed>
     */
    function wuc_pa_forecast(array $series, int $horizon, string $unit): array
    {
        $horizon = max(1, min(12, $horizon));
        if ($series === []) {
            return [
                'available' => false,
                'unit' => $unit,
                'history' => [],
                'forecast' => [],
                'expected_total' => 0,
                'confidence_score' => 0,
                'confidence_label' => 'No data',
                'direction' => 'unknown',
                'momentum_percent' => null,
                'sample_records' => 0,
                'method' => 'No projection was produced because no complete monthly history is available.',
            ];
        }

        $values = array_map(static fn(array $row): float => (float)$row['value'], $series);
        $n = count($values);
        $sampleRecords = array_sum(array_map(static fn(array $row): int => (int)$row['records'], $series));

        $recentCount = min(6, $n);
        $recent = array_slice($values, -$recentCount);
        $weightedTotal = 0.0;
        $weightSum = 0.0;
        foreach ($recent as $index => $value) {
            $weight = $index + 1;
            $weightedTotal += $value * $weight;
            $weightSum += $weight;
        }
        $baseline = $weightSum > 0 ? $weightedTotal / $weightSum : 0.0;

        $slope = 0.0;
        $intercept = $values[0];
        if ($n >= 2) {
            $meanX = ($n - 1) / 2;
            $meanY = array_sum($values) / $n;
            $numerator = 0.0;
            $denominator = 0.0;
            foreach ($values as $index => $value) {
                $dx = $index - $meanX;
                $numerator += $dx * ($value - $meanY);
                $denominator += $dx * $dx;
            }
            $slope = $denominator > 0 ? $numerator / $denominator : 0.0;
            $intercept = $meanY - ($slope * $meanX);
        }

        $residuals = [];
        if ($n >= 3) {
            foreach ($values as $index => $value) {
                $residuals[] = abs($value - ($intercept + $slope * $index));
            }
        }
        $mae = $residuals ? array_sum($residuals) / count($residuals) : 0.0;
        $mean = array_sum($values) / max(1, $n);
        $relativeError = $mean > 0 ? min(1.0, $mae / $mean) : 1.0;
        $nonZeroMonths = count(array_filter($values, static fn(float $value): bool => $value > 0));
        $density = $n > 0 ? $nonZeroMonths / $n : 0.0;
        $confidenceScore = (int)round(min(92, ($n * 7) + ($density * 22) + min(22, log10($sampleRecords + 1) * 12) - ($relativeError * 18)));
        $confidenceScore = max(5, $confidenceScore);
        if ($n < 3) {
            $confidenceScore = min(25, $confidenceScore);
        }
        $confidenceLabel = $confidenceScore < 30 ? 'Very low' : ($confidenceScore < 55 ? 'Low' : ($confidenceScore < 75 ? 'Moderate' : 'High'));

        $lastPeriod = DateTimeImmutable::createFromFormat('!Y-m', (string)$series[$n - 1]['period']);
        $forecast = [];
        $uncertaintyRate = $confidenceScore < 30 ? 0.55 : ($confidenceScore < 55 ? 0.38 : ($confidenceScore < 75 ? 0.25 : 0.15));
        $cap = max($baseline * 3, max($values) * 2, $unit === 'count' ? 1.0 : 0.01);
        for ($step = 1; $step <= $horizon; $step++) {
            $trendEstimate = $intercept + ($slope * ($n - 1 + $step));
            $estimate = $n < 3 ? $baseline : (($baseline * 0.60) + ($trendEstimate * 0.40));
            $estimate = max(0.0, min($cap, $estimate));
            $uncertainty = max($mae * 1.28, $estimate * $uncertaintyRate);
            $forecastValue = $unit === 'count' ? round($estimate) : round($estimate, 2);
            $lower = $unit === 'count' ? round(max(0, $estimate - $uncertainty)) : round(max(0, $estimate - $uncertainty), 2);
            $upper = $unit === 'count' ? round($estimate + $uncertainty) : round($estimate + $uncertainty, 2);
            $forecast[] = [
                'period' => $lastPeriod->modify('+' . $step . ' month')->format('Y-m'),
                'value' => $forecastValue,
                'lower' => $lower,
                'upper' => $upper,
            ];
        }

        $momentumPercent = null;
        if ($n >= 2) {
            $window = min(3, intdiv($n, 2));
            $previous = array_sum(array_slice($values, -($window * 2), $window));
            $current = array_sum(array_slice($values, -$window));
            if ($previous > 0) {
                $momentumPercent = round((($current - $previous) / $previous) * 100, 1);
            }
        }
        $relativeSlope = $baseline > 0 ? $slope / $baseline : 0.0;
        $direction = $relativeSlope > 0.05 ? 'rising' : ($relativeSlope < -0.05 ? 'falling' : 'stable');

        return [
            'available' => true,
            'unit' => $unit,
            'history' => $series,
            'forecast' => $forecast,
            'expected_total' => array_sum(array_column($forecast, 'value')),
            'confidence_score' => $confidenceScore,
            'confidence_label' => $confidenceLabel,
            'direction' => $direction,
            'momentum_percent' => $momentumPercent,
            'sample_records' => $sampleRecords,
            'method' => 'Recent weighted average blended with a damped linear trend; ranges widen when history is sparse or volatile.',
        ];
    }
}

if (!function_exists('wuc_predictive_analytics_build')) {
    /** @return array<string, mixed> */
    function wuc_predictive_analytics_build(mysqli $db, array $filters, int $horizon = 3, int $historyMonths = 12): array
    {
        $horizon = max(1, min(12, $horizon));
        $historyMonths = max(6, min(24, $historyMonths));
        $endExclusive = new DateTimeImmutable('first day of this month 00:00:00');
        $start = $endExclusive->modify('-' . $historyMonths . ' months');
        [$filterSql, $filterTypes, $filterParams] = wuc_pa_filter_sql($filters);

        $admissionRows = [];
        if (wuc_table_exists($db, 'students')) {
            $admissionSql = "SELECT DATE_FORMAT(COALESCE(s.dte_adm, DATE(s.created_at)), '%Y-%m') AS period,
                                    COUNT(DISTINCT s.SID) AS value,
                                    COUNT(DISTINCT s.SID) AS records
                             FROM students s
                             WHERE COALESCE(s.dte_adm, DATE(s.created_at)) >= ?
                               AND COALESCE(s.dte_adm, DATE(s.created_at)) < ?" . $filterSql . "
                             GROUP BY period ORDER BY period";
            $admissionRows = wuc_pa_monthly_rows(
                $db,
                $admissionSql,
                'ss' . $filterTypes,
                array_merge([$start->format('Y-m-d'), $endExclusive->format('Y-m-d')], $filterParams)
            );
        }

        $paymentRows = [];
        if (wuc_table_exists($db, 'payments') && wuc_table_exists($db, 'students')) {
            $paymentSql = "SELECT DATE_FORMAT(p.payment_date, '%Y-%m') AS period,
                                  COALESCE(SUM(p.amount), 0) AS value,
                                  COUNT(*) AS records
                           FROM payments p
                           INNER JOIN students s ON s.SID = p.student_id
                           WHERE p.payment_date >= ? AND p.payment_date < ?
                             AND LOWER(TRIM(p.status)) IN ('approved', 'paid', 'completed', 'successful', 'success')" . $filterSql . "
                           GROUP BY period ORDER BY period";
            $paymentRows = wuc_pa_monthly_rows(
                $db,
                $paymentSql,
                'ss' . $filterTypes,
                array_merge([$start->format('Y-m-d H:i:s'), $endExclusive->format('Y-m-d H:i:s')], $filterParams)
            );
        }

        $admissions = wuc_pa_forecast(wuc_pa_dense_series($admissionRows, $endExclusive), $horizon, 'count');
        $collections = wuc_pa_forecast(wuc_pa_dense_series($paymentRows, $endExclusive), $horizon, 'currency');
        $actions = [];
        foreach (['Admissions' => $admissions, 'Fee collections' => $collections] as $label => $model) {
            if (!$model['available']) {
                $actions[] = $label . ': capture monthly transactions before relying on a projection.';
            } elseif ($model['confidence_score'] < 55) {
                $actions[] = $label . ': treat the forecast as an early planning signal until at least six complete months are available.';
            } elseif ($model['direction'] === 'falling') {
                $actions[] = $label . ': investigate the declining trend and compare it with targets before the next reporting cycle.';
            }
        }

        return [
            'generated_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            'through_period' => $endExclusive->modify('-1 month')->format('Y-m'),
            'horizon' => $horizon,
            'history_months' => $historyMonths,
            'admissions' => $admissions,
            'collections' => $collections,
            'actions' => array_values(array_unique($actions)),
            'disclaimer' => 'Forecasts are planning estimates derived from completed monthly portal records. They are not guaranteed outcomes or audited targets.',
        ];
    }
}
