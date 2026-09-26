<?php

namespace App\Models;

use Database\Factories\AutomationRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationRun extends Model
{
    /** @use HasFactory<AutomationRunFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'automation_id',
        'contact_email',
        'status',
        'current_step_position',
        'due_at',
        'payload',
        'error',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'current_step_position' => 'integer',
            'due_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(AutomationRunStep::class)->orderBy('position');
    }

    /**
     * @return array<string, mixed>
     */
    public function toWorkspaceArray(): array
    {
        return [
            'id' => $this->id,
            'automation_id' => $this->automation_id,
            'contact_email' => $this->contact_email,
            'status' => $this->status,
            'current_step_position' => $this->current_step_position,
            'due_at' => $this->due_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'error' => $this->error,
            'created' => $this->created_at?->diffForHumans() ?? '',
        ];
    }
}
