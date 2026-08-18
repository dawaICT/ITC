<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentLogin extends Model
{
    protected $table = 'student_login';
    protected $primaryKey = 'Sid';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = ['Sid', 'Password'];
    protected $hidden = ['Password'];

    public function student(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Student::class, 'Sid', 'SID');
    }
}
