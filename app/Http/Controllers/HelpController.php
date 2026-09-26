<?php

namespace App\Http\Controllers;

use App\Services\Billing\BillingService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * In-app help: overview, getting started and FAQ. Only non-secret props.
 */
class HelpController extends Controller
{
    public function index(): Response
    {
        $supportEmail = config('maildesk.support_email');

        return Inertia::render('Help/Index', [
            'appName' => (string) config('app.name', 'MailDesk'),
            'supportEmail' => is_string($supportEmail) && filter_var($supportEmail, FILTER_VALIDATE_EMAIL) ? $supportEmail : null,
            'paymentsConfigured' => app(BillingService::class)->isConfigured(),
        ]);
    }
}
