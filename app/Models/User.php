<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
use Laravel\Sanctum\HasApiTokens;

/**
 * Central User model for WUCPortal Laravel.
 *
 * This table bridges the legacy split auth system:
 *   - students.SID → student_profiles.user_id → users.id
 *   - staff.staff_id → staff_profiles.user_id → users.id
 *
 * Roles (via Spatie): systems_admin, admissions_officer, registrar,
 *   head_of_section, lecturer, student, alumni, employer.
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    protected $fillable = [
        'name',
        'email',
        'username',
        'password',
        'user_type',      // 'student' | 'staff' | 'employer' | 'alumni'
        'legacy_id',      // SID or staff_id from legacy tables
        'status',         // 'active' | 'suspended' | 'pending'
        'last_login_at',
        'failed_login_count',
        'lockout_until',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'lockout_until' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // ──────────────────────────────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────────────────────────────

    public function studentProfile(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Student::class, 'user_id');
    }

    public function staffProfile(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Staff::class, 'user_id');
    }

    // ──────────────────────────────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeStudents($query)
    {
        return $query->where('user_type', 'student');
    }

    public function scopeStaff($query)
    {
        return $query->where('user_type', 'staff');
    }

    // ──────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────

    public function isStudent(): bool
    {
        return $this->user_type === 'student';
    }

    public function isStaff(): bool
    {
        return $this->user_type === 'staff';
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    public function isLockedOut(): bool
    {
        return $this->lockout_until && $this->lockout_until->isFuture();
    }

    public function getFullNameAttribute(): string
    {
        if ($this->isStudent() && $this->studentProfile) {
            return trim($this->studentProfile->Fname . ' ' . $this->studentProfile->Lname);
        }
        if ($this->isStaff() && $this->staffProfile) {
            return trim($this->staffProfile->Fname . ' ' . $this->staffProfile->Lname);
        }
        return $this->name ?? $this->email;
    }

    public function getAvatarUrlAttribute(): string
    {
        if ($this->isStudent() && $this->studentProfile && $this->studentProfile->profile_image) {
            $img = $this->studentProfile->profile_image;
            if (preg_match('/^[A-Za-z0-9._\-]+$/', $img)) {
                return asset('uploads/profile_images/' . $img);
            }
        }
        if ($this->isStaff() && $this->staffProfile && $this->staffProfile->profile_image) {
            $img = $this->staffProfile->profile_image;
            if (preg_match('/^[A-Za-z0-9._\-]+$/', $img)) {
                return asset('uploads/staff_images/' . $img);
            }
        }
        return asset('images/default_avatar.png');
    }
}
