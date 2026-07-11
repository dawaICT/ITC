<?php
// get_departments.php – returns JSON for the DataTable on departments.php
header('Content-Type: application/json');

// Disable error display to prevent HTML error text from breaking JSON
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Suppress the global error handler so it doesn't emit HTML
if (function_exists('set_exception_handler')) { set_exception_handler(null); }
if (function_exists('set_error_handler')) {
    set_error_handler(function($errno, $errstr, $errfile, $errline) {
        error_log("get_departments.php PHP error [{$errno}]: {$errstr} in {$errfile}:{$errline}");
        return true; // suppress
    });
}

// Include your database connection
define('IS_SCRIPT', true);
require_once "includes/admin.php";

$response = ['data' => []];

try {
    // ── Discover actual departments schema ──
    $deptCols = [];
    if ($meta = $db->query("SHOW COLUMNS FROM departments")) {
        while ($c = $meta->fetch_assoc()) {
            $deptCols[strtolower((string)$c['Field'])] = (string)$c['Field'];
        }
        $meta->free();
    }

    $deptIdCol = $deptCols['department_id'] ?? ($deptCols['id'] ?? 'id');
    $deptNameCol = $deptCols['department_name'] ?? ($deptCols['name'] ?? 'department_name');
    $deptCodeCol = $deptCols['department_code'] ?? ($deptCols['code'] ?? null);
    $deptFacultyCol = $deptCols['faculty'] ?? ($deptCols['faculty_id'] ?? null);
    $deptStatusCol = $deptCols['status'] ?? ($deptCols['is_active'] ?? null);
    $deptHodCol = $deptCols['hod_id'] ?? null;
    $deptSectionCol = $deptCols['section_id'] ?? null;

    // ── Check which supporting tables exist ──
    $hasSections = false;
    $hasSSA = false;
    $hasStaff = false;
    $hasPrograms = false;

    foreach (['sections' => 'hasSections', 'staff_section_assignments' => 'hasSSA', 'staff' => 'hasStaff', 'programs' => 'hasPrograms'] as $tbl => $var) {
        if ($chk = @$db->query("SHOW TABLES LIKE '{$tbl}'")) {
            $$var = $chk->num_rows > 0;
            $chk->free();
        }
    }

    // ── Check programs.department_id linkage ──
    $progHasDeptId = false;
    if ($hasPrograms) {
        if ($chk = @$db->query("SHOW COLUMNS FROM programs LIKE 'department_id'")) {
            $progHasDeptId = $chk->num_rows > 0;
            $chk->free();
        }
    }

    // ── Build query based on actual schema ──
    $selectParts = [
        "d.`{$deptIdCol}` AS department_id",
        "d.`{$deptNameCol}` AS department_name",
    ];

    // Department code (for display as department_id if no department_id column)
    if ($deptCodeCol) {
        $selectParts[] = "d.`{$deptCodeCol}` AS department_code";
    }

    // Faculty
    if ($deptFacultyCol) {
        $selectParts[] = "d.`{$deptFacultyCol}` AS faculty";
    } else {
        $selectParts[] = "'' AS faculty";
    }

    // Status
    if ($deptStatusCol) {
        $selectParts[] = "d.`{$deptStatusCol}` AS status";
    } else {
        $selectParts[] = "'active' AS status";
    }

    // HOD/HOS name. Prefer departments.hod_id if that column exists; otherwise
    // derive the head from the department's section (staff_section_assignments).
    // Sections span many departments, so every department in a section reflects
    // that section's active Head of Section. Correlated subqueries avoid the row
    // multiplication a JOIN would cause when a section has stale assignments.
    if ($deptHodCol && $hasStaff) {
        $selectParts[] = "d.`{$deptHodCol}` AS hod_id";
        $selectParts[] = "CONCAT(s.Fname, ' ', s.Lname) AS hod_name";
    } elseif ($deptSectionCol && $hasSections && $hasSSA && $hasStaff) {
        $selectParts[] = "(SELECT ssa_h.staff_id
             FROM staff_section_assignments ssa_h
             WHERE ssa_h.section_id = d.`{$deptSectionCol}`
               AND ssa_h.role_key = 'head_of_department'
               AND ssa_h.status = 'active'
             ORDER BY ssa_h.is_primary DESC, ssa_h.assigned_at DESC
             LIMIT 1) AS hod_id";
        $selectParts[] = "(SELECT CONCAT(st_h.Fname, ' ', st_h.Lname)
             FROM staff_section_assignments ssa_n
             JOIN staff st_h ON st_h.staff_id = ssa_n.staff_id
             WHERE ssa_n.section_id = d.`{$deptSectionCol}`
               AND ssa_n.role_key = 'head_of_department'
               AND ssa_n.status = 'active'
             ORDER BY ssa_n.is_primary DESC, ssa_n.assigned_at DESC
             LIMIT 1) AS hod_name";
    } else {
        $selectParts[] = "NULL AS hod_id";
        $selectParts[] = "NULL AS hod_name";
    }

    // Program count subquery
    if ($hasPrograms && $progHasDeptId) {
        $selectParts[] = "(SELECT COUNT(*) FROM programs p WHERE p.department_id = d.`{$deptIdCol}`) AS program_count";
    } else {
        $selectParts[] = "0 AS program_count";
    }

    // Staff count subquery
    if ($hasSections && $hasSSA) {
        $selectParts[] = "(SELECT COUNT(DISTINCT ssa.staff_id) FROM staff_section_assignments ssa
             JOIN sections sec ON ssa.section_id = sec.section_id
             WHERE sec.department_id = d.`{$deptIdCol}` AND ssa.status = 'active') AS staff_count";
    } else {
        // Fallback: count staff by deptId if staff table has it
        $staffHasDeptId = false;
        if ($hasStaff) {
            if ($chk = @$db->query("SHOW COLUMNS FROM staff LIKE 'deptId'")) {
                $staffHasDeptId = $chk->num_rows > 0;
                $chk->free();
            }
        }
        if ($staffHasDeptId) {
            // staff.deptId may reference department_code or department id
            $selectParts[] = "(SELECT COUNT(*) FROM staff st WHERE st.deptId = d.`{$deptIdCol}` OR " .
                ($deptCodeCol ? "st.deptId = d.`{$deptCodeCol}`" : "0") .
                ") AS staff_count";
        } else {
            $selectParts[] = "0 AS staff_count";
        }
    }

    // Student count subquery
    if ($hasPrograms && $progHasDeptId) {
        $selectParts[] = "(SELECT COUNT(DISTINCT sp.Sid) FROM student_program sp
             JOIN programs p ON sp.program_code = p.program_code
             WHERE p.department_id = d.`{$deptIdCol}`) AS student_count";
    } else {
        $selectParts[] = "0 AS student_count";
    }

    $sql = "SELECT " . implode(",\n    ", $selectParts) . "\nFROM departments d\n";

    if ($deptHodCol && $hasStaff) {
        $sql .= "LEFT JOIN staff s ON d.`{$deptHodCol}` = s.staff_id\n";
    }

    $sql .= "ORDER BY d.`{$deptNameCol}` ASC";

    $result = $db->query($sql);

    if (!$result) {
        throw new Exception($db->error);
    }

    $data = [];
    while ($row = $result->fetch_assoc()) {
        // Display ID: prefer department_code, fall back to department_id
        $displayId = !empty($row['department_code']) ? $row['department_code'] : $row['department_id'];

        // Format Status Badge
        $rawStatus = strtolower(trim((string)($row['status'] ?? 'active')));
        if ($rawStatus === 'active' || $rawStatus === '1' || $rawStatus === '') {
            $statusClass = 'bg-success';
            $statusText = 'Active';
        } else {
            $statusClass = 'bg-secondary';
            $statusText = ucfirst($rawStatus);
        }
        $statusHtml = "<span class='badge rounded-pill {$statusClass}'>{$statusText}</span>";

        // Format HOS Name
        $hodHtml = !empty($row['hod_name'])
            ? "<div class='d-flex align-items-center'><div class='avatar-circle me-3'>" . strtoupper(substr(trim($row['hod_name']), 0, 1)) . "</div><div><div class='fw-semibold'>" . htmlspecialchars($row['hod_name']) . "</div></div></div>"
            : "<span class='text-muted small'><em><i class='fas fa-exclamation-circle me-1'></i>Not Assigned</em></span>";

        // Faculty display
        $faculty = $row['faculty'] ?? '';
        if (is_numeric($faculty)) {
            // faculty_id — could map to a faculties table, but for now show the ID or "Not Set"
            $faculty = $faculty ? "Faculty #{$faculty}" : 'Not Set';
        }
        if ($faculty === '' || $faculty === null) {
            $facultyHtml = "<span class='badge bg-secondary'>Unassigned</span>";
        } else {
            $facultyHtml = "<span class='badge bg-success'>" . htmlspecialchars($faculty) . "</span>";
        }

        $data[] = [
            'id'              => (string)($row['department_id'] ?? ''), // numeric PK for view/edit/delete
            'department_id'   => "<span class='badge bg-light text-dark border font-monospace'>" . htmlspecialchars($displayId) . "</span>",
            'department_name' => "<div class='fw-semibold'>" . htmlspecialchars($row['department_name']) . "</div>",
            'hod'             => $hodHtml,
            'faculty'         => $facultyHtml,
            'programs'        => "<span class='badge bg-info'>" . (int)($row['program_count'] ?? 0) . "</span>",
            'students'        => (int)($row['student_count'] ?? 0),
            'faculty_count'   => (int)($row['staff_count'] ?? 0),
            'status'          => $statusHtml,
        ];
    }

    $response['data'] = $data;

} catch (Exception $e) {
    error_log("get_departments.php error: " . $e->getMessage());
    http_response_code(500);
    $response['error'] = $e->getMessage();
}

echo json_encode($response);
exit;
