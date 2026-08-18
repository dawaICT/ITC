<?php
declare(strict_types=1);

/**
 * Per-period CA results table.
 *
 * Renders a compact table for a single semester/term showing only the
 * component marks (Ass1, Ass2, Test) and period total for that period.
 *
 * Expected variables (set by the including page):
 *   $caPeriodRecords   — list<object>  the same $records array from the annual builder
 *   $caPeriodNumber    — int           which period to display (1, 2, or 3)
 *   $caPeriodLabel     — string        e.g. "Semester 1", "Term 2"
 *   $caPeriodComponentLabels — array<string,string>  e.g. ['ass1'=>'Ass1','ass2'=>'Ass2','test'=>'Test']
 *   $caPeriodEmptyMessage — string     message when no records
 */

if (!isset($caPeriodRecords) || !is_array($caPeriodRecords)) {
    $caPeriodRecords = [];
}
$caPeriodNumber = (int)($caPeriodNumber ?? 1);
$caPeriodLabel = (string)($caPeriodLabel ?? 'Period ' . $caPeriodNumber);
if (!isset($caPeriodComponentLabels) || !is_array($caPeriodComponentLabels)) {
    $caPeriodComponentLabels = ['ass1' => 'Ass1', 'ass2' => 'Ass2', 'test' => 'Test'];
}
$caPeriodEmptyMessage = (string)($caPeriodEmptyMessage ?? 'No CA records for this period yet.');

$componentCount = count($caPeriodComponentLabels);
$colspan = 3 + $componentCount + 1; // # + name + YOS + components + total
?>
<div class="ca-table-wrap">
    <table class="table ca-table ca-table-period align-middle mb-0">
        <thead>
            <tr>
                <th scope="col" class="ca-row-number">#</th>
                <th scope="col" class="text-start ca-name-column">Course</th>
                <th scope="col" class="ca-yos-column" title="Year of study">YOS</th>
                <?php foreach ($caPeriodComponentLabels as $componentLabel): ?>
                <th scope="col" class="ca-component-heading"><?= student_ca_h($componentLabel) ?></th>
                <?php endforeach; ?>
                <th scope="col" class="ca-total-heading">Total</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($caPeriodRecords === []): ?>
            <tr>
                <td colspan="<?= (int)$colspan ?>">
                    <div class="ca-empty ca-empty-compact">
                        <i class="fas fa-folder-open mb-1 d-block"></i>
                        <p class="mb-0 small"><?= student_ca_h($caPeriodEmptyMessage) ?></p>
                    </div>
                </td>
            </tr>
        <?php else: ?>
            <?php $rowNum = 1; foreach ($caPeriodRecords as $row):
                $periodScores = is_array($row->periods[$caPeriodNumber] ?? null) ? $row->periods[$caPeriodNumber] : student_ca_empty_period_components();
                $rowHasMarks = function_exists('student_ca_period_has_mark') && student_ca_period_has_mark($periodScores);
            ?>
            <tr class="<?= $rowHasMarks ? 'ca-row-has-marks' : 'ca-row-awaiting' ?>">
                <td class="ca-score text-muted"><?= $rowNum++ ?></td>
                <td class="ca-name-cell">
                    <div class="ca-course-name"><?= student_ca_h((string)($row->course_name ?? $row->course_code ?? '')) ?></div>
                    <?php if (trim((string)($row->course_name ?? '')) !== '' && trim((string)($row->course_code ?? '')) !== ''): ?>
                    <div class="ca-course-code text-muted small"><?= student_ca_h((string)$row->course_code) ?></div>
                    <?php endif; ?>
                </td>
                <td class="ca-yos-cell"><?= student_ca_h((string)($row->year_of_study ?? '')) ?></td>
                <?php foreach ($caPeriodComponentLabels as $componentKey => $componentLabel):
                    $scoreVal = $periodScores[$componentKey] ?? null;
                    $cellClass = 'ca-component-cell' . ($scoreVal !== null && $scoreVal !== '' ? ' ca-has-score' : ' ca-no-score');
                ?>
                <td class="<?= student_ca_h($cellClass) ?>"><?= student_ca_render_component_score_html($scoreVal !== null && $scoreVal !== '' ? (float)$scoreVal : null) ?></td>
                <?php endforeach; ?>
                <td class="ca-final-cell"><?php
                    $periodTotal = $periodScores['total'] ?? null;
                    echo student_ca_render_final_score_html($periodTotal !== null && $periodTotal !== '' ? (float)$periodTotal : null);
                ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>
