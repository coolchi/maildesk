<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Mailbox;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Thread;
use App\Support\EmailHtmlSanitizer;
use App\Support\EmailSnippet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Read one mailbox's mail over the public API. Reads the same threads and
 * messages tables the web inbox uses, scoped by mailbox_id like a mail
 * user's own inbox (WorkspaceAccess::scopeMailData).
 *
 * Polling for new mail: pass since_id = the highest "id" you have seen
 * (results come back oldest first), or since = an ISO 8601 timestamp.
 */
class MailboxMessageController extends Controller
{
    public const FOLDERS = ['inbox', 'archive', 'spam', 'trash', 'all'];

    public function index(Request $request, int $mailbox): JsonResponse
    {
        $model = $this->mailbox($request, $mailbox);

        $validated = $request->validate([
            'folder' => ['nullable', Rule::in(self::FOLDERS)],
            'direction' => ['nullable', Rule::in(['inbound', 'outbound', 'all'])],
            'unread' => ['nullable', 'boolean'],
            'since' => ['nullable', 'date'],
            'since_id' => ['nullable', 'integer', 'min:0'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $folder = $validated['folder'] ?? 'inbox';
        $direction = $validated['direction'] ?? 'inbound';
        $polling = isset($validated['since_id']) || filled($validated['since'] ?? null);

        $query = Message::query()
            ->where('organization_id', $model->organization_id)
            ->where('mailbox_id', $model->id)
            ->when($direction !== 'all', fn (Builder $q) => $q->where('direction', $direction))
            ->when($folder !== 'all', fn (Builder $q) => $q->whereHas('thread', fn (Builder $t) => self::applyFolder($t, $folder)))
            ->when(array_key_exists('unread', $validated) && $validated['unread'] !== null, fn (Builder $q) => $q->whereHas(
                'thread',
                fn (Builder $t) => $t->where('is_read', ! $request->boolean('unread')),
            ))
            ->when(isset($validated['since_id']), fn (Builder $q) => $q->where('id', '>', (int) $validated['since_id']))
            ->when(filled($validated['since'] ?? null), fn (Builder $q) => $q->where('created_at', '>', Carbon::parse($validated['since'])))
            ->with(['thread', 'attachments']);

        $polling ? $query->orderBy('id') : $query->orderByDesc('id');

        $page = $query->paginate((int) ($validated['per_page'] ?? 25))->withQueryString();
        $page->setCollection($page->getCollection()->map(fn (Message $message) => self::present($message, $model, full: false)));

        return response()->json($page);
    }

    public function show(Request $request, int $mailbox, string $message): JsonResponse
    {
        $model = $this->mailbox($request, $mailbox);

        return response()->json(self::present($this->message($model, $message), $model, full: true));
    }

    /**
     * Mark the message's conversation read or unread (read state is kept per thread).
     */
    public function markRead(Request $request, int $mailbox, string $message): JsonResponse
    {
        $model = $this->mailbox($request, $mailbox);
        $validated = $request->validate(['read' => ['required', 'boolean']]);

        $found = $this->message($model, $message);
        $found->thread?->forceFill(['is_read' => (bool) $validated['read']])->save();

        return response()->json([
            'id' => $found->id,
            'uuid' => $found->uuid,
            'thread_id' => $found->thread_id,
            'read' => (bool) $found->thread?->is_read,
        ]);
    }

    public static function applyFolder(Builder $threads, string $folder): Builder
    {
        return match ($folder) {
            'trash' => $threads->where('is_trashed', true),
            'spam' => $threads->where('is_trashed', false)->where('is_spam', true),
            'archive' => $threads->where('is_trashed', false)->where('is_spam', false)->where('is_archived', true),
            default => $threads->where('is_trashed', false)->where('is_spam', false)->where('is_archived', false),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Message $message, Mailbox $mailbox, bool $full): array
    {
        $thread = $message->thread;

        $data = [
            'id' => $message->id,
            'uuid' => $message->uuid,
            'mailbox_id' => $mailbox->id,
            'thread_id' => $message->thread_id,
            'direction' => $message->direction,
            'status' => $message->status,
            'from' => ['email' => $message->from_email, 'name' => $message->senderName()],
            'to' => self::addresses($message->to),
            'cc' => self::addresses($message->cc),
            'reply_to' => self::addresses($message->reply_to),
            'subject' => $message->subject,
            'snippet' => EmailSnippet::from($message->text_body, $message->html_body),
            'read' => (bool) $thread?->is_read,
            'folder' => self::folderOf($thread),
            'message_id_header' => $message->message_id_header,
            'in_reply_to' => $message->in_reply_to,
            'received_at' => $message->received_at?->toIso8601String(),
            'sent_at' => $message->sent_at?->toIso8601String(),
            'created_at' => $message->created_at?->toIso8601String(),
            'attachments' => $message->attachments->map(fn (Attachment $attachment) => [
                'id' => $attachment->id,
                'filename' => $attachment->filename,
                'content_type' => $attachment->content_type ?: 'application/octet-stream',
                'size' => (int) $attachment->size,
            ])->values()->all(),
        ];

        if ($full) {
            $data['text'] = $message->text_body;
            $data['html'] = EmailHtmlSanitizer::clean($message->html_body);
            $data['references'] = $message->references;
        }

        return $data;
    }

    /**
     * @return list<string>
     */
    protected static function addresses(mixed $value): array
    {
        return collect(is_array($value) ? $value : array_filter([$value]))
            ->map(fn ($item) => is_array($item) ? ($item['email'] ?? $item['address'] ?? null) : $item)
            ->filter(fn ($email) => is_string($email) && $email !== '')
            ->values()
            ->all();
    }

    protected static function folderOf(?Thread $thread): ?string
    {
        if (! $thread) {
            return null;
        }

        return match (true) {
            (bool) $thread->is_trashed => 'trash',
            (bool) $thread->is_spam => 'spam',
            (bool) $thread->is_archived => 'archive',
            default => 'inbox',
        };
    }

    protected function mailbox(Request $request, int $mailbox): Mailbox
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('organization');

        return $organization->mailboxes()->findOrFail($mailbox);
    }

    protected function message(Mailbox $mailbox, string $message): Message
    {
        return Message::query()
            ->where('organization_id', $mailbox->organization_id)
            ->where('mailbox_id', $mailbox->id)
            ->where(fn (Builder $q) => ctype_digit($message)
                ? $q->where('id', (int) $message)
                : $q->where('uuid', $message))
            ->with(['thread', 'attachments'])
            ->firstOrFail();
    }
}
