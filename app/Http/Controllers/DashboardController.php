<?php

namespace App\Http\Controllers;

use App\Jobs\DeliverWebhook;
use App\Services\Webhooks\WebhookDeliverer;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): RedirectResponse
    {
        return redirect()->route('emails');
    }

    public function compose(): Response
    {
        return Inertia::render('Compose/Index');
    }

    public function docs(): Response
    {
        return Inertia::render('Docs/Index', [
            'apiBaseUrl' => rtrim(url('/api/v1'), '/'),
            'rateLimit' => max(1, (int) config('maildesk.api.rate_limit', 120)),
            'webhookEvents' => WebhookController::EVENT_OPTIONS,
            'webhookRetry' => [
                'attempts' => DeliverWebhook::MAX_ATTEMPTS,
                'backoff' => DeliverWebhook::BACKOFF,
                'timeout' => WebhookDeliverer::TIMEOUT_SECONDS,
            ],
        ]);
    }
}
