<?php

namespace App\Http\Controllers\Api\App;

use App\Enums\ConversationType;
use App\Events\ChatRead;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Services\Chat\ConversationService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ConversationController extends Controller
{
    use AuthorizesRequests;

    public function __construct(public ConversationService $chat) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $organization = $this->organization($request);
        $archived = $request->boolean('archived');

        $conversations = $this->chat->listFor($organization, $user, $archived);

        return response()->json([
            'data' => $conversations->map(fn ($conversation) => $conversation->toAppArray($user))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'type' => ['required', Rule::enum(ConversationType::class)],
            'name' => ['required_if:type,group', 'nullable', 'string', 'max:80'],
            'user_ids' => ['required', 'array', 'min:1', 'max:50'],
            'user_ids.*' => ['integer', 'distinct'],
        ], [
            'name.required_if' => 'Give the group a name.',
        ]);

        $type = ConversationType::from($validated['type']);

        if ($type === ConversationType::Direct && count($validated['user_ids']) !== 1) {
            throw ValidationException::withMessages([
                'user_ids' => 'A direct chat is with one person.',
            ]);
        }

        $conversation = $this->chat->open(
            $this->organization($request),
            $user,
            $type,
            $validated['user_ids'],
            $validated['name'] ?? null,
        );

        return response()->json([
            'data' => $conversation->toAppArray($user),
        ], $conversation->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Request $request, int $conversation): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $model = $this->chat->findFor($this->organization($request), $conversation);
        $this->authorize('view', $model);

        $readAt = $this->chat->markRead($model, $user);
        if ($readAt !== null) {
            ChatRead::dispatch($model->id, $user->id, $readAt);
            $model->participants->each(function ($participant) use ($user, $readAt) {
                if ($participant->user_id === $user->id) {
                    $participant->last_read_at = $readAt;
                    $participant->last_delivered_at = $readAt;
                }
            });
        }

        return response()->json([
            'data' => $model->toAppArray($user),
            'messages' => $this->chat->messages($model)->map->toAppArray()->values(),
        ]);
    }

    public function addMembers(Request $request, int $conversation): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $organization = $this->organization($request);
        $model = $this->chat->findFor($organization, $conversation);

        $this->authorize('manage', $model);

        $validated = $request->validate([
            'user_ids' => ['required', 'array', 'min:1', 'max:50'],
            'user_ids.*' => ['integer', 'distinct'],
        ]);

        $model = $this->chat->addMembers($model, $organization, $user, $validated['user_ids']);

        return response()->json([
            'data' => $model->toAppArray($user),
        ]);
    }

    public function pin(Request $request, int $conversation): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $model = $this->chat->findFor($this->organization($request), $conversation);
        $this->authorize('view', $model);

        $validated = $request->validate([
            'pinned' => ['required', 'boolean'],
        ]);

        $this->chat->pin($model, $user, $validated['pinned']);
        $model->load(['participants.user:id,name,last_seen_at']);

        return response()->json([
            'data' => $model->toAppArray($user),
        ]);
    }

    public function mute(Request $request, int $conversation): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $model = $this->chat->findFor($this->organization($request), $conversation);
        $this->authorize('view', $model);

        $validated = $request->validate([
            'muted' => ['required', 'boolean'],
        ]);

        $this->chat->mute($model, $user, $validated['muted']);
        $model->load(['participants.user:id,name,last_seen_at']);

        return response()->json([
            'data' => $model->toAppArray($user),
        ]);
    }

    public function archive(Request $request, int $conversation): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $model = $this->chat->findFor($this->organization($request), $conversation);
        $this->authorize('view', $model);

        $validated = $request->validate([
            'archived' => ['required', 'boolean'],
        ]);

        $this->chat->archive($model, $user, $validated['archived']);
        $model->load(['participants.user:id,name,last_seen_at']);

        return response()->json([
            'data' => $model->toAppArray($user),
        ]);
    }

    public function destroy(Request $request, int $conversation): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $model = $this->chat->findFor($this->organization($request), $conversation);
        $this->authorize('view', $model);

        $this->chat->leave($model, $user);

        return response()->json(['ok' => true]);
    }

    private function organization(Request $request): Organization
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('organization');

        return $organization;
    }
}
