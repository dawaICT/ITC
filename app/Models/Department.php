<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    protected $table = 'departments';
    public $timestamps = false;

    protected $fillable = ['id', 'deptName', 'description', 'head_staff_id'];

    public function staff(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Staff::class, 'deptId', 'id');
    }

    public function programmes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Programme::class, 'department_id', 'id');
    }
}
