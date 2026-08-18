<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Programme model — maps to legacy `programs` table.
 * PK is `program_code` (varchar, e.g. "BSCS").
 */
class Programme extends Model
{
    protected $table = 'programs';
    protected $primaryKey = 'program_code';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'program_code', 'program_name', 'program_type', 'study_mode',
        'program_duration', 'period_mode', 'duration_months',
        'program_description', 'department_id', 'is_active', 'term_based',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'term_based' => 'boolean',
        'program_duration' => 'decimal:1',
    ];

    // ──────────────────────────────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────────────────────────────

    public function department(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function studentProgrammes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(StudentProgramme::class, 'program_code', 'program_code');
    }

    public function programCourses(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProgramCourse::class, 'program_code', 'program_code');
    }

    public function courses(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(
            Course::class,
            'program_courses',
            'program_code',
            'course_code',
            'program_code',
            'course_code'
        );
    }

    // ──────────────────────────────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', 1);
    }

    public function scopeTermBased($query)
    {
        return $query->where('term_based', 1);
    }

    public function scopeSemesterBased($query)
    {
        return $query->where('term_based', 0);
    }

    // ──────────────────────────────────────────────────────────────
    // Accessors
    // ──────────────────────────────────────────────────────────────

    public function getPeriodLabelAttribute(): string
    {
        return $this->term_based ? 'Term' : 'Semester';
    }

    public function getActiveStudentCountAttribute(): int
    {
        return $this->studentProgrammes()->where('status', 'active')->count();
    }

    public function isTermBased(): bool
    {
        return (bool) $this->term_based;
    }

    public function isSemesterBased(): bool
    {
        return !$this->isTermBased();
    }
}
