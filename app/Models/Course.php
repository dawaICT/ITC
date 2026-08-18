<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Course model — maps to legacy `courses` table.
 * PK is `course_code` (varchar, e.g. "CSC101").
 */
class Course extends Model
{
    protected $table = 'courses';
    protected $primaryKey = 'course_code';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'course_code', 'course_name', 'credits', 'course_fee', 'syllabus',
        'credit_hours', 'fee', 'prerequisites', 'is_compulsory', 'max_capacity',
    ];

    protected $casts = [
        'is_compulsory' => 'boolean',
        'course_fee' => 'decimal:2',
        'fee' => 'decimal:2',
    ];

    // ──────────────────────────────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────────────────────────────

    public function lecturers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CourseLecturer::class, 'course_code', 'course_code');
    }

    public function activeLecturer(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(CourseLecturer::class, 'course_code', 'course_code');
    }

    public function programmes(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(
            Programme::class,
            'program_courses',
            'course_code',
            'program_code',
            'course_code',
            'program_code'
        );
    }

    public function registrations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CourseRegistration::class, 'course_code', 'course_code');
    }

    public function assessments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Assessment::class, 'course_code', 'course_code');
    }

    // ──────────────────────────────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────────────────────────────

    public function scopeForProgramme($query, string $programmeCode)
    {
        return $query->whereHas('programmes', fn($q) => $q->where('program_code', $programmeCode));
    }

    // ──────────────────────────────────────────────────────────────
    // Accessors
    // ──────────────────────────────────────────────────────────────

    public function getDisplayNameAttribute(): string
    {
        return $this->course_code . ' — ' . $this->course_name;
    }
}
