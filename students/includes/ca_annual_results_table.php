<?php
declare(strict_types=1);

/**
 * Annual CA results table — semester/term component marks with Final CA column.
 * Empty cells use em-dash (—) when marks are not published.
 */

if (!isset($caTableRecords) || !is_array($caTableRecords)) {
    $caTableRecords = [];
}
if (!isset($caPeriodNumbers) || !is_array($caPeriodNumbers)) {
    $caPeriodNumbers = [];
}
if (!isset($caPeriodHeaders) || !is_array($caPeriodHeaders)) {
    $caPeriodHeaders = [];
}
$caTableEmptyMessage = (string)($caTableEmptyMessage ?? 'No CA records for this academic year yet.');

$isTermMode = count($caPeriodNumbers) >= 3;
if (!isset($caPeriodComponentLabels) || !is_array($caPeriodComponentLabels)) {
    $caPeriodComponentLabels = student_ca_period_component_labels(
        $caPeriodNumbers,
        $isTermMode ? 'term' : 'semester'
    );
}

if (!function_exists('student_ca_render_table_component')) {
    function student_ca_render_table_component(?float $score): string
    {
        if ($score === null || $score === '') {
            return student_ca_render_component_score_html(null);
        }
        return student_ca_render_component_score_html((float)$score);
    }
}
?>
<div class="ca-results-heading no-print">
    <h2><i class="fas fa-table me-2 text-purple" aria-hidden="true"></i>Course Results</h2>
    <p class="ca-results-hint mb-0">Component marks by <?= $isTermMode ? 'term' : 'semester' ?>. Final CA is the year average of published period totals.</p>
</div>
<div class="ca-exact-table-wrap">
    <table class="ca-exact-table align-middle">
        <thead>
            <tr class="ca-exact-group-row">
                <th scope="colgroup" colspan="4" class="ca-exact-group-hdr text-center">Details</th>
                <?php if ($isTermMode): ?>
                    <th scope="colgroup" colspan="3" class="ca-exact-group-hdr text-center">Term 1</th>
                    <th scope="colgroup" colspan="3" class="ca-exact-group-hdr text-center">Term 2</th>
                    <th scope="colgroup" colspan="2" class="ca-exact-group-hdr text-center">Term 3</th>
                <?php else: ?>
                    <th scope="colgroup" colspan="3" class="ca-exact-group-hdr text-center">Semester 1</th>
                    <th scope="colgroup" colspan="3" class="ca-exact-group-hdr text-center">Semester 2</th>
                <?php endif; ?>
                <th scope="colgroup" colspan="1" class="ca-exact-group-hdr text-center ca-col-ca-group">Final CA</th>
            </tr>
            <tr class="ca-exact-col-row">
                <th scope="col" class="ca-col-no text-center">No</th>
                <th scope="col" class="ca-col-code text-start">Code</th>
                <th scope="col" class="ca-col-name ca-name-column text-start">Course Name</th>
                <th scope="col" class="ca-col-yos text-center">YOS</th>
                <?php if ($isTermMode): ?>
                    <th scope="col" class="ca-col-score">Ass1</th>
                    <th scope="col" class="ca-col-score">Ass2</th>
                    <th scope="col" class="ca-col-score">Test</th>
                    <th scope="col" class="ca-col-score">Ass1</th>
                    <th scope="col" class="ca-col-score">Ass2</th>
                    <th scope="col" class="ca-col-score">Test</th>
                    <th scope="col" class="ca-col-score">Ass1</th>
                    <th scope="col" class="ca-col-score">Ass2</th>
                <?php else: ?>
                    <th scope="col" class="ca-col-score">Ass1</th>
                    <th scope="col" class="ca-col-score">Ass2</th>
                    <th scope="col" class="ca-col-score">Test</th>
                    <th scope="col" class="ca-col-score">Ass1</th>
                    <th scope="col" class="ca-col-score">Ass2</th>
                    <th scope="col" class="ca-col-score">Test</th>
                <?php endif; ?>
                <th scope="col" class="ca-col-score ca-col-ca text-center">Total CA</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($caTableRecords === []): ?>
            <tr>
                <td colspan="<?= $isTermMode ? 13 : 11 ?>" class="text-center py-4 text-muted">
                    <i class="fas fa-folder-open me-2" aria-hidden="true"></i><?= student_ca_h($caTableEmptyMessage) ?>
                </td>
            </tr>
        <?php else: ?>
            <?php
            $rowNum = 1;
            foreach ($caTableRecords as $row):
                $hasMarks = false;
                foreach ($caPeriodNumbers as $periodNum) {
                    if (student_ca_period_has_mark($row->periods[$periodNum] ?? null)) {
                        $hasMarks = true;
                        break;
                    }
                }
                $rowClass = 'ca-exact-row' . ($hasMarks ? ' ca-row-has-marks' : ' ca-row-awaiting');
            ?>
            <tr class="<?= student_ca_h($rowClass) ?>">
                <td class="text-center ca-cell-no"><?= $rowNum++ ?></td>
                <td class="text-start ca-cell-code font-monospace fw-semibold text-purple"><?= student_ca_h((string)($row->course_code ?? '')) ?></td>
                <td class="text-start ca-cell-name fw-medium ca-course-name"><?= student_ca_h((string)($row->course_name ?? '')) ?></td>
                <td class="text-center ca-cell-yos"><?= student_ca_h((string)($row->year_of_study ?? '')) ?></td>
                <?php foreach ($caPeriodNumbers as $periodNum): ?>
                    <?php
                    $periodScores = is_array($row->periods[$periodNum] ?? null)
                        ? $row->periods[$periodNum]
                        : student_ca_empty_period_components();
                    $periodLabels = $caPeriodComponentLabels[$periodNum] ?? ['ass1' => 'Ass1', 'ass2' => 'Ass2', 'test' => 'Test'];
                    foreach (array_keys($periodLabels) as $componentKey):
                        $rawScore = $periodScores[$componentKey] ?? null;
                        $scoreVal = ($rawScore !== null && $rawScore !== '') ? (float)$rawScore : null;
                    ?>
                <td class="text-center ca-cell-score ca-component-cell <?= $scoreVal !== null ? 'ca-has-score' : 'ca-no-score' ?>">
                    <?= student_ca_render_table_component($scoreVal) ?>
                </td>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <td class="text-center ca-cell-score ca-cell-ca ca-final-cell">
                    <?= student_ca_render_final_score_html(
                        ($row->final_ca !== null && $row->final_ca !== '') ? (float)$row->final_ca : null
                    ) ?>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>
