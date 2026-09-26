<?php

namespace App\Http\Controllers;

use App\Ai\Exceptions\AiException;
use App\Models\Message;
use App\Models\Thread;
use App\Services\AbuseDetectionService;
use App\Services\AutomationSmartStepsService;
use App\Services\BounceExplanationService;
use App\Services\BroadcastAssistService;
use App\Services\ComposeAssistService;
use App\Services\InAppHelpService;
use App\Services\NlSegmentService;
use App\Services\PlatformSettings;
use App\Services\ThreadSummaryService;
use App\Services\WorkspaceAccess;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiAssistController extends Controller
{
    public function __construct(public WorkspaceAccess $access) {}

    public function summarizeThread(Request $request, int $thread, ThreadSummaryService $summaries): JsonResponse
    {
        $organization = CurrentOrganization::from($request);

        /** @var Thread $model */
        $model = $this->access->scopeMailData($organization->threads(), $request->user(), $organization)
            ->findOrFail($thread);

        if (! $summaries->enabled()) {
            return response()->json(['message' => 'Thread summary is not enabled.'], 403);
        }

        try {
            return response()->json($summaries->summarize($model));
        } catch (AiException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function compose(Request $request, ComposeAssistService $assist): JsonResponse
    {
        CurrentOrganization::from($request);

        if (! $assist->enabled()) {
            return response()->json(['message' => 'Compose assist is not enabled.'], 403);
        }

        $validated = $request->validate([
            'action' => ['required', 'string', 'in:rewrite,tone,shorten,translate,subject'],
            'html' => ['nullable', 'string', 'max:50000'],
            'subject' => ['nullable', 'string', 'max:255'],
            'tone' => ['nullable', 'string', 'in:friendly,formal,concise,persuasive'],
            'language' => ['nullable', 'string', 'max:16'],
        ]);

        try {
            return response()->json($assist->assist(
                $validated['action'],
                (string) ($validated['html'] ?? ''),
                $validated['subject'] ?? null,
                $validated['tone'] ?? null,
                $validated['language'] ?? null,
            ));
        } catch (AiException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function broadcast(Request $request, BroadcastAssistService $assist): JsonResponse
    {
        CurrentOrganization::from($request);

        if (! $assist->enabled()) {
            return response()->json(['message' => 'Broadcast assist is not enabled.'], 403);
        }

        $validated = $request->validate([
            'brief' => ['nullable', 'string', 'max:2000'],
            'subject' => ['nullable', 'string', 'max:255'],
            'html' => ['nullable', 'string', 'max:50000'],
        ]);

        try {
            return response()->json($assist->suggest(
                $validated['brief'] ?? null,
                $validated['subject'] ?? null,
                $validated['html'] ?? null,
            ));
        } catch (AiException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function automation(Request $request, AutomationSmartStepsService $assist): JsonResponse
    {
        CurrentOrganization::from($request);

        if (! $assist->enabled()) {
            return response()->json(['message' => 'Automation smart steps are not enabled.'], 403);
        }

        $validated = $request->validate([
            'prompt' => ['required', 'string', 'max:2000'],
        ]);

        try {
            return response()->json($assist->fromPrompt($validated['prompt']));
        } catch (AiException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function segment(Request $request, NlSegmentService $assist): JsonResponse
    {
        CurrentOrganization::from($request);

        if (! $assist->enabled()) {
            return response()->json(['message' => 'Natural-language segments are not enabled.'], 403);
        }

        $validated = $request->validate([
            'prompt' => ['required', 'string', 'max:2000'],
        ]);

        try {
            return response()->json($assist->fromPrompt($validated['prompt']));
        } catch (AiException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function bounce(Request $request, string $message, BounceExplanationService $assist): JsonResponse
    {
        $organization = CurrentOrganization::from($request);

        /** @var Message $model */
        $model = $this->access->scopeMailData($organization->messages(), $request->user(), $organization)
            ->where(fn ($query) => $query->where('uuid', $message)->orWhere('id', $message))
            ->firstOrFail();

        if (! $assist->enabled()) {
            return response()->json(['message' => 'Bounce explanations are not enabled.'], 403);
        }

        try {
            return response()->json($assist->explain($model));
        } catch (AiException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function help(Request $request, InAppHelpService $assist): JsonResponse
    {
        $organization = CurrentOrganization::from($request);

        if (! $assist->enabled()) {
            return response()->json(['message' => 'In-app help is not enabled.'], 403);
        }

        $validated = $request->validate([
            'question' => ['required', 'string', 'max:2000'],
        ]);

        try {
            return response()->json($assist->answer($validated['question'], $organization));
        } catch (AiException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function abuse(Request $request, AbuseDetectionService $detector, PlatformSettings $settings): JsonResponse
    {
        $organization = CurrentOrganization::from($request);

        if (! $settings->aiFeatureEnabled('abuse_detection')) {
            return response()->json(['message' => 'Abuse detection is not enabled.'], 403);
        }

        return response()->json($detector->scan($organization));
    }
}
