<?php

namespace App\Http\Controllers;

use App\Jobs\DeliverWebhook;
use App\Services\Webhooks\WebhookDeliverer;
use App\Services\WorkspaceAccess;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DashboardController extends Controller
{
    public function index(Request $request, WorkspaceAccess $access): RedirectResponse
    {
        try {
            $organization = CurrentOrganization::from($request);
        } catch (NotFoundHttpException) {
            return redirect()->route('emails');
        }

        return redirect()->route($access->homeRoute($request->user(), $organization));
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
