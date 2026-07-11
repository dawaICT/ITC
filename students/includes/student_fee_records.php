<?php
declare(strict_types=1);

require_once __DIR__ . '/period_mode_helper.php';

function student_fee_table_exists(mysqli $db, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    $safe = $db->real_escape_string($table);
    $result = @$db->query("SHOW TABLES LIKE '{$safe}'");
    $exists = $result && $result->num_rows > 0;
    if ($result) {
        $result->free();
    }
    return $cache[$table] = $exists;
}

function student_fee_columns(mysqli $db, string $table): array
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    $columns = [];
    if (!student_fee_table_exists($db, $table)) {
        return $cache[$table] = $columns;
    }
    if ($result = @$db->query("SHOW COLUMNS FROM `{$table}`")) {
        while ($row = $result->fetch_assoc()) {
            $columns[strtolower((string)$row['Field'])] = (string)$row['Field'];
        }
        $result->free();
    }
    return $cache[$table] = $columns;
}

function student_fee_column_exists(mysqli $db, string $table, string $column): bool
{
    $columns = student_fee_columns($db, $table);
    return isset($columns[strtolower($column)]);
}

function student_fee_bind(mysqli_stmt $stmt, string $types, array &$params): void
{
    if ($types === '') {
        return;
    }
    $refs = [$types];
    foreach ($params as $idx => &$value) {
        $refs[] = &$params[$idx];
    }
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function student_fee_latest_registration(mysqli $db, string $studentId): ?array
{
    if (!student_fee_table_exists($db, 'semester_registration')) {
        return null;
    }

    require_once __DIR__ . '/RegistrationDataService.php';
    $svc = new RegistrationDataService($db);
    $row = $svc->getLatestSemesterRegistration($studentId);
    if (!$row) {
        return null;
    }

    return [
        'id' => $row['id'] ?? null,
        'academic_year' => $row['academic_year'] ?? null,
        'semester' => $row['semester'] ?? null,
        'year_of_study' => $row['year_of_study'] ?? null,
        'program_code' => $row['program_code'] ?? null,
        'period_type' => $row['period_type'] ?? 'semester',
        'active_course_count' => (int)($row['active_course_count'] ?? 0),
    ];
}

function student_fee_student_program(mysqli $db, string $studentId): string
{
    require_once __DIR__ . '/RegistrationDataService.php';
    return (new RegistrationDataService($db))->getBestStudentProgramCode($studentId);
}

function student_fee_program_due(mysqli $db, string $programCode, string $yearOfStudy, string $period): array
{
    $summary = ['fee_rows' => 0, 'total_due' => 0.0];
    if ($programCode === '' || $yearOfStudy === '' || $period === '' || !student_fee_table_exists($db, 'fee_structure')) {
        return $summary;
    }
    $where = ['program_code = ?', 'year_of_study = ?', 'semester = ?'];
    $types = 'sss';
    $params = [$programCode, $yearOfStudy, $period];
    if (student_fee_column_exists($db, 'fee_structure', 'entity_type')) {
        $where[] = "entity_type = 'program'";
    }
    if (student_fee_column_exists($db, 'fee_structure', 'status')) {
        $where[] = "status = 'active'";
    }
    $sql = 'SELECT COUNT(id) AS fee_rows, COALESCE(SUM(amount), 0) AS total_due FROM fee_structure WHERE ' . implode(' AND ', $where);
    if (!$stmt = $db->prepare($sql)) {
        return $summary;
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    if ($row = $stmt->get_result()->fetch_assoc()) {
        $summary['fee_rows'] = (int)($row['fee_rows'] ?? 0);
        $summary['total_due'] = (float)($row['total_due'] ?? 0.0);
    }
    $stmt->close();
    return $summary;
}

function student_fee_flexible_year_matches(string $stored, string $academicYear): bool
{
    $stored = trim($stored);
    $academicYear = trim($academicYear);
    if ($stored === '' || $academicYear === '') {
        return true;
    }
    return $stored === $academicYear
        || str_starts_with($stored, substr($academicYear, 0, 4))
        || str_starts_with($academicYear, substr($stored, 0, 4));
}

function student_fee_completed_payments(mysqli $db, string $studentId, ?array $periodFilter = null, int $limit = 100): array
{
    $records = [];
    $seen = [];
    $addRecord = static function (array $row) use (&$records, &$seen, $periodFilter): void {
        $ref = trim((string)($row['reference_number'] ?? ''));
        $source = (string)($row['_source'] ?? '');
        $amount = (float)($row['amount_paid'] ?? 0.0);
        $key = $ref !== '' ? $ref : $source . '|' . (string)($row['payment_date'] ?? '') . '|' . number_format($amount, 2, '.', '');
        if (isset($seen[$key])) {
            return;
        }
        if ($periodFilter) {
            $period = (string)($periodFilter['semester'] ?? '');
            $yearOfStudy = (string)($periodFilter['year_of_study'] ?? '');
            $academicYear = (string)($periodFilter['academic_year'] ?? '');
            $rowPeriod = trim((string)($row['semester'] ?? ''));
            $rowYear = trim((string)($row['Year'] ?? ''));
            $rowAcademicYear = trim((string)($row['academic_year'] ?? ''));
            if ($period !== '' && $rowPeriod !== '' && $rowPeriod !== $period) {
                return;
            }
            if ($yearOfStudy !== '' && $rowYear !== '' && $rowYear !== $yearOfStudy) {
                return;
            }
            if ($rowYear === '' && $academicYear !== '' && !student_fee_flexible_year_matches($rowAcademicYear, $academicYear)) {
                return;
            }
        }
        $seen[$key] = true;
        $records[] = $row;
    };

    if (student_fee_table_exists($db, 'payments')) {
        $cols = student_fee_columns($db, 'payments');
        if (isset($cols['student_id'])) {
            $statusExpr = isset($cols['status']) ? "AND (LOWER(`{$cols['status']}`) IN ('completed','posted','confirmed','paid','success') OR `{$cols['status']}` IS NULL)" : '';
            $sql = "SELECT "
                . (isset($cols['receipt_no']) ? "`{$cols['receipt_no']}`" : "CAST(`id` AS CHAR)") . " AS reference_number, "
                . (isset($cols['description']) ? "COALESCE(`{$cols['description']}`, 'Payment')" : "'Payment'") . " AS narration, "
                . (isset($cols['semester']) ? "`{$cols['semester']}`" : "''") . " AS semester, "
                . "'' AS Year, "
                . (isset($cols['academic_year']) ? "`{$cols['academic_year']}`" : "''") . " AS academic_year, "
                . (isset($cols['amount']) ? "`{$cols['amount']}`" : '0') . " AS amount_paid, "
                . (isset($cols['method']) ? "`{$cols['method']}`" : "''") . " AS channel, "
                . (isset($cols['payment_date']) ? "`{$cols['payment_date']}`" : (isset($cols['created_at']) ? "`{$cols['created_at']}`" : 'NOW()')) . " AS payment_date, "
                . (isset($cols['status']) ? "`{$cols['status']}`" : "'completed'") . " AS payment_status, "
                . "'payments' AS _source "
                . "FROM payments WHERE `{$cols['student_id']}` = ? {$statusExpr}";
            if ($stmt = $db->prepare($sql)) {
                $stmt->bind_param('s', $studentId);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $addRecord($row);
                }
                $stmt->close();
            }
        }
    }

    if (student_fee_table_exists($db, 'student_payments')) {
        $cols = student_fee_columns($db, 'student_payments');
        $sidCol = $cols['sid'] ?? ($cols['student_id'] ?? null);
        $amountCol = $cols['amount_paid'] ?? ($cols['amount'] ?? null);
        if ($sidCol && $amountCol) {
            $refExpr = isset($cols['reference_number']) ? "`{$cols['reference_number']}`" : (isset($cols['receipt_no']) ? "`{$cols['receipt_no']}`" : "CAST(`{$cols['payment_id']}` AS CHAR)");
            $statusSql = isset($cols['payment_status']) ? "AND (LOWER(`{$cols['payment_status']}`) IN ('completed','posted','confirmed','paid','success') OR `{$cols['payment_status']}` IS NULL)" : '';
            $sql = "SELECT "
                . "{$refExpr} AS reference_number, "
                . (isset($cols['description']) ? "COALESCE(`{$cols['description']}`, 'Payment')" : "'Payment'") . " AS narration, "
                . (isset($cols['semester_term']) ? "`{$cols['semester_term']}`" : (isset($cols['semester']) ? "`{$cols['semester']}`" : "''")) . " AS semester, "
                . (isset($cols['year_of_study']) ? "`{$cols['year_of_study']}`" : (isset($cols['year']) ? "`{$cols['year']}`" : "''")) . " AS Year, "
                . (isset($cols['academic_year']) ? "`{$cols['academic_year']}`" : "''") . " AS academic_year, "
                . "`{$amountCol}` AS amount_paid, "
                . (isset($cols['channel']) ? "`{$cols['channel']}`" : (isset($cols['payment_method']) ? "`{$cols['payment_method']}`" : "''")) . " AS channel, "
                . (isset($cols['payment_date']) ? "`{$cols['payment_date']}`" : (isset($cols['created_at']) ? "`{$cols['created_at']}`" : 'NOW()')) . " AS payment_date, "
                . (isset($cols['payment_status']) ? "`{$cols['payment_status']}`" : "'completed'") . " AS payment_status, "
                . "'student_payments' AS _source "
                . "FROM student_payments WHERE `{$sidCol}` = ? {$statusSql}";
            if ($stmt = $db->prepare($sql)) {
                $stmt->bind_param('s', $studentId);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $addRecord($row);
                }
                $stmt->close();
            }
        }
    }

    usort($records, static function (array $a, array $b): int {
        return strtotime((string)($b['payment_date'] ?? '')) <=> strtotime((string)($a['payment_date'] ?? ''));
    });
    $total = array_reduce($records, static fn(float $carry, array $row): float => $carry + (float)($row['amount_paid'] ?? 0), 0.0);
    return [
        'records' => array_slice($records, 0, $limit),
        'total_paid' => $total,
        'limited' => count($records) > $limit,
    ];
}

function student_fee_current_program_summary(mysqli $db, string $studentId): array
{
    require_once __DIR__ . '/RegistrationDataService.php';
    $svc = new RegistrationDataService($db);
    $registration = $svc->resolveRegistrationTermContext($studentId);
    $currentPeriod = $registration;

    if ($registration) {
        $programCode = trim((string)($registration['program_code'] ?? ''));
        $year = (string)($registration['year_of_study'] ?? '');
        $period = (string)($registration['semester'] ?? '');
        $academicYear = (string)($registration['academic_year'] ?? '');
    } elseif ($currentPeriod) {
        $programCode = trim((string)($currentPeriod['program_code'] ?? ''));
        $year = (string)($currentPeriod['year_of_study'] ?? '1');
        $period = (string)($currentPeriod['semester'] ?? '');
        $academicYear = (string)($currentPeriod['academic_year'] ?? '');
    } else {
        $registration = student_fee_latest_registration($db, $studentId);
        $programCode = trim((string)($registration['program_code'] ?? ''));
        if ($programCode === '') {
            $programCode = student_fee_student_program($db, $studentId);
        }
        $year = (string)($registration['year_of_study'] ?? '');
        $period = (string)($registration['semester'] ?? '');
        $academicYear = (string)($registration['academic_year'] ?? '');
    }

    if ($programCode === '') {
        $programCode = student_fee_student_program($db, $studentId);
    }

    $due = student_fee_program_due($db, $programCode, $year, $period);
    $paid = student_fee_completed_payments($db, $studentId, [
        'academic_year' => $academicYear,
        'year_of_study' => $year,
        'semester' => $period,
    ], 5000);
    return [
        'registration' => $registration,
        'program_code' => $programCode,
        'fee_rows' => $due['fee_rows'],
        'total_due' => $due['total_due'],
        'total_paid' => $paid['total_paid'],
        'balance' => max(0.0, (float)$due['total_due'] - (float)$paid['total_paid']),
        'has_fees' => (int)$due['fee_rows'] > 0,
        'current_period' => $currentPeriod,
    ];
}
