<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\Inbound\Contracts\InboundDriver;
use App\Mail\Inbound\Exceptions\InboundRetryException;
use App\Mail\Inbound\GenericInboundDriver;
use App\Mail\Inbound\ResendInboundDriver;
use App\Services\DeliveryEventService;
use App\Services\InboundEmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Public endpoint providers POST received mail to. Authenticated by the
 * provider's signature (not an API key), so it lives outside the API-key group.
 *
 * Status codes are chosen for provider retry behaviour: 2xx means "done, don't
 * resend", 401 means "bad signature", 5xx means "try again later".
 */
class InboundEmailController extends Controller
{
    /** @var array<string, class-string<InboundDriver>> */
    protected const DRIVERS = [
        'resend' => ResendInboundDriver::class,
        'generic' => GenericInboundDriver::class,
    ];

    public function __invoke(Request $request, string $driver, InboundEmailService $service): JsonResponse
    {
        $class = self::DRIVERS[$driver] ?? null;
        abort_if($class === null, 404, 'Unknown inbound driver.');

        /** @var InboundDriver $inbound */
        $inbound = app($class);
        $context = ['driver' => $driver, 'ip' => $request->ip()];

        // Fail loudly (and retryably) rather than rejecting every email as a
        // bad signature when a production deploy is missing its secret.
        if (! $inbound->isConfigured() && ! app()->environment(['local', 'testing'])) {
            $this->log()->critical('Inbound email rejected: webhook secret is not configured', $context);

            return response()->json(['message' => 'Inbound receiving is not configured.'], 503);
        }

        if (! $inbound->verify($request)) {
            $this->log()->warning('Inbound email rejected: invalid signature', $context);

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        try {
            $email = $inbound->parse($request);

            // Delivery events sent to the inbound URL (one Resend webhook for everything).
            if ($email === null && DeliveryEventService::supports($driver)) {
                return DeliveryEventController::process($request, $driver, app(DeliveryEventService::class), $this->log(), $context + [
                    'type' => $request->json('type'),
                ]);
            }

            // Acknowledge non-inbound events so the provider does not retry them.
            if ($email === null) {
                $this->log()->info('Inbound event ignored', $context + [
                    'type' => $request->json('type'),
                ]);

                return response()->json(['status' => 'ignored']);
            }

            // Resend emails are processed asynchronously to fetch received_for for routing.
            if ($email->deferredProcessing) {
                return response()->json([
                    'status' => 'accepted',
                    'email_id' => $email->providerMessageId,
                ], 202);
            }

            $message = $service->receive($email);
        } catch (InboundRetryException $e) {
            $this->log()->warning('Inbound email deferred for retry: '.$e->getMessage(), $context);

            return response()->json(['message' => 'Temporarily unable to process, please retry.'], 503);
        } catch (Throwable $e) {
            report($e);
            $this->log()->error('Inbound email failed', $context + ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Inbound processing failed.'], 500);
        }

        $context += [
            'provider_message_id' => $email->providerMessageId,
            'message_id' => $email->messageId,
            'from' => $email->fromEmail,
            'to' => $email->to,
        ];

        if ($message === null) {
            // 2xx so providers don't retry mail for addresses we will never own.
            $this->log()->info('Inbound email unroutable', $context);

            return response()->json(['status' => 'unroutable'], 202);
        }

        $created = $message->wasRecentlyCreated;

        $this->log()->info($created ? 'Inbound email received' : 'Inbound email duplicate ignored', $context + [
            'organization_id' => $message->organization_id,
            'thread_id' => $message->thread_id,
            'id' => $message->uuid,
        ]);

        return response()->json([
            'status' => 'received',
            'id' => $message->uuid,
            'thread_id' => $message->thread_id,
        ], $created ? 201 : 200);
    }

    protected function log(): LoggerInterface
    {
        return Log::channel(config('maildesk.inbound.log_channel'));
    }
}
