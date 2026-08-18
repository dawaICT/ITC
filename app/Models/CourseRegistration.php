<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CourseRegistration extends Model
{
    // Note: legacy table name may have variations — using course_registration
    protected $table = 'course_registration';

    protected $fillable = [
        'student_id', 'course_code', 'academic_year', 'semester',
        'year_of_study', 'status', 'registered_at',
    ];

    protected $casts = [
        'registered_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function student(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id', 'SID');
    }

    public function course(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_code', 'course_code');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeForStudent($query, string $sid)
    {
        return $query->where('student_id', $sid);
    }
}
