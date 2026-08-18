<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Staff model — maps to legacy `staff` table.
 * PK is `staff_id` (varchar, e.g. "WUC026").
 *
 * Roles come from staff_positions → positions tables.
 * Credentials come from user_credentials.pass.
 */
class Staff extends Model
{
    use SoftDeletes;

    protected $table = 'staff';
    protected $primaryKey = 'staff_id';
    public $incrementing = false;
    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'staff_id', 'deptId', 'title', 'Fname', 'Lname', 'sex',
        'nrc_pass', 'mobile', 'email', 'address', 'country',
        'qualification', 'profile_image', 'status',
        'failed_attempts', 'lockout_until', 'last_login', 'password', 'role',
        'user_id',
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'last_login' => 'datetime',
        'lockout_until' => 'datetime',
        'failed_attempts' => 'integer',
    ];

    // ──────────────────────────────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────────────────────────────

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function credentials(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(UserCredential::class, 'staff_id', 'staff_id');
    }

    public function positions(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(
            Position::class,
            'staff_positions',
            'staff_id',
            'PosID',
            'staff_id',
            'PosID'
        )->withPivot('status')->wherePivot('status', 'active');
    }

    public function department(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Department::class, 'deptId', 'id');
    }

    public function assignedCourses(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CourseLecturer::class, 'staff_id', 'staff_id');
    }

    // ──────────────────────────────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeLecturers($query)
    {
        return $query->whereHas('positions', function ($q) {
            $q->where('PosName', 'like', '%Lecturer%');
        });
    }

    // ──────────────────────────────────────────────────────────────
    // Accessors
    // ──────────────────────────────────────────────────────────────

    public function getFullNameAttribute(): string
    {
        return trim(($this->title ? $this->title . ' ' : '') . $this->Fname . ' ' . $this->Lname);
    }

    public function getProfileImageUrlAttribute(): string
    {
        if ($this->profile_image && preg_match('/^[A-Za-z0-9._\-]+$/', $this->profile_image)) {
            return asset('uploads/staff_images/' . $this->profile_image);
        }
        return asset('images/default_avatar.png');
    }

    public function hasPosition(string $positionName): bool
    {
        return $this->positions->contains('PosName', $positionName);
    }
}
