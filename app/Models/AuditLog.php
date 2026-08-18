<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * AuditLog — Laravel-structured audit log (separate from legacy audit_log).
 * Records critical academic and security actions with actor, subject, old/new values.
 */
class AuditLog extends Model
{
    protected $table = 'wuc_audit_logs';

    protected $fillable = [
        'actor_id',
        'actor_type',
        'action',
        'subject_type',
        'subject_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'correlation_id',
        'portal',
        'notes',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function actor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function scopeForSubject($query, string $type, $id)
    {
        return $query->where('subject_type', $type)->where('subject_id', $id);
    }

    public function scopeForAction($query, string $action)
    {
        return $query->where('action', $action);
    }
}
