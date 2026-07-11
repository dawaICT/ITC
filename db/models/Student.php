<?php
/**
 * Student Model
 * Represents the students table
 */

require_once __DIR__ . '/../Model.php';

class Student extends Model {
    protected static string $table = 'students';
    protected static string $primaryKey = 'SID';  // Uses SID as primary key
    
    /**
     * Get student's program
     */
    public function getProgram(): ?array {
        if (!$this->program_code) {
            return null;
        }
        
        $db = Database::getInstance();
        return $db->fetchOne(
            "SELECT * FROM programs WHERE program_code = ?", 
            [$this->program_code]
        );
    }
    
    /**
     * Get student's registered courses
     */
    public function getCourses(): array {
        $db = Database::getInstance();
        return $db->fetchAll(
            "SELECT c.*, sc.grade, sc.semester, sc.academic_year 
             FROM student_courses sc
             JOIN courses c ON sc.course_id = c.course_id
             WHERE sc.student_id = ?
             ORDER BY sc.academic_year DESC, sc.semester DESC",
            [$this->SID]
        );
    }
    
    /**
     * Get student's fee balance
     */
    public function getFeeBalance(): float {
        $db = Database::getInstance();
        
        $totalFees = (float)$db->fetchValue(
            "SELECT COALESCE(SUM(amount), 0) FROM fee_charges WHERE student_id = ?",
            [$this->SID]
        );
        
        $totalPaid = (float)$db->fetchValue(
            "SELECT COALESCE(SUM(amount), 0) FROM fee_payments WHERE student_id = ?",
            [$this->SID]
        );
        
        return $totalFees - $totalPaid;
    }
    
    /**
     * Get payment percentage
     */
    public function getPaymentPercentage(): float {
        $db = Database::getInstance();
        
        $totalFees = (float)$db->fetchValue(
            "SELECT COALESCE(SUM(amount), 0) FROM fee_charges WHERE student_id = ?",
            [$this->SID]
        );
        
        if ($totalFees <= 0) {
            return 100.0;
        }
        
        $totalPaid = (float)$db->fetchValue(
            "SELECT COALESCE(SUM(amount), 0) FROM fee_payments WHERE student_id = ?",
            [$this->SID]
        );
        
        return min(100.0, ($totalPaid / $totalFees) * 100);
    }
    
    /**
     * Check exam eligibility (75% fees paid)
     */
    public function isExamEligible(): bool {
        return $this->getPaymentPercentage() >= 75.0;
    }
    
    /**
     * Get student's schedule
     */
    public function getSchedule(): array {
        $db = Database::getInstance();
        return $db->fetchAll(
            "SELECT cs.*, c.course_code, c.course_name, 
                    ts.day_of_week, ts.start_time, ts.end_time,
                    cr.room_name, cr.building
             FROM course_schedule cs
             JOIN courses c ON cs.course_id = c.course_id
             JOIN time_slots ts ON cs.time_slot_id = ts.id
             LEFT JOIN classrooms cr ON cs.classroom_id = cr.id
             WHERE cs.course_id IN (
                 SELECT course_id FROM student_courses 
                 WHERE student_id = ? AND semester = cs.semester
             )
             ORDER BY ts.day_of_week, ts.start_time",
            [$this->SID]
        );
    }
    
    /**
     * Find by student ID
     */
    public static function findBySID(string $sid): ?self {
        return static::find($sid);
    }
    
    /**
     * Get full name
     */
    public function getFullName(): string {
        $parts = array_filter([
            $this->Fname ?? '',
            $this->Lname ?? ''
        ]);
        return implode(' ', $parts);
    }
}
