<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AcademicPeriod extends Model
{
    protected $table = 'academic_periods';

    protected $fillable = [
        'academic_year', 'semester_term', 'start_date', 'end_date',
        'is_current', 'status',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_current' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function scopeCurrent($query)
    {
        return $query->where('is_current', true);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->academic_year . ' — ' . $this->semester_term;
    }
}
