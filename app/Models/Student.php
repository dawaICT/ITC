<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Student model — maps to legacy `students` table.
 * PK is `SID` (varchar, e.g. "WUC/24/0001").
 *
 * Legacy columns preserved as-is. user_id added by migration.
 */
class Student extends Model
{
    use SoftDeletes;

    protected $table = 'students';
    protected $primaryKey = 'SID';
    public $incrementing = false;
    protected $keyType = 'string';

    public $timestamps = false; // legacy table uses dte_adm/enrollment_date

    protected $fillable = [
        'SID', 'title', 'Fname', 'Lname', 'sex', 'dob', 'country',
        'nrc_pass', 'mobile', 'email', 'status', 'h_addre', 'p_addre',
        'sponsor', 'next_kin', 'next_kin_mobile', 'relat',
        'school', 'grade', 'dte1', 'dte2', 'english_grade', 'math_grade',
        'bursary_percentage', 'results', 'nrc_file', 'profile_image',
        'is_transfer', 'academic_year', 'transfer_from', 'transfer_credits',
        'transfer_program', 'transfer_letter', 'user_id',
    ];

    protected $casts = [
        'dob' => 'date',
        'dte_adm' => 'datetime',
        'enrollment_date' => 'datetime',
        'is_transfer' => 'boolean',
        'bursary_percentage' => 'decimal:2',
    ];

    // ──────────────────────────────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────────────────────────────

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function programmes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(StudentProgramme::class, 'Sid', 'SID');
    }

    public function activeProgramme(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(StudentProgramme::class, 'Sid', 'SID')
            ->where('status', 'active')
            ->latestOfMany('created_at');
    }

    public function courseRegistrations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CourseRegistration::class, 'student_id', 'SID');
    }

    public function assessments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Assessment::class, 'SID', 'SID');
    }

    public function loginRecord(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(StudentLogin::class, 'Sid', 'SID');
    }

    // ──────────────────────────────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'Active');
    }

    public function scopeByProgramme($query, string $programmeCode)
    {
        return $query->whereHas('programmes', function ($q) use ($programmeCode) {
            $q->where('program_code', $programmeCode)->where('status', 'active');
        });
    }

    // ──────────────────────────────────────────────────────────────
    // Accessors
    // ──────────────────────────────────────────────────────────────

    public function getFullNameAttribute(): string
    {
        return trim($this->Fname . ' ' . $this->Lname);
    }

    public function getDisplayIdAttribute(): string
    {
        return $this->SID;
    }

    public function getProfileImageUrlAttribute(): string
    {
        if ($this->profile_image && preg_match('/^[A-Za-z0-9._\-]+$/', $this->profile_image)) {
            return asset('uploads/profile_images/' . $this->profile_image);
        }
        return asset('images/default_avatar.png');
    }
}
