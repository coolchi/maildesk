<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Thread;
use App\Services\EmailService;
use App\Services\WorkspaceAccess;
use App\Support\AddressList;
use App\Support\DesignTemplates;
use App\Support\InboxSyncState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InboxController extends Controller
{
    public function __construct(public WorkspaceAccess $access) {}

    /**
     * List inbox threads with pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        $user = $request->user();

        $folder = $request->query('folder', 'inbox');
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(50, max(10, (int) $request->query('per_page', 20)));

        $threads = $this->access->scopeMailData($organization->threads(), $user, $organization)
            ->when(
                $folder === 'trash',
                fn ($query) => $query->where('is_trashed', true),
                fn ($query) => $query
                    ->where('is_trashed', false)
                    ->when(
                        $folder === 'spam',
                        fn ($inner) => $inner->where('is_spam', true),
                        fn ($inner) => $inner
                            ->where('is_spam', false)
                            ->when(
                                $folder === 'archive',
                                fn ($q) => $q->where('is_archived', true),
                                fn ($q) => $q->where('is_archived', false),
                            ),
                    ),
            )
            ->with(['mailbox', 'messages' => fn ($query) => $query->orderBy('created_at'), 'messages.attachments'])
            ->latest('last_message_at')
            ->paginate($perPage, ['*'], 'page', $page);

        $items = collect($threads->items())
            ->map(fn (Thread $thread) => $thread->toWorkspaceArray())
            ->values()
            ->all();

        $mailboxId = $this->access->scopedMailboxId($user, $organization);
        $syncState = InboxSyncState::for($organization, $mailboxId);

        return response()->json([
            'threads' => $items,
            'pagination' => [
                'current_page' => $threads->currentPage(),
                'last_page' => $threads->lastPage(),
                'per_page' => $threads->perPage(),
                'total' => $threads->total(),
            ],
            'folder' => $folder,
            'unread_count' => $syncState['unread'] ?? 0,
        ]);
    }

    /**
     * Get a single thread with all messages.
     */
    public function show(Request $request, int $thread): JsonResponse
    {
        $organization = $this->organization($request);

        /** @var Thread $model */
        $model = $this->access->scopeMailData($organization->threads(), $request->user(), $organization)
            ->with(['mailbox', 'messages' => fn ($query) => $query->orderBy('created_at'), 'messages.attachments'])
            ->findOrFail($thread);

        if (! $model->is_read) {
            $model->forceFill(['is_read' => true])->save();
        }

        return response()->json([
            'thread' => $model->toWorkspaceArray(),
        ]);
    }

    /**
     * Mark a thread as read or unread.
     */
    public function markRead(Request $request, int $thread): JsonResponse
    {
        $organization = $this->organization($request);
        $user = $request->user();

        $validated = $request->validate(['read' => ['required', 'boolean']]);

        /** @var Thread $model */
        $model = $this->access->scopeMailData($organization->threads(), $user, $organization)
            ->findOrFail($thread);

        $model->forceFill(['is_read' => $validated['read']])->save();

        $mailboxId = $this->access->scopedMailboxId($user, $organization);
        $syncState = InboxSyncState::for($organization, $mailboxId);

        return response()->json([
            'id' => $model->id,
            'is_read' => $model->is_read,
            'unread_count' => $syncState['unread'] ?? 0,
        ]);
    }

    /**
     * Toggle archive status.
     */
    public function toggleArchive(Request $request, int $thread): JsonResponse
    {
        $organization = $this->organization($request);

        /** @var Thread $model */
        $model = $this->access->scopeMailData($organization->threads(), $request->user(), $organization)
            ->where('is_trashed', false)
            ->findOrFail($thread);

        $archived = ! $model->is_archived;
        $model->forceFill([
            'is_archived' => $archived,
            'is_spam' => $archived ? false : $model->is_spam,
        ])->save();

        $mailboxId = $this->access->scopedMailboxId($request->user(), $organization);
        $syncState = InboxSyncState::for($organization, $mailboxId);

        return response()->json([
            'id' => $model->id,
            'is_archived' => $model->is_archived,
            'unread_count' => $syncState['unread'] ?? 0,
        ]);
    }

    /**
     * Toggle spam status.
     */
    public function toggleSpam(Request $request, int $thread): JsonResponse
    {
        $organization = $this->organization($request);

        /** @var Thread $model */
        $model = $this->access->scopeMailData($organization->threads(), $request->user(), $organization)
            ->where('is_trashed', false)
            ->findOrFail($thread);

        $spam = ! $model->is_spam;
        $model->forceFill([
            'is_spam' => $spam,
            'is_archived' => $spam ? false : $model->is_archived,
        ])->save();

        $mailboxId = $this->access->scopedMailboxId($request->user(), $organization);
        $syncState = InboxSyncState::for($organization, $mailboxId);

        return response()->json([
            'id' => $model->id,
            'is_spam' => $model->is_spam,
            'unread_count' => $syncState['unread'] ?? 0,
        ]);
    }

    /**
     * Toggle trash status.
     */
    public function toggleTrash(Request $request, int $thread): JsonResponse
    {
        $organization = $this->organization($request);

        /** @var Thread $model */
        $model = $this->access->scopeMailData($organization->threads(), $request->user(), $organization)
            ->findOrFail($thread);

        $trashed = ! $model->is_trashed;
        $model->forceFill([
            'is_trashed' => $trashed,
            'trashed_at' => $trashed ? now() : null,
        ])->save();

        $mailboxId = $this->access->scopedMailboxId($request->user(), $organization);
        $syncState = InboxSyncState::for($organization, $mailboxId);

        return response()->json([
            'id' => $model->id,
            'is_trashed' => $model->is_trashed,
            'unread_count' => $syncState['unread'] ?? 0,
        ]);
    }

    /**
     * Reply to a conversation.
     */
    public function reply(Request $request, int $thread, EmailService $emails): JsonResponse
    {
        $organization = $this->organization($request);
        $organization->loadMissing('mailProvider');

        /** @var Thread $model */
        $model = $this->access->scopeMailData($organization->threads(), $request->user(), $organization)
            ->with(['mailbox', 'messages' => fn ($query) => $query->orderBy('created_at')])
            ->findOrFail($thread);

        $validated = $request->validate([
            'html' => ['required', 'string', 'max:200000'],
            'cc' => ['nullable', 'string', 'max:2000', AddressList::rule()],
            'bcc' => ['nullable', 'string', 'max:2000', AddressList::rule()],
        ]);

        $text = trim(html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], "\n", $validated['html']))));
        if ($text === '') {
            return response()->json(['message' => 'Write a reply before sending.'], 422);
        }

        if (! $organization->mailProvider || $organization->mailProvider->status !== 'active') {
            return response()->json(['message' => 'Cannot send — this workspace has no active mail provider.'], 422);
        }

        /** @var Message|null $lastInbound */
        $lastInbound = $model->messages->where('direction', 'inbound')->last();
        /** @var Message|null $last */
        $last = $model->messages->last();

        if ($lastInbound === null || $last === null) {
            return response()->json(['message' => 'This conversation has no customer message to reply to.'], 422);
        }

        $from = $model->mailbox?->email
            ?? collect($lastInbound->to ?? [])->first()
            ?? $last->from_email;
        $to = collect($lastInbound->reply_to ?? [])->first() ?? $lastInbound->from_email;

        $references = array_values(array_unique(array_filter([
            ...($last->references ?? []),
            $last->in_reply_to,
            $last->message_id_header,
        ])));

        $headers = array_filter([
            'In-Reply-To' => $last->message_id_header,
            'References' => $references !== [] ? implode(' ', $references) : null,
        ]);

        $subject = preg_match('/^re\s*:/i', (string) $model->subject) ? $model->subject : 'Re: '.$model->subject;
        $fromName = $model->mailbox?->display_name;

        $message = $emails->send($organization, [
            'from' => $fromName ? "{$fromName} <{$from}>" : $from,
            'to' => $to,
            'subject' => $subject,
            'html' => $validated['html'],
            'text' => $text,
            'design' => DesignTemplates::defaultKey($organization),
            'headers' => $headers ?: null,
            'in_reply_to' => $last->message_id_header,
            'references' => $references ?: null,
            'cc' => $validated['cc'] ?? null,
            'bcc' => $validated['bcc'] ?? null,
            'signature' => true,
        ], thread: $model);

        $status = match ($message->status) {
            'failed' => 'error',
            'suppressed' => 'error',
            default => 'success',
        };

        $error = match ($message->status) {
            'failed' => data_get($message->meta, 'error', 'Provider rejected the reply.'),
            'suppressed' => data_get($message->meta, 'error', 'Recipient is suppressed.'),
            default => null,
        };

        return response()->json([
            'status' => $status,
            'message' => $status === 'success' ? 'Reply sent.' : $error,
            'message_id' => $message->uuid,
        ], $status === 'error' ? 422 : 200);
    }

    /**
     * Get the organization from the request.
     */
    protected function organization(Request $request): Organization
    {
        $workspaceId = $request->header('X-Workspace-Id') ?? $request->query('workspace_id');

        if (! $workspaceId) {
            abort(400, 'Workspace ID is required. Pass X-Workspace-Id header or workspace_id query parameter.');
        }

        $user = $request->user();
        $organization = $user->organizations()->whereKey($workspaceId)->first();

        if (! $organization) {
            abort(403, 'You do not have access to this workspace.');
        }

        return $organization;
    }
}
