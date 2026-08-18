<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Position model — maps to legacy `positions` table.
 * Defines staff roles (e.g., Registrar, HOD, Lecturer).
 */
class Position extends Model
{
    protected $table = 'positions';
    protected $primaryKey = 'PosID';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = ['PosID', 'PosName'];

    public function staffMembers(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(
            Staff::class,
            'staff_positions',
            'PosID',
            'staff_id',
            'PosID',
            'staff_id'
        )->withPivot('status')->wherePivot('status', 'active');
    }

    /**
     * Map legacy PosName values to Laravel role slugs.
     */
    public function toLaravelRole(): string
    {
        return match (true) {
            str_contains($this->PosName, 'Admin') || str_contains($this->PosName, 'System') => 'systems_admin',
            str_contains($this->PosName, 'Registrar') => 'registrar',
            str_contains($this->PosName, 'Admission') => 'admissions_officer',
            str_contains($this->PosName, 'Dean') || str_contains($this->PosName, 'HOD') || str_contains($this->PosName, 'Head') => 'head_of_section',
            str_contains($this->PosName, 'Lecturer') || str_contains($this->PosName, 'Tutor') => 'lecturer',
            str_contains($this->PosName, 'Librarian') => 'librarian',
            str_contains($this->PosName, 'Accountant') || str_contains($this->PosName, 'Finance') => 'accountant',
            default => 'staff',
        };
    }
}
