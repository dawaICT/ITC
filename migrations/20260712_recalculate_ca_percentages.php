<?php
/**
 * Recalculate legacy CA totals as weighted percentages (0-100).
 *
 * Idempotent: it only updates rows whose saved Total_CA differs from the
 * result produced by the canonical CA helper. Raw component marks are not
 * changed, and workflow/publication status is preserved.
 */
declare(strict_types=1);

require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/../includes/ca_helpers.php';

if (!isset($db) || !$db instanceof mysqli || !ca_table_exists($db, 'semester_assessment')) {
    fwrite(STDERR, "semester_assessment is unavailable.\n");
    exit(1);
}

$rows = $db->query(
    'SELECT id, Sid, Course_Code, A1, A2, A3, T1, T2, Total_CA
       FROM semester_assessment
      ORDER BY id'
);
$update = $db->prepare('UPDATE semester_assessment SET Total_CA = ? WHERE id = ?');
$changed = 0;

$db->begin_transaction();
try {
    while ($row = $rows->fetch_assoc()) {
        $components = [];
        foreach (['A1', 'A2', 'A3', 'T1', 'T2'] as $component) {
            $components[$component] = $row[$component] === null ? null : (float)$row[$component];
        }
        $total = ca_calculate_course_total(
            $db,
            (string)$row['Sid'],
            (string)$row['Course_Code'],
            $components
        );
        $stored = $row['Total_CA'] === null ? null : (float)$row['Total_CA'];
        if (($stored === null && $total === null)
            || ($stored !== null && $total !== null && abs($stored - $total) < 0.005)) {
            continue;
        }
        $id = (int)$row['id'];
        $update->bind_param('di', $total, $id);
        $update->execute();
        $changed++;
    }
    $update->close();
    $db->commit();
    echo "CA total repair complete. Rows updated: {$changed}\n";
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, 'CA total repair failed: ' . $e->getMessage() . "\n");
    exit(1);
}

