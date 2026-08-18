<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * CourseLecturer — maps to legacy `course_lecturer` table.
 * Links staff to courses they are assigned to teach.
 */
class CourseLecturer extends Model
{
    protected $table = 'course_lecturer';
    protected $primaryKey = 'course_lecturer_id';
    public $timestamps = false;

    protected $fillable = [
        'course_code',
        'staff_id',
        'academic_period_id',
        'programme_code',
        'status',
        'assigned_at',
        'assigned_by',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
    ];

    // ──────────────────────────────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────────────────────────────

    public function course(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_code', 'course_code');
    }

    public function staff(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id', 'staff_id');
    }

    // ──────────────────────────────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->where('status', 'active')->orWhereNull('status');
        });
    }

    public function scopeForStaff($query, string $staffId)
    {
        return $query->where('staff_id', $staffId);
    }

    public function scopeForCourse($query, string $courseCode)
    {
        return $query->where('course_code', $courseCode);
    }
}
