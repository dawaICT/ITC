<?php
declare(strict_types=1);

/**
 * Year-end CA summary table.
 *
 * Shows each course with its period totals side-by-side and the final
 * year-end CA average. This is compact because the columns are just
 * the period totals (one number each) rather than all individual components.
 *
 * Expected variables (set by the including page):
 *   $caSummaryRecords  — list<object>  the same $records array from the annual builder
 *   $caSummaryPeriodNumbers  — int[]   e.g. [1,2] or [1,2,3]
 *   $caSummaryPeriodHeaders  — array<int,string>  e.g. [1=>'Sem 1',2=>'Sem 2']
 *   $caSummaryEmptyMessage   — string
 */

if (!isset($caSummaryRecords) || !is_array($caSummaryRecords)) {
    $caSummaryRecords = [];
}
if (!isset($caSummaryPeriodNumbers) || !is_array($caSummaryPeriodNumbers)) {
    $caSummaryPeriodNumbers = [1, 2];
}
if (!isset($caSummaryPeriodHeaders) || !is_array($caSummaryPeriodHeaders)) {
    $caSummaryPeriodHeaders = [];
}
$caSummaryEmptyMessage = (string)($caSummaryEmptyMessage ?? 'No CA records for this academic year yet.');

$colspanSummary = 3 + count($caSummaryPeriodNumbers) + 1; // # + name + YOS + period totals + final CA
?>
<div class="ca-table-wrap">
    <table class="table ca-table ca-table-summary align-middle mb-0">
        <thead>
            <tr>
                <th scope="col" class="ca-row-number">#</th>
                <th scope="col" class="text-start ca-name-column">Course</th>
                <th scope="col" class="ca-yos-column" title="Year of study">YOS</th>
                <?php foreach ($caSummaryPeriodNumbers as $periodNum): ?>
                <th scope="col" class="ca-period-heading"><?= student_ca_h($caSummaryPeriodHeaders[$periodNum] ?? ('P' . $periodNum)) ?></th>
                <?php endforeach; ?>
                <th scope="col" class="ca-final-heading">Final CA</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($caSummaryRecords === []): ?>
            <tr>
                <td colspan="<?= (int)$colspanSummary ?>">
                    <div class="ca-empty ca-empty-compact">
                        <i class="fas fa-folder-open mb-1 d-block"></i>
                        <p class="mb-0 small"><?= student_ca_h($caSummaryEmptyMessage) ?></p>
                    </div>
                </td>
            </tr>
        <?php else: ?>
            <?php $rowNum = 1; foreach ($caSummaryRecords as $row):
                $rowHasMarks = false;
                foreach ($caSummaryPeriodNumbers as $pn) {
                    if (function_exists('student_ca_period_has_mark')
                        && student_ca_period_has_mark(is_array($row->periods[$pn] ?? null) ? $row->periods[$pn] : null)
                    ) {
                        $rowHasMarks = true;
                        break;
                    }
                }
                if (!$rowHasMarks && ($row->final_ca ?? null) !== null) {
                    $rowHasMarks = true;
                }
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
                <?php foreach ($caSummaryPeriodNumbers as $pn):
                    $periodScores = is_array($row->periods[$pn] ?? null) ? $row->periods[$pn] : student_ca_empty_period_components();
                    $periodTotal = $periodScores['total'] ?? null;
                ?>
                <td class="ca-period-cell"><?= student_ca_render_final_score_html($periodTotal !== null && $periodTotal !== '' ? (float)$periodTotal : null) ?></td>
                <?php endforeach; ?>
                <td class="ca-final-cell"><?= student_ca_render_final_score_html($row->final_ca ?? null) ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>
