<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DeliveryEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Public endpoint providers POST delivery events to (signature-verified).
 * 2xx = done (including ignored/unmatched events), 401 = bad signature,
 * 5xx = retry later.
 */
class DeliveryEventController extends Controller
{
    public function __invoke(Request $request, string $driver, DeliveryEventService $service): JsonResponse
    {
        $events = $service->driver($driver);
        abort_if($events === null, 404, 'Unknown event driver.');

        $context = ['driver' => $driver, 'ip' => $request->ip(), 'type' => $request->json('type')];

        if (! $events->isConfigured() && ! app()->environment(['local', 'testing'])) {
            $this->log()->critical('Delivery event rejected: webhook secret is not configured', $context);

            return response()->json(['message' => 'Event receiving is not configured.'], 503);
        }

        if (! $events->verify($request)) {
            $this->log()->warning('Delivery event rejected: invalid signature', $context);

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        return self::process($request, $driver, $service, $this->log(), $context);
    }

    /**
     * Shared by the inbound endpoint so a single provider webhook can carry
     * both received mail and delivery events. The request must already be verified.
     *
     * @param  array<string, mixed>  $context
     */
    public static function process(Request $request, string $driver, DeliveryEventService $service, LoggerInterface $log, array $context): JsonResponse
    {
        try {
            $event = $service->driver($driver)?->parse($request);

            if ($event === null) {
                $log->info('Delivery event ignored', $context);

                return response()->json(['status' => 'ignored']);
            }

            $result = $service->handle($event);
        } catch (Throwable $e) {
            report($e);
            $log->error('Delivery event failed', $context + ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Event processing failed.'], 500);
        }

        $context += [
            'event' => $event->type,
            'provider_message_id' => $event->providerMessageId,
            'result' => $result['status'],
            'suppressed' => $result['suppressed'],
        ];

        if ($result['status'] === 'unmatched') {
            // Not one of our messages (or sent outside MailDesk): acknowledge, don't retry.
            $log->info('Delivery event for unknown message ignored', $context);

            return response()->json(['status' => 'ignored']);
        }

        $log->info('Delivery event '.$result['status'], $context + ['id' => $result['message']?->uuid]);

        return response()->json([
            'status' => $result['status'],
            'id' => $result['message']?->uuid,
            'message_status' => $result['message']?->status,
            'suppressed' => $result['suppressed'],
        ]);
    }

    protected function log(): LoggerInterface
    {
        return Log::channel(config('maildesk.inbound.log_channel'));
    }
}
