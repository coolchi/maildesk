<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RunE2EMailTest;
use App\Models\E2ETestRun;
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

        return Inertia::render('Admin/SystemTest/Index', [
            'runs' => $runs,
            'config' => [
                'from' => config('maildesk.e2e.from', config('mail.from.address')),
                'mailbox' => config('maildesk.e2e.mailbox', 'e2e-check@maildesk.ng'),
                'timeout' => (int) config('maildesk.e2e.timeout', 180),
                'hasResendKey' => filled(config('services.resend.key')),
                'hasWebhookSecret' => filled(config('maildesk.inbound.resend_webhook_secret')),
            ],
        ]);
    }

    public function start(Request $request): JsonResponse
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

        RunE2EMailTest::dispatch($run->id);

        return response()->json([
            'run' => $run->toAdminArray(),
        ]);
    }

    public function show(E2ETestRun $run): JsonResponse
    {
        return response()->json([
            'run' => $run->toAdminArray(),
        ]);
    }

    public function poll(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'run_id' => ['required', 'integer'],
        ]);

        $run = E2ETestRun::query()->find($validated['run_id']);

        if (! $run) {
            return response()->json(['run' => null], 404);
        }

        return response()->json([
            'run' => $run->toAdminArray(),
        ]);
    }
}
