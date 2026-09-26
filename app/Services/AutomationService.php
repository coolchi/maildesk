<?php

namespace App\Services;

use App\Jobs\AdvanceAutomationRun;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\AutomationRunStep;
use App\Models\AutomationStep;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AutomationService
{
    public function __construct(
        protected EmailService $emails,
    ) {}

    /**
     * Replace every step on an automation from the editor payload.
     *
     * @param  array<int, array{type: string, label?: string, config?: array<string, mixed>}>  $steps
     */
    public function syncSteps(Automation $automation, array $steps): void
    {
        DB::transaction(function () use ($automation, $steps): void {
            $automation->steps()->delete();

            foreach (array_values($steps) as $index => $step) {
                $type = (string) ($step['type'] ?? 'email');
                $label = (string) ($step['label'] ?? ucfirst($type));
                $config = is_array($step['config'] ?? null) ? $step['config'] : [];

                AutomationStep::query()->create([
                    'automation_id' => $automation->id,
                    'position' => $index,
                    'type' => $type,
                    'config' => $this->buildStepConfig($type, $label, $config),
                ]);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function buildStepConfig(string $type, string $label, array $config = []): array
    {
        $built = array_merge($config, ['label' => $label]);

        if ($type === 'delay') {
            $built['duration_minutes'] = $config['duration_minutes']
                ?? $this->parseDelayMinutes($label);
        }

        if ($type === 'email') {
            $subject = (string) ($config['subject'] ?? $label);
            $built['subject'] = $subject;
            $built['html'] = (string) ($config['html'] ?? '<p>'.e($subject).'</p>');
        }

        return $built;
    }

    public function parseDelayMinutes(string $label): int
    {
        $normalized = Str::lower(trim($label));

        return match (true) {
            str_contains($normalized, '5 minute') => 5,
            str_contains($normalized, '1 hour') => 60,
            str_contains($normalized, '2 day') => 2880,
            str_contains($normalized, '1 day') => 1440,
            default => $this->parseDelayMinutesFallback($normalized),
        };
    }

    private function parseDelayMinutesFallback(string $normalized): int
    {
        if (preg_match('/(\d+)\s*minute/', $normalized, $matches)) {
            return max(1, (int) $matches[1]);
        }

        if (preg_match('/(\d+)\s*hour/', $normalized, $matches)) {
            return max(1, (int) $matches[1]) * 60;
        }

        if (preg_match('/(\d+)\s*day/', $normalized, $matches)) {
            return max(1, (int) $matches[1]) * 1440;
        }

        return 60;
    }

    /**
     * Start a run for every active automation matching the event name.
     *
     * @param  array<string, mixed>  $payload
     * @return list<AutomationRun>
     */
    public function startRunsForEvent(Organization $organization, string $eventName, array $payload): array
    {
        $email = Str::lower(trim((string) ($payload['email'] ?? '')));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'payload.email' => 'A valid contact email is required to start an automation.',
            ]);
        }

        $automations = $organization->automations()
            ->whereIn('status', ['active', 'enabled'])
            ->where('trigger', $eventName)
            ->with('steps')
            ->get();

        $runs = [];

        foreach ($automations as $automation) {
            $runs[] = $this->createRun($automation, $email, $payload);
        }

        return $runs;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createRun(Automation $automation, string $contactEmail, array $payload = []): AutomationRun
    {
        $automation->loadMissing('steps');

        return DB::transaction(function () use ($automation, $contactEmail, $payload): AutomationRun {
            $run = AutomationRun::query()->create([
                'organization_id' => $automation->organization_id,
                'automation_id' => $automation->id,
                'contact_email' => Str::lower(trim($contactEmail)),
                'status' => 'pending',
                'current_step_position' => 0,
                'payload' => $payload,
                'started_at' => now(),
            ]);

            foreach ($automation->steps as $step) {
                $run->steps()->create([
                    'automation_step_id' => $step->id,
                    'position' => $step->position,
                    'type' => $step->type,
                    'status' => 'pending',
                    'config' => $step->config ?? [],
                ]);
            }

            return $run->fresh('steps') ?? $run;
        });
    }

    public function advance(AutomationRun $run): void
    {
        $run->loadMissing(['steps', 'automation', 'organization.domains', 'organization.mailProvider']);

        if (in_array($run->status, ['completed', 'failed'], true)) {
            return;
        }

        if ($run->status === 'pending') {
            $run->forceFill(['status' => 'running'])->save();
        }

        while (true) {
            $run->refresh()->load('steps');

            /** @var AutomationRunStep|null $step */
            $step = $run->steps->firstWhere('position', $run->current_step_position);

            if ($step === null) {
                $this->completeRun($run);

                return;
            }

            if ($step->status === 'completed' || $step->status === 'skipped') {
                $run->forceFill(['current_step_position' => $run->current_step_position + 1])->save();

                continue;
            }

            try {
                $shouldPause = $this->processStep($run, $step);
            } catch (Throwable $e) {
                $this->failStep($run, $step, $e);

                return;
            }

            if ($shouldPause) {
                return;
            }

            $run->forceFill(['current_step_position' => $run->current_step_position + 1])->save();
        }
    }

    /**
     * @return bool True when the run should wait (delay) before continuing.
     */
    private function processStep(AutomationRun $run, AutomationRunStep $step): bool
    {
        return match ($step->type) {
            'trigger' => $this->completeStep($step),
            'delay' => $this->processDelayStep($run, $step),
            'email' => $this->processEmailStep($run, $step),
            default => $this->completeStep($step),
        };
    }

    private function processDelayStep(AutomationRun $run, AutomationRunStep $step): bool
    {
        $minutes = max(0, (int) data_get($step->config, 'duration_minutes', 0));

        if ($minutes <= 0) {
            $this->completeStep($step);

            return false;
        }

        if ($step->status !== 'waiting') {
            $dueAt = now()->addMinutes($minutes);
            $step->forceFill([
                'status' => 'waiting',
                'due_at' => $dueAt,
                'started_at' => $step->started_at ?? now(),
            ])->save();
            $run->forceFill([
                'status' => 'waiting',
                'due_at' => $dueAt,
            ])->save();

            AdvanceAutomationRun::dispatch($run->id)->delay($dueAt);

            return true;
        }

        if ($step->due_at !== null && $step->due_at->isFuture()) {
            // Not due yet (e.g. sync driver ignored delay). Leave waiting.
            return true;
        }

        $this->completeStep($step);
        $run->forceFill([
            'status' => 'running',
            'due_at' => null,
        ])->save();

        return false;
    }

    private function processEmailStep(AutomationRun $run, AutomationRunStep $step): bool
    {
        $organization = $run->organization;
        app(AccountAccess::class)->assertCanSend($organization);

        $subject = (string) data_get($step->config, 'subject', data_get($step->config, 'label', 'Automation email'));
        $html = (string) data_get($step->config, 'html', '<p>'.e($subject).'</p>');
        $from = (string) data_get($step->config, 'from', $this->resolveFromAddress($organization));

        $step->forceFill(['started_at' => $step->started_at ?? now()])->save();

        $message = $this->emails->send($organization, [
            'from' => $from,
            'to' => [['email' => $run->contact_email]],
            'subject' => $subject,
            'html' => $html,
            'text' => trim(html_entity_decode(strip_tags($html))),
            'thread' => false,
            'signature' => false,
            'expand_groups' => false,
            'tags' => ['automation:'.$run->automation_id, 'automation_run:'.$run->id],
            'meta' => [
                'automation_id' => $run->automation_id,
                'automation_run_id' => $run->id,
            ],
        ]);

        $step->forceFill([
            'message_id' => $message->id,
            'status' => $message->status === 'failed' ? 'failed' : 'completed',
            'error' => $message->status === 'failed'
                ? mb_substr((string) data_get($message->meta, 'error', 'Send failed'), 0, 500)
                : null,
            'completed_at' => now(),
        ])->save();

        if ($step->status === 'failed') {
            $run->forceFill([
                'status' => 'failed',
                'error' => $step->error,
                'completed_at' => now(),
            ])->save();

            return true;
        }

        return false;
    }

    private function completeStep(AutomationRunStep $step): bool
    {
        $step->forceFill([
            'status' => $step->type === 'trigger' ? 'skipped' : 'completed',
            'started_at' => $step->started_at ?? now(),
            'completed_at' => now(),
        ])->save();

        return false;
    }

    private function completeRun(AutomationRun $run): void
    {
        $run->forceFill([
            'status' => 'completed',
            'due_at' => null,
            'completed_at' => now(),
            'error' => null,
        ])->save();
    }

    private function failStep(AutomationRun $run, AutomationRunStep $step, Throwable $e): void
    {
        $message = mb_substr($e->getMessage(), 0, 500);

        $step->forceFill([
            'status' => 'failed',
            'error' => $message,
            'completed_at' => now(),
        ])->save();

        $run->forceFill([
            'status' => 'failed',
            'error' => $message,
            'completed_at' => now(),
        ])->save();

        report($e);
    }

    public function resolveFromAddress(Organization $organization): string
    {
        $domain = $organization->domains()
            ->where('status', 'verified')
            ->orderBy('name')
            ->value('name');

        if ($domain) {
            return 'hello@'.$domain;
        }

        return 'hello@'.($organization->slug ?: 'maildesk').'.test';
    }
}
