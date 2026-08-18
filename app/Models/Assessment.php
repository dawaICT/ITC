<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Assessment — maps to legacy `assessments` table.
 * Stores CA marks per student/course/semester.
 */
class Assessment extends Model
{
    protected $table = 'assessments';

    protected $fillable = [
        'SID', 'course_code', 'semester', 'assess_type', 'assess_num',
        'marks', 'year',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    // ──────────────────────────────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────────────────────────────

    public function student(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Student::class, 'SID', 'SID');
    }

    public function course(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_code', 'course_code');
    }

    // ──────────────────────────────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────────────────────────────

    public function scopeForStudent($query, string $sid)
    {
        return $query->where('SID', $sid);
    }

    public function scopeForCourse($query, string $courseCode)
    {
        return $query->where('course_code', $courseCode);
    }

    public function scopeForSemester($query, string $semester, string $year)
    {
        return $query->where('semester', $semester)->where('year', $year);
    }
}
