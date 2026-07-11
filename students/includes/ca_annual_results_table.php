<?php
declare(strict_types=1);

/** @var list<object> $caTableRecords */
/** @var array<int, string> $caPeriodHeaders */
/** @var int[] $caPeriodNumbers */
/** @var array<int, array<string, string>> $caPeriodComponents */
/** @var string $caTableEmptyMessage */

if (!isset($caTableRecords) || !is_array($caTableRecords)) {
    $caTableRecords = [];
}
if (!isset($caPeriodHeaders) || !is_array($caPeriodHeaders)) {
    $caPeriodHeaders = [];
}
if (!isset($caPeriodNumbers) || !is_array($caPeriodNumbers)) {
    $caPeriodNumbers = [];
}
$caTableEmptyMessage = (string)($caTableEmptyMessage ?? 'No CA records for this academic year yet.');

if (!isset($caPeriodComponents) || !is_array($caPeriodComponents) || $caPeriodComponents === []) {
    $caPeriodComponents = student_ca_period_component_labels($caPeriodNumbers, 'term');
}

$componentColumnCount = 0;
foreach ($caPeriodNumbers as $periodNumber) {
    $componentColumnCount += count($caPeriodComponents[$periodNumber] ?? []);
}
$colspan = 4 + $componentColumnCount;
?>
<div class="ca-table-wrap">
    <table class="table ca-table ca-table-annual align-middle mb-0">
        <thead>
            <tr class="ca-table-group-row">
                <th scope="colgroup" colspan="3" class="ca-group-heading">Details</th>
                <?php foreach ($caPeriodNumbers as $periodNumber): ?>
                <th scope="colgroup" colspan="<?= count($caPeriodComponents[$periodNumber] ?? []) ?>" class="ca-group-heading"><?= student_ca_h($caPeriodHeaders[$periodNumber] ?? ('Term ' . $periodNumber)) ?></th>
                <?php endforeach; ?>
                <th scope="col" rowspan="2" class="ca-final-heading">CA</th>
            </tr>
            <tr>
                <th scope="col" class="ca-row-number">No</th>
                <th scope="col" class="text-start ca-name-column">Name</th>
                <th scope="col" class="ca-yos-column">YOS</th>
                <?php foreach ($caPeriodNumbers as $periodNumber): ?>
                    <?php foreach (($caPeriodComponents[$periodNumber] ?? []) as $componentLabel): ?>
                <th scope="col" class="ca-component-heading"><?= student_ca_h($componentLabel) ?></th>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
        <?php if ($caTableRecords === []): ?>
            <tr>
                <td colspan="<?= (int)$colspan ?>">
                    <div class="ca-empty ca-empty-compact">
                        <i class="fas fa-folder-open mb-1 d-block"></i>
                        <p class="mb-0 small"><?= student_ca_h($caTableEmptyMessage) ?></p>
                    </div>
                </td>
            </tr>
        <?php else: ?>
            <?php $rowNum = 1; foreach ($caTableRecords as $row): ?>
            <tr>
                <td class="ca-score text-muted"><?= $rowNum++ ?></td>
                <td class="ca-name-cell">
                    <div class="ca-course-name"><?= student_ca_h((string)($row->course_name ?? $row->course_code ?? '')) ?></div>
                    <?php if (trim((string)($row->course_name ?? '')) !== '' && trim((string)($row->course_code ?? '')) !== ''): ?>
                    <div class="ca-course-code text-muted small"><?= student_ca_h((string)$row->course_code) ?></div>
                    <?php endif; ?>
                </td>
                <td class="ca-yos-cell"><?= student_ca_h((string)($row->year_of_study ?? '')) ?></td>
                <?php foreach ($caPeriodNumbers as $periodNumber): ?>
                    <?php $periodScores = is_array($row->periods[$periodNumber] ?? null) ? $row->periods[$periodNumber] : student_ca_empty_period_components(); ?>
                    <?php foreach (($caPeriodComponents[$periodNumber] ?? []) as $componentKey => $componentLabel): ?>
                <td class="ca-component-cell"><?= student_ca_render_component_score_html($periodScores[$componentKey] ?? null) ?></td>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <td class="ca-final-cell"><?= student_ca_render_final_score_html($row->final_ca ?? null) ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>
