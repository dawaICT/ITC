<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/helpers/course_availability_helpers.php';

class CourseDataService
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Fetch courses based on Program, Year, and Semester.
     * Tries `course_levels` first, then `program_courses`.
     * Join with `courses` to get details like Name and Cost.
     */
    public function getCoursesByProgramAndLevel(string $programCode, string $year, string $semester): array
    {
        $courses = [];
        
        // 1. Try course_levels (Program + Year + Semester)
        // We assume 'cost' might be in 'courses' table or 'course_levels'
        // Let's check columns for 'cost' in 'courses'
        $hasCostInCourses = $this->columnExists('courses', 'cost');
        $costColumn = $hasCostInCourses ? 'c.cost' : '0'; // Default to 0 if no column
        $statusFilter = $this->columnExists('courses', 'status')
            ? "AND LOWER(COALESCE(c.status, 'active')) = 'active'"
            : '';
        
        // Check for is_compulsory/core (often in course_levels)
        // Using generic fallback
        
        if ($this->tableExists('course_levels')) {
            $clCols = wuc_course_availability_columns($this->db, 'course_levels');
            $periodFilter = wuc_course_availability_period_filter($clCols, 'cl', $clCols['semester'] ?? 'semester', $semester);
            $where = ['cl.program_code = ?', 'cl.year = ?'];
            $types = 'ss';
            $params = [$programCode, $year];
            if ($periodFilter['sql'] !== '1=1') {
                $where[] = $periodFilter['sql'];
                $types .= $periodFilter['types'];
                $params = array_merge($params, $periodFilter['params']);
            }

            $sql = "SELECT
                        cl.course_code,
                        c.course_name,
                        c.credits,
                        $costColumn AS cost,
                        1 AS is_compulsory
                    FROM course_levels cl
                    JOIN programs p ON p.program_code = cl.program_code
                    JOIN courses c ON cl.course_code = c.course_code
                    WHERE " . implode(' AND ', $where) . "
                    AND COALESCE(p.is_active, 1) = 1
                    {$statusFilter}";

            if ($stmt = $this->db->prepare($sql)) {
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $courses[] = $row;
                }
                $stmt->close();
            }
        }

        if (!empty($courses)) {
            return $courses;
        }

        // 2. Fallback: program_courses
        $pcCols = wuc_course_availability_columns($this->db, 'program_courses');
        $pcYearCol = $pcCols['year'] ?? ($pcCols['year_of_study'] ?? ($pcCols['year_level'] ?? null));
        $pcSemesterCol = $pcCols['semester'] ?? null;
        $where = ['pc.program_code = ?'];
        $types = 's';
        $params = [$programCode];
        if ($pcYearCol) {
            $where[] = "pc.`{$pcYearCol}` = ?";
            $types .= 's';
            $params[] = $year;
        }
        $periodFilter = wuc_course_availability_period_filter($pcCols, 'pc', $pcSemesterCol, $semester);
        if ($periodFilter['sql'] !== '1=1') {
            $where[] = $periodFilter['sql'];
            $types .= $periodFilter['types'];
            $params = array_merge($params, $periodFilter['params']);
        }

        $sql = "SELECT 
                    pc.course_code,
                    c.course_name,
                    c.credits,
                    $costColumn AS cost,
                    0 AS is_compulsory
                FROM program_courses pc
                JOIN programs p ON p.program_code = pc.program_code
                JOIN courses c ON pc.course_code = c.course_code
                WHERE " . implode(' AND ', $where) . "
                AND COALESCE(p.is_active, 1) = 1
                {$statusFilter}";
        
        if ($stmt = $this->db->prepare($sql)) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $courses[] = $row;
            }
            $stmt->close();
        }

        return $courses;
    }

    /**
     * Get details for a single course, specifically likely for cost validation.
     */
    public function getCourseDetails(string $courseCode): ?array
    {
        $hasCost = $this->columnExists('courses', 'cost');
        $costSelect = $hasCost ? 'cost' : '0 AS cost';
        
        $statusFilter = $this->columnExists('courses', 'status')
            ? "AND LOWER(COALESCE(status, 'active')) = 'active'"
            : '';
        $sql = "SELECT course_code, course_name, credits, $costSelect FROM courses WHERE course_code = ? {$statusFilter}";
        
        if ($stmt = $this->db->prepare($sql)) {
            $stmt->bind_param("s", $courseCode);
            $stmt->execute();
            $res = $stmt->get_result();
            $data = $res->fetch_assoc();
            $stmt->close();
            return $data;
        }
        return null;
    }

    /**
     * Check if student is already registered for this term.
     * Considers `course_registrations` table.
     */
    public function checkIfRegistered(string $studentId, string $academicYear, string $semester): bool
    {
        // Use course_registration table (canonical table)
        $sql = "SELECT 1 FROM course_registration 
                WHERE student_id = ? AND academic_year = ? AND semester = ? LIMIT 1";
        
        if ($stmt = $this->db->prepare($sql)) {
            $stmt->bind_param("sss", $studentId, $academicYear, $semester);
            try {
                $stmt->execute();
                $stmt->store_result();
                $exists = $stmt->num_rows > 0;
                $stmt->close();
                return $exists;
            } catch (Exception $e) {
                return false;
            }
        }
        return false;
    }

    private function columnExists($table, $column) {
        $result = $this->db->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        return $result && $result->num_rows > 0;
    }
    
    private function tableExists($table) {
        $result = $this->db->query("SHOW TABLES LIKE '$table'");
        return $result && $result->num_rows > 0;
    }
}
