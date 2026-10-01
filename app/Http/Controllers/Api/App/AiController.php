<?php

namespace App\Http\Controllers\Api\App;

use App\Ai\Exceptions\AiException;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Thread;
use App\Services\ComposeAssistService;
use App\Services\PlatformSettings;
use App\Services\ReplyDraftService;
use App\Services\ThreadSummaryService;
use App\Services\WorkspaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiController extends Controller
{
    public function __construct(public WorkspaceAccess $access) {}

    public function show(PlatformSettings $settings): JsonResponse
    {
        $flags = $settings->aiFeatureFlags();

        return response()->json([
            'compose_assist' => (bool) ($flags['compose_assist'] ?? false),
            'reply_draft' => (bool) ($flags['reply_draft'] ?? false),
            'thread_summary' => (bool) ($flags['thread_summary'] ?? false),
        ]);
    }

    public function compose(Request $request, ComposeAssistService $assist): JsonResponse
    {
        if (! $assist->enabled()) {
            return response()->json(['message' => 'Compose assist is not enabled.'], 403);
        }

        $validated = $request->validate([
            'action' => ['required', 'string', 'in:rewrite,tone,shorten,translate,subject'],
            'body' => ['nullable', 'string', 'max:20000'],
            'subject' => ['nullable', 'string', 'max:255'],
            'tone' => ['nullable', 'string', 'in:friendly,formal,concise,persuasive'],
            'language' => ['nullable', 'string', 'max:16'],
        ]);

        $body = trim((string) ($validated['body'] ?? ''));

        try {
            $result = $assist->assist(
                $validated['action'],
                $body === '' ? '' : '<p>'.e($body).'</p>',
                $validated['subject'] ?? null,
                $validated['tone'] ?? null,
                $validated['language'] ?? null,
            );
        } catch (AiException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'text' => isset($result['html']) ? $this->plain($result['html']) : null,
            'subject' => $result['subject'] ?? null,
            'subjects' => $result['subjects'] ?? [],
        ]);
    }

    public function suggestReply(Request $request, int $thread, ReplyDraftService $drafts): JsonResponse
    {
        if (! $drafts->enabled()) {
            return response()->json(['message' => 'Reply draft is not enabled.'], 403);
        }

        $model = $this->thread($request, $thread);

        $validated = $request->validate([
            'tone' => ['nullable', 'string', 'in:friendly,formal,concise'],
        ]);

        try {
            $html = $drafts->draft($model, $validated['tone'] ?? null);
        } catch (AiException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'text' => $this->plain($html),
        ]);
    }

    public function summarize(Request $request, int $thread, ThreadSummaryService $summaries): JsonResponse
    {
        if (! $summaries->enabled()) {
            return response()->json(['message' => 'Thread summary is not enabled.'], 403);
        }

        $model = $this->thread($request, $thread);

        try {
            $result = $summaries->summarize($model);
        } catch (AiException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'summary' => $result['summary'],
            'action_items' => $result['action_items'],
        ]);
    }

    private function plain(string $html): string
    {
        $text = str_replace(['</p>', '<br>', '<br/>', '<br />'], "\n", $html);
        $text = html_entity_decode(strip_tags($text));
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function thread(Request $request, int $thread): Thread
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('organization');

        $model = $this->access->scopeMailData($organization->threads(), $request->user(), $organization)
            ->find($thread);

        if (! $model instanceof Thread) {
            abort(404, 'This conversation is no longer available.');
        }

        return $model;
    }
}
