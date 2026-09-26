<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\Thread;
use App\Services\EmailService;
use App\Services\Impersonation\ImpersonationService;
use App\Services\SignatureService;
use App\Services\WorkspaceAccess;
use App\Support\AddressList;
use App\Support\CurrentOrganization;
use App\Support\InboxSyncState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InboxController extends Controller
{
    public function __construct(public WorkspaceAccess $access) {}

    public function index(Request $request): Response
    {
        return $this->folder($request, archived: false);
    }

    public function archiveIndex(Request $request): Response
    {
        return $this->folder($request, archived: true);
    }

    protected function folder(Request $request, bool $archived): Response
    {
        $organization = CurrentOrganization::from($request);
        $user = $request->user();

        $threads = $this->access->scopeMailData($organization->threads(), $user, $organization)
            ->where('is_archived', $archived)
            ->with(['mailbox', 'messages' => fn ($query) => $query->orderBy('created_at'), 'messages.attachments'])
            ->latest('last_message_at')
            ->limit(50)
            ->get()
            ->map(fn (Thread $thread) => $thread->toWorkspaceArray())
            ->values()
            ->all();

        return Inertia::render('Inbox/Index', [
            'folder' => $archived ? 'archive' : 'inbox',
            'threads' => $threads,
            'signatureEnabled' => app(SignatureService::class)->settings($organization)['enabled']
                || filled($this->access->mailboxFor($user, $organization)?->signature),
        ]);
    }

    public function toggleArchive(Request $request, int $thread): RedirectResponse|JsonResponse
    {
        $organization = CurrentOrganization::from($request);

        /** @var Thread $model */
        $model = $this->access->scopeMailData($organization->threads(), $request->user(), $organization)
            ->findOrFail($thread);

        $model->forceFill(['is_archived' => ! $model->is_archived])->save();

        $message = $model->is_archived ? 'Archived.' : 'Moved to inbox.';

        if ($request->wantsJson()) {
            return response()->json([
                'id' => $model->id,
                'is_archived' => $model->is_archived,
                'inbox_unread' => InboxSyncState::for(
                    $organization,
                    $this->access->scopedMailboxId($request->user(), $organization),
                )['unread'],
            ]);
        }

        return back()->with('success', $message);
    }

    /**
     * Lightweight poll for the sidebar badge and open-inbox refresh
     * (fallback when the WebSocket is disconnected).
     */
    public function sync(Request $request): JsonResponse
    {
        $organization = CurrentOrganization::from($request);
        $mailboxId = $this->access->scopedMailboxId($request->user(), $organization);

        return response()->json(InboxSyncState::for($organization, $mailboxId));
    }

    public function show(Request $request, int $thread): Response
    {
        $organization = CurrentOrganization::from($request);

        /** @var Thread $model */
        $model = $this->access->scopeMailData($organization->threads(), $request->user(), $organization)
            ->with(['mailbox', 'messages' => fn ($query) => $query->orderBy('created_at'), 'messages.attachments'])
            ->findOrFail($thread);

        // Viewing as an impersonating admin must not change read state.
        if (! $model->is_read && ! app(ImpersonationService::class)->isImpersonating($request)) {
            $model->forceFill(['is_read' => true])->save();
        }

        return Inertia::render('Inbox/Show', [
            'thread' => $model->toWorkspaceArray(),
        ]);
    }

    public function markRead(Request $request, int $thread): JsonResponse
    {
        $organization = CurrentOrganization::from($request);
        $user = $request->user();
        $validated = $request->validate(['read' => ['required', 'boolean']]);

        /** @var Thread $model */
        $model = $this->access->scopeMailData($organization->threads(), $user, $organization)
            ->findOrFail($thread);
        $model->forceFill(['is_read' => $validated['read']])->save();

        $mailboxId = $this->access->scopedMailboxId($user, $organization);

        return response()->json([
            'id' => $model->id,
            'unread' => ! $model->is_read,
            'inbox_unread' => InboxSyncState::for($organization, $mailboxId)['unread'],
        ]);
    }

    /**
     * Reply to a conversation from the shared inbox, keeping it in the same thread.
     */
    public function reply(Request $request, int $thread, EmailService $emails): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        $organization->loadMissing('mailProvider');

        /** @var Thread $model */
        $model = $this->access->scopeMailData($organization->threads(), $request->user(), $organization)
            ->with(['mailbox', 'messages' => fn ($query) => $query->orderBy('created_at')])
            ->findOrFail($thread);

        $validated = $request->validate([
            'html' => ['required', 'string', 'max:200000'],
            'cc' => ['nullable', 'string', 'max:2000', AddressList::rule()],
            'bcc' => ['nullable', 'string', 'max:2000', AddressList::rule()],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:10240'],
        ]);

        $text = trim(html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], "\n", $validated['html']))));
        if ($text === '') {
            return back()->withErrors(['html' => 'Write a reply before sending.']);
        }

        if (! $organization->mailProvider || $organization->mailProvider->status !== 'active') {
            return back()->with('error', 'Cannot send — this workspace has no active mail provider.');
        }

        /** @var Message|null $lastInbound */
        $lastInbound = $model->messages->where('direction', 'inbound')->last();
        /** @var Message|null $last */
        $last = $model->messages->last();

        if ($lastInbound === null || $last === null) {
            return back()->with('error', 'This conversation has no customer message to reply to.');
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
            'headers' => $headers ?: null,
            'in_reply_to' => $last->message_id_header,
            'references' => $references ?: null,
            'cc' => $validated['cc'] ?? null,
            'bcc' => $validated['bcc'] ?? null,
            'signature' => true,
        ], files: array_values(array_filter((array) $request->file('attachments', []))), thread: $model);

        return match ($message->status) {
            'failed' => back()->with('error', data_get($message->meta, 'error', 'Provider rejected the reply.')),
            'suppressed' => back()->with('error', data_get($message->meta, 'error', 'Recipient is suppressed.')),
            default => back()->with('success', 'Reply sent.'),
        };
    }

    /**
     * Forward a message from a conversation (the latest one, or ?message=ID)
     * to new recipients, with an optional note, the sender's signature and
     * the original attachments. The forward stays on the same thread.
     */
    public function forward(Request $request, int $thread, EmailService $emails, SignatureService $signatures): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        $organization->loadMissing('mailProvider');

        /** @var Thread $model */
        $model = $this->access->scopeMailData($organization->threads(), $request->user(), $organization)
            ->with(['mailbox', 'messages' => fn ($query) => $query->orderBy('created_at'), 'messages.attachments'])
            ->findOrFail($thread);

        $validated = $request->validate([
            'to' => ['required', 'string', 'max:2000', AddressList::rule()],
            'html' => ['nullable', 'string', 'max:200000'],
            'cc' => ['nullable', 'string', 'max:2000', AddressList::rule()],
            'bcc' => ['nullable', 'string', 'max:2000', AddressList::rule()],
            'message' => ['nullable', 'integer'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:10240'],
        ]);

        if (! $organization->mailProvider || $organization->mailProvider->status !== 'active') {
            return back()->with('error', 'Cannot send — this workspace has no active mail provider.');
        }

        /** @var Message|null $original */
        $original = isset($validated['message'])
            ? $model->messages->firstWhere('id', (int) $validated['message'])
            : $model->messages->last();

        if ($original === null) {
            return back()->with('error', 'There is no message to forward.');
        }

        $lastInbound = $model->messages->where('direction', 'inbound')->last();
        $from = $model->mailbox?->email
            ?? collect($lastInbound?->to ?? [])->first()
            ?? $original->from_email;
        $fromName = $model->mailbox?->display_name;

        $subject = preg_match('/^(fwd?|fw)\s*:/i', (string) $original->subject)
            ? $original->subject
            : 'Fwd: '.$original->subject;

        $note = trim(strip_tags((string) ($validated['html'] ?? ''), '<img>')) === '' ? '' : (string) $validated['html'];
        $signed = $signatures->apply($note, null, $signatures->resolve($organization, $from));

        $originalFrom = $original->from_name
            ? e($original->from_name).' &lt;'.e($original->from_email).'&gt;'
            : e($original->from_email);
        $date = ($original->sent_at ?? $original->created_at)?->format('D, j M Y \a\t H:i');
        $originalBody = $original->html_body ?: nl2br(e((string) $original->text_body));

        $html = ($signed['html'] ?? '')
            .'<div style="margin-top:20px;color:#52525b;font-size:13px">---------- Forwarded message ---------<br>'
            .'From: '.$originalFrom.'<br>'
            .'Date: '.e((string) $date).'<br>'
            .'Subject: '.e((string) $original->subject).'<br>'
            .'To: '.e(implode(', ', (array) ($original->to ?? []))).'</div><br>'
            .'<div>'.$originalBody.'</div>';

        $text = trim(($signed['text'] ?? '')."\n\n---------- Forwarded message ---------\n"
            .'From: '.html_entity_decode(strip_tags($originalFrom))."\n"
            .'Date: '.$date."\n"
            .'Subject: '.$original->subject."\n"
            .'To: '.implode(', ', (array) ($original->to ?? []))."\n\n"
            .($original->text_body ?: trim(html_entity_decode(strip_tags((string) $original->html_body)))));

        $files = [
            ...$original->attachments->map(fn ($attachment) => $attachment->toUploadedFile())->filter()->values()->all(),
            ...array_values(array_filter((array) $request->file('attachments', []))),
        ];

        $message = $emails->send($organization, [
            'from' => $fromName ? "{$fromName} <{$from}>" : $from,
            'to' => $validated['to'],
            'cc' => $validated['cc'] ?? null,
            'bcc' => $validated['bcc'] ?? null,
            'subject' => $subject,
            'html' => $html,
            'text' => $text,
            // Already placed above the forwarded block.
            'signature' => false,
            'meta' => ['forwarded_from' => $original->id],
        ], files: array_slice($files, 0, 20), thread: $model);

        return match ($message->status) {
            'failed' => back()->with('error', data_get($message->meta, 'error', 'Provider rejected the forward.')),
            'suppressed' => back()->with('error', data_get($message->meta, 'error', 'Recipient is suppressed.')),
            default => back()->with('success', 'Message forwarded.'),
        };
    }
}
