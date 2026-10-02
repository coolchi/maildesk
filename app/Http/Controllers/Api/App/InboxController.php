<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\MailDraft;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Thread;
use App\Rules\AllowedAttachmentFile;
use App\Services\EmailService;
use App\Services\SignatureService;
use App\Services\WorkspaceAccess;
use App\Support\AddressList;
use App\Support\DesignTemplates;
use App\Support\EmailSnippet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InboxController extends Controller
{
    public function __construct(public WorkspaceAccess $access) {}

    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        $folder = $request->string('folder')->toString() ?: 'inbox';
        $search = trim($request->string('q')->toString());

        abort_unless(in_array($folder, ['inbox', 'archive', 'spam', 'trash', 'sent'], true), 422, 'Unknown folder.');

        if ($folder === 'sent') {
            return response()->json(['data' => $this->sent($request)]);
        }

        $threads = $this->access->scopeMailData($organization->threads(), $request->user(), $organization)
            ->when($folder === 'trash', fn ($query) => $query->where('is_trashed', true), function ($query) use ($folder) {
                $query->where('is_trashed', false)
                    ->when($folder === 'spam', fn ($inner) => $inner->where('is_spam', true), function ($inner) use ($folder) {
                        $inner->where('is_spam', false)->where('is_archived', $folder === 'archive');
                    });
            })
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.strtolower($search).'%';
                $query->where(function ($inner) use ($like) {
                    $inner->whereRaw('lower(subject) like ?', [$like])
                        ->orWhereRaw('lower(snippet) like ?', [$like]);
                });
            })
            ->with(['messages' => fn ($query) => $query->select('id', 'thread_id', 'from_email', 'from_name', 'headers', 'created_at')->latest()])
            ->latest('last_message_at')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => $threads->map(fn (Thread $thread) => $this->card($thread))->values(),
        ]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $updated = $this->access
            ->scopeMailData($this->organization($request)->threads(), $request->user(), $this->organization($request))
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json([
            'message' => 'Marked as read.',
            'count' => $updated,
        ]);
    }

    public function show(Request $request, int $thread): JsonResponse
    {
        $model = $this->thread($request, $thread);

        if (! $model->is_read) {
            $model->forceFill(['is_read' => true])->save();
        }

        return response()->json([
            'data' => $this->withMobileAttachmentUrls($model->toWorkspaceArray()),
        ]);
    }

    public function reply(Request $request, int $thread, EmailService $emails): JsonResponse
    {
        $organization = $this->organization($request);
        $organization->loadMissing('mailProvider');
        $model = $this->thread($request, $thread);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:20000'],
            'file' => ['nullable', 'file', 'max:10240', new AllowedAttachmentFile],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*' => ['file', 'max:10240', new AllowedAttachmentFile],
        ], [
            'body.required' => 'Write a reply before sending.',
            'files.max' => 'You can attach up to 10 files.',
        ]);

        if (! $organization->mailProvider || $organization->mailProvider->status !== 'active') {
            return response()->json(['message' => 'Cannot send — this workspace has no active mail provider.'], 422);
        }

        $lastInbound = $model->messages->where('direction', 'inbound')->last();
        $last = $model->messages->last();

        if ($lastInbound === null || $last === null) {
            return response()->json(['message' => 'This conversation has no customer message to reply to.'], 422);
        }

        $from = $this->firstEmail($model->mailbox?->email)
            ?? $this->firstEmail($lastInbound->to)
            ?? $last->from_email;
        $to = $this->firstEmail($lastInbound->reply_to) ?? $lastInbound->from_email;
        $references = array_values(array_unique(array_filter([
            ...($last->references ?? []),
            $last->in_reply_to,
            $last->message_id_header,
        ])));
        $subject = preg_match('/^re\s*:/i', (string) $model->subject) ? $model->subject : 'Re: '.$model->subject;
        $html = '<p>'.e($validated['body']).'</p>';

        $message = $emails->send($organization, [
            'from' => ($model->mailbox?->display_name ? $model->mailbox->display_name.' <'.$from.'>' : $from),
            'to' => $to,
            'subject' => $subject,
            'html' => $html,
            'text' => $validated['body'],
            'design' => DesignTemplates::defaultKey($organization),
            'headers' => array_filter([
                'In-Reply-To' => $last->message_id_header,
                'References' => $references !== [] ? implode(' ', $references) : null,
            ]) ?: null,
            'in_reply_to' => $last->message_id_header,
            'references' => $references ?: null,
            'signature' => true,
        ], files: $this->uploadedFiles($request), thread: $model);

        return $this->sendResult($message);
    }

    public function forward(Request $request, int $thread, EmailService $emails, SignatureService $signatures): JsonResponse
    {
        $organization = $this->organization($request);
        $organization->loadMissing('mailProvider');
        $model = $this->thread($request, $thread);

        $validated = $request->validate([
            'to' => ['required', 'string', 'max:2000', AddressList::rule()],
            'body' => ['nullable', 'string', 'max:20000'],
        ]);

        if (! $organization->mailProvider || $organization->mailProvider->status !== 'active') {
            return response()->json(['message' => 'Cannot send — this workspace has no active mail provider.'], 422);
        }

        $original = $model->messages->last();
        if ($original === null) {
            return response()->json(['message' => 'There is no message to forward.'], 422);
        }

        $lastInbound = $model->messages->where('direction', 'inbound')->last();
        $from = $this->firstEmail($model->mailbox?->email)
            ?? $this->firstEmail($lastInbound?->to)
            ?? $original->from_email;
        $fromName = $model->mailbox?->display_name;
        $subject = preg_match('/^(fwd?|fw)\s*:/i', (string) $original->subject) ? $original->subject : 'Fwd: '.$original->subject;
        $note = trim((string) ($validated['body'] ?? ''));
        $signed = $signatures->apply($note === '' ? '' : '<p>'.e($note).'</p>', null, $signatures->resolve($organization, $from));
        $originalFrom = $original->from_name ? e($original->from_name).' &lt;'.e($original->from_email).'&gt;' : e($original->from_email);
        $date = ($original->sent_at ?? $original->created_at)?->format('D, j M Y \a\t H:i');
        $html = ($signed['html'] ?? '')
            .'<div style="margin-top:20px">---------- Forwarded message ---------<br>From: '.$originalFrom.'<br>Date: '.e((string) $date).'<br>Subject: '.e((string) $original->subject).'</div><br>'
            .'<div>'.($original->html_body ?: nl2br(e((string) $original->text_body))).'</div>';

        $message = $emails->send($organization, [
            'from' => $fromName ? "{$fromName} <{$from}>" : $from,
            'to' => $validated['to'],
            'subject' => $subject,
            'html' => $html,
            'text' => trim($note."\n\n---------- Forwarded message ---------\n".$original->text_body),
            'design' => DesignTemplates::defaultKey($organization),
            'signature' => false,
        ], files: $original->attachments->map(fn ($attachment) => $attachment->toUploadedFile())->filter()->values()->all(), thread: $model);

        return $this->sendResult($message);
    }

    public function compose(Request $request, EmailService $emails): JsonResponse
    {
        $organization = $this->organization($request);
        $organization->loadMissing('mailProvider');

        $validated = $request->validate([
            'to' => ['required', 'string', 'max:2000', AddressList::rule()],
            'cc' => ['nullable', 'string', 'max:2000', AddressList::rule()],
            'bcc' => ['nullable', 'string', 'max:2000', AddressList::rule()],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:20000'],
            'file' => ['nullable', 'file', 'max:10240', new AllowedAttachmentFile],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*' => ['file', 'max:10240', new AllowedAttachmentFile],
        ], [
            'files.max' => 'You can attach up to 10 files.',
        ]);

        if (! $organization->mailProvider || $organization->mailProvider->status !== 'active') {
            return response()->json(['message' => 'Cannot send — this workspace has no active mail provider.'], 422);
        }

        $from = $this->access->mailboxFor($request->user(), $organization)?->email
            ?? $organization->domains()->where('status', 'verified')->value('name');
        $fromAddress = is_string($from) && str_contains($from, '@') ? $from : 'hello@'.$from;

        $message = $emails->send($organization, [
            'from' => $fromAddress,
            'to' => $validated['to'],
            'cc' => $validated['cc'] ?? null,
            'bcc' => $validated['bcc'] ?? null,
            'subject' => $validated['subject'],
            'html' => '<p>'.e($validated['body']).'</p>',
            'text' => $validated['body'],
            'design' => DesignTemplates::defaultKey($organization),
            'signature' => true,
        ], files: $this->uploadedFiles($request));

        return $this->sendResult($message);
    }

    public function read(Request $request, int $thread): JsonResponse
    {
        return $this->setRead($request, $thread, true, 'Marked as read.');
    }

    public function unread(Request $request, int $thread): JsonResponse
    {
        return $this->setRead($request, $thread, false, 'Marked as unread.');
    }

    private function setRead(Request $request, int $thread, bool $read, string $message): JsonResponse
    {
        $model = $this->thread($request, $thread, withMessages: false);
        $model->forceFill(['is_read' => $read])->save();

        return response()->json([
            'message' => $message,
            'data' => $this->card($model),
        ]);
    }

    public function archive(Request $request, int $thread): JsonResponse
    {
        return $this->flip($request, $thread, 'is_archived', 'Archived.');
    }

    public function spam(Request $request, int $thread): JsonResponse
    {
        return $this->flip($request, $thread, 'is_spam', 'Marked as spam.');
    }

    public function trash(Request $request, int $thread): JsonResponse
    {
        $model = $this->thread($request, $thread, withMessages: false);
        $trashed = ! $model->is_trashed;
        $model->forceFill([
            'is_trashed' => $trashed,
            'trashed_at' => $trashed ? now() : null,
        ])->save();

        return response()->json(['message' => $trashed ? 'Moved to trash.' : 'Restored.', 'data' => $this->card($model)]);
    }

    public function attachment(Request $request, int $attachment): StreamedResponse
    {
        $organization = $this->organization($request);
        $mailboxId = $this->access->scopedMailboxId($request->user(), $organization);

        $model = Attachment::query()
            ->whereHas('message', function ($query) use ($organization, $mailboxId) {
                $query->where('organization_id', $organization->id);
                if ($mailboxId !== null) {
                    $query->where('mailbox_id', $mailboxId);
                }
            })
            ->findOrFail($attachment);

        $disk = Storage::disk($model->disk ?: 'local');
        abort_unless($disk->exists($model->path), 404, 'This attachment file is missing from storage.');

        $inline = $request->boolean('inline') && ($model->isPreviewable() || str_starts_with(strtolower((string) $model->content_type), 'video/'));

        return $inline
            ? $disk->response($model->path, $model->filename, ['Content-Type' => $model->content_type, 'X-Content-Type-Options' => 'nosniff'], 'inline')
            : $disk->download($model->path, $model->filename, ['X-Content-Type-Options' => 'nosniff']);
    }

    public function drafts(Request $request): JsonResponse
    {
        $drafts = MailDraft::query()
            ->where('organization_id', $this->organization($request)->id)
            ->where('user_id', $request->user()->id)
            ->latest()
            ->limit(50)
            ->get();

        return response()->json([
            'data' => $drafts->map(fn (MailDraft $draft) => [
                'id' => $draft->id,
                'to' => $draft->to,
                'cc' => $draft->cc,
                'bcc' => $draft->bcc,
                'subject' => $draft->subject,
                'body' => $this->draftText($draft->html),
                'updated' => $draft->updated_at?->diffForHumans() ?? '',
            ])->values(),
        ]);
    }

    public function storeDraft(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id' => ['nullable', 'integer'],
            'to' => ['nullable', 'string', 'max:2000'],
            'cc' => ['nullable', 'string', 'max:2000'],
            'bcc' => ['nullable', 'string', 'max:2000'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:20000'],
        ]);

        $attributes = [
            'to' => $validated['to'] ?? null,
            'cc' => $validated['cc'] ?? null,
            'bcc' => $validated['bcc'] ?? null,
            'subject' => $validated['subject'] ?? null,
            'html' => isset($validated['body']) ? '<p>'.e($validated['body']).'</p>' : null,
        ];

        if ($request->filled('id')) {
            $draft = MailDraft::query()
                ->where('organization_id', $this->organization($request)->id)
                ->where('user_id', $request->user()->id)
                ->findOrFail($request->integer('id'));
            $draft->update($attributes);

            return response()->json([
                'data' => ['id' => $draft->id, 'subject' => $draft->subject],
            ]);
        }

        $draft = MailDraft::query()->create([
            'organization_id' => $this->organization($request)->id,
            'user_id' => $request->user()->id,
            ...$attributes,
        ]);

        return response()->json([
            'data' => ['id' => $draft->id, 'subject' => $draft->subject],
        ], 201);
    }

    public function destroyDraft(Request $request, int $draft): JsonResponse
    {
        MailDraft::query()
            ->where('organization_id', $this->organization($request)->id)
            ->where('user_id', $request->user()->id)
            ->whereKey($draft)
            ->delete();

        return response()->json(['message' => 'Draft deleted.']);
    }

    private function draftText(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $withBreaks = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
        $withBreaks = preg_replace('/<\/p>/i', "\n", $withBreaks) ?? $withBreaks;

        return trim(html_entity_decode(strip_tags($withBreaks)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sent(Request $request): array
    {
        $organization = $this->organization($request);

        return $this->access->scopeMailData($organization->messages(), $request->user(), $organization)
            ->where('direction', 'outbound')
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (Message $message) => [
                'id' => $message->thread_id ?: $message->id,
                'thread_id' => $message->thread_id,
                'subject' => $message->subject,
                'snippet' => EmailSnippet::from($message->text_body, $message->html_body, 140),
                'from_name' => $message->toSentArray()['to'] ?? '',
                'from_email' => $message->toSentArray()['to'] ?? '',
                'unread' => false,
                'updated' => $message->created_at?->diffForHumans() ?? '',
                'labels' => [],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function card(Thread $thread): array
    {
        $latest = $thread->relationLoaded('messages') ? $thread->messages->first() : null;
        $ai = is_array($thread->ai) ? $thread->ai : [];

        return [
            'id' => $thread->id,
            'subject' => $thread->subject,
            'snippet' => EmailSnippet::display($thread->snippet),
            'from_name' => $latest?->senderName(),
            'from_email' => $latest?->from_email,
            'unread' => ! $thread->is_read,
            'updated' => $thread->last_message_at?->diffForHumans() ?? '',
            'labels' => array_values(array_filter([
                isset($ai['priority']) && is_string($ai['priority']) ? ucfirst($ai['priority']) : null,
                isset($ai['intent']) && is_string($ai['intent']) ? ucfirst($ai['intent']) : null,
            ])),
        ];
    }

    /**
     * @param  array<string, mixed>  $thread
     * @return array<string, mixed>
     */
    private function withMobileAttachmentUrls(array $thread): array
    {
        $messages = $thread['messages'] ?? [];
        foreach ($messages as $index => $message) {
            foreach ($message['attachments'] ?? [] as $attachmentIndex => $attachment) {
                $id = $attachment['id'];
                $messages[$index]['attachments'][$attachmentIndex]['url'] = url('/api/app/inbox/attachments/'.$id);
                $messages[$index]['attachments'][$attachmentIndex]['preview_url'] = ($attachment['previewable'] ?? false) || ($attachment['is_video'] ?? false)
                    ? url('/api/app/inbox/attachments/'.$id.'?inline=1')
                    : null;
            }
        }
        $thread['messages'] = $messages;

        return $thread;
    }

    private function flip(Request $request, int $thread, string $column, string $message): JsonResponse
    {
        $model = $this->thread($request, $thread, withMessages: false);
        $model->forceFill([$column => ! $model->{$column}])->save();

        return response()->json(['message' => $message, 'data' => $this->card($model)]);
    }

    private function thread(Request $request, int $thread, bool $withMessages = true): Thread
    {
        $query = $this->access->scopeMailData($this->organization($request)->threads(), $request->user(), $this->organization($request));

        if ($withMessages) {
            $query->with(['mailbox', 'messages' => fn ($inner) => $inner->orderBy('created_at'), 'messages.attachments']);
        }

        $model = $query->find($thread);

        if (! $model instanceof Thread) {
            abort(404, 'This conversation is no longer available.');
        }

        return $model;
    }

    private function sendResult(Message $message): JsonResponse
    {
        if (in_array($message->status, ['failed', 'suppressed'], true)) {
            return response()->json([
                'message' => data_get($message->meta, 'error', 'The message was not sent.'),
            ], 422);
        }

        return response()->json([
            'message' => 'Sent.',
            'data' => ['id' => $message->id, 'thread_id' => $message->thread_id, 'status' => $message->status],
        ], 201);
    }

    private function organization(Request $request): Organization
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('organization');

        return $organization;
    }

    private function firstEmail(mixed $addresses): ?string
    {
        if (is_string($addresses) && trim($addresses) !== '') {
            return trim($addresses);
        }

        if (! is_array($addresses) || $addresses === []) {
            return null;
        }

        $first = $addresses[0] ?? null;

        if (is_string($first) && trim($first) !== '') {
            return trim($first);
        }

        if (is_array($first)) {
            $email = $first['email'] ?? $first['address'] ?? null;

            return is_string($email) && $email !== '' ? $email : null;
        }

        return null;
    }

    /**
     * @return list<UploadedFile>
     */
    private function uploadedFiles(Request $request): array
    {
        $files = $request->file('files', []);
        if ($files instanceof UploadedFile) {
            $files = [$files];
        }
        if (! is_array($files)) {
            $files = [];
        }

        $single = $request->file('file');
        if ($single instanceof UploadedFile) {
            $files[] = $single;
        }

        return array_values(array_filter($files, fn ($file) => $file instanceof UploadedFile));
    }
}
