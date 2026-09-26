<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Services\EmailService;
use App\Support\AddressList;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class EmailController extends Controller
{
    public function index(Request $request): Response
    {
        $organization = CurrentOrganization::from($request);

        $messages = $organization->messages()
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (Message $message) => $message->toWorkspaceArray())
            ->values()
            ->all();

        $since = now()->subDays(15);
        $outbound = $organization->messages()->where('direction', 'outbound');

        $stats = [
            'sent' => (clone $outbound)->where('created_at', '>=', $since)->count(),
            'delivered' => (clone $outbound)->where('status', 'delivered')->where('created_at', '>=', $since)->count(),
            'bounced' => (clone $outbound)->where('status', 'bounced')->where('created_at', '>=', $since)->count(),
            'received' => $organization->messages()
                ->where('direction', 'inbound')
                ->where('created_at', '>=', $since)
                ->count(),
        ];

        return Inertia::render('Emails/Index', [
            'emails' => $messages,
            'stats' => $stats,
        ]);
    }

    public function sent(Request $request): Response
    {
        $organization = CurrentOrganization::from($request);

        $groups = [
            'delivered' => ['delivered'],
            'sent' => ['sent', 'queued'],
            'failed' => ['failed', 'bounced', 'complained', 'suppressed'],
            'scheduled' => ['scheduled'],
        ];

        $filter = array_key_exists($request->string('status')->toString(), $groups)
            ? $request->string('status')->toString()
            : 'all';
        $search = trim($request->string('q')->toString());

        $base = fn () => $organization->messages()->where('direction', 'outbound');

        $query = $base()
            ->when($filter !== 'all', fn ($q) => $q->whereIn('status', $groups[$filter]))
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.$search.'%';
                $q->where(fn ($w) => $w->where('subject', 'like', $like)->orWhere('to', 'like', $like));
            })
            ->orderByRaw('COALESCE(sent_at, created_at) DESC')
            ->orderByDesc('id');

        $paginator = $query->paginate(25)->withQueryString();

        $counts = ['all' => $base()->count()];
        foreach ($groups as $key => $statuses) {
            $counts[$key] = $base()->whereIn('status', $statuses)->count();
        }

        return Inertia::render('Sent/Index', [
            'emails' => collect($paginator->items())
                ->map(fn (Message $message) => $message->toSentArray())
                ->values()
                ->all(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'prev_url' => $paginator->previousPageUrl(),
                'next_url' => $paginator->nextPageUrl(),
            ],
            'counts' => $counts,
            'filters' => ['status' => $filter, 'q' => $search],
        ]);
    }

    /**
     * Resend a failed outbound email (inbox replies included) without retyping it.
     */
    public function retry(Request $request, string $id, EmailService $emails): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        $organization->loadMissing('mailProvider');

        /** @var Message $message */
        $message = $organization->messages()
            ->where('uuid', $id)
            ->where('direction', 'outbound')
            ->firstOrFail();

        if ($message->status !== 'failed') {
            return back()->with('error', 'Only failed emails can be retried.');
        }

        if (! $organization->mailProvider || $organization->mailProvider->status !== 'active') {
            return back()->with('error', 'Cannot send — this workspace has no active mail provider.');
        }

        $message = $emails->retry($organization, $message);

        return match ($message->status) {
            'failed' => back()->with('error', 'Retry failed: '.data_get($message->meta, 'error', 'Provider rejected the message.')),
            'suppressed' => back()->with('error', data_get($message->meta, 'error', 'Recipient is suppressed.')),
            default => back()->with('success', 'Email resent.'),
        };
    }

    public function show(Request $request, string $id): Response
    {
        $organization = CurrentOrganization::from($request);

        /** @var Message $message */
        $message = $organization->messages()
            ->where('uuid', $id)
            ->firstOrFail();

        return Inertia::render('Emails/Show', [
            'email' => $message->toWorkspaceArray(detailed: true),
        ]);
    }

    public function store(Request $request, EmailService $emails): RedirectResponse
    {
        $organization = CurrentOrganization::from($request);
        $organization->loadMissing('mailProvider');

        if (! $organization->mailProvider || $organization->mailProvider->status !== 'active') {
            return back()->with('error', 'Cannot send — this workspace has no active mail provider.');
        }

        $validated = $request->validate([
            'from' => ['required', 'string', 'max:255'],
            'to' => ['required', 'string', 'max:2000', AddressList::rule()],
            'subject' => ['required', 'string', 'max:998'],
            'html' => ['nullable', 'string'],
            'text' => ['nullable', 'string'],
            'cc' => ['nullable', 'string', 'max:2000', AddressList::rule()],
            'bcc' => ['nullable', 'string', 'max:2000', AddressList::rule()],
            'reply_to' => ['nullable', 'string', 'max:255'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:64'],
            'schedule' => ['sometimes', 'boolean'],
            'schedule_at' => ['nullable', 'date', 'after:now'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:10240'],
        ]);

        $fromEmail = $this->extractEmail($validated['from']);
        $domainName = Str::after($fromEmail, '@');

        $hasVerifiedDomains = $organization->domains()->where('status', 'verified')->exists();
        $verified = $organization->domains()
            ->where('status', 'verified')
            ->where('name', $domainName)
            ->exists();

        if ($hasVerifiedDomains && ! $verified) {
            return back()->withErrors([
                'from' => 'From address must use a verified domain for this workspace.',
            ]);
        }

        $payload = [
            'from' => $validated['from'],
            'to' => $validated['to'],
            'subject' => $validated['subject'],
            'html' => $validated['html'] ?? null,
            'text' => $validated['text'] ?? strip_tags($validated['html'] ?? ''),
            'tags' => $validated['tags'] ?? null,
            'signature' => $request->boolean('signature', true),
        ];

        if (! empty($validated['cc'])) {
            $payload['cc'] = $validated['cc'];
        }

        if (! empty($validated['bcc'])) {
            $payload['bcc'] = $validated['bcc'];
        }

        if (! empty($validated['reply_to'])) {
            $payload['reply_to'] = $validated['reply_to'];
        }

        if ($request->boolean('schedule')) {
            $scheduleAt = $validated['schedule_at'] ?? null;
            if (! $scheduleAt) {
                return back()->withErrors([
                    'schedule_at' => 'Choose a future date and time to schedule this send.',
                ]);
            }
            $payload['scheduled_at'] = Carbon::parse($scheduleAt);
        }

        $files = $request->file('attachments', []) ?: [];
        if (! is_array($files)) {
            $files = [$files];
        }

        $message = $emails->send($organization, $payload, $files);

        if ($request->boolean('stay')) {
            return $this->stayResponse($message);
        }

        if ($message->status === 'scheduled') {
            return redirect()
                ->route('emails.show', $message->uuid)
                ->with('success', 'Email scheduled.');
        }

        if ($message->status === 'suppressed') {
            $error = data_get($message->meta, 'error', 'Recipient is suppressed.');

            return redirect()
                ->route('emails.show', $message->uuid)
                ->with('error', $error);
        }

        if ($message->status === 'failed') {
            $error = data_get($message->meta, 'error', 'Provider rejected the message.');

            return redirect()
                ->route('emails.show', $message->uuid)
                ->with('error', $error);
        }

        return redirect()
            ->route('emails.show', $message->uuid)
            ->with('success', 'Email sent.');
    }

    private function extractEmail(string $from): string
    {
        if (preg_match('/<(.+)>/', $from, $matches)) {
            return strtolower(trim($matches[1]));
        }

        return strtolower(trim($from));
    }

    /**
     * Keep the user where they are (the floating compose panel) instead of
     * navigating to the email's page.
     */
    private function stayResponse(Message $message): RedirectResponse
    {
        return match ($message->status) {
            'scheduled' => back()->with('success', 'Email scheduled.'),
            'suppressed' => back()->with('error', data_get($message->meta, 'error', 'Recipient is suppressed.')),
            'failed' => back()->with('error', data_get($message->meta, 'error', 'Provider rejected the message.')),
            default => back()->with('success', 'Email sent.'),
        };
    }
}
