<?php

namespace App\Models;

use Database\Factories\AutomationStepFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationStep extends Model
{
    /** @use HasFactory<AutomationStepFactory> */
    use HasFactory;

    protected $fillable = [
        'automation_id',
        'position',
        'type',
        'config',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'position' => 'integer',
        ];
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
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
            'label' => data_get($this->config, 'label', ucfirst($this->type)),
            'config' => $this->config ?? [],
        ];
    }
}
