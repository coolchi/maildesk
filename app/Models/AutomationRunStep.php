<?php

namespace App\Models;

use Database\Factories\AutomationRunStepFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationRunStep extends Model
{
    /** @use HasFactory<AutomationRunStepFactory> */
    use HasFactory;

    protected $fillable = [
        'automation_run_id',
        'automation_step_id',
        'position',
        'type',
        'status',
        'config',
        'due_at',
        'message_id',
        'error',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'position' => 'integer',
            'due_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class, 'automation_run_id');
    }

    public function automationStep(): BelongsTo
    {
        return $this->belongsTo(AutomationStep::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toWorkspaceArray(): array
    {
        return [
            'id' => $this->id,
            'position' => $this->position,
            'type' => $this->type,
            'status' => $this->status,
            'label' => data_get($this->config, 'label', ucfirst($this->type)),
            'due_at' => $this->due_at?->toIso8601String(),
            'error' => $this->error,
            'message_id' => $this->message_id,
        ];
    }
}
