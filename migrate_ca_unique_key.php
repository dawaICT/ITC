<?php
/**
 * Migration: prevent duplicate CA records in semester_assessment.
 *
 * De-duplicates existing rows (merging component marks into the surviving
 * latest row so nothing is lost), recomputes Total_CA, then adds a UNIQUE key
 * on the natural key (Sid, Course_Code, semester, Year).
 *
 * Idempotent: if the unique key already exists it does nothing.
 *
 * Run from CLI:   php migrate_ca_unique_key.php
 * Run from web:   migrate_ca_unique_key.php?confirm=yes   (DELETE is destructive)
 */

require 'db/connect.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli && (($_GET['confirm'] ?? '') !== 'yes')) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "This migration de-duplicates semester_assessment and adds a UNIQUE key.\n";
    echo "It performs a DELETE on duplicate rows. Re-run with ?confirm=yes to proceed.\n";
    exit;
}
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

echo "CA unique-key migration\n" . str_repeat('=', 60) . "\n\n";

$keyName = 'uq_ca_student_course_period';

// 0. Idempotency guard — bail if the unique key is already present.
$exists = false;
if ($res = $db->query("SHOW INDEX FROM semester_assessment WHERE Key_name = '" . $db->real_escape_string($keyName) . "'")) {
    $exists = $res->num_rows > 0;
    $res->free();
}
if ($exists) {
    echo "Unique key '{$keyName}' already exists — nothing to do.\n";
    $db->close();
    exit;
}

try {
    // 1. Merge duplicate groups into the surviving (MAX id) row.
    $merge = "UPDATE semester_assessment s
        JOIN (
            SELECT MAX(id) AS keep_id, Sid, Course_Code, semester, Year,
                   MAX(A1) AS A1, MAX(A2) AS A2, MAX(A3) AS A3, MAX(T1) AS T1, MAX(T2) AS T2
            FROM semester_assessment
            GROUP BY Sid, Course_Code, semester, Year
            HAVING COUNT(*) > 1
        ) g ON s.id = g.keep_id
        SET s.A1 = COALESCE(s.A1, g.A1), s.A2 = COALESCE(s.A2, g.A2), s.A3 = COALESCE(s.A3, g.A3),
            s.T1 = COALESCE(s.T1, g.T1), s.T2 = COALESCE(s.T2, g.T2)";
    if (!$db->query($merge)) {
        throw new RuntimeException('merge survivors: ' . $db->error);
    }
    echo "1. Merged duplicate groups into surviving rows ({$db->affected_rows} updated).\n";

    // 2. Delete the redundant duplicate rows (keep latest id per group).
    $dedupe = "DELETE sa1 FROM semester_assessment sa1
        JOIN semester_assessment sa2
          ON sa1.Sid = sa2.Sid AND sa1.Course_Code = sa2.Course_Code
         AND sa1.semester = sa2.semester AND sa1.Year = sa2.Year
         AND sa1.id < sa2.id";
    if (!$db->query($dedupe)) {
        throw new RuntimeException('delete duplicates: ' . $db->error);
    }
    echo "2. Removed duplicate rows ({$db->affected_rows} deleted).\n";

    // 3. Recompute Total_CA (average of entered components).
    $recalc = "UPDATE semester_assessment
        SET Total_CA = CASE
            WHEN ((A1 IS NOT NULL)+(A2 IS NOT NULL)+(A3 IS NOT NULL)+(T1 IS NOT NULL)+(T2 IS NOT NULL)) > 0
            THEN ROUND((COALESCE(A1,0)+COALESCE(A2,0)+COALESCE(A3,0)+COALESCE(T1,0)+COALESCE(T2,0)) /
                 ((A1 IS NOT NULL)+(A2 IS NOT NULL)+(A3 IS NOT NULL)+(T1 IS NOT NULL)+(T2 IS NOT NULL)), 2)
            ELSE NULL END";
    if (!$db->query($recalc)) {
        throw new RuntimeException('recompute Total_CA: ' . $db->error);
    }
    echo "3. Recomputed Total_CA.\n";

    // 4. Add the unique key.
    $alter = "ALTER TABLE semester_assessment ADD UNIQUE KEY {$keyName} (Sid, Course_Code, semester, Year)";
    if (!$db->query($alter)) {
        throw new RuntimeException('add unique key: ' . $db->error);
    }
    echo "4. Added UNIQUE key '{$keyName}'.\n";

    echo "\n" . str_repeat('=', 60) . "\nMigration complete.\n";
} catch (Throwable $e) {
    error_log('migrate_ca_unique_key failed: ' . $e->getMessage());
    echo "\nERROR: " . $e->getMessage() . "\n";
    echo "No unique key was added. Resolve the issue and re-run.\n";
}

$db->close();
