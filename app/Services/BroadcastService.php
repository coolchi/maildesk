<?php

namespace App\Services;

use App\Jobs\SendBroadcast;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Suppression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class BroadcastService
{
    public function __construct(
        protected EmailService $emails,
        protected SignatureService $signatures,
    ) {}

    /**
     * Mark a broadcast queued and hand it to the queue.
     */
    public function queue(Broadcast $broadcast, ?string $audience = null, ?string $from = null): Broadcast
    {
        app(AccountAccess::class)->assertCanSend($broadcast->organization);

        $broadcast->forceFill([
            'audience' => $audience ?? $broadcast->audience ?? 'all',
            'from' => $from ?? $broadcast->from,
            'status' => 'queued',
            'queued_at' => now(),
            'sent_at' => null,
            'completed_at' => null,
        ])->save();

        SendBroadcast::dispatch($broadcast->id);

        return $broadcast;
    }

    /**
     * Snapshot the audience into recipient rows. Unsubscribed and suppressed
     * contacts are recorded as "skipped" so the counts explain the gap.
     */
    public function buildRecipients(Broadcast $broadcast): int
    {
        $organization = $broadcast->organization;
        $audience = (string) ($broadcast->audience ?: 'all');

        if (str_starts_with($audience, 'group:')) {
            return $this->buildGroupRecipients($broadcast, (int) substr($audience, 6));
        }

        $query = $organization->contacts()->orderBy('id');

        if ($audience !== 'all' && ctype_digit($audience)) {
            $query->whereHas('segments', fn ($q) => $q->where('segments.id', (int) $audience));
        }

        $suppressed = array_flip(Suppression::query()
            ->where('organization_id', $organization->id)
            ->pluck('email')
            ->map(fn (string $email) => Str::lower($email))
            ->all());

        $query->chunkById(500, function ($contacts) use ($broadcast, $suppressed) {
            $rows = [];
            $now = now();

            /** @var Contact $contact */
            foreach ($contacts as $contact) {
                $email = Str::lower(trim($contact->email));
                $reason = match (true) {
                    $contact->unsubscribed_at !== null || (($contact->meta['status'] ?? null) === 'unsubscribed') => 'Unsubscribed',
                    isset($suppressed[$email]) => 'On the suppression list',
                    default => null,
                };

                $rows[] = [
                    'broadcast_id' => $broadcast->id,
                    'contact_id' => $contact->id,
                    'email' => $email,
                    'status' => $reason ? 'skipped' : 'pending',
                    'error' => $reason,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            BroadcastRecipient::query()->insertOrIgnore($rows);
        });

        $count = $broadcast->recipients()->count();
        $broadcast->forceFill(['recipient_count' => $count])->save();

        return $count;
    }

    /**
     * Send the broadcast to one recipient with a personal unsubscribe link.
     */
    public function sendTo(BroadcastRecipient $recipient): Message
    {
        $broadcast = $recipient->broadcast;
        $organization = $broadcast->organization;
        $unsubscribeUrl = $this->unsubscribeUrl($recipient);
        $contact = $recipient->contact;

        $body = (string) $broadcast->html;
        $from = $broadcast->from ?: 'hello@'.$organization->slug.'.test';

        // Signature sits above the unsubscribe footer.
        if ($this->signatures->settings($organization)['broadcasts']) {
            $body = (string) $this->signatures->apply($body, null, $this->signatures->resolve($organization, $this->emailOf($from)))['html'];
        }

        $html = $this->personalize($body, $recipient, $contact, $unsubscribeUrl);

        $message = $this->emails->send($organization, [
            'from' => $from,
            'to' => [['email' => $recipient->email]],
            'subject' => $broadcast->subject,
            'html' => $html,
            'text' => trim(html_entity_decode(strip_tags($html)))."\n\nUnsubscribe: ".$unsubscribeUrl,
            'tags' => ['broadcast:'.$broadcast->id],
            'headers' => [
                'List-Unsubscribe' => '<'.$unsubscribeUrl.'>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            ],
            // One message per recipient; keep them out of the shared inbox.
            'thread' => false,
            'signature' => false,
            'expand_groups' => false,
            'meta' => ['broadcast_id' => $broadcast->id],
        ]);

        $recipient->forceFill([
            'message_id' => $message->id,
            'status' => match ($message->status) {
                'failed' => 'failed',
                'suppressed' => 'suppressed',
                default => 'sent',
            },
            'error' => $message->status === 'sent' ? null : (mb_substr((string) ($message->meta['error'] ?? ''), 0, 250) ?: null),
        ])->save();

        return $message;
    }

    /**
     * Close out the broadcast once no recipient is still pending.
     */
    public function finalizeIfDone(Broadcast $broadcast): void
    {
        if ($broadcast->recipients()->where('status', 'pending')->exists()) {
            return;
        }

        $attempted = $broadcast->recipients()->whereIn('status', ['sent', 'failed'])->count();
        $failed = $broadcast->recipients()->where('status', 'failed')->count();
        $status = ($attempted > 0 && $failed === $attempted) ? 'failed' : 'sent';

        // Guarded update so two workers finishing together only close it once.
        Broadcast::query()
            ->whereKey($broadcast->id)
            ->whereIn('status', ['queued', 'sending'])
            ->update([
                'status' => $status,
                'sent_at' => now(),
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

        $broadcast->refresh();
    }

    public function unsubscribeUrl(BroadcastRecipient $recipient): string
    {
        // Signed over the path only, so the link works on whichever host
        // (tenant subdomain or custom domain) the app is served from. The
        // same URL accepts GET (confirm page) and one-click POST.
        return url(URL::signedRoute('unsubscribe.show', ['recipient' => $recipient->id], absolute: false));
    }

    /**
     * Unsubscribe the recipient's contact from future broadcasts and add the
     * address to the organization's suppression list.
     */
    public function unsubscribe(BroadcastRecipient $recipient): void
    {
        $broadcast = $recipient->broadcast;

        DB::transaction(function () use ($recipient, $broadcast) {
            if ($recipient->unsubscribed_at === null) {
                $recipient->forceFill(['unsubscribed_at' => now()])->save();
            }

            $contact = $recipient->contact
                ?? Contact::query()
                    ->where('organization_id', $broadcast->organization_id)
                    ->whereRaw('lower(email) = ?', [$recipient->email])
                    ->first();

            if ($contact !== null && $contact->unsubscribed_at === null) {
                $contact->forceFill([
                    'unsubscribed_at' => now(),
                    'meta' => array_merge((array) ($contact->meta ?? []), ['status' => 'unsubscribed']),
                ])->save();
            }

            Suppression::query()->firstOrCreate(
                ['organization_id' => $broadcast->organization_id, 'email' => $recipient->email],
                ['reason' => 'Unsubscribed from broadcast "'.Str::limit($broadcast->name, 80).'"', 'source' => 'unsubscribe'],
            );
        });
    }

    /**
     * Delivery counts for a broadcast, from its recipient rows and the
     * messages they produced (which delivery webhooks keep up to date).
     *
     * @return array<string, int>
     */
    public function counts(Broadcast $broadcast): array
    {
        $byStatus = $broadcast->recipients()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $messages = Message::query()
            ->whereIn('id', $broadcast->recipients()->whereNotNull('message_id')->select('message_id'));

        $byMessage = (clone $messages)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $opened = (clone $messages)->whereNotNull('meta->first_opened_at')->count();
        $clicked = (clone $messages)->whereNotNull('meta->first_clicked_at')->count();

        $accepted = (int) $byStatus->get('sent', 0);

        return [
            'recipients' => (int) $byStatus->sum(),
            'pending' => (int) $byStatus->get('pending', 0),
            'sent' => $accepted,
            'failed' => (int) $byStatus->get('failed', 0),
            'skipped' => (int) $byStatus->get('skipped', 0) + (int) $byStatus->get('suppressed', 0),
            'delivered' => (int) $byMessage->get('delivered', 0),
            'bounced' => (int) $byMessage->get('bounced', 0),
            'complained' => (int) $byMessage->get('complained', 0),
            'opened' => $opened,
            'clicked' => $clicked,
            'unsubscribed' => $broadcast->recipients()->whereNotNull('unsubscribed_at')->count(),
        ];
    }

    /**
     * Recipients for a group-address audience: every member of the group.
     * Members that match a contact are linked to it, and unsubscribed or
     * suppressed addresses are recorded as skipped.
     */
    protected function buildGroupRecipients(Broadcast $broadcast, int $groupId): int
    {
        $organization = $broadcast->organization;
        $group = $organization->groupAddresses()->with('members')->find($groupId);

        $members = $group?->members ?? collect();
        $emails = $members->map(fn ($m) => Str::lower(trim($m->email)))->unique()->values();

        $contacts = Contact::query()
            ->where('organization_id', $organization->id)
            ->whereIn(DB::raw('lower(email)'), $emails->all())
            ->get()
            ->keyBy(fn (Contact $c) => Str::lower(trim($c->email)));

        $suppressed = array_flip(Suppression::query()
            ->where('organization_id', $organization->id)
            ->whereIn('email', $emails->all())
            ->pluck('email')
            ->map(fn (string $email) => Str::lower($email))
            ->all());

        $groupEmails = $organization->groupAddresses()->pluck('email')->map(fn ($e) => Str::lower($e))->all();
        $now = now();
        $rows = [];

        foreach ($emails as $email) {
            if (in_array($email, $groupEmails, true)) {
                continue;
            }

            /** @var Contact|null $contact */
            $contact = $contacts->get($email);
            $reason = match (true) {
                $contact !== null && ($contact->unsubscribed_at !== null || (($contact->meta['status'] ?? null) === 'unsubscribed')) => 'Unsubscribed',
                isset($suppressed[$email]) => 'On the suppression list',
                default => null,
            };

            $rows[] = [
                'broadcast_id' => $broadcast->id,
                'contact_id' => $contact?->id,
                'email' => $email,
                'status' => $reason ? 'skipped' : 'pending',
                'error' => $reason,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            BroadcastRecipient::query()->insertOrIgnore($chunk);
        }

        $count = $broadcast->recipients()->count();
        $broadcast->forceFill(['recipient_count' => $count])->save();

        return $count;
    }

    protected function emailOf(string $address): string
    {
        return preg_match('/<(.+)>/', $address, $m) ? trim($m[1]) : trim($address);
    }

    protected function personalize(string $html, BroadcastRecipient $recipient, ?Contact $contact, string $unsubscribeUrl): string
    {
        $escapedUrl = e($unsubscribeUrl);
        $replacements = [
            '{{unsubscribe_url}}' => $escapedUrl,
            '{{ unsubscribe_url }}' => $escapedUrl,
            '{{email}}' => e($recipient->email),
            '{{first_name}}' => e($contact?->first_name ?? ''),
            '{{last_name}}' => e($contact?->last_name ?? ''),
        ];

        $hadPlaceholder = str_contains($html, 'unsubscribe_url');
        $html = strtr($html, $replacements);

        if ($hadPlaceholder) {
            return $html;
        }

        $footer = '<p style="margin-top:32px;font-size:12px;color:#71717a;text-align:center">'
            .'You are receiving this because you subscribed to updates. '
            .'<a href="'.$escapedUrl.'" style="color:#71717a;text-decoration:underline">Unsubscribe</a></p>';

        return str_contains(Str::lower($html), '</body>')
            ? preg_replace('~</body>~i', $footer.'</body>', $html, 1)
            : $html.$footer;
    }
}
