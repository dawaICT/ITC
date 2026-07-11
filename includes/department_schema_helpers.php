<?php
/**
 * Schema-aware department lookups for installs where departments.id != department_id
 * and HOS assignment may live in staff_section_assignments instead of departments.hod_id.
 */

if (!function_exists('wuc_department_columns')) {
    function wuc_department_columns(mysqli $db): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $cols = [];
        if ($meta = @$db->query('SHOW COLUMNS FROM departments')) {
            while ($row = $meta->fetch_assoc()) {
                $cols[strtolower((string)$row['Field'])] = (string)$row['Field'];
            }
            $meta->free();
        }

        return $cache = $cols;
    }
}

if (!function_exists('wuc_department_pk_column')) {
    function wuc_department_pk_column(mysqli $db): string
    {
        $cols = wuc_department_columns($db);
        return $cols['department_id'] ?? ($cols['id'] ?? 'id');
    }
}

if (!function_exists('wuc_table_exists_simple')) {
    function wuc_table_exists_simple(mysqli $db, string $table): bool
    {
        $safe = $db->real_escape_string($table);
        if ($res = @$db->query("SHOW TABLES LIKE '{$safe}'")) {
            $exists = $res->num_rows > 0;
            $res->free();
            return $exists;
        }
        return false;
    }
}

if (!function_exists('wuc_department_fetch_detail')) {
    /**
     * @return array<string,mixed>|null
     */
    function wuc_department_fetch_detail(mysqli $db, string $deptId): ?array
    {
        $deptId = trim($deptId);
        if ($deptId === '' || !wuc_table_exists_simple($db, 'departments')) {
            return null;
        }

        $cols = wuc_department_columns($db);
        $pkCol = wuc_department_pk_column($db);
        $nameCol = $cols['department_name'] ?? ($cols['name'] ?? 'department_name');
        $codeCol = $cols['department_code'] ?? ($cols['code'] ?? null);
        $facultyCol = $cols['faculty'] ?? ($cols['faculty_id'] ?? null);
        $statusCol = $cols['status'] ?? null;
        $hodCol = $cols['hod_id'] ?? null;
        $sectionCol = $cols['section_id'] ?? null;

        $select = [
            "d.`{$pkCol}` AS department_id",
            "d.`{$nameCol}` AS department_name",
        ];
        if ($codeCol) {
            $select[] = "d.`{$codeCol}` AS department_code";
        } else {
            $select[] = "CAST(d.`{$pkCol}` AS CHAR) AS department_code";
        }
        if ($facultyCol) {
            $select[] = "d.`{$facultyCol}` AS faculty";
        } else {
            $select[] = "'' AS faculty";
        }
        if ($statusCol) {
            $select[] = "d.`{$statusCol}` AS status";
        } else {
            $select[] = "'active' AS status";
        }

        $join = '';
        if ($hodCol && wuc_table_exists_simple($db, 'staff')) {
            $select[] = 's.Fname';
            $select[] = 's.Lname';
            $select[] = 's.title';
            $select[] = 's.email';
            $join = " LEFT JOIN staff s ON d.`{$hodCol}` = s.staff_id";
        } elseif ($sectionCol && wuc_table_exists_simple($db, 'staff_section_assignments') && wuc_table_exists_simple($db, 'staff')) {
            $select[] = 's.Fname';
            $select[] = 's.Lname';
            $select[] = 's.title';
            $select[] = 's.email';
            $join = " LEFT JOIN staff_section_assignments ssa ON ssa.section_id = d.`{$sectionCol}` AND ssa.status = 'active'
                      LEFT JOIN staff s ON s.staff_id = ssa.staff_id";
        } else {
            $select[] = 'NULL AS Fname';
            $select[] = 'NULL AS Lname';
            $select[] = 'NULL AS title';
            $select[] = 'NULL AS email';
        }

        $sql = 'SELECT ' . implode(', ', $select) . " FROM departments d{$join} WHERE d.`{$pkCol}` = ? LIMIT 1";
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $deptId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }
}

if (!function_exists('wuc_department_programs_stmt')) {
    function wuc_department_programs_stmt(mysqli $db, string $deptId): ?mysqli_stmt
    {
        $deptId = trim($deptId);
        if ($deptId === '' || !wuc_table_exists_simple($db, 'programs')) {
            return null;
        }

        $pkCol = wuc_department_pk_column($db);
        $progCols = [];
        if ($meta = @$db->query('SHOW COLUMNS FROM programs')) {
            while ($row = $meta->fetch_assoc()) {
                $progCols[strtolower((string)$row['Field'])] = (string)$row['Field'];
            }
            $meta->free();
        }
        if (!isset($progCols['department_id'])) {
            return null;
        }

        $orderCol = $progCols['program_name'] ?? 'program_code';
        $sql = "SELECT * FROM programs WHERE department_id = ? ORDER BY `{$orderCol}` ASC";
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $deptId);
        $stmt->execute();
        return $stmt;
    }
}

if (!function_exists('wuc_semester_registration_date_column')) {
    function wuc_semester_registration_date_column(mysqli $db): ?string
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        if (!wuc_table_exists_simple($db, 'semester_registration')) {
            return $cache = null;
        }

        foreach (['registration_date', 'date_registered', 'created_at'] as $candidate) {
            if ($res = @$db->query("SHOW COLUMNS FROM semester_registration LIKE '" . $db->real_escape_string($candidate) . "'")) {
                $exists = $res->num_rows > 0;
                $res->free();
                if ($exists) {
                    return $cache = $candidate;
                }
            }
        }

        return $cache = null;
    }
}
