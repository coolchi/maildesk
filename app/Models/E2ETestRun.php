<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class E2ETestRun extends Model
{
    protected $table = 'e2e_test_runs';

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'steps' => 'array',
        'timings' => 'array',
        'include_events' => 'boolean',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function outboundMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'outbound_message_id');
    }

    public function inboundMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'inbound_message_id');
    }

    public function markStarted(): void
    {
        $this->update([
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    public function markStep(string $step, float $durationMs, bool $success = true, ?string $detail = null): void
    {
        $steps = $this->steps ?? [];
        $timings = $this->timings ?? [];

        $steps[$step] = [
            'success' => $success,
            'detail' => $detail,
            'completed_at' => now()->toIso8601String(),
        ];
        $timings[$step] = round($durationMs, 2);

        $this->update([
            'steps' => $steps,
            'timings' => $timings,
        ]);
    }

    public function markFailed(string $step, string $error, ?string $hint = null): void
    {
        $this->update([
            'status' => 'failed',
            'failed_step' => $step,
            'error' => $error,
            'error_hint' => $hint,
            'completed_at' => now(),
        ]);
    }

    public function markPassed(): void
    {
        $this->update([
            'status' => 'passed',
            'completed_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toAdminArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'token' => $this->token,
            'source' => $this->source,
            'steps' => $this->steps,
            'timings' => $this->timings,
            'error' => $this->error,
            'error_hint' => $this->error_hint,
            'failed_step' => $this->failed_step,
            'include_events' => $this->include_events,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'duration_ms' => $this->started_at && $this->completed_at
                ? $this->completed_at->diffInMilliseconds($this->started_at)
                : null,
            'user' => $this->user?->name,
        ];
    }
}
