<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\E2ETestRun;
use App\Services\E2EMailTestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class SystemTestController extends Controller
{
    public function index(): Response
    {
        $runs = E2ETestRun::query()
            ->with('user')
            ->latest('id')
            ->limit(20)
            ->get()
            ->map->toAdminArray();

        $orgId = config('maildesk.e2e.organization_id');

        return Inertia::render('Admin/SystemTest/Index', [
            'runs' => $runs,
            'config' => [
                'from' => config('maildesk.e2e.from', config('mail.from.address')),
                'mailbox' => config('maildesk.e2e.mailbox', 'e2e-check@maildesk.ng'),
                'timeout' => (int) config('maildesk.e2e.timeout', 180),
                'hasResendKey' => filled(config('services.resend.key')),
                'hasWebhookSecret' => filled(config('maildesk.inbound.resend_webhook_secret')),
                'hasOrganizationId' => filled($orgId),
                'organizationId' => $orgId,
            ],
        ]);
    }

    /**
     * Start a new E2E test run. Performs preflight + send synchronously (under 10s).
     * The poll endpoint then advances the run to check for the inbound message.
     */
    public function start(Request $request, E2EMailTestService $service): JsonResponse
    {
        $validated = $request->validate([
            'include_events' => ['boolean'],
        ]);

        $run = E2ETestRun::query()->create([
            'status' => 'pending',
            'token' => Str::random(32),
            'source' => 'web',
            'user_id' => $request->user()?->id,
            'include_events' => $validated['include_events'] ?? false,
        ]);

        $result = $service->start($run);

        return response()->json([
            'run' => $result['run']->toAdminArray(),
            'phase' => $result['phase'],
            'error' => $result['error'],
            'hint' => $result['hint'],
        ]);
    }

    /**
     * Poll to advance a running test: checks for inbound message arrival and timeouts.
     */
    public function poll(Request $request, E2EMailTestService $service): JsonResponse
    {
        $validated = $request->validate([
            'run_id' => ['required', 'integer'],
        ]);

        $run = E2ETestRun::query()->find($validated['run_id']);

        if (! $run) {
            return response()->json(['run' => null], 404);
        }

        $result = $service->advance($run);

        return response()->json([
            'run' => $result['run']->toAdminArray(),
            'phase' => $result['phase'],
            'complete' => $result['complete'],
            'error' => $result['error'],
            'hint' => $result['hint'],
        ]);
    }
}
