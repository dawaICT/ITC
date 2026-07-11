<?php
/**
 * Program Model
 * Represents the programs table
 */

require_once __DIR__ . '/../Model.php';

class Program extends Model {
    protected static string $table = 'programs';
    protected static string $primaryKey = 'program_code';  // Uses program_code as PK
    
    /**
     * Get all courses in this program
     */
    public function getCourses(): array {
        $db = Database::getInstance();
        return $db->fetchAll(
            "SELECT c.*, pc.year, pc.semester as course_semester
             FROM courses c
             JOIN program_courses pc ON c.course_id = pc.course_id
             WHERE pc.program_code = ?
             ORDER BY pc.year, pc.semester, c.course_code",
            [$this->program_code]
        );
    }
    
    /**
     * Get enrolled students
     */
    public function getStudents(): array {
        $db = Database::getInstance();
        return $db->fetchAll(
            "SELECT * FROM students WHERE program_code = ? ORDER BY Lname, Fname",
            [$this->program_code]
        );
    }
    
    /**
     * Get student count
     */
    public function getStudentCount(): int {
        $db = Database::getInstance();
        return (int)$db->fetchValue(
            "SELECT COUNT(*) FROM students WHERE program_code = ?",
            [$this->program_code]
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
     * Get fee structure
     */
    public function getFeeStructure(): ?array {
        $db = Database::getInstance();
        return $db->fetchOne(
            "SELECT * FROM program_fees WHERE program_code = ?",
            [$this->program_code]
        );
    }
    
    /**
     * Find by program code
     */
    public static function findByCode(string $code): ?self {
        return static::find($code);
    }
    
    /**
     * Get programs by department
     */
    public static function findByDepartment(int $departmentId): array {
        $db = Database::getInstance();
        $rows = $db->fetchAll(
            "SELECT * FROM programs WHERE department_id = ? ORDER BY program_name",
            [$departmentId]
        );
        
        return array_map(function($row) {
            $model = new self($row);
            $model->exists = true;
            $model->original = $row;
            return $model;
        }, $rows);
    }
}
