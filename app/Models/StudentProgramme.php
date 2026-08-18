<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * StudentProgramme — maps to legacy `student_program` table.
 * Records which programme a student is enrolled in.
 */
class StudentProgramme extends Model
{
    protected $table = 'student_program';

    protected $fillable = [
        'student_id', 'Sid', 'program_code', 'intake', 'mode',
        'startYear', 'endYear', 'term', 'term_start_date', 'term_end_date',
        'enrollment_date', 'is_transfer', 'previous_institution',
        'credits_transferred', 'status', 'academic_year', 'enrolled_by',
        'transfer_document',
    ];

    protected $casts = [
        'term_start_date' => 'date',
        'term_end_date' => 'date',
        'enrollment_date' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'is_transfer' => 'boolean',
    ];

    // ──────────────────────────────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────────────────────────────

    public function student(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Student::class, 'Sid', 'SID');
    }

    public function programme(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Programme::class, 'program_code', 'program_code');
    }

    // ──────────────────────────────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeForStudent($query, string $sid)
    {
        return $query->where('Sid', $sid);
    }
}
