<?php

namespace App\Models;

use Database\Factories\AutomationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Automation extends Model
{
    /** @use HasFactory<AutomationFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'status',
        'trigger',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(AutomationStep::class)->orderBy('position');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toWorkspaceArray(): array
    {
        $steps = $this->relationLoaded('steps')
            ? $this->steps
            : $this->steps()->get();

        $uiStatus = match ($this->status) {
            'active', 'enabled' => 'enabled',
            default => 'disabled',
        };

        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $uiStatus,
            'runs' => $this->runs_count ?? $this->runs()->count(),
            'created' => $this->created_at?->diffForHumans() ?? '',
            'trigger' => $this->trigger,
            'steps' => $steps->map(fn (AutomationStep $step) => [
                'type' => $step->type,
                'label' => data_get($step->config, 'label', ucfirst($step->type)),
            ])->values()->all(),
        ];
    }
}
