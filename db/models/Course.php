<?php
/**
 * Course Model
 * Represents the courses table
 */

require_once __DIR__ . '/../Model.php';

class Course extends Model {
    protected static string $table = 'courses';
    protected static string $primaryKey = 'course_id';
    
    /**
     * Get enrolled students
     */
    public function getEnrolledStudents(string $semester, string $academicYear): array {
        $db = Database::getInstance();
        return $db->fetchAll(
            "SELECT s.* 
             FROM students s
             JOIN student_courses sc ON s.student_id = sc.student_id
             WHERE sc.course_id = ? AND sc.semester = ? AND sc.academic_year = ?",
            [$this->course_id, $semester, $academicYear]
        );
    }
    
    /**
     * Get course schedule
     */
    public function getSchedule(): array {
        $db = Database::getInstance();
        return $db->fetchAll(
            "SELECT cs.*, ts.day_of_week, ts.start_time, ts.end_time,
                    cr.room_name, cr.building, cr.capacity
             FROM course_schedule cs
             JOIN time_slots ts ON cs.time_slot_id = ts.id
             LEFT JOIN classrooms cr ON cs.classroom_id = cr.id
             WHERE cs.course_id = ?
             ORDER BY ts.day_of_week, ts.start_time",
            [$this->course_id]
        );
    }
    
    /**
     * Get lecturer info
     */
    public function getLecturer(): ?array {
        if (!$this->lecturer_id) {
            return null;
        }
        
        $db = Database::getInstance();
        return $db->fetchOne(
            "SELECT * FROM staff WHERE staff_id = ?",
            [$this->lecturer_id]
        );
    }
    
    /**
     * Get department
     */
    public function getDepartment(): ?array {
        if (!$this->department_id) {
            return null;
        }
        
        $db = Database::getInstance();
        return $db->fetchOne(
            "SELECT * FROM departments WHERE department_id = ?",
            [$this->department_id]
        );
    }
    
    /**
     * Get courses by program
     */
    public static function findByProgram(int $programId, int $year = null, int $semester = null): array {
        $db = Database::getInstance();
        
        $sql = "SELECT c.* FROM courses c
                JOIN program_courses pc ON c.course_id = pc.course_id
                WHERE pc.program_id = ?";
        $params = [$programId];
        
        if ($year !== null) {
            $sql .= " AND pc.year = ?";
            $params[] = $year;
        }
        
        if ($semester !== null) {
            $sql .= " AND pc.semester = ?";
            $params[] = $semester;
        }
        
        $sql .= " ORDER BY pc.year, pc.semester, c.course_code";
        
        $rows = $db->fetchAll($sql, $params);
        
        return array_map(function($row) {
            $model = new self($row);
            $model->exists = true;
            $model->original = $row;
            return $model;
        }, $rows);
    }
    
    /**
     * Get enrollment count
     */
    public function getEnrollmentCount(string $semester, string $academicYear): int {
        $db = Database::getInstance();
        return (int)$db->fetchValue(
            "SELECT COUNT(*) FROM student_courses 
             WHERE course_id = ? AND semester = ? AND academic_year = ?",
            [$this->course_id, $semester, $academicYear]
        );
    }
}
