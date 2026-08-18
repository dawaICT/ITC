<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserCredential extends Model
{
    protected $table = 'user_credentials';
    public $timestamps = false;

    protected $fillable = ['staff_id', 'pass'];
    protected $hidden = ['pass'];

    public function staff(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id', 'staff_id');
    }
}
