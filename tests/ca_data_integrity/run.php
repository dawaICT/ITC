<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/db/connect.php';
require_once $root . '/includes/ca_integrity_audit.php';

$failures = 0;
$assert = static function (bool $condition, string $label, string $details = '') use (&$failures): void {
    if ($condition) {
        echo "PASS: {$label}", $details !== '' ? " | {$details}" : '', PHP_EOL;
        return;
    }
    $failures++;
    echo "FAIL: {$label}", $details !== '' ? " | {$details}" : '', PHP_EOL;
};

$merged = ca_merge_component_values(
    ['A1' => 55.0, 'A2' => 60.0, 'T1' => 70.0, 'Exam' => 88.0],
    ['A1' => null, 'A2' => 65.0, 'T2' => null]
);
$assert($merged['A1'] === 55.0, 'partial_upload_preserves_existing_A1');
$assert($merged['A2'] === 65.0, 'partial_upload_updates_provided_A2');
$assert($merged['T1'] === 70.0, 'partial_upload_preserves_existing_test');
$assert($merged['Exam'] === null, 'ca_upload_never_writes_exam');

$checks = ca_integrity_audit($db);
$summary = ca_integrity_summary($checks);
$assert($summary['fail'] === 0, 'live_ca_integrity', json_encode($summary));

$requiredIndexes = [
    ['assessment_components', 'uq_assessment_component_name'],
    ['semester_assessment', 'idx_ca_course_period_status'],
    ['semester_assessment', 'uq_semester_assessment_student_course_period'],
];
foreach ($requiredIndexes as [$table, $index]) {
    $stmt = $db->prepare(
        'SELECT 1 FROM information_schema.statistics
          WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1'
    );
    $stmt->bind_param('ss', $table, $index);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    $assert($exists, 'schema_index_' . $index);
}

foreach (['chk_ca_component_ranges', 'chk_ca_workflow_status'] as $constraint) {
    $stmt = $db->prepare(
        "SELECT 1 FROM information_schema.table_constraints
          WHERE constraint_schema = DATABASE()
            AND table_name = 'semester_assessment'
            AND constraint_name = ? LIMIT 1"
    );
    $stmt->bind_param('s', $constraint);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    $assert($exists, 'schema_constraint_' . $constraint);
}

echo $failures === 0 ? "RESULT=OK\n" : "RESULT=FAIL failures={$failures}\n";
exit($failures === 0 ? 0 : 1);
