<?php
require_once __DIR__ . '/../db/connect.php';

function find_column(mysqli $db, $table, $pattern)
{
    $res = $db->query("SHOW COLUMNS FROM `" . $db->real_escape_string($table) . "`");
    if (!$res) return null;
    while ($row = $res->fetch_assoc()) {
        if (preg_match($pattern, $row['Field'])) return $row['Field'];
    }
    return null;
}

$sr_table = 'semester_registration';
$cr_table = 'course_registration';

// Detect useful columns
$sr_id_col = find_column($db, $sr_table, '/^id$/i') ?: 'id';
$sr_student_col = find_column($db, $sr_table, '/student|sid/i') ?: 'student_id';
$sr_sem_col = find_column($db, $sr_table, '/^semester$/i') ?: find_column($db, $sr_table, '/sem/i') ?: 'semester';
$sr_year_col = find_column($db, $sr_table, '/year|year_of_study/i') ?: 'year_of_study';

$cr_sid_col = find_column($db, $cr_table, '/^Sid$|student|sid/i') ?: 'Sid';
$cr_sem_col = find_column($db, $cr_table, '/^semester$/i') ?: 'semester';
$cr_year_col = find_column($db, $cr_table, '/^Year$|year/i') ?: 'Year';

echo "Detected columns:\n";
echo "  semester_registration: id={$sr_id_col}, student={$sr_student_col}, semester={$sr_sem_col}, year={$sr_year_col}\n";
echo "  course_registration: sid={$cr_sid_col}, semester={$cr_sem_col}, year={$cr_year_col}\n";

// 1) Add column if not exists
$check = $db->query("SHOW COLUMNS FROM `{$cr_table}` LIKE 'semester_registration_id'");
if ($check && $check->num_rows > 0) {
    echo "Column semester_registration_id already exists on {$cr_table}.\n";
} else {
    $sql = "ALTER TABLE `{$cr_table}` ADD COLUMN `semester_registration_id` INT NULL";
    echo "Adding column: {$sql}\n";
    if (!$db->query($sql)) {
        echo "ERROR adding column: " . $db->error . "\n";
        exit(1);
    }
    echo "Column added.\n";
}

// 2) Backfill values by matching student + semester + year
$updateSql = "UPDATE `{$cr_table}` cr
JOIN `{$sr_table}` sr ON (
    sr.`" . $db->real_escape_string($sr_student_col) . "` = cr.`" . $db->real_escape_string($cr_sid_col) . "`
    AND sr.`" . $db->real_escape_string($sr_sem_col) . "` = cr.`" . $db->real_escape_string($cr_sem_col) . "`
    AND sr.`" . $db->real_escape_string($sr_year_col) . "` = cr.`" . $db->real_escape_string($cr_year_col) . "`
)
SET cr.`semester_registration_id` = sr.`" . $db->real_escape_string($sr_id_col) . "`
WHERE cr.`semester_registration_id` IS NULL";

echo "Backfilling semester_registration_id...\n";
if (!$db->query($updateSql)) {
    echo "ERROR during backfill: " . $db->error . "\n";
    exit(1);
}
echo "Backfill affected rows: " . $db->affected_rows . "\n";

// 3) Add index and FK (allow NULLs safely)
$idxCheck = $db->query("SHOW INDEX FROM `{$cr_table}` WHERE Column_name='semester_registration_id'");
if ($idxCheck && $idxCheck->num_rows > 0) {
    echo "Index on semester_registration_id already exists.\n";
} else {
    $sqlIdx = "ALTER TABLE `{$cr_table}` ADD INDEX (`semester_registration_id`)";
    echo "Adding index: {$sqlIdx}\n";
    if (!$db->query($sqlIdx)) {
        echo "ERROR adding index: " . $db->error . "\n";
        exit(1);
    }
    echo "Index added.\n";
}

// Check if FK already exists (best-effort)
$fkName = 'fk_course_reg_sem_reg';
$fkExists = false;
$res = $db->query("SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = '" . $db->real_escape_string($db->real_escape_string($db->query("SELECT DATABASE() AS db")->fetch_assoc()['db'])) . "' AND TABLE_NAME = '" . $db->real_escape_string($cr_table) . "' AND CONSTRAINT_TYPE = 'FOREIGN KEY'");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        if ($r['CONSTRAINT_NAME'] === $fkName) $fkExists = true;
    }
}

if ($fkExists) {
    echo "Foreign key {$fkName} already exists.\n";
} else {
    $sqlFk = "ALTER TABLE `{$cr_table}` ADD CONSTRAINT `{$fkName}` FOREIGN KEY (`semester_registration_id`) REFERENCES `{$sr_table}`(`{$sr_id_col}`) ON DELETE CASCADE ON UPDATE CASCADE";
    echo "Adding FK: {$sqlFk}\n";
    if (!$db->query($sqlFk)) {
        echo "ERROR adding FK: " . $db->error . "\n";
        echo "You can inspect orphan rows by running:\nSELECT cr.* FROM `{$cr_table}` cr LEFT JOIN `{$sr_table}` sr ON (sr.`{$sr_student_col}` = cr.`{$cr_sid_col}` AND sr.`{$sr_sem_col}` = cr.`{$cr_sem_col}` AND sr.`{$sr_year_col}` = cr.`{$cr_year_col}`) WHERE sr.`{$sr_id_col}` IS NULL;\n";
        exit(1);
    }
    echo "FK added.\n";
}

// 4) If no NULLs remain, make column NOT NULL for stronger integrity
$nullCountRes = $db->query("SELECT COUNT(*) AS c FROM `{$cr_table}` WHERE `semester_registration_id` IS NULL");
$nullCount = $nullCountRes ? (int)$nullCountRes->fetch_assoc()['c'] : -1;
echo "Rows remaining with NULL semester_registration_id: {$nullCount}\n";
if ($nullCount === 0) {
    $sqlNotNull = "ALTER TABLE `{$cr_table}` MODIFY `semester_registration_id` INT NOT NULL";
    echo "Making semester_registration_id NOT NULL: {$sqlNotNull}\n";
    if (!$db->query($sqlNotNull)) {
        echo "ERROR making NOT NULL: " . $db->error . "\n";
    } else {
        echo "Column set NOT NULL.\n";
    }
} else {
    echo "Some rows remain orphaned; leaving column nullable for now.\n";
}

echo "Migration complete. Review output above for any errors.\n";

// Helpful summary counts
$totalCr = $db->query("SELECT COUNT(*) AS c FROM `{$cr_table}`")->fetch_assoc()['c'];
$withSr = $db->query("SELECT COUNT(*) AS c FROM `{$cr_table}` WHERE `semester_registration_id` IS NOT NULL")->fetch_assoc()['c'];
echo "Total course_registration rows: {$totalCr}\n";
echo "Rows linked to semester_registration: {$withSr}\n";

?>
