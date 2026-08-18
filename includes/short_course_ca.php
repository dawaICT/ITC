<?php
/**
 * Short-course CA helpers.
 *
 * Short courses have no semester/term/year, so CA is stored in
 * short_course_assessment keyed on (short_course_id, student_id). Total_CA is
 * the average of the entered raw components — same rule as the mainstream CA
 * (ca_calculate_total_ca in includes/ca_helpers.php), so reporting stays
 * consistent across both flows.
 */

require_once __DIR__ . '/ca_helpers.php';

/**
 * Create the storage table if it is missing (defensive; the canonical creation
 * lives in migrate_short_course_assessment.php).
 */
function sc_ca_ensure_schema(mysqli $db): void
{
    // The app user is DML-only. CREATE TABLE requires the migrator user (root).
    // Skip the DDL when the table already exists to avoid a privilege exception
    // under MYSQLI_REPORT_STRICT. If the table is genuinely missing, log the
    // failure and let the page continue — function callers guard with
    // ca_table_exists() before querying it.
    $check = @$db->query("SHOW TABLES LIKE 'short_course_assessment'");
    if ($check && $check->num_rows > 0) {
        $check->free();
        return;
    }
    if ($check) { $check->free(); }

    try {
        $db->query("CREATE TABLE IF NOT EXISTS short_course_assessment (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            short_course_id INT NOT NULL,
            course_code VARCHAR(50) NOT NULL,
            student_id VARCHAR(50) NOT NULL,
            A1 DECIMAL(6,2) NULL DEFAULT NULL,
            A2 DECIMAL(6,2) NULL DEFAULT NULL,
            A3 DECIMAL(6,2) NULL DEFAULT NULL,
            T1 DECIMAL(6,2) NULL DEFAULT NULL,
            T2 DECIMAL(6,2) NULL DEFAULT NULL,
            Total_CA DECIMAL(6,2) NULL DEFAULT NULL,
            posted_by VARCHAR(50) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_sc_assessment (short_course_id, student_id),
            KEY idx_sc_course_code (course_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        error_log('sc_ca_ensure_schema: DDL failed (run migrate_short_course_assessment.php as root): ' . $e->getMessage());
    }
}

/**
 * Short courses the staff member may enter CA for.
 *
 * Assignment source: course_lecturer (the canonical lecturer-course table).
 * created_by is an admin metadata field — it is NOT used for CA access because
 * it caused every admin who created short courses to see all of them in the
 * lecturer CA page. Lecturers must be explicitly assigned via course_lecturer.
 *
 * Returns rows of [id, course_code, course_name].
 */
function sc_ca_staff_courses(mysqli $db, string $staffId): array
{
    $courses = [];
    if (!ca_table_exists($db, 'short_courses') || !ca_table_exists($db, 'course_lecturer')) {
        return $courses;
    }

    // Detect the staff column name in course_lecturer (staff_id / lecturer_id).
    $clStaffCol = null;
    foreach (['staff_id', 'lecturer_id', 'staffId', 'staff'] as $candidate) {
        if (ca_column_exists($db, 'course_lecturer', $candidate)) {
            $clStaffCol = $candidate;
            break;
        }
    }
    if ($clStaffCol === null) {
        return $courses;
    }

    $sql = "SELECT DISTINCT sc.id, sc.course_code, sc.course_name
            FROM short_courses sc
            INNER JOIN course_lecturer cl ON cl.course_code = sc.course_code
            WHERE cl.`{$clStaffCol}` = ?
              AND sc.status = 'active'
              AND (cl.status IS NULL OR cl.status <> 'inactive')
            ORDER BY sc.course_code";
    if ($stmt = $db->prepare($sql)) {
        $stmt->bind_param('s', $staffId);
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $courses[] = $row;
            }
        }
        $stmt->close();
    }
    return $courses;
}

/**
 * True when the staff member is assigned to the given short course via
 * course_lecturer. Used to guard manual and CSV CA submissions.
 */
function sc_ca_staff_owns(mysqli $db, string $staffId, int $shortCourseId): bool
{
    if (!ca_table_exists($db, 'short_courses') || !ca_table_exists($db, 'course_lecturer')) {
        return false;
    }

    $clStaffCol = null;
    foreach (['staff_id', 'lecturer_id', 'staffId', 'staff'] as $candidate) {
        if (ca_column_exists($db, 'course_lecturer', $candidate)) {
            $clStaffCol = $candidate;
            break;
        }
    }
    if ($clStaffCol === null) {
        return false;
    }

    $ok = false;
    $sql = "SELECT 1
            FROM short_courses sc
            INNER JOIN course_lecturer cl ON cl.course_code = sc.course_code
            WHERE sc.id = ?
              AND cl.`{$clStaffCol}` = ?
              AND (cl.status IS NULL OR cl.status <> 'inactive')
            LIMIT 1";
    if ($stmt = $db->prepare($sql)) {
        $stmt->bind_param('is', $shortCourseId, $staffId);
        $stmt->execute();
        $stmt->store_result();
        $ok = $stmt->num_rows > 0;
        $stmt->close();
    }
    return $ok;
}

/**
 * Resolve a short course id + course_code from a posted id (validates it owns).
 * Returns ['id'=>int,'course_code'=>string] or null.
 */
function sc_ca_course_meta(mysqli $db, int $shortCourseId): ?array
{
    if ($stmt = $db->prepare("SELECT id, course_code, course_name FROM short_courses WHERE id = ? LIMIT 1")) {
        $stmt->bind_param('i', $shortCourseId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();
        if ($row) {
            return ['id' => (int)$row['id'], 'course_code' => (string)$row['course_code'], 'course_name' => (string)$row['course_name']];
        }
    }
    return null;
}

/**
 * Enrolled students for a short course (excludes withdrawn/expired).
 * Returns rows of ['Sid'=>..., 'name'=>...].
 */
function sc_ca_enrolled_students(mysqli $db, int $shortCourseId): array
{
    $students = [];
    if (!ca_table_exists($db, 'short_course_enrollments')) {
        return $students;
    }
    $sidCol = ca_column_exists($db, 'students', 'SID') ? 'SID' : (ca_column_exists($db, 'students', 'Sid') ? 'Sid' : 'SID');
    $sql = "SELECT DISTINCT e.student_id AS Sid,
                   TRIM(CONCAT(COALESCE(s.Fname,''), ' ', COALESCE(s.Lname,''))) AS name
            FROM short_course_enrollments e
            LEFT JOIN students s
                   ON s.`{$sidCol}` COLLATE utf8mb4_general_ci = e.student_id COLLATE utf8mb4_general_ci
            WHERE e.short_course_id = ?
              AND (e.status IS NULL OR e.status NOT IN ('withdrawn','expired'))
            ORDER BY e.student_id";
    if ($stmt = $db->prepare($sql)) {
        $stmt->bind_param('i', $shortCourseId);
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $students[] = ['Sid' => $row['Sid'], 'name' => trim((string)($row['name'] ?? ''))];
            }
        }
        $stmt->close();
    }
    return $students;
}

/**
 * True when the student is enrolled (not withdrawn/expired) on the short course.
 */
function sc_ca_student_enrolled(mysqli $db, int $shortCourseId, string $studentId): bool
{
    if (!ca_table_exists($db, 'short_course_enrollments')) {
        return false;
    }
    $ok = false;
    $sql = "SELECT 1 FROM short_course_enrollments
            WHERE short_course_id = ? AND student_id = ?
              AND (status IS NULL OR status NOT IN ('withdrawn','expired'))
            LIMIT 1";
    if ($stmt = $db->prepare($sql)) {
        $stmt->bind_param('is', $shortCourseId, $studentId);
        $stmt->execute();
        $stmt->store_result();
        $ok = $stmt->num_rows > 0;
        $stmt->close();
    }
    return $ok;
}

/**
 * Upsert one or more CA components for a short-course student, preserving the
 * components not supplied and recalculating Total_CA. $newComponents maps
 * component keys (A1,A2,A3,T1,T2) to a numeric value, null, or '' (= leave).
 */
function sc_ca_save(mysqli $db, int $shortCourseId, string $courseCode, string $studentId, array $newComponents, string $postedBy): array
{
    $valid = ['A1', 'A2', 'A3', 'T1', 'T2'];
    foreach ($newComponents as $key => $value) {
        if (!in_array($key, $valid, true)) {
            return ['ok' => false, 'message' => "Unknown CA component {$key}."];
        }
        if ($value === null || $value === '') {
            continue;
        }
        if (!is_numeric($value)) {
            return ['ok' => false, 'message' => "{$key} must be numeric."];
        }
        if ((float)$value < 0 || (float)$value > 100) {
            return ['ok' => false, 'message' => "{$key} must be between 0 and 100."];
        }
    }

    $db->begin_transaction();
    try {
        $components = ['A1' => null, 'A2' => null, 'A3' => null, 'T1' => null, 'T2' => null];
        $exists = false;
        if ($stmt = $db->prepare("SELECT A1,A2,A3,T1,T2 FROM short_course_assessment WHERE short_course_id=? AND student_id=? LIMIT 1 FOR UPDATE")) {
            $stmt->bind_param('is', $shortCourseId, $studentId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                foreach ($components as $key => $_) {
                    $components[$key] = $row[$key] === null ? null : (float)$row[$key];
                }
                $exists = true;
            }
            $stmt->close();
        }

        // Apply supplied components (only those provided / non-blank).
        foreach ($newComponents as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $components[$key] = round((float)$value, 2);
        }
        $totalCa = ca_calculate_total_ca($components);

        if ($exists) {
            $stmt = $db->prepare("UPDATE short_course_assessment SET A1=?, A2=?, A3=?, T1=?, T2=?, Total_CA=?, posted_by=? WHERE short_course_id=? AND student_id=?");
            if (!$stmt) {
                throw new RuntimeException($db->error);
            }
            // Types: A1..T2 (5×d), Total_CA (d), posted_by (s), short_course_id (i), student_id (s).
            $stmt->bind_param('ddddddsis', $components['A1'], $components['A2'], $components['A3'], $components['T1'], $components['T2'], $totalCa, $postedBy, $shortCourseId, $studentId);
        } else {
            $stmt = $db->prepare("INSERT INTO short_course_assessment (short_course_id, course_code, student_id, A1, A2, A3, T1, T2, Total_CA, posted_by) VALUES (?,?,?,?,?,?,?,?,?,?)");
            if (!$stmt) {
                throw new RuntimeException($db->error);
            }
            $stmt->bind_param('issdddddds', $shortCourseId, $courseCode, $studentId, $components['A1'], $components['A2'], $components['A3'], $components['T1'], $components['T2'], $totalCa, $postedBy);
        }
        $stmt->execute();
        $stmt->close();
        $db->commit();
        return ['ok' => true, 'message' => $exists ? 'Short-course CA updated.' : 'Short-course CA saved.', 'total_ca' => $totalCa];
    } catch (Throwable $e) {
        $db->rollback();
        error_log('sc_ca_save failed: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Failed to save short-course CA.'];
    }
}
