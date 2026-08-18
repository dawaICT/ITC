<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcessedApplicant extends Model
{
    protected $table = 'processed_applicants';

    protected $fillable = [
        'title', 'Fname', 'Lname', 'sex', 'country', 'nrc_pass', 'dob',
        'mobile', 'status', 'email', 'h_addre', 'p_addre', 'sponsor',
        'next_kin', 'next_kin_mobile', 'relat', 'program', 'intake',
        'mode', 'year', 'dte_adm', 'nrc_file', 'deposit_slip',
    ];

    protected $casts = [
        'dte_adm' => 'datetime',
    ];

    public function getFullNameAttribute(): string
    {
        return trim($this->Fname . ' ' . $this->Lname);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }
}
