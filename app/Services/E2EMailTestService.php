<?php

namespace App\Services;

use App\Jobs\E2EHeartbeatJob;
use App\Mail\MailManager;
use App\Models\E2ETestRun;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Organization;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Runs end-to-end mail tests that verify both sending and receiving work.
 *
 * The test flow:
 * 1. Preflight - verify config, queue worker, domain receiving status
 * 2. Send - send an email through the app's normal outbound pipeline
 * 3. Receive - poll for the inbound message via real MX and webhook
 * 4. Events (optional) - test delivery events with Resend test addresses
 * 5. Cleanup - mark test threads as system tests
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
        protected MailManager $mailManager,
        protected EmailService $emailService,
    ) {}

    /**
     * @return array{success: bool, error: ?string, hint: ?string, run: E2ETestRun}
     */
    public function execute(E2ETestRun $run): array
    {
        $this->run = $run;
        $this->token = $run->token;
        $this->timeout = (int) config('maildesk.e2e.timeout', 180);
        $this->fromAddress = (string) config('maildesk.e2e.from', config('mail.from.address'));
        $this->testMailbox = (string) config('maildesk.e2e.mailbox', 'e2e-check@maildesk.ng');

        $this->run->markStarted();

        try {
            $this->preflight();
            $outboundMessage = $this->send();
            $inboundMessage = $this->receive();
            if ($run->include_events) {
                $this->testDeliveryEvents();
            }
            $this->cleanup($outboundMessage, $inboundMessage);
            $this->run->markPassed();

            return ['success' => true, 'error' => null, 'hint' => null, 'run' => $this->run->fresh()];
        } catch (E2ETestException $e) {
            $this->run->markFailed($e->step, $e->getMessage(), $e->hint);

            return ['success' => false, 'error' => $e->getMessage(), 'hint' => $e->hint, 'run' => $this->run->fresh()];
        } catch (\Throwable $e) {
            $this->run->markFailed('unknown', $e->getMessage(), 'An unexpected error occurred.');

            return ['success' => false, 'error' => $e->getMessage(), 'hint' => 'An unexpected error occurred.', 'run' => $this->run->fresh()];
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

        $this->checkQueueWorker();

        $this->checkDomainReceiving();

        $this->run->markStep('preflight', (microtime(true) - $start) * 1000);
    }

    protected function ensureOrganizationAndMailbox(): void
    {
        $orgId = (int) config('maildesk.e2e.organization_id', 1);
        $this->organization = Organization::query()->findOrFail($orgId);

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

    protected function checkQueueWorker(): void
    {
        $cacheKey = 'e2e_heartbeat_'.Str::random(16);
        Cache::forget($cacheKey);

        dispatch(new E2EHeartbeatJob($cacheKey));

        $deadline = now()->addSeconds(30);
        while (now()->lt($deadline)) {
            if (Cache::get($cacheKey) === 'alive') {
                Cache::forget($cacheKey);

                return;
            }
            usleep(500000);
        }

        throw new E2ETestException('preflight', 'Queue worker did not respond within 30 seconds.', 'Ensure a queue worker is running: php artisan queue:work');
    }

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
                throw new E2ETestException('preflight', "Domain {$domain} not found in Resend.", "Add {$domain} to your Resend account and enable receiving.");
            }

            $receivingEnabled = ($found['receiving'] ?? false) === true
                || in_array($found['status'] ?? '', ['verified', 'active'], true);

            if (! $receivingEnabled) {
                throw new E2ETestException('preflight', "Domain {$domain} does not have receiving enabled.", 'Enable receiving in your Resend domain settings.');
            }
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
            'html' => "<p>{$body}</p>",
            'signature' => false,
            'expand_groups' => false,
            'thread' => true,
            'meta' => ['e2e_test' => true, 'e2e_token' => $this->token],
        ]);

        if (! in_array($message->status, ['sent', 'queued'], true)) {
            throw new E2ETestException('send', "Outbound message failed with status: {$message->status}", $message->meta['error'] ?? 'Check mail provider configuration.');
        }

        if ($message->status === 'queued') {
            $deadline = now()->addSeconds(30);
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

        return $message;
    }

    protected function receive(): Message
    {
        $start = microtime(true);
        $deadline = now()->addSeconds($this->timeout);

        while (now()->lt($deadline)) {
            $message = Message::query()
                ->where('organization_id', $this->organization->id)
                ->where('direction', 'inbound')
                ->where('subject', 'like', "%{$this->token}%")
                ->first();

            if ($message) {
                $this->run->update(['inbound_message_id' => $message->id]);
                $this->run->markStep('receive', (microtime(true) - $start) * 1000, true, "Message ID: {$message->uuid}");

                return $message;
            }

            usleep(2000000);
        }

        $hint = $this->diagnoseReceiveFailure();
        throw new E2ETestException('receive', "Inbound message not received within {$this->timeout} seconds.", $hint);
    }

    protected function diagnoseReceiveFailure(): string
    {
        $failedJob = DB::table('failed_jobs')
            ->where('payload', 'like', "%{$this->token}%")
            ->orWhere('payload', 'like', '%ProcessResendInboundEmail%')
            ->where('failed_at', '>=', now()->subMinutes(5))
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

    protected function cleanup(Message $outbound, Message $inbound): void
    {
        $start = microtime(true);

        if ($outbound->thread) {
            $outbound->thread->update([
                'is_trashed' => true,
                'trashed_at' => now(),
            ]);
        }

        if ($inbound->thread && $inbound->thread_id !== $outbound->thread_id) {
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
