<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Applicant extends Model
{
    protected $table = 'online_applicants';

    protected $fillable = [
        'title', 'Fname', 'Lname', 'sex', 'country', 'nrc_pass', 'dob',
        'mobile', 'status', 'email', 'h_addre', 'p_addre', 'sponsor',
        'next_kin', 'next_kin_mobile', 'relat', 'program', 'intake',
        'mode', 'year', 'dte_adm',
    ];

    protected $casts = [
        'dte_adm' => 'datetime',
    ];

    // Statuses: 'pending', 'reviewing', 'approved', 'rejected', 'converted'
    const STATUS_PENDING = 'pending';
    const STATUS_REVIEWING = 'reviewing';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_CONVERTED = 'converted';

    public function getFullNameAttribute(): string
    {
        return trim($this->Fname . ' ' . $this->Lname);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function processedRecord(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ProcessedApplicant::class, 'email', 'email');
    }
}
