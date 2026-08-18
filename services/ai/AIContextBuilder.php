<?php
declare(strict_types=1);

final class AIContextBuilder
{
    public function __construct(private mysqli $db) {}

    public function buildStudentContext(string $studentId, string $courseId): array
    {
        $sql = "SELECT cr.academic_year, cr.semester, cr.Year AS year_of_study,
                       sp.program_code, sp.id AS student_program_id, c.course_name,
                       COALESCE(c.department_id, p.department_id) AS department_id
                FROM course_registration cr
                INNER JOIN courses c ON c.course_code = cr.course_code
                LEFT JOIN student_program sp ON sp.Sid = cr.Sid AND LOWER(COALESCE(sp.status, 'active')) = 'active'
                LEFT JOIN programs p ON p.program_code = sp.program_code
                WHERE cr.Sid = ? AND cr.course_code = ? AND COALESCE(cr.is_active, 1) = 1
                ORDER BY sp.id DESC, cr.id DESC LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('ss', $studentId, $courseId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            throw new RuntimeException('No active course registration was found.');
        }

        $year = (string)($row['academic_year'] ?? '');
        $semester = (int)($row['semester'] ?? 0);
        $periodId = null;
        $stmt = $this->db->prepare(
            "SELECT id FROM academic_periods
             WHERE academic_year = ? AND (period_number = ? OR semester_term = CAST(? AS CHAR))
               AND status IN ('active','completed')
             ORDER BY is_current DESC, (period_type = 'semester') DESC, id DESC LIMIT 1"
        );
        $stmt->bind_param('sii', $year, $semester, $semester);
        $stmt->execute();
        $period = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($period) {
            $periodId = (int)$period['id'];
        }

        $lecturerId = $this->resolveLecturer($courseId, $year, $semester);
        $hodId = $lecturerId === null ? $this->resolveHeadOfSection((string)($row['department_id'] ?? '')) : null;

        return [
            'student_id' => $studentId,
            'course_id' => $courseId,
            'course_name' => (string)$row['course_name'],
            'program_code' => (string)($row['program_code'] ?? ''),
            'academic_year' => $year,
            'semester' => $semester,
            'year_of_study' => (int)($row['year_of_study'] ?? 0),
            'academic_period_id' => $periodId,
            'lecturer_id' => $lecturerId,
            'hod_id' => $hodId,
            'department_id' => isset($row['department_id']) ? (string)$row['department_id'] : null,
            'active_portal' => (string)($_SESSION['portal_context'] ?? 'student_academic'),
        ];
    }

    private function resolveLecturer(string $courseId, string $year, int $semester): ?string
    {
        $sql = "SELECT cl.staff_id,
                       MAX(CASE WHEN LOWER(p.PosName) = 'lecturer' THEN 1 ELSE 0 END) is_lecturer,
                       MAX(CASE WHEN LOWER(p.PosName) IN ('systems admin','system administrator') THEN 1 ELSE 0 END) is_admin,
                       MAX(CASE WHEN LOWER(p.PosName) IN ('head of section','head of department') THEN 1 ELSE 0 END) is_head
                FROM course_lecturer cl
                INNER JOIN staff s ON s.staff_id = cl.staff_id AND LOWER(COALESCE(s.status,'active')) = 'active'
                LEFT JOIN staff_positions sp ON sp.staff_id = cl.staff_id
                LEFT JOIN positions p ON p.PosID = sp.PosID
                WHERE cl.course_code = ? AND COALESCE(cl.status,'active') <> 'inactive'
                  AND (cl.academic_year IS NULL OR cl.academic_year = '' OR cl.academic_year = ?)
                  AND (cl.semester IS NULL OR cl.semester = '' OR CAST(cl.semester AS UNSIGNED) = ?)
                GROUP BY cl.staff_id, cl.id
                HAVING is_lecturer = 1
                ORDER BY is_admin ASC, is_head ASC, cl.id ASC LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('ssi', $courseId, $year, $semester);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (string)$row['staff_id'] : null;
    }

    private function resolveHeadOfSection(string $departmentId): ?string
    {
        if ($departmentId !== '') {
            $sql = "SELECT ssa.staff_id
                    FROM departments d
                    INNER JOIN sections sec ON sec.section_id = d.section_id
                    INNER JOIN staff_section_assignments ssa ON ssa.section_id = sec.section_id
                    WHERE CAST(d.id AS CHAR) = ? AND sec.status = 'active'
                      AND ssa.status = 'active' AND ssa.role_key IN ('head_of_department','head_of_section')
                    ORDER BY ssa.is_primary DESC, ssa.id ASC LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param('s', $departmentId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                return (string)$row['staff_id'];
            }

            $sql = "SELECT sp.staff_id
                    FROM staff_positions sp
                    INNER JOIN positions p ON p.PosID = sp.PosID
                    INNER JOIN staff s ON s.staff_id = sp.staff_id
                    WHERE CAST(s.deptId AS CHAR) = ?
                      AND LOWER(p.PosName) IN ('head of section','head of department')
                      AND LOWER(COALESCE(s.status,'active')) = 'active'
                    ORDER BY sp.id ASC LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param('s', $departmentId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                return (string)$row['staff_id'];
            }
        }
        return null;
    }
}
