<?php

namespace App\Http\Controllers\Api\App;

use App\Events\ChatDelivered;
use App\Events\ChatRead;
use App\Events\ChatTyping;
use App\Http\Controllers\Controller;
use App\Models\ChatAttachment;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\Organization;
use App\Models\User;
use App\Rules\AllowedAttachmentFile;
use App\Services\Chat\ConversationService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatMessageController extends Controller
{
    use AuthorizesRequests;

    public function __construct(public ConversationService $chat) {}

    public function index(Request $request, int $conversation): JsonResponse
    {
        $model = $this->visible($request, $conversation);

        return response()->json([
            'data' => $this->chat->messages($model, $request->integer('before') ?: null)
                ->map->toAppArray()
                ->values(),
        ]);
    }

    public function store(Request $request, int $conversation): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $model = $this->visible($request, $conversation);

        $validated = $request->validate([
            'body' => ['nullable', 'string', 'max:4000'],
            'file' => ['nullable', 'file', 'max:10240', new AllowedAttachmentFile],
            'kind' => ['nullable', Rule::in(['text', 'image', 'file', 'voice'])],
            'duration_ms' => ['nullable', 'integer', 'min:0', 'max:600000'],
        ]);

        if (trim((string) ($validated['body'] ?? '')) === '' && ! $request->hasFile('file')) {
            throw ValidationException::withMessages([
                'body' => 'Write a message.',
            ]);
        }

        $message = $this->chat->send(
            $model,
            $user,
            trim((string) ($validated['body'] ?? '')),
            $request->file('file'),
            $validated['kind'] ?? 'text',
            $validated['duration_ms'] ?? null,
        );

        return response()->json([
            'data' => $message->toAppArray(),
        ], 201);
    }

    public function destroy(Request $request, int $conversation, int $message): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $model = $this->visible($request, $conversation);
        $chatMessage = ChatMessage::query()
            ->where('conversation_id', $model->id)
            ->whereKey($message)
            ->firstOrFail();

        $this->chat->deleteMessage($model, $user, $chatMessage);

        return response()->json([
            'message' => 'Message deleted.',
        ]);
    }

    public function read(Request $request, int $conversation): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $model = $this->visible($request, $conversation);
        $readAt = $this->chat->markRead($model, $user);
        if ($readAt !== null) {
            ChatRead::dispatch($model->id, $user->id, $readAt);
        }

        return response()->json([
            'message' => 'Marked as read.',
        ]);
    }

    public function delivered(Request $request, int $conversation): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $model = $this->visible($request, $conversation);
        $deliveredAt = $this->chat->markDelivered($model, $user);
        if ($deliveredAt !== null) {
            ChatDelivered::dispatch($model->id, $user->id, $deliveredAt);
        }

        return response()->json([
            'message' => 'Received.',
        ]);
    }

    public function typing(Request $request, int $conversation): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $model = $this->visible($request, $conversation);

        ChatTyping::dispatch($model->id, $user->id, $user->name);

        return response()->json([
            'message' => 'Typing.',
        ]);
    }

    public function attachment(Request $request, int $attachment): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        $organization = $this->organization($request);

        $model = ChatAttachment::query()
            ->whereHas('message.conversation', function ($query) use ($organization, $user) {
                $query->where('organization_id', $organization->id)
                    ->whereHas('participants', fn ($participants) => $participants->where('user_id', $user->id));
            })
            ->findOrFail($attachment);

        $disk = Storage::disk($model->disk ?: 'local');
        abort_unless($disk->exists($model->path), 404, 'This file is missing.');

        $inline = $request->boolean('inline') && ($model->isImage() || str_starts_with($model->content_type, 'audio/'));

        return $inline
            ? $disk->response($model->path, $model->filename, ['Content-Type' => $model->content_type, 'X-Content-Type-Options' => 'nosniff'], 'inline')
            : $disk->download($model->path, $model->filename, ['X-Content-Type-Options' => 'nosniff']);
    }

    private function visible(Request $request, int $conversation): Conversation
    {
        $model = $this->chat->findFor($this->organization($request), $conversation);
        $this->authorize('view', $model);

        return $model;
    }

    private function organization(Request $request): Organization
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('organization');

        return $organization;
    }
}
