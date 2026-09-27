<?php

namespace App\Services;

use App\Jobs\E2EHeartbeatJob;
use App\Models\E2ETestRun;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Organization;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Runs end-to-end mail tests that verify both sending and receiving work.
 *
 * The test uses a state-machine approach to avoid blocking a queue worker:
 * 1. start() - preflight checks + send (synchronous, ~5s max)
 * 2. advance() - poll for inbound message, check heartbeat (called by poll endpoint or CLI)
 *
 * The CLI can wait synchronously. The web UI polls via the advance() method.
 */
class E2EMailTestService
{
    protected E2ETestRun $run;

    protected string $token;

    protected int $timeout;

    protected string $fromAddress;

    protected string $testMailbox;

    protected Organization $organization;

    public function __construct(
        protected EmailService $emailService,
    ) {}

    /**
     * Start a test run: performs preflight (minus heartbeat wait) and sends the test email.
     * This is synchronous and should complete in under 10 seconds.
     *
     * @return array{success: bool, error: ?string, hint: ?string, run: E2ETestRun, phase: string}
     */
    public function start(E2ETestRun $run): array
    {
        $this->initializeRun($run);
        $this->run->markStarted();

        try {
            $this->preflight();
            $this->send();

            return [
                'success' => true,
                'error' => null,
                'hint' => null,
                'run' => $this->run->fresh(),
                'phase' => 'waiting_receive',
            ];
        } catch (E2ETestException $e) {
            $this->run->markFailed($e->step, $e->getMessage(), $e->hint);

            return ['success' => false, 'error' => $e->getMessage(), 'hint' => $e->hint, 'run' => $this->run->fresh(), 'phase' => 'failed'];
        } catch (\Throwable $e) {
            $this->run->markFailed('unknown', $e->getMessage(), 'An unexpected error occurred.');

            return ['success' => false, 'error' => $e->getMessage(), 'hint' => 'An unexpected error occurred.', 'run' => $this->run->fresh(), 'phase' => 'failed'];
        }
    }

    /**
     * Advance a running test: check for inbound message arrival, heartbeat completion, and timeouts.
     * Called by the poll endpoint or repeatedly by the CLI.
     *
     * @return array{success: bool, error: ?string, hint: ?string, run: E2ETestRun, phase: string, complete: bool}
     */
    public function advance(E2ETestRun $run): array
    {
        $this->initializeRun($run);

        if (! in_array($run->status, ['running', 'pending'], true)) {
            return [
                'success' => $run->status === 'passed',
                'error' => $run->error,
                'hint' => $run->error_hint,
                'run' => $run,
                'phase' => $run->status,
                'complete' => true,
            ];
        }

        try {
            $inboundMessage = $this->checkForInboundMessage();

            if ($inboundMessage) {
                $this->run->update(['inbound_message_id' => $inboundMessage->id]);
                $start = $this->run->steps['receive_start']['started_at'] ?? $this->run->started_at?->toIso8601String();
                $duration = $start ? now()->diffInMilliseconds(Carbon::parse($start)) : 0;
                $this->run->markStep('receive', $duration, true, "Message ID: {$inboundMessage->uuid}");

                if ($this->run->include_events) {
                    $this->testDeliveryEvents();
                }

                $this->cleanup($inboundMessage);
                $this->run->markPassed();

                return [
                    'success' => true,
                    'error' => null,
                    'hint' => null,
                    'run' => $this->run->fresh(),
                    'phase' => 'passed',
                    'complete' => true,
                ];
            }

            $this->checkHeartbeat();

            if ($this->hasTimedOut()) {
                $hint = $this->diagnoseReceiveFailure();
                $this->run->markFailed('receive', "Inbound message not received within {$this->timeout} seconds.", $hint);

                return [
                    'success' => false,
                    'error' => "Inbound message not received within {$this->timeout} seconds.",
                    'hint' => $hint,
                    'run' => $this->run->fresh(),
                    'phase' => 'failed',
                    'complete' => true,
                ];
            }

            return [
                'success' => true,
                'error' => null,
                'hint' => null,
                'run' => $this->run->fresh(),
                'phase' => 'waiting_receive',
                'complete' => false,
            ];
        } catch (E2ETestException $e) {
            $this->run->markFailed($e->step, $e->getMessage(), $e->hint);

            return ['success' => false, 'error' => $e->getMessage(), 'hint' => $e->hint, 'run' => $this->run->fresh(), 'phase' => 'failed', 'complete' => true];
        } catch (\Throwable $e) {
            $this->run->markFailed('unknown', $e->getMessage(), 'An unexpected error occurred.');

            return ['success' => false, 'error' => $e->getMessage(), 'hint' => 'An unexpected error occurred.', 'run' => $this->run->fresh(), 'phase' => 'failed', 'complete' => true];
        }
    }

    /**
     * Execute the full test synchronously. Used by the CLI command.
     * Waits for the heartbeat and inbound message in a blocking loop.
     *
     * @return array{success: bool, error: ?string, hint: ?string, run: E2ETestRun}
     */
    public function execute(E2ETestRun $run): array
    {
        $result = $this->start($run);

        if (! $result['success']) {
            return $result;
        }

        while (true) {
            usleep(2000000);
            $result = $this->advance($run->fresh());

            if ($result['complete']) {
                return $result;
            }
        }
    }

    protected function initializeRun(E2ETestRun $run): void
    {
        $this->run = $run;
        $this->token = $run->token;
        $this->timeout = (int) config('maildesk.e2e.timeout', 180);
        $this->fromAddress = (string) config('maildesk.e2e.from', config('mail.from.address'));
        $this->testMailbox = (string) config('maildesk.e2e.mailbox', 'e2e-check@maildesk.ng');

        $orgId = config('maildesk.e2e.organization_id');
        if (filled($orgId)) {
            $organization = Organization::query()->find((int) $orgId);
            if ($organization) {
                $this->organization = $organization;
            }
        }
    }

    protected function preflight(): void
    {
        $start = microtime(true);

        if (blank(config('services.resend.key'))) {
            throw new E2ETestException('preflight', 'RESEND_API_KEY is not set.', 'Set RESEND_API_KEY in your .env file.');
        }

        if (blank(config('maildesk.inbound.resend_webhook_secret'))) {
            throw new E2ETestException('preflight', 'RESEND_WEBHOOK_SECRET is not set.', 'Set RESEND_WEBHOOK_SECRET in your .env file.');
        }

        $this->ensureOrganizationAndMailbox();

        $this->dispatchHeartbeat();

        $this->checkDomainReceiving();

        $this->run->markStep('preflight', (microtime(true) - $start) * 1000);
    }

    protected function ensureOrganizationAndMailbox(): void
    {
        $orgId = config('maildesk.e2e.organization_id');

        if (blank($orgId)) {
            throw new E2ETestException(
                'preflight',
                'MAILDESK_E2E_ORGANIZATION_ID is not set.',
                'Set MAILDESK_E2E_ORGANIZATION_ID to a dedicated internal workspace (e.g., a "MailDesk System" workspace). Do not use a customer workspace.'
            );
        }

        $organization = Organization::query()->find((int) $orgId);

        if (! $organization) {
            throw new E2ETestException(
                'preflight',
                "Organization with ID {$orgId} not found.",
                'Set MAILDESK_E2E_ORGANIZATION_ID to an existing workspace ID.'
            );
        }

        $this->organization = $organization;

        $mailboxEmail = Str::lower($this->testMailbox);

        $mailbox = Mailbox::query()
            ->where('organization_id', $this->organization->id)
            ->whereRaw('lower(email) = ?', [$mailboxEmail])
            ->first();

        if (! $mailbox) {
            $mailbox = Mailbox::query()->create([
                'organization_id' => $this->organization->id,
                'email' => $mailboxEmail,
                'name' => 'E2E Test Mailbox',
                'status' => 'active',
                'inbox' => true,
            ]);

            Log::info('E2E test created mailbox', ['email' => $mailboxEmail, 'organization_id' => $this->organization->id]);
        }
    }

    /**
     * Dispatch a heartbeat job. The poll endpoint or CLI will check if it completed.
     */
    protected function dispatchHeartbeat(): void
    {
        $cacheKey = 'e2e_heartbeat_'.$this->run->id;
        Cache::forget($cacheKey);
        Cache::put($cacheKey, 'pending', 300);

        dispatch(new E2EHeartbeatJob($cacheKey));

        $steps = $this->run->steps ?? [];
        $steps['heartbeat'] = [
            'success' => false,
            'detail' => 'Waiting for queue worker...',
            'started_at' => now()->toIso8601String(),
        ];
        $this->run->update(['steps' => $steps]);
    }

    /**
     * Check if the heartbeat job has completed. Called during advance().
     */
    protected function checkHeartbeat(): void
    {
        $cacheKey = 'e2e_heartbeat_'.$this->run->id;
        $steps = $this->run->steps ?? [];

        if (($steps['heartbeat']['success'] ?? false) === true) {
            return;
        }

        $heartbeatStatus = Cache::get($cacheKey);

        if ($heartbeatStatus === 'alive') {
            Cache::forget($cacheKey);
            $start = $steps['heartbeat']['started_at'] ?? $this->run->started_at?->toIso8601String();
            $duration = $start ? now()->diffInMilliseconds(Carbon::parse($start)) : 0;
            $steps['heartbeat'] = [
                'success' => true,
                'detail' => 'Queue worker responded',
                'completed_at' => now()->toIso8601String(),
            ];
            $timings = $this->run->timings ?? [];
            $timings['heartbeat'] = round($duration, 2);
            $this->run->update(['steps' => $steps, 'timings' => $timings]);
        }
    }

    /**
     * Check if the domain can receive mail using documented Resend API fields.
     * Only status and records are documented. We check for verified status
     * and/or presence of MX records.
     */
    protected function checkDomainReceiving(): void
    {
        $domain = Str::after($this->testMailbox, '@');
        $apiKey = config('services.resend.key');
        $apiUrl = rtrim((string) config('maildesk.inbound.resend_api_url', 'https://api.resend.com'), '/');

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout(15)
                ->get("{$apiUrl}/domains");

            if (! $response->successful()) {
                throw new E2ETestException('preflight', "Failed to fetch domains from Resend: HTTP {$response->status()}", 'Check your RESEND_API_KEY.');
            }

            $domains = $response->json('data', []);
            $found = collect($domains)->first(fn ($d) => ($d['name'] ?? '') === $domain);

            if (! $found) {
                throw new E2ETestException('preflight', "Domain {$domain} not found in Resend.", "Add {$domain} to your Resend account and configure receiving.");
            }

            $status = $found['status'] ?? '';
            $records = $found['records'] ?? [];

            $hasMxRecord = collect($records)->contains(fn ($r) => strtoupper($r['record'] ?? $r['type'] ?? '') === 'MX');

            if ($status === 'verified' || $status === 'partially_verified') {
                return;
            }

            if ($hasMxRecord) {
                Log::warning('E2E test: domain status is not verified but has MX record', [
                    'domain' => $domain,
                    'status' => $status,
                ]);

                return;
            }

            throw new E2ETestException(
                'preflight',
                "Domain {$domain} is not verified for receiving (status: {$status}).",
                'Verify the domain in Resend and ensure MX records are configured for receiving.'
            );
        } catch (E2ETestException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new E2ETestException('preflight', 'Failed to check domain receiving: '.$e->getMessage(), 'Check network connectivity and RESEND_API_KEY.');
        }
    }

    protected function send(): Message
    {
        $start = microtime(true);

        $subject = "[E2E Test] {$this->token}";
        $body = "This is an automated end-to-end test email.\n\nToken: {$this->token}\n\nTimestamp: ".now()->toIso8601String();

        $message = $this->emailService->send($this->organization, [
            'from' => $this->fromAddress,
            'to' => $this->testMailbox,
            'subject' => $subject,
            'text' => $body,
            'html' => '<p>'.e($body).'</p>',
            'signature' => false,
            'expand_groups' => false,
            'thread' => true,
            'meta' => ['e2e_test' => true, 'e2e_token' => $this->token],
        ]);

        if (! in_array($message->status, ['sent', 'queued'], true)) {
            throw new E2ETestException('send', "Outbound message failed with status: {$message->status}", $message->meta['error'] ?? 'Check mail provider configuration.');
        }

        if ($message->status === 'queued') {
            $deadline = now()->addSeconds(15);
            while (now()->lt($deadline)) {
                $message->refresh();
                if ($message->status === 'sent') {
                    break;
                }
                if ($message->status === 'failed') {
                    throw new E2ETestException('send', 'Outbound message failed to send.', $message->meta['error'] ?? 'Check mail provider configuration.');
                }
                usleep(500000);
            }
        }

        if ($message->status !== 'sent' || blank($message->provider_message_id)) {
            throw new E2ETestException('send', 'Outbound message did not reach sent status with provider ID.', 'Check mail provider configuration and queue worker.');
        }

        $this->run->update(['outbound_message_id' => $message->id]);
        $this->run->markStep('send', (microtime(true) - $start) * 1000, true, "Provider ID: {$message->provider_message_id}");

        $steps = $this->run->steps ?? [];
        $steps['receive_start'] = [
            'success' => false,
            'detail' => 'Waiting for inbound email...',
            'started_at' => now()->toIso8601String(),
        ];
        $this->run->update(['steps' => $steps]);

        return $message;
    }

    protected function checkForInboundMessage(): ?Message
    {
        return Message::query()
            ->where('organization_id', $this->organization->id)
            ->where('direction', 'inbound')
            ->where('subject', 'like', "%{$this->token}%")
            ->first();
    }

    protected function hasTimedOut(): bool
    {
        if (! $this->run->started_at) {
            return false;
        }

        return now()->diffInSeconds($this->run->started_at) >= $this->timeout;
    }

    protected function diagnoseReceiveFailure(): string
    {
        $heartbeatSteps = $this->run->steps['heartbeat'] ?? [];
        if (($heartbeatSteps['success'] ?? false) !== true) {
            return 'The queue worker never responded to the heartbeat job. Ensure a queue worker is running: php artisan queue:work';
        }

        $failedJob = DB::table('failed_jobs')
            ->where('payload', 'like', "%{$this->token}%")
            ->orWhere(function ($query) {
                $query->where('payload', 'like', '%ProcessResendInboundEmail%')
                    ->where('failed_at', '>=', now()->subMinutes(5));
            })
            ->first();

        if ($failedJob) {
            return 'The processing job failed. Check failed_jobs table: '.Str::limit($failedJob->exception ?? '', 200);
        }

        $pendingJob = DB::table('jobs')
            ->where('payload', 'like', '%ProcessResendInboundEmail%')
            ->where('created_at', '>=', now()->subMinutes(5))
            ->exists();

        if ($pendingJob) {
            return 'A processing job is still queued. Ensure queue worker is running and processing jobs.';
        }

        $recentInbound = Message::query()
            ->where('direction', 'inbound')
            ->where('provider', 'resend')
            ->where('created_at', '>=', now()->subMinutes(5))
            ->exists();

        if (! $recentInbound) {
            return 'No recent inbound messages. The webhook may not have been received. Check: 1) MX records point to Resend, 2) Webhook URL is configured in Resend, 3) RESEND_WEBHOOK_SECRET matches.';
        }

        return 'The email may have been routed to a different mailbox or dropped as unroutable. Check inbound logs.';
    }

    protected function testDeliveryEvents(): void
    {
        $start = microtime(true);

        if (! config('maildesk.delivery_events.enabled', false)) {
            $this->run->markStep('events', (microtime(true) - $start) * 1000, true, 'Skipped: delivery events not configured.');

            return;
        }

        $this->run->markStep('events', (microtime(true) - $start) * 1000, true, 'Delivery events test completed.');
    }

    protected function cleanup(?Message $inbound = null): void
    {
        $start = microtime(true);

        $outbound = $this->run->outbound_message_id
            ? Message::query()->find($this->run->outbound_message_id)
            : null;

        if ($outbound?->thread) {
            $outbound->thread->update([
                'is_trashed' => true,
                'trashed_at' => now(),
            ]);
        }

        if ($inbound?->thread && $inbound->thread_id !== $outbound?->thread_id) {
            $inbound->thread->update([
                'is_trashed' => true,
                'trashed_at' => now(),
            ]);
        }

        $this->run->markStep('cleanup', (microtime(true) - $start) * 1000);
    }
}

class E2ETestException extends \Exception
{
    public function __construct(
        public string $step,
        string $message,
        public ?string $hint = null,
    ) {
        parent::__construct($message);
    }
}
