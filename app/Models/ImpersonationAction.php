<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImpersonationAction extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'impersonation_log_id',
        'method',
        'route_name',
        'path',
        'status',
        'blocked',
        'ip',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'blocked' => 'boolean',
        ];
    }

    public function log(): BelongsTo
    {
        return $this->belongsTo(ImpersonationLog::class, 'impersonation_log_id');
    }
}
