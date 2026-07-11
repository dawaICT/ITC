<?php
declare(strict_types=1);

/**
 * Template-driven report insights (zero-cost AI, Sprint 7).
 *
 * Takes any tabular report (title + columns + rows, e.g. a trx_* report from
 * includes/training_reports_engine.php) and derives a structured insight block
 * — summary, key findings, warnings, trends, missing records, recommended
 * actions — with plain PHP arithmetic. No LLM anywhere; the optional Ollama
 * narrative remains a separate layer on top.
 *
 * Column spec follows the trx convention: [field, label, type] where type is
 * 'int'/'num' for numeric, 'date' for dates, anything else for text.
 */

require_once __DIR__ . '/schema_guard.php';

if (!function_exists('wuc_report_insights_build')) {
    function wuc_report_insights_build(array $report, array $rows): array
    {
        $insights = [
            'title' => (string)($report['title'] ?? 'Report'),
            'row_count' => count($rows),
            'numeric_summary' => [],   // per numeric column: sum/avg/min/max
            'key_findings' => [],
            'warnings' => [],
            'trends' => [],
            'missing_records' => [],
            'actions' => [],
        ];

        $columns = is_array($report['columns'] ?? null) ? $report['columns'] : [];
        if (!$rows) {
            $insights['warnings'][] = 'The report returned no rows for the selected criteria.';
            $insights['actions'][] = 'Widen the date range or filters, or confirm that source data has been captured.';
            return $insights;
        }

        $numericCols = [];
        $dateCol = null;
        $labelCol = null;
        foreach ($columns as $col) {
            $field = (string)($col[0] ?? '');
            $label = (string)($col[1] ?? $field);
            $type = (string)($col[2] ?? 'text');
            if ($field === '') {
                continue;
            }
            if (in_array($type, ['int', 'num', 'number', 'decimal'], true)) {
                $numericCols[$field] = $label;
            } elseif ($type === 'date' && $dateCol === null) {
                $dateCol = $field;
            } elseif ($labelCol === null) {
                $labelCol = $field;
            }
        }
        // Fall back to sniffing numeric fields from the first row.
        if (!$numericCols) {
            foreach ($rows[0] as $field => $value) {
                if (is_numeric($value)) {
                    $numericCols[$field] = ucwords(str_replace('_', ' ', (string)$field));
                }
            }
        }

        // --- Numeric summary + missing-value scan ---
        foreach ($numericCols as $field => $label) {
            $values = [];
            $empty = 0;
            foreach ($rows as $row) {
                $v = $row[$field] ?? null;
                if ($v === null || $v === '') {
                    $empty++;
                } elseif (is_numeric($v)) {
                    $values[] = (float)$v;
                }
            }
            if ($values) {
                $insights['numeric_summary'][] = [
                    'label' => $label,
                    'sum' => round(array_sum($values), 2),
                    'avg' => round(array_sum($values) / count($values), 2),
                    'min' => min($values),
                    'max' => max($values),
                ];
            }
            if ($empty > 0) {
                $insights['missing_records'][] = $empty . ' row(s) have no value for "' . $label . '".';
            }
        }

        // --- Key findings: top row per leading numeric column ---
        $firstNumeric = array_key_first($numericCols);
        if ($firstNumeric !== null && $labelCol !== null) {
            $best = null;
            foreach ($rows as $row) {
                if (!is_numeric($row[$firstNumeric] ?? null)) {
                    continue;
                }
                if ($best === null || (float)$row[$firstNumeric] > (float)$best[$firstNumeric]) {
                    $best = $row;
                }
            }
            if ($best !== null) {
                $insights['key_findings'][] = 'Highest "' . $numericCols[$firstNumeric] . '": '
                    . trim((string)($best[$labelCol] ?? '')) . ' (' . (float)$best[$firstNumeric] . ').';
            }
        }
        $insights['key_findings'][] = $insights['row_count'] . ' row(s) in scope.';

        // --- Trend: first half vs second half on the leading numeric column ---
        if ($firstNumeric !== null && count($rows) >= 4) {
            $ordered = $rows;
            if ($dateCol !== null) {
                usort($ordered, static function (array $a, array $b) use ($dateCol): int {
                    return strcmp((string)($a[$dateCol] ?? ''), (string)($b[$dateCol] ?? ''));
                });
            }
            $half = (int)floor(count($ordered) / 2);
            $sumHalf = static function (array $slice) use ($firstNumeric): float {
                $s = 0.0;
                foreach ($slice as $row) {
                    if (is_numeric($row[$firstNumeric] ?? null)) {
                        $s += (float)$row[$firstNumeric];
                    }
                }
                return $s;
            };
            $firstSum = $sumHalf(array_slice($ordered, 0, $half));
            $secondSum = $sumHalf(array_slice($ordered, $half));
            if ($firstSum > 0 || $secondSum > 0) {
                $direction = $secondSum > $firstSum ? 'rising' : ($secondSum < $firstSum ? 'falling' : 'flat');
                $insights['trends'][] = '"' . $numericCols[$firstNumeric] . '" is ' . $direction
                    . ' across the period (' . round($firstSum, 1) . ' → ' . round($secondSum, 1) . ').';
                if ($direction === 'falling') {
                    $insights['actions'][] = 'Investigate the decline in "' . $numericCols[$firstNumeric] . '" during the later part of the period.';
                }
            }
        }

        // --- Warnings + default actions ---
        if ($insights['missing_records']) {
            $insights['warnings'][] = 'Some rows are missing values; totals may understate reality.';
            $insights['actions'][] = 'Complete the missing records flagged below before circulating this report.';
        }
        if (!$insights['warnings']) {
            $insights['warnings'][] = 'No data-quality warnings triggered for this report.';
        }
        if (!$insights['actions']) {
            $insights['actions'][] = 'Review the highlighted findings and confirm they match operational expectations.';
        }

        return $insights;
    }
}

if (!function_exists('wuc_report_insights_persist')) {
    function wuc_report_insights_persist(mysqli $db, string $reportKey, string $scopeType, string $scopeId, string $periodLabel, array $insights, string $generatedBy): void
    {
        if (!wuc_table_exists($db, 'report_insights')) {
            return;
        }
        try {
            $summaryJson = json_encode([
                'title' => $insights['title'],
                'row_count' => $insights['row_count'],
                'numeric_summary' => $insights['numeric_summary'],
                'key_findings' => $insights['key_findings'],
                'missing_records' => $insights['missing_records'],
            ], JSON_UNESCAPED_UNICODE) ?: '{}';
            $warningsJson = json_encode($insights['warnings'], JSON_UNESCAPED_UNICODE) ?: null;
            $trendsJson = json_encode($insights['trends'], JSON_UNESCAPED_UNICODE) ?: null;
            $actionsJson = json_encode($insights['actions'], JSON_UNESCAPED_UNICODE) ?: null;
            $stmt = $db->prepare(
                'INSERT INTO report_insights (report_key, scope_type, scope_id, period_label, summary_json, warnings_json, trends_json, actions_json, generated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if ($stmt) {
                $stmt->bind_param('sssssssss', $reportKey, $scopeType, $scopeId, $periodLabel, $summaryJson, $warningsJson, $trendsJson, $actionsJson, $generatedBy);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable $e) {
            error_log('wuc_report_insights_persist failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('wuc_report_insights_render')) {
    /** Printable Bootstrap block: summary, findings, warnings, trends, actions. */
    function wuc_report_insights_render(array $insights): string
    {
        ob_start();
        ?>
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong><i class="fas fa-lightbulb me-2"></i>Report Insights (rule-based)</strong>
                <span class="badge bg-primary"><?= (int)$insights['row_count'] ?> row(s)</span>
            </div>
            <div class="card-body">
                <?php if ($insights['numeric_summary']): ?>
                    <div class="row g-2 mb-3">
                        <?php foreach (array_slice($insights['numeric_summary'], 0, 4) as $n): ?>
                            <div class="col-md-3 col-6">
                                <div class="p-2 rounded-3 bg-light border">
                                    <div class="text-muted small text-truncate" title="<?= htmlspecialchars((string)$n['label']) ?>"><?= htmlspecialchars((string)$n['label']) ?></div>
                                    <div class="fw-bold"><?= htmlspecialchars((string)$n['sum']) ?></div>
                                    <div class="small text-muted">avg <?= htmlspecialchars((string)$n['avg']) ?> · max <?= htmlspecialchars((string)$n['max']) ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="row g-3 small">
                    <div class="col-lg-3 col-md-6">
                        <div class="fw-semibold mb-1"><i class="fas fa-magnifying-glass-chart me-1"></i>Key findings</div>
                        <ul class="ps-3 mb-0"><?php foreach ($insights['key_findings'] as $f): ?><li><?= htmlspecialchars((string)$f) ?></li><?php endforeach; ?></ul>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="fw-semibold mb-1 text-warning"><i class="fas fa-triangle-exclamation me-1"></i>Warnings</div>
                        <ul class="ps-3 mb-0"><?php foreach ($insights['warnings'] as $w): ?><li><?= htmlspecialchars((string)$w) ?></li><?php endforeach; ?></ul>
                        <?php if ($insights['missing_records']): ?>
                            <div class="fw-semibold mb-1 mt-2 text-danger">Missing records</div>
                            <ul class="ps-3 mb-0"><?php foreach (array_slice($insights['missing_records'], 0, 4) as $m): ?><li><?= htmlspecialchars((string)$m) ?></li><?php endforeach; ?></ul>
                        <?php endif; ?>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="fw-semibold mb-1 text-info"><i class="fas fa-arrow-trend-up me-1"></i>Trends</div>
                        <?php if ($insights['trends']): ?>
                            <ul class="ps-3 mb-0"><?php foreach ($insights['trends'] as $t): ?><li><?= htmlspecialchars((string)$t) ?></li><?php endforeach; ?></ul>
                        <?php else: ?>
                            <div class="text-muted">Not enough rows to derive a trend.</div>
                        <?php endif; ?>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="fw-semibold mb-1 text-success"><i class="fas fa-list-check me-1"></i>Recommended actions</div>
                        <ul class="ps-3 mb-0"><?php foreach ($insights['actions'] as $a): ?><li><?= htmlspecialchars((string)$a) ?></li><?php endforeach; ?></ul>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return (string)ob_get_clean();
    }
}
