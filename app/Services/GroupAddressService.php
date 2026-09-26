<?php

namespace App\Services;

use App\Jobs\FanOutGroupMessage;
use App\Models\GroupAddress;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Suppression;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Group addresses (e.g. staff@company.com) that fan out to their members,
 * both for mail we send and for mail that arrives at the group.
 */
class GroupAddressService
{
    public const HEADER = 'X-MailDesk-Group';

    /**
     * Find the organization's active group for an address, if any.
     */
    public function find(Organization $organization, string $email): ?GroupAddress
    {
        return GroupAddress::query()
            ->where('organization_id', $organization->id)
            ->where('active', true)
            ->where('email', Str::lower(trim($email)))
            ->first();
    }

    /**
     * Replace any group addresses in a recipient list with the group's
     * members. Duplicates are dropped and suppressed members are left out
     * (addresses the sender typed directly are not touched here). Groups are
     * not nested: a member that is itself a group address is skipped.
     *
     * @param  array<int, string>  $emails
     * @param  array<int, string>  $exclude  Addresses already used elsewhere (e.g. in "to").
     * @return array<int, string>
     */
    public function expand(Organization $organization, array $emails, array $exclude = []): array
    {
        if ($emails === []) {
            return [];
        }

        $groups = $this->groupsFor($organization, $emails);
        if ($groups->isEmpty() && $exclude === []) {
            return $emails;
        }

        $groupEmails = $groups->keys()->all();
        $suppressed = $this->suppressedAmong($organization, $groups->flatMap(fn (GroupAddress $g) => $g->members->pluck('email'))->all());

        $seen = array_fill_keys(array_map(fn ($e) => Str::lower(trim($e)), $exclude), true);
        $result = [];

        foreach ($emails as $email) {
            $key = Str::lower(trim($email));
            $group = $groups->get($key);

            $candidates = $group
                ? $group->members->pluck('email')
                    ->map(fn ($m) => Str::lower($m))
                    ->reject(fn ($m) => in_array($m, $groupEmails, true) || isset($suppressed[$m]))
                    ->all()
                : [$email];

            foreach ($candidates as $candidate) {
                $candidateKey = Str::lower(trim($candidate));
                if (isset($seen[$candidateKey])) {
                    continue;
                }
                $seen[$candidateKey] = true;
                $result[] = $candidate;
            }
        }

        return $result;
    }

    /**
     * Fan an inbound message out to the members of any group it was sent to.
     * Each copy goes through the queue (rate limited like broadcasts).
     *
     * @param  array<int, string>  $envelopeRecipients  Envelope/Bcc recipients not visible in To/Cc.
     * @return int Number of copies queued.
     */
    public function routeInbound(Message $message, array $envelopeRecipients = []): int
    {
        $organization = $message->organization;
        if ($organization === null) {
            return 0;
        }

        // Loop protection: never re-expand a copy we produced ourselves.
        $headers = array_change_key_case((array) ($message->headers ?? []), CASE_LOWER);
        if (isset($headers[Str::lower(self::HEADER)])) {
            return 0;
        }

        $recipients = [...(array) ($message->to ?? []), ...(array) ($message->cc ?? []), ...$envelopeRecipients];
        $recipients = array_map(fn ($r) => is_array($r) ? ($r['email'] ?? '') : (string) $r, $recipients);

        $groups = $this->groupsFor($organization, $recipients);
        if ($groups->isEmpty()) {
            return 0;
        }

        $sender = Str::lower((string) $message->from_email);
        $allGroupEmails = GroupAddress::query()
            ->where('organization_id', $organization->id)
            ->pluck('email')
            ->map(fn ($e) => Str::lower($e))
            ->all();
        $alreadyGotIt = array_map(fn ($e) => Str::lower(trim($e)), $recipients);

        $queued = 0;
        $fanout = [];
        $seen = [];

        foreach ($groups as $group) {
            $members = [];
            foreach ($group->members as $member) {
                $email = Str::lower($member->email);
                if ($email === $sender
                    || in_array($email, $allGroupEmails, true)
                    || in_array($email, $alreadyGotIt, true)
                    || isset($seen[$email])) {
                    continue;
                }
                $seen[$email] = true;
                $members[] = $email;

                FanOutGroupMessage::dispatch($message->id, $group->id, $email);
                $queued++;
            }

            $fanout[] = ['id' => $group->id, 'email' => $group->email, 'name' => $group->name, 'members' => $members];
        }

        $message->forceFill([
            'meta' => array_merge((array) ($message->meta ?? []), ['group_fanout' => $fanout]),
        ])->save();

        return $queued;
    }

    /**
     * Send one member their copy of an inbound group message.
     */
    public function deliverCopy(Message $original, GroupAddress $group, string $memberEmail, EmailService $emails): Message
    {
        $original->loadMissing('attachments', 'organization');
        $senderName = $original->from_name ?: $original->from_email;
        $fromName = Str::limit(str_replace(['"', '<', '>'], '', $senderName), 60, '').' via '.$group->name;

        $files = $original->attachments
            ->map(fn ($attachment) => $attachment->toUploadedFile())
            ->filter()
            ->values()
            ->all();

        return $emails->send($original->organization, [
            'from' => ['email' => $group->email, 'name' => $fromName],
            'to' => [['email' => $memberEmail]],
            'reply_to' => [$original->from_email],
            'subject' => $original->subject ?: '(no subject)',
            'html' => $original->html_body,
            'text' => $original->text_body ?? strip_tags((string) $original->html_body),
            'headers' => [self::HEADER => $group->email],
            'tags' => ['group:'.$group->id],
            'thread' => false,
            'expand_groups' => false,
            'signature' => false,
            'meta' => ['fanout_of' => $original->id, 'group_id' => $group->id],
        ], $files);
    }

    /**
     * @param  array<int, string>  $emails
     * @return Collection<string, GroupAddress> keyed by lowercase email
     */
    protected function groupsFor(Organization $organization, array $emails): Collection
    {
        $normalized = array_values(array_unique(array_filter(array_map(fn ($e) => Str::lower(trim((string) $e)), $emails))));
        if ($normalized === []) {
            return collect();
        }

        return GroupAddress::query()
            ->with('members')
            ->where('organization_id', $organization->id)
            ->where('active', true)
            ->whereIn('email', $normalized)
            ->get()
            ->keyBy(fn (GroupAddress $group) => Str::lower($group->email));
    }

    /**
     * @param  array<int, string>  $emails
     * @return array<string, true>
     */
    protected function suppressedAmong(Organization $organization, array $emails): array
    {
        if ($emails === []) {
            return [];
        }

        return array_fill_keys(Suppression::query()
            ->where('organization_id', $organization->id)
            ->whereIn('email', array_map(fn ($e) => Str::lower($e), $emails))
            ->pluck('email')
            ->map(fn ($e) => Str::lower($e))
            ->all(), true);
    }
}
