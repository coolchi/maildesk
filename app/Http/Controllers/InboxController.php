<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\Thread;
use App\Services\EmailService;
use App\Support\AddressList;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InboxController extends Controller
{
    public function index(Request $request): Response
    {
        $organization = CurrentOrganization::from($request);

        $threads = $organization->threads()
            ->with(['mailbox', 'messages' => fn ($query) => $query->orderBy('created_at'), 'messages.attachments'])
            ->latest('last_message_at')
            ->limit(50)
            ->get()
            ->map(fn (Thread $thread) => $thread->toWorkspaceArray())
            ->values()
            ->all();

        return Inertia::render('Inbox/Index', [
            'threads' => $threads,
        ]);
    }

    public function show(Request $request, int $thread): Response
    {
        $organization = CurrentOrganization::from($request);

        /** @var Thread $model */
        $model = $organization->threads()
            ->with(['mailbox', 'messages' => fn ($query) => $query->orderBy('created_at'), 'messages.attachments'])
            ->findOrFail($thread);

        if (! $model->is_read) {
            $model->forceFill(['is_read' => true])->save();
        }

        return Inertia::render('Inbox/Show', [
            'thread' => $model->toWorkspaceArray(),
        ]);
    }

    public function markRead(Request $request, int $thread): JsonResponse
    {
        $organization = CurrentOrganization::from($request);
        $validated = $request->validate(['read' => ['required', 'boolean']]);

        /** @var Thread $model */
        $model = $organization->threads()->findOrFail($thread);
        $model->forceFill(['is_read' => $validated['read']])->save();

        return response()->json([
            'id' => $model->id,
            'unread' => ! $model->is_read,
            'inbox_unread' => $organization->threads()->where('is_read', false)->count(),
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
        $model = $organization->threads()
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
        ], files: array_values(array_filter((array) $request->file('attachments', []))), thread: $model);

        return match ($message->status) {
            'failed' => back()->with('error', data_get($message->meta, 'error', 'Provider rejected the reply.')),
            'suppressed' => back()->with('error', data_get($message->meta, 'error', 'Recipient is suppressed.')),
            default => back()->with('success', 'Reply sent.'),
        };
    }
}
