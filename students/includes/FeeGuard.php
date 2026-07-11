<?php
// FeeGuard: central fee-threshold checks that work with both legacy and new fee schemas

declare(strict_types=1);

/** Check if a table exists */
function fg_table_exists(mysqli $db, string $table): bool {
    $t = $db->real_escape_string($table);
    if ($res = $db->query("SHOW TABLES LIKE '{$t}'")) { $ok = $res->num_rows > 0; $res->free(); return $ok; }
    return false;
}

/** Check if a column exists on a table */
function fg_column_exists(mysqli $db, string $table, string $column): bool {
    $t = $db->real_escape_string($table);
    $c = $db->real_escape_string($column);
    if ($res = $db->query("SHOW COLUMNS FROM {$t} LIKE '{$c}'")) { $ok = $res->num_rows > 0; $res->free(); return $ok; }
    return false;
}

/** Get latest payment balance for a term; returns null if none */
function fg_latest_term_balance(mysqli $db, string $sid, int $year, int $semester): ?float {
    if (!fg_table_exists($db, 'student_payments')) {
        return null;
    }
    $Sid = $db->real_escape_string($sid);
    $yr = $db->real_escape_string((string)$year);
    $sem = $db->real_escape_string((string)$semester);
    $sidCol = fg_column_exists($db, 'student_payments', 'Sid') ? 'Sid' : (fg_column_exists($db, 'student_payments', 'SID') ? 'SID' : null);
    $semCol = fg_column_exists($db, 'student_payments', 'semester_term') ? 'semester_term' : (fg_column_exists($db, 'student_payments', 'semester') ? 'semester' : null);
    // Match year-of-study (1..4), not calendar academic_year — callers pass year_of_study.
    if (fg_column_exists($db, 'student_payments', 'year_of_study')) {
        $yearCol = 'year_of_study';
    } elseif (fg_column_exists($db, 'student_payments', 'Year')) {
        $yearCol = 'Year';
    } elseif (fg_column_exists($db, 'student_payments', 'academic_year')) {
        $yearCol = 'academic_year';
    } else {
        $yearCol = null;
    }
    $idCol = fg_column_exists($db, 'student_payments', 'payment_id') ? 'payment_id' : (fg_column_exists($db, 'student_payments', 'id') ? 'id' : null);
    if ($sidCol === null || $semCol === null || $yearCol === null || !fg_column_exists($db, 'student_payments', 'balance')) {
        return null;
    }
    $order = $idCol ? " ORDER BY `{$idCol}` DESC" : '';
    $sql = "SELECT balance FROM student_payments WHERE `{$sidCol}`='{$Sid}' AND `{$semCol}`='{$sem}' AND `{$yearCol}`='{$yr}'{$order} LIMIT 1";
    if ($r = $db->query($sql)) {
        if ($row = $r->fetch_assoc()) { $r->free(); return (float)($row['balance'] ?? 0.0); }
        $r->free();
    }
    return null;
}

/** Get program_code for a student */
function fg_student_program(mysqli $db, string $sid): ?string {
    if (!fg_table_exists($db, 'student_program')) {
        return null;
    }
    $Sid = $db->real_escape_string($sid);
    $sidCol = fg_column_exists($db, 'student_program', 'Sid') ? 'Sid' : (fg_column_exists($db, 'student_program', 'SID') ? 'SID' : null);
    if ($sidCol === null || !fg_column_exists($db, 'student_program', 'program_code')) {
        return null;
    }
    $orderCol = fg_column_exists($db, 'student_program', 'updated_at') ? 'updated_at' : (fg_column_exists($db, 'student_program', 'startYear') ? 'startYear' : null);
    $order = $orderCol ? " ORDER BY `{$orderCol}` DESC" : '';
    $sql = "SELECT program_code FROM student_program WHERE `{$sidCol}`='{$Sid}'{$order} LIMIT 1";
    if ($r = $db->query($sql)) { if ($row = $r->fetch_assoc()) { $r->free(); return (string)$row['program_code']; } $r->free(); }
    return null;
}

/** Compute required tuition fee for program/year/semester using available schema */
function fg_required_fee(mysqli $db, string $programCode, int $year, int $semester): float {
    // Live schema: normalized fee_structure table (entity_type/program_code/
    // year_of_study/semester/amount). This is the table the accounts module
    // maintains via add_fee_structure.php, so it is checked first.
    if (fg_table_exists($db, 'fee_structure')) {
        $where = ["program_code = ?", "year_of_study = ?", "semester = ?"];
        $types = 'sii';
        $params = [$programCode, $year, $semester];
        if (fg_column_exists($db, 'fee_structure', 'entity_type')) {
            $where[] = "entity_type = 'program'";
        }
        if (fg_column_exists($db, 'fee_structure', 'status')) {
            $where[] = "status = 'active'";
        }
        $sql = "SELECT COALESCE(SUM(amount),0) AS fee FROM fee_structure WHERE " . implode(' AND ', $where);
        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $fee = (float)($stmt->get_result()->fetch_assoc()['fee'] ?? 0.0);
            $stmt->close();
            if ($fee > 0) return $fee;
        }
    }

    if (fg_table_exists($db, 'program_fees')) {
        // Legacy wide columns: YR_{Y}_S{S}
        $col = sprintf('YR_%d_S%d', $year, $semester);
        if (fg_column_exists($db, 'program_fees', $col)) {
            $sql = "SELECT `{$col}` AS fee FROM program_fees WHERE program_code = ? ORDER BY id DESC LIMIT 1";
            if ($stmt = $db->prepare($sql)) {
                $stmt->bind_param('s', $programCode);
                $stmt->execute();
                $fee = (float)($stmt->get_result()->fetch_assoc()['fee'] ?? 0.0);
                $stmt->close();
                if ($fee > 0) return $fee;
            }
        }
        // Simplified: academic_year + semester_fee (no semester dimension)
        if (fg_column_exists($db, 'program_fees', 'academic_year') && fg_column_exists($db, 'program_fees', 'semester_fee')) {
            $sql = "SELECT semester_fee AS fee FROM program_fees WHERE program_code = ? AND academic_year = ? ORDER BY id DESC LIMIT 1";
            if ($stmt = $db->prepare($sql)) {
                $yearStr = (string)$year;
                $stmt->bind_param('ss', $programCode, $yearStr);
                $stmt->execute();
                $fee = (float)($stmt->get_result()->fetch_assoc()['fee'] ?? 0.0);
                $stmt->close();
                if ($fee > 0) return $fee;
            }
        }
    }

    // Newer normalized fee_structures table
    if (fg_table_exists($db, 'fee_structures')) {
        $yr = $db->real_escape_string((string)$year);
        $sem = $db->real_escape_string((string)$semester);
        $hasStatus = fg_column_exists($db, 'fee_structures', 'status');
        $where = $hasStatus ? "AND status='active'" : '';
        $sql = "SELECT COALESCE(SUM(amount),0) AS fee FROM fee_structures WHERE program_code='{$prog}' AND year_of_study={$yr} AND semester={$sem} {$where}";
        if ($r = $db->query($sql)) { $fee = (float)($r->fetch_assoc()['fee'] ?? 0.0); $r->free(); if ($fee > 0) return $fee; }
    }

    return 0.0;
}

/**
 * Fully-sponsored students (e.g. TEVETA / CDF bursaries) are cleared by their
 * sponsorship status rather than by payments.
 */
function fg_is_fully_sponsored(mysqli $db, string $sid): bool {
    if (!fg_table_exists($db, 'students') || !fg_column_exists($db, 'students', 'sponsor')) {
        return false;
    }
    $Sid = $db->real_escape_string($sid);
    if ($r = $db->query("SELECT sponsor FROM students WHERE SID='{$Sid}' LIMIT 1")) {
        $row = $r->fetch_assoc();
        $r->free();
        $sponsor = strtoupper(trim((string)($row['sponsor'] ?? '')));
        return in_array($sponsor, ['TEVETA', 'CDF'], true);
    }
    return false;
}

/**
 * Returns [ok=>bool, message=>string]
 *
 * Accounts-clearance policy:
 *  - Fully sponsored students are always clear.
 *  - If no fee structure is configured for the term, the check FAILS OPEN
 *    (logged) — an unconfigured fee table must not lock students out of
 *    registration, results, or CA. Enforcement starts automatically once
 *    accounts populates fee_structure for the programme.
 *  - Otherwise the student must have paid at least the threshold percentage,
 *    evidenced by the per-term student_payments balance, or failing that by
 *    the invoices/payments ledger kept by the accounts module.
 */
function fg_check_fee_threshold(mysqli $db, string $sid, int $year, int $semester, float $thresholdPercent, ?string $academicYear = null): array {
    if (fg_is_fully_sponsored($db, $sid)) {
        return ['ok' => true, 'message' => 'Cleared: fees covered by sponsorship.'];
    }

    $program = fg_student_program($db, $sid);
    if ($program === null) {
        return ['ok' => false, 'message' => 'Program not found for your account.'];
    }
    $required = fg_required_fee($db, $program, $year, $semester);
    if ($required <= 0) {
        error_log(sprintf('FeeGuard: fee structure not configured for program "%s", year %d, semester %d — allowing (fail-open).', $program, $year, $semester));
        return ['ok' => true, 'message' => 'No fee structure configured for this term.'];
    }

    // Primary evidence: per-term running balance in student_payments.
    $latestBalance = fg_latest_term_balance($db, $sid, $year, $semester);
    if ($latestBalance !== null) {
        $paid = max(0.0, $required - (float)$latestBalance);
        $ok = ($paid >= ($thresholdPercent / 100.0) * $required);
        return ['ok' => $ok, 'message' => $ok ? 'OK' : sprintf('Please pay at least %.0f%% of your tuition fees before proceeding.', $thresholdPercent)];
    }

    // Fallback evidence: the accounts-module ledger (posted payments vs the
    // required term fee).
    $Sid = $db->real_escape_string($sid);
    $paidTotal = 0.0;
    if (fg_table_exists($db, 'payments')) {
        $where = ["student_id = ?", "status IN ('posted','completed','confirmed')"];
        $types = 's';
        $params = [$sid];
        // Match the same academic period when the payments ledger has period
        // fields; otherwise fall back to the older all-time payment total.
        if ($academicYear !== null && $academicYear !== '' && fg_column_exists($db, 'payments', 'academic_year')) {
            $where[] = "academic_year = ?";
            $types .= 's';
            $params[] = $academicYear;
        }
        if (fg_column_exists($db, 'payments', 'semester')) {
            $where[] = "semester = ?";
            $types .= 'i';
            $params[] = $semester;
        }
        $sql = "SELECT COALESCE(SUM(amount),0) AS p FROM payments WHERE " . implode(' AND ', $where);
        if ($stmt = $db->prepare($sql)) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $paidTotal = (float)($stmt->get_result()->fetch_assoc()['p'] ?? 0.0);
            $stmt->close();
        }
    }
    $ok = ($paidTotal >= ($thresholdPercent / 100.0) * $required);
    return ['ok' => $ok, 'message' => $ok ? 'OK' : sprintf('Please pay at least %.0f%% of your tuition fees before proceeding.', $thresholdPercent)];
}

?>


