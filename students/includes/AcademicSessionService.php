<?php
class AcademicSessionService {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function getCurrentSession(?string $periodType = null) {
        $periodType = $this->normalisePeriodType($periodType) ?: 'semester';
        $filterPeriodType = in_array($periodType, ['semester', 'term'], true) ? $periodType : null;
        if ($this->tableExists('academic_periods')) {
            $where = "WHERE is_current = 1";
            $types = '';
            $params = [];
            if ($filterPeriodType !== null && $this->columnExists('academic_periods', 'period_type')) {
                $where .= " AND period_type = ?";
                $types = 's';
                $params[] = $filterPeriodType;
            }

            $periodNumberSelect = $this->columnExists('academic_periods', 'period_number')
                ? "period_number AS semester_term"
                : ($this->columnExists('academic_periods', 'semester_term') ? "semester_term" : "1 AS semester_term");
            $periodNameSelect = $this->columnExists('academic_periods', 'period_name')
                ? 'period_name'
                : "NULL AS period_name";
            $regOpenSelect = $this->columnExists('academic_periods', 'registration_open')
                ? 'registration_open'
                : '1 AS registration_open';
            $docketOpenSelect = $this->columnExists('academic_periods', 'docket_open')
                ? 'docket_open'
                : '0 AS docket_open';
            $examSlipOpenSelect = $this->columnExists('academic_periods', 'exam_slip_open')
                ? 'exam_slip_open'
                : '0 AS exam_slip_open';
            $sql = "SELECT *, {$periodNumberSelect}, {$periodNameSelect}, {$regOpenSelect}, {$docketOpenSelect}, {$examSlipOpenSelect} FROM academic_periods {$where} ORDER BY id DESC LIMIT 1";
            $stmt = $this->db->prepare($sql);
            if (!$stmt) return $this->fallbackSession($periodType);

            if ($types !== '') {
                $stmt->bind_param($types, ...$params);
            }

            $stmt->execute();
            $result = $stmt->get_result();
            $session = $result->fetch_assoc();
            $stmt->close();

            if ($session && isset($session['academic_year'])) {
                $session['academic_year'] = $this->normaliseAcademicYear((string)$session['academic_year']);
                $session['semester_term'] = (string)($session['semester_term'] ?? $session['period_number'] ?? $session['semester'] ?? '1');
                $session['period_number'] = (int)$session['semester_term'];
                $session['semester'] = $session['semester_term'];
                $session['period_type'] = $filterPeriodType ?? $periodType;
                $session['registration_open'] = (int)($session['registration_open'] ?? 1);
                $session['docket_open'] = (int)($session['docket_open'] ?? 0);
                $session['exam_slip_open'] = (int)($session['exam_slip_open'] ?? 0);
                return $session;
            }
        }

        return $this->fallbackSession($periodType);
    }

    public function getAllActiveSessions() {
        if (!$this->tableExists('academic_periods')) {
            return [$this->fallbackSession()];
        }
        $stmt = $this->db->prepare("SELECT * FROM academic_periods WHERE status IN ('open', 'active', 'upcoming') ORDER BY start_date ASC");
        if (!$stmt) return [];
        
        $stmt->execute();
        $result = $stmt->get_result();
        $sessions = [];
        while ($row = $result->fetch_assoc()) {
            $sessions[] = $row;
        }
        return $sessions;
    }

    private function tableExists(string $table): bool {
        $safeTable = $this->db->real_escape_string($table);
        if ($result = $this->db->query("SHOW TABLES LIKE '{$safeTable}'")) {
            $exists = $result->num_rows > 0;
            $result->free();
            return $exists;
        }
        return false;
    }

    private function columnExists(string $table, string $column): bool {
        if (!$this->tableExists($table)) {
            return false;
        }
        $safeColumn = $this->db->real_escape_string($column);
        if ($result = $this->db->query("SHOW COLUMNS FROM `{$table}` LIKE '{$safeColumn}'")) {
            $exists = $result->num_rows > 0;
            $result->free();
            return $exists;
        }
        return false;
    }

    private function normalisePeriodType($periodType): ?string {
        $periodType = strtolower(trim((string)$periodType));
        if ($periodType === 'term' || $periodType === 'termly') {
            return 'term';
        }
        if ($periodType === 'semester' || $periodType === 'semesterly') {
            return 'semester';
        }
        if (in_array($periodType, ['short_course', 'short-course', 'short course'], true)) {
            return 'short_course';
        }
        if (in_array($periodType, ['intake', 'intake_based', 'intake-based', 'intake based'], true)) {
            return 'intake';
        }
        if (in_array($periodType, ['duration', 'duration_based', 'duration-based', 'duration based'], true)) {
            return 'duration';
        }
        return null;
    }

    private function normaliseAcademicYear(string $academicYear): string {
        $academicYear = trim($academicYear);
        if (preg_match('/^\d{4}\s*[\/-]\s*\d{4}$/', $academicYear)) {
            return preg_split('/[\/-]/', $academicYear)[0];
        }
        return $academicYear;
    }

    private function fallbackSession(?string $periodType = null): array {
        $currentYear = date('Y');
        $month = (int)date('n');
        $periodType = $periodType ?: 'semester';
        if ($periodType === 'term') {
            $period = $month <= 4 ? '1' : ($month <= 8 ? '2' : '3');
        } elseif ($periodType === 'semester') {
            $period = $month <= 6 ? '1' : '2';
        } else {
            $period = '1';
        }
        return [
            'id' => null,
            'academic_year' => $currentYear,
            'semester_term' => $period,
            'period_number' => (int)$period,
            'semester' => $period,
            'period_type' => $periodType,
            'period_name' => ($periodType === 'term' ? 'Term ' : 'Semester ') . $period,
            'start_date' => $currentYear . '-01-01',
            'end_date' => $currentYear . '-12-31',
            'status' => 'active',
            'is_current' => 1,
            'registration_open' => 1,
            'docket_open' => 0,
            'exam_slip_open' => 0,
        ];
    }
}
?>
