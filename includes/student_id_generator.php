<?php
/**
 * Student and intake number helpers for the ITC portal.
 *
 * New student number format:
 *   PROGRAM_CODE + YY + NRC_LAST6
 *
 * Example:
 *   CSE + 26 + 456789 = CSE26456789
 *
 * The public function names are preserved so the existing admission and
 * registration flows continue to work while using the new numbering scheme.
 */

if (!function_exists('wuc_validate_enrollment_year')) {
    function wuc_validate_enrollment_year(int $year): int
    {
        $min = 2000;
        $max = (int)date('Y') + 1;
        if ($year < $min || $year > $max) {
            throw new InvalidArgumentException("Invalid admission year ({$year}); expected {$min}-{$max}.");
        }
        return $year;
    }
}

if (!function_exists('wuc_validate_period')) {
    /**
     * Kept for legacy call sites that still need to derive a period from an
     * intake. Student numbers no longer include the period.
     */
    function wuc_validate_period($period): int
    {
        $digits = preg_replace('/\D+/', '', (string)$period);
        $n = (int)$digits;
        if ($n < 1 || $n > 3) {
            throw new InvalidArgumentException("Invalid academic period ('{$period}'); expected 1, 2 or 3.");
        }
        return $n;
    }
}

if (!function_exists('wuc_extract_nrc6')) {
    function wuc_extract_nrc6(?string $nrc): ?string
    {
        $digits = preg_replace('/\D+/', '', (string)$nrc);
        if (strlen($digits) < 6) {
            return null;
        }
        return substr($digits, -6);
    }
}

if (!function_exists('wuc_program_code_map')) {
    function wuc_program_code_map(): array
    {
        return [
            'DIPLOMA IN COMPUTER SYSTEMS ENGINEERING' => 'CSE',
            'CRAFT CERTIFICATE IN COMPUTER SYSTEMS ENGINEERING' => 'CSE',
            'DIPLOMA IN MOTOR VEHICLE ENGINEERING' => 'MVE',
            'TECHNICIAN CERTIFICATE IN MOTOR VEHICLE ENGINEERING' => 'TME',
            'CRAFT CERTIFICATE IN ELECTRONIC AND TELECOMMUNICATIONS' => 'CET',
            'CRAFT CERTIFICATE IN TELECOMMUNICATIONS' => 'TEL',
            'CRAFT CERTIFICATE IN AUTO-ELECTRICAL AND ELECTRONICS' => 'AEE',
            'CRAFT CERTIFICATE IN AUTOMOTIVE ELECTRICAL' => 'AEL',
            'CRAFT CERTIFICATE IN POWER ELECTRICAL' => 'PWE',
            'CRAFT CERTIFICATE IN ELECTRICAL ENGINEERING' => 'ELE',
            'CRAFT CERTIFICATE IN AUTOMOTIVE MECHANICS' => 'AME',
            'PLUMBING AND SHEET METAL' => 'PSM',
            'DIPLOMA IN TRANSPORT AND LOGISTICS' => 'TLG',
            'DIPLOMA IN TRANSPORT AND LOGISTICS MANAGEMENT' => 'TLM',
            'DIPLOMA INFORMATION TECHNOLOGY FOR TEACHERS' => 'ITT',
            'TRADE TEST LEVEL 1 ELECTRICAL TECHNOLOGY' => 'TET',
            'PROFESSIONAL DRIVING' => 'PDR',
            'DEFENSIVE DRIVING' => 'DDR',
            'DRIVER REFRESHER COURSE' => 'DRC',
            'MOTOR BIKE RIDING' => 'MBR',
            'CHAUFFEUR DRIVING' => 'CDR',
            'OCCUPATIONAL HEALTH AND SAFETY' => 'OHS',
            'FIRST AID' => 'FAD',
            'SCAFFOLDING' => 'SCA',
            'FRONT END LOADER' => 'FEL',
            'RIGGING' => 'RIG',
            'EXCAVATOR OPERATION' => 'EXC',
            'PROGRAMMABLE LOGIC CONTROLLERS' => 'PLC',
            'LOCAL AREA NETWORK AND ADMINISTRATION' => 'LAN',
            'FIBRE OPTICS MAINTENANCE AND INSTALLATION' => 'FOM',
            'WINDOWS SERVER ADMINISTRATION' => 'WSA',
            'PROGRAMMING' => 'PRG',
            'WEB TECHNOLOGY' => 'WEB',
            'ADVANCED DATABASES' => 'ADB',
            'INTERMEDIATE EXCEL' => 'IEX',
            'ADVANCED EXCEL' => 'AEX',
            'MICROSOFT OFFICE APPLICATIONS' => 'MOA',
            'COMPUTER HARDWARE MAINTENANCE AND REPAIR' => 'CHM',
            'CISCO CCNA 1' => 'CC1',
            'CISCO CCNA 2' => 'CC2',
        ];
    }
}

if (!function_exists('wuc_clean_program_code')) {
    function wuc_clean_program_code(string $value): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($value))));
    }
}

if (!function_exists('wuc_program_initial_code')) {
    function wuc_program_initial_code(string $programName, string $fallback): string
    {
        $name = strtoupper(trim($programName));
        $map = wuc_program_code_map();
        if (isset($map[$name])) {
            return $map[$name];
        }

        $stopWords = ['IN', 'OF', 'AND', 'THE', 'FOR', 'A', 'AN'];
        $words = preg_split('/[^A-Z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY);
        $letters = '';
        foreach ($words as $word) {
            if (!in_array($word, $stopWords, true)) {
                $letters .= substr($word, 0, 1);
            }
            if (strlen($letters) >= 3) {
                break;
            }
        }

        if (strlen($letters) < 3) {
            $letters .= wuc_clean_program_code($fallback);
        }
        $letters = substr($letters, 0, 3);
        if (strlen($letters) < 3) {
            throw new InvalidArgumentException('Programme code must exist before generating a student number.');
        }
        return $letters;
    }
}

if (!function_exists('wuc_student_program_identity_code')) {
    function wuc_student_program_identity_code(mysqli $db, string $programCode): string
    {
        $programCode = trim($programCode);
        if ($programCode === '') {
            throw new InvalidArgumentException('Programme must be selected before generating a student number.');
        }

        $cleanCode = wuc_clean_program_code($programCode);
        if (preg_match('/^[A-Z]{3}$/', $cleanCode)) {
            return $cleanCode;
        }

        $programName = '';
        if ($stmt = $db->prepare('SELECT program_name FROM programs WHERE program_code = ? LIMIT 1')) {
            $stmt->bind_param('s', $programCode);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $programName = (string)($row['program_name'] ?? '');
        }

        return wuc_program_initial_code($programName, $programCode);
    }
}

if (!function_exists('wuc_student_number_regex')) {
    function wuc_student_number_regex(): string
    {
        return '/^[A-Z0-9]{3}\d{8}$/';
    }
}

if (!function_exists('wuc_validate_student_number')) {
    function wuc_validate_student_number(?string $sid): bool
    {
        return is_string($sid) && preg_match(wuc_student_number_regex(), $sid) === 1;
    }
}

if (!function_exists('wuc_assert_student_number')) {
    function wuc_assert_student_number(string $sid): string
    {
        if (!wuc_validate_student_number($sid)) {
            throw new RuntimeException("Generated an invalid student number: {$sid}.");
        }
        return $sid;
    }
}

if (!function_exists('wuc_sid_exists')) {
    function wuc_sid_exists(mysqli $db, string $sid): bool
    {
        $stmt = $db->prepare('SELECT 1 FROM students WHERE SID = ? LIMIT 1');
        $stmt->bind_param('s', $sid);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();
        return $exists;
    }
}

if (!function_exists('wuc_make_student_number')) {
    function wuc_make_student_number(mysqli $db, string $programCode, ?string $nrc, int $year): string
    {
        $identityCode = wuc_student_program_identity_code($db, $programCode);
        $year = wuc_validate_enrollment_year($year);

        $nrc6 = wuc_extract_nrc6($nrc);
        if ($nrc6 === null) {
            throw new InvalidArgumentException('A valid NRC with at least 6 digits is required to generate a student number.');
        }

        $yy = substr((string)$year, -2);
        $studentNumber = $identityCode . $yy . $nrc6;

        if (wuc_sid_exists($db, $studentNumber)) {
            throw new RuntimeException('This student number already exists. Please check the student NRC, programme, or admission year.');
        }

        return wuc_assert_student_number($studentNumber);
    }
}

if (!function_exists('generateStudentId')) {
    function generateStudentId(mysqli $db, string $program_code, string $semester, string $academic_year = '', string $nrc = ''): string
    {
        unset($semester);
        $year = preg_match('/^\d{4}$/', $academic_year) ? (int)$academic_year : (int)date('Y');
        return wuc_make_student_number($db, $program_code, $nrc, $year);
    }
}

if (!function_exists('wuc_period_for_date')) {
    function wuc_period_for_date(?int $month = null): int
    {
        $m = $month ?? (int)date('n');
        if ($m <= 4) {
            return 1;
        }
        if ($m <= 8) {
            return 2;
        }
        return 3;
    }
}
