<?php
/**
 * Core Logic for Fees and Fee Breakdown Module
 */

if (!function_exists('fees_calculate_payable')) {
    /**
     * Calculates the expected fees for a given course, training mode, and academic year.
     * Separates institutional, external, and informational fees.
     */
    function fees_calculate_payable(mysqli $db, int $courseId, int $trainingModeId, string $academicYear): array
    {
        $baseFee = 0.00;
        $currency = 'ZMW';

        // 1. Fetch base course fee
        $stmt = $db->prepare("SELECT base_fee, currency FROM course_fees 
                              WHERE course_id = ? AND training_mode_id = ? AND academic_year = ? AND status = 'active' 
                              LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('iis', $courseId, $trainingModeId, $academicYear);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $baseFee = (float)$row['base_fee'];
                $currency = $row['currency'] ?: 'ZMW';
            }
            $stmt->close();
        }

        // 2. Fetch all linked fee items
        $mandatoryInstitutionFees = [];
        $externalFees = [];
        $informationalFees = [];
        $additionalFeeTotal = 0.00;

        $query = "SELECT fi.id, fi.name, fi.description, fi.amount, fi.mandatory_status, fi.collection_type 
                  FROM course_fee_breakdown cfb
                  INNER JOIN fee_items fi ON cfb.fee_item_id = fi.id
                  WHERE cfb.course_id = ? AND fi.academic_year = ? AND fi.status = 'active'";
                  
        $stmt = $db->prepare($query);
        if ($stmt) {
            $stmt->bind_param('is', $courseId, $academicYear);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $item = [
                    'id' => (int)$row['id'],
                    'name' => $row['name'],
                    'description' => $row['description'],
                    'amount' => (float)$row['amount'],
                    'mandatory' => $row['mandatory_status'] === 'mandatory',
                    'collection_type' => $row['collection_type']
                ];

                if ($row['collection_type'] === 'Institution Collected') {
                    if ($item['mandatory']) {
                        $mandatoryInstitutionFees[] = $item;
                        $additionalFeeTotal += $item['amount'];
                    } else {
                        // Optional institutional fees are not automatically added to payable
                    }
                } elseif ($row['collection_type'] === 'External Payment') {
                    $externalFees[] = $item;
                } else {
                    $informationalFees[] = $item;
                }
            }
            $stmt->close();
        }

        $totalPayable = $baseFee + $additionalFeeTotal;

        return [
            'base_fee' => $baseFee,
            'currency' => $currency,
            'mandatory_institution_fees' => $mandatoryInstitutionFees,
            'external_fees' => $externalFees,
            'informational_fees' => $informationalFees,
            'additional_fee_total' => $additionalFeeTotal,
            'total_payable' => $totalPayable
        ];
    }
}

if (!function_exists('fees_statement_resolve_program_code')) {
    function fees_statement_resolve_program_code(mysqli $db, string $studentId): string
    {
        $studentId = trim($studentId);
        if ($studentId === '') {
            return '';
        }

        $feeGuard = __DIR__ . '/../students/includes/FeeGuard.php';
        if (is_file($feeGuard)) {
            require_once $feeGuard;
            if (function_exists('fg_student_program')) {
                $code = fg_student_program($db, $studentId);
                if (is_string($code) && trim($code) !== '') {
                    return trim($code);
                }
            }
        }

        if ($stmt = $db->prepare('SELECT program FROM students WHERE SID = ? LIMIT 1')) {
            $stmt->bind_param('s', $studentId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                return trim((string)($row['program'] ?? ''));
            }
        }

        return '';
    }
}

if (!function_exists('fees_statement_resolve_registration_context')) {
    /**
     * @return array{year_of_study:int,period_number:int,period_type:string,academic_year:string,period_label:string,period_label_full:string}
     */
    function fees_statement_resolve_registration_context(mysqli $db, string $studentId, string $accountAcademicYear = ''): array
    {
        $defaults = [
            'year_of_study' => 1,
            'period_number' => 1,
            'period_type' => 'semester',
            'academic_year' => $accountAcademicYear,
            'period_label' => 'Semester 1',
            'period_label_full' => 'Year 1, Semester 1',
        ];

        $tbl = $db->query("SHOW TABLES LIKE 'semester_registration'");
        $hasReg = $tbl && $tbl->num_rows > 0;
        if ($tbl) {
            $tbl->free();
        }
        if (!$hasReg) {
            return $defaults;
        }

        $sql = 'SELECT year_of_study, semester, period_type, academic_year
                  FROM semester_registration
                 WHERE student_id = ?';
        $types = 's';
        $params = [$studentId];
        if ($accountAcademicYear !== '') {
            $sql .= ' AND academic_year = ?';
            $types .= 's';
            $params[] = $accountAcademicYear;
        }
        $sql .= ' ORDER BY id DESC LIMIT 1';

        if (!$stmt = $db->prepare($sql)) {
            return $defaults;
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return $defaults;
        }

        $yearOfStudy = max(1, (int)($row['year_of_study'] ?? 1));
        $periodNumber = max(1, (int)($row['semester'] ?? 1));
        $periodType = strtolower(trim((string)($row['period_type'] ?? 'semester')));
        $academicYear = trim((string)($row['academic_year'] ?? $accountAcademicYear));
        $unit = $periodType === 'term' ? 'Term' : 'Semester';
        $periodLabel = $unit . ' ' . $periodNumber;
        $periodLabelFull = 'Year ' . $yearOfStudy . ', ' . $periodLabel;
        if ($academicYear !== '') {
            $periodLabelFull .= ' (' . $academicYear . ')';
        }

        return [
            'year_of_study' => $yearOfStudy,
            'period_number' => $periodNumber,
            'period_type' => $periodType,
            'academic_year' => $academicYear,
            'period_label' => $periodLabel,
            'period_label_full' => $periodLabelFull,
        ];
    }
}

if (!function_exists('fees_statement_fetch_program_fee_lines')) {
    /**
     * @return list<array{name:string,amount:float,category:string}>
     */
    function fees_statement_fetch_program_fee_lines(mysqli $db, string $programCode, int $yearOfStudy, int $periodNumber): array
    {
        $programCode = trim($programCode);
        if ($programCode === '') {
            return [];
        }

        $tbl = $db->query("SHOW TABLES LIKE 'fee_structure'");
        $exists = $tbl && $tbl->num_rows > 0;
        if ($tbl) {
            $tbl->free();
        }
        if (!$exists) {
            return [];
        }

        $where = ['program_code = ?', 'year_of_study = ?', 'semester = ?'];
        $types = 'sii';
        $params = [$programCode, $yearOfStudy, $periodNumber];

        $entityCol = $db->query("SHOW COLUMNS FROM fee_structure LIKE 'entity_type'");
        if ($entityCol && $entityCol->num_rows > 0) {
            $where[] = "entity_type = 'program'";
            $entityCol->free();
        }
        $statusCol = $db->query("SHOW COLUMNS FROM fee_structure LIKE 'status'");
        if ($statusCol && $statusCol->num_rows > 0) {
            $where[] = "status = 'active'";
            $statusCol->free();
        }

        $sql = 'SELECT fee_description, amount FROM fee_structure WHERE ' . implode(' AND ', $where) . ' ORDER BY id ASC';
        if (!$stmt = $db->prepare($sql)) {
            return [];
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $lines = [];
        while ($row = $res->fetch_assoc()) {
            $name = trim((string)($row['fee_description'] ?? 'Program Fee'));
            if ($name === '') {
                $name = 'Program Fee';
            }
            $lines[] = [
                'name' => $name,
                'amount' => (float)($row['amount'] ?? 0),
                'category' => 'Institutional Fee',
            ];
        }
        $stmt->close();

        return $lines;
    }
}

if (!function_exists('fees_statement_build_breakdown')) {
    /**
     * Build a fee breakdown for the statement that reconciles with the account
     * snapshot (total_payable). Prefers programme fee_structure for registered
     * students; falls back to course_fees catalog lines.
     *
     * @param array<string,mixed> $account student_fee_accounts row (+ joined fields)
     * @return array{
     *   institutional_lines:list<array{name:string,amount:float,category:string}>,
     *   external_fees:list<array>,
     *   informational_fees:list<array>,
     *   total_payable:float,
     *   bursary:float,
     *   period_label_full:string,
     *   program_code:string,
     *   source:string
     * }
     */
    function fees_statement_build_breakdown(mysqli $db, array $account): array
    {
        $studentId = trim((string)($account['student_id'] ?? ''));
        $academicYear = trim((string)($account['academic_year'] ?? ''));
        $courseId = (int)($account['course_id'] ?? 0);
        $trainingModeId = (int)($account['training_mode_id'] ?? 0);
        $totalPayable = (float)($account['total_payable'] ?? 0);
        $bursary = (float)($account['bursary_amount'] ?? 0);
        $baseFee = (float)($account['base_fee'] ?? 0);
        $additionalTotal = (float)($account['additional_fee_total'] ?? 0);

        $regCtx = fees_statement_resolve_registration_context($db, $studentId, $academicYear);
        $programCode = fees_statement_resolve_program_code($db, $studentId);
        $periodLabelFull = (string)($regCtx['period_label_full'] ?? '');

        $courseBreakdown = ($courseId > 0 && $trainingModeId > 0 && $academicYear !== '')
            ? fees_calculate_payable($db, $courseId, $trainingModeId, $academicYear)
            : [
                'mandatory_institution_fees' => [],
                'external_fees' => [],
                'informational_fees' => [],
                'total_payable' => 0.0,
                'base_fee' => 0.0,
            ];

        $externalFees = is_array($courseBreakdown['external_fees'] ?? null) ? $courseBreakdown['external_fees'] : [];
        $informationalFees = is_array($courseBreakdown['informational_fees'] ?? null) ? $courseBreakdown['informational_fees'] : [];

        $institutionalLines = [];
        $source = 'account';

        $programLines = $programCode !== ''
            ? fees_statement_fetch_program_fee_lines(
                $db,
                $programCode,
                (int)$regCtx['year_of_study'],
                (int)$regCtx['period_number']
            )
            : [];
        $programSum = array_sum(array_map(static fn(array $line): float => (float)$line['amount'], $programLines));

        $matchesAccount = static function (float $sum, float $target, float $bursaryAmount): bool {
            return abs($sum - $target) <= 0.01 || abs(($sum - $bursaryAmount) - $target) <= 0.01;
        };

        if ($programLines !== [] && $matchesAccount($programSum, $totalPayable, $bursary)) {
            foreach ($programLines as $line) {
                $institutionalLines[] = [
                    'name' => $line['name'] . ($periodLabelFull !== '' ? ' (' . $periodLabelFull . ')' : ''),
                    'amount' => (float)$line['amount'],
                    'category' => $line['category'],
                ];
            }
            $source = 'program_fee_structure';
        } elseif (
            (float)($courseBreakdown['total_payable'] ?? 0) > 0
            && $matchesAccount((float)$courseBreakdown['total_payable'], $totalPayable, $bursary)
        ) {
            $institutionalLines[] = [
                'name' => 'Base Course Tuition Fee',
                'amount' => (float)$courseBreakdown['base_fee'],
                'category' => 'Institutional Fee',
            ];
            foreach ($courseBreakdown['mandatory_institution_fees'] as $item) {
                $institutionalLines[] = [
                    'name' => (string)($item['name'] ?? 'Additional Fee'),
                    'amount' => (float)($item['amount'] ?? 0),
                    'category' => 'Institutional Fee (Mandatory)',
                ];
            }
            $source = 'course_fees';
        } elseif ($matchesAccount($baseFee + $additionalTotal, $totalPayable + $bursary, 0.0)) {
            if ($baseFee > 0) {
                $institutionalLines[] = [
                    'name' => 'Base Course Tuition Fee',
                    'amount' => $baseFee,
                    'category' => 'Institutional Fee',
                ];
            }
            if ($additionalTotal > 0) {
                $institutionalLines[] = [
                    'name' => 'Additional Institutional Fees (at registration)',
                    'amount' => $additionalTotal,
                    'category' => 'Institutional Fee (Mandatory)',
                ];
            }
            $source = 'account_snapshot';
        } elseif ($programLines !== []) {
            foreach ($programLines as $line) {
                $institutionalLines[] = [
                    'name' => $line['name'] . ($periodLabelFull !== '' ? ' (' . $periodLabelFull . ')' : ''),
                    'amount' => (float)$line['amount'],
                    'category' => $line['category'],
                ];
            }
            $source = 'program_fee_structure';
        } elseif ($totalPayable > 0) {
            $institutionalLines[] = [
                'name' => 'Institutional Fees' . ($periodLabelFull !== '' ? ' (' . $periodLabelFull . ')' : ''),
                'amount' => $totalPayable + $bursary,
                'category' => 'Institutional Fee',
            ];
            $source = 'account_total';
        }

        return [
            'institutional_lines' => $institutionalLines,
            'external_fees' => $externalFees,
            'informational_fees' => $informationalFees,
            'total_payable' => $totalPayable,
            'bursary' => $bursary,
            'period_label_full' => $periodLabelFull,
            'program_code' => $programCode,
            'source' => $source,
        ];
    }
}

if (!function_exists('fees_generate_student_account')) {
    /**
     * Automatically generates a student fee account during registration or updates.
     * Prevents duplication and preserves fee amounts at registration.
     */
    function fees_generate_student_account(mysqli $db, string $studentId, int $courseId, int $trainingModeId, string $academicYear, string $intake): ?int
    {
        $studentId = trim($studentId);
        $academicYear = trim($academicYear);
        $intake = trim($intake);

        if ($studentId === '' || $courseId <= 0 || $trainingModeId <= 0 || $academicYear === '') {
            return null;
        }

        // 1. Check if account already exists
        $stmt = $db->prepare("SELECT id FROM student_fee_accounts 
                              WHERE student_id = ? AND course_id = ? AND training_mode_id = ? AND intake = ? AND academic_year = ? 
                              LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('siiss', $studentId, $courseId, $trainingModeId, $intake, $academicYear);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $accountId = (int)$row['id'];
                $stmt->close();
                return $accountId;
            }
            $stmt->close();
        }

        // 2. Fetch student's sponsor
        $sponsor = 'Self';
        if ($spStmt = $db->prepare("SELECT sponsor FROM students WHERE SID = ? LIMIT 1")) {
            $spStmt->bind_param('s', $studentId);
            $spStmt->execute();
            $spRes = $spStmt->get_result();
            if ($spRow = $spRes->fetch_assoc()) {
                $sponsor = trim((string)($spRow['sponsor'] ?? 'Self')) ?: 'Self';
            }
            $spStmt->close();
        }

        // 3. Calculate initial fees and apply bursary discount based on sponsorship configuration
        $fees = fees_calculate_payable($db, $courseId, $trainingModeId, $academicYear);
        
        $bursary_amount = 0.00;
        require_once __DIR__ . '/sponsorship_helpers.php';
        if (function_exists('student_sponsorship_summary')) {
            $sum = student_sponsorship_summary($db, $studentId, $fees['total_payable']);
            if (!empty($sum['is_sponsored'])) {
                $bursary_amount = $sum['sponsor_contribution'];
            }
        } else {
            // Fallback to legacy hardcoded rules
            if (in_array(strtoupper($sponsor), ['TEVETA', 'CDF'], true)) {
                $bursary_amount = $fees['base_fee']; // 100% of Tuition is covered
            }
        }
        
        $totalPayable = round($fees['total_payable'] - $bursary_amount, 2);

        // 4. Insert account
        $stmt = $db->prepare("INSERT INTO student_fee_accounts 
            (student_id, course_id, training_mode_id, academic_year, intake, base_fee, additional_fee_total, total_payable, amount_paid, balance, payment_status, status, sponsor, bursary_amount) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0.00, ?, 'Unpaid', 'active', ?, ?)");
            
        if ($stmt) {
            $balance = $totalPayable;
            $stmt->bind_param(
                'siissddddsd', 
                $studentId, 
                $courseId, 
                $trainingModeId, 
                $academicYear, 
                $intake, 
                $fees['base_fee'], 
                $fees['additional_fee_total'], 
                $totalPayable, 
                $balance,
                $sponsor,
                $bursary_amount
            );
            
            if ($stmt->execute()) {
                $accountId = $stmt->insert_id;
                $stmt->close();
                
                // Recalculate balance just in case payments already exist for this student/period
                fees_recalculate_student_balance($db, $accountId);
                return $accountId;
            }
            $stmt->close();
        }

        return null;
    }
}

if (!function_exists('fees_sum_completed_payments_for_account')) {
    /**
     * Sum completed payments for a fee account from account-linked rows and the
     * combined student ledger (payments + student_payments), de-duplicated.
     *
     * @return array{total_paid:float,records:list<array<string,mixed>>}
     */
    function fees_sum_completed_payments_for_account(mysqli $db, int $feeAccountId): array
    {
        $empty = ['total_paid' => 0.0, 'records' => []];
        $studentId = '';
        $academicYear = '';
        $stmt = $db->prepare("SELECT student_id, academic_year FROM student_fee_accounts WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return $empty;
        }
        $stmt->bind_param('i', $feeAccountId);
        $stmt->execute();
        $res = $stmt->get_result();
        $accountRow = $res->fetch_assoc();
        $stmt->close();
        if (!$accountRow) {
            return $empty;
        }
        $studentId = trim((string)($accountRow['student_id'] ?? ''));
        $academicYear = trim((string)($accountRow['academic_year'] ?? ''));
        if ($studentId === '') {
            return $empty;
        }

        $linkedPaid = 0.0;
        $tblRes = $db->query("SHOW TABLES LIKE 'student_payments'");
        $hasStudentPayments = $tblRes && $tblRes->num_rows > 0;
        if ($tblRes) {
            $tblRes->free();
        }
        if ($hasStudentPayments) {
            $colRes = $db->query("SHOW COLUMNS FROM student_payments LIKE 'student_fee_account_id'");
            $hasAccountLink = $colRes && $colRes->num_rows > 0;
            if ($colRes) {
                $colRes->free();
            }
            if ($hasAccountLink) {
                $linkStmt = $db->prepare("SELECT COALESCE(SUM(amount_paid), 0) AS total_paid FROM student_payments
                                          WHERE student_fee_account_id = ? AND status = 'approved' AND payment_status = 'completed'");
                if ($linkStmt) {
                    $linkStmt->bind_param('i', $feeAccountId);
                    $linkStmt->execute();
                    $linkedPaid = (float)($linkStmt->get_result()->fetch_assoc()['total_paid'] ?? 0.0);
                    $linkStmt->close();
                }
            }
        }

        require_once __DIR__ . '/../students/includes/student_fee_records.php';
        $periodFilter = $academicYear !== '' ? ['academic_year' => $academicYear] : null;
        $combined = student_fee_completed_payments($db, $studentId, $periodFilter, 500);

        $totalPaid = $linkedPaid > 0.0 ? $linkedPaid : (float)($combined['total_paid'] ?? 0.0);

        return [
            'total_paid' => round($totalPaid, 2),
            'records' => is_array($combined['records'] ?? null) ? $combined['records'] : [],
        ];
    }
}

if (!function_exists('fees_recalculate_student_balance')) {
    /**
     * Recalculates amount paid, outstanding balance, and updates the payment status.
     */
    function fees_recalculate_student_balance(mysqli $db, int $feeAccountId): bool
    {
        // 1. Fetch total payable
        $totalPayable = 0.00;
        $studentId = '';
        $stmt = $db->prepare("SELECT student_id, total_payable FROM student_fee_accounts WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $feeAccountId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $totalPayable = (float)$row['total_payable'];
                $studentId = $row['student_id'];
            }
            $stmt->close();
        }

        if ($studentId === '') {
            return false;
        }

        // 2. Sum approved payments (account-linked + combined student ledger)
        $paymentSummary = fees_sum_completed_payments_for_account($db, $feeAccountId);
        $totalPaid = (float)($paymentSummary['total_paid'] ?? 0.0);

        // 3. Compute balance & status
        $balance = round($totalPayable - $totalPaid, 2);

        if ($totalPaid <= 0.0) {
            $status = 'Unpaid';
        } elseif ($totalPaid + 0.01 < $totalPayable) {
            $status = 'Partially Paid';
        } elseif (abs($totalPaid - $totalPayable) <= 0.01) {
            $status = 'Paid';
        } else {
            $status = 'Overpaid';
        }

        // 4. Update account row
        $stmt = $db->prepare("UPDATE student_fee_accounts 
                              SET amount_paid = ?, balance = ?, payment_status = ? 
                              WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('ddsi', $totalPaid, $balance, $status, $feeAccountId);
            $ok = $stmt->execute();
            $stmt->close();
            
            // Also synchronize with student_accounts table if it exists
            // select or update student_accounts balance
            $saRes = $db->query("SHOW TABLES LIKE 'student_accounts'");
            if ($saRes && $saRes->num_rows > 0) {
                $saRes->free();
                
                // Get overall balance of this student across all accounts
                $totalBal = 0.00;
                $sumRes = $db->query("SELECT SUM(balance) FROM student_fee_accounts WHERE student_id = '" . $db->real_escape_string($studentId) . "' AND status = 'active'");
                if ($sumRes) {
                    $totalBal = (float)($sumRes->fetch_row()[0] ?? 0.00);
                    $sumRes->free();
                }
                
                // Update or Insert student_accounts
                $checkSa = $db->prepare("SELECT 1 FROM student_accounts WHERE SID = ? LIMIT 1");
                if ($checkSa) {
                    $checkSa->bind_param('s', $studentId);
                    $checkSa->execute();
                    $hasSa = $checkSa->get_result()->num_rows > 0;
                    $checkSa->close();
                    
                    if ($hasSa) {
                        $upSa = $db->prepare("UPDATE student_accounts SET balance = ?, last_payment_date = NOW(), updated_at = NOW() WHERE SID = ?");
                        if ($upSa) {
                            $upSa->bind_param('ds', $totalBal, $studentId);
                            $upSa->execute();
                            $upSa->close();
                        }
                    } else {
                        $inSa = $db->prepare("INSERT INTO student_accounts (SID, balance, last_payment_date) VALUES (?, ?, NOW())");
                        if ($inSa) {
                            $inSa->bind_param('sd', $studentId, $totalBal);
                            $inSa->execute();
                            $inSa->close();
                        }
                    }
                }
            }
            
            return $ok;
        }

        return false;
    }
}
?>
