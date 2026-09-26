<?php

namespace App\Services;

use App\Models\Mailbox;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Product and management abilities for a user inside a workspace.
 *
 * Team (owner/admin) gets everything. Members are gated by their linked
 * mailbox Inbox / Transactional / Marketing flags.
 */
class WorkspaceAccess
{
    /**
     * Route-name patterns → required ability.
     * First match wins. Unlisted authenticated workspace routes stay open
     * (profile, help, logout, impersonate leave, workspace switch).
     *
     * @var array<string, string>
     */
    public const ROUTE_ABILITIES = [
        'users*' => 'manage',
        'team.impersonate' => 'manage',
        'groups*' => 'manage',
        'domains*' => 'manage',
        'metrics*' => 'manage',
        'logs*' => 'manage',
        'api-keys*' => 'manage',
        'webhooks*' => 'manage',
        'settings*' => 'manage',
        'billing*' => 'manage',
        'suppressions*' => 'manage',
        'docs' => 'manage',

        'inbox*' => 'inbox',
        'sent*' => 'inbox',
        'bounced*' => 'manage',

        'broadcasts*' => 'marketing',
        'automations*' => 'marketing',
        'templates*' => 'marketing',
        'audience*' => 'marketing',

        'emails.store' => 'mail',
        'emails*' => 'manage',
        'compose' => 'mail',
        'attachments*' => 'mail',

        'mailbox.signature*' => 'inbox',
        'archive*' => 'inbox',
        'drafts*' => 'mail',
        'inbox.archive' => 'inbox',
    ];

    public function orgRole(User $user, Organization $organization): ?string
    {
        return $organization->users()->whereKey($user->id)->first()?->pivot?->role;
    }

    public function isTeam(User $user, Organization $organization): bool
    {
        if ($user->isPlatformAdmin()) {
            return true;
        }

        return in_array($this->orgRole($user, $organization), ['owner', 'admin'], true);
    }

    public function mailboxFor(User $user, Organization $organization): ?Mailbox
    {
        return $organization->mailboxes()->where('user_id', $user->id)->first();
    }

    /**
     * Mailbox ID to restrict mail data to. Null means the actor may see the
     * whole workspace (team, or legacy members without a linked mailbox).
     */
    public function scopedMailboxId(User $user, Organization $organization): ?int
    {
        if ($this->isTeam($user, $organization)) {
            return null;
        }

        return $this->mailboxFor($user, $organization)?->id;
    }

    /**
     * Limit a threads/messages query to the actor's mailbox when scoped.
     *
     * @param  Builder|Relation  $query
     * @return Builder|Relation
     */
    public function scopeMailData($query, User $user, Organization $organization)
    {
        $mailboxId = $this->scopedMailboxId($user, $organization);

        if ($mailboxId !== null) {
            $query->where('mailbox_id', $mailboxId);
        }

        return $query;
    }

    /**
     * From addresses the actor may use when composing. Null keeps workspace defaults.
     *
     * @return list<string>|null
     */
    public function sendingFromAddresses(User $user, Organization $organization): ?array
    {
        if ($this->isTeam($user, $organization)) {
            return null;
        }

        $mailbox = $this->mailboxFor($user, $organization);

        if (! $mailbox || $mailbox->email === '') {
            return null;
        }

        return [strtolower($mailbox->email)];
    }

    /**
     * Whether the actor may send as this From address.
     */
    public function maySendAs(User $user, Organization $organization, string $fromEmail): bool
    {
        $allowed = $this->sendingFromAddresses($user, $organization);

        if ($allowed === null) {
            return true;
        }

        return in_array(strtolower(trim($fromEmail)), $allowed, true);
    }

    /**
     * @return array{inbox: bool, transactional: bool, marketing: bool, manage: bool, mail: bool}
     */
    public function abilities(?User $user, ?Organization $organization): array
    {
        $none = [
            'inbox' => false,
            'transactional' => false,
            'marketing' => false,
            'manage' => false,
            'mail' => false,
        ];

        if (! $user || ! $organization) {
            return $none;
        }

        if ($this->isTeam($user, $organization)) {
            return [
                'inbox' => true,
                'transactional' => true,
                'marketing' => true,
                'manage' => true,
                'mail' => true,
            ];
        }

        $mailbox = $this->mailboxFor($user, $organization);

        if (! $mailbox) {
            // Legacy org members without a mailbox keep product access until
            // they are converted to mailbox sign-in users.
            return [
                'inbox' => true,
                'transactional' => true,
                'marketing' => true,
                'manage' => false,
                'mail' => true,
            ];
        }

        if ($mailbox->status !== 'active') {
            return $none;
        }

        $inbox = (bool) $mailbox->inbox;
        $transactional = (bool) $mailbox->transactional;
        $marketing = (bool) $mailbox->marketing;

        return [
            'inbox' => $inbox,
            'transactional' => $transactional,
            'marketing' => $marketing,
            'manage' => false,
            'mail' => $inbox || $transactional,
        ];
    }

    public function can(User $user, Organization $organization, string $ability): bool
    {
        $abilities = $this->abilities($user, $organization);

        return (bool) ($abilities[$ability] ?? false);
    }

    public function abilityForRoute(?string $routeName): ?string
    {
        if ($routeName === null) {
            return null;
        }

        foreach (self::ROUTE_ABILITIES as $pattern => $ability) {
            if ($routeName === $pattern || str($routeName)->is($pattern)) {
                return $ability;
            }
        }

        return null;
    }

    public function homeRoute(User $user, Organization $organization): string
    {
        $abilities = $this->abilities($user, $organization);

        if ($abilities['inbox']) {
            return 'inbox';
        }

        if ($abilities['manage']) {
            return 'emails';
        }

        if ($abilities['marketing']) {
            return 'broadcasts';
        }

        if ($abilities['mail']) {
            return 'compose';
        }

        return 'profile.edit';
    }

    /**
     * Whether a mailbox-linked member may sign in to this workspace.
     */
    public function mailboxLoginAllowed(User $user): bool
    {
        if ($user->isPlatformAdmin()) {
            return true;
        }

        $mailboxes = Mailbox::query()->where('user_id', $user->id)->get();

        if ($mailboxes->isEmpty()) {
            // Owners/admins without a personal mailbox may still sign in.
            return $user->organizations()->wherePivotIn('role', ['owner', 'admin'])->exists()
                || $user->organizations()->exists();
        }

        return $mailboxes->contains(fn (Mailbox $mailbox) => $mailbox->status === 'active');
    }

    /**
     * Message shown when a mailbox-linked member cannot sign in.
     */
    public function mailboxLoginDeniedMessage(User $user): string
    {
        $mailboxes = Mailbox::query()->where('user_id', $user->id)->get();

        if ($mailboxes->contains(fn (Mailbox $mailbox) => $mailbox->status === 'pending')) {
            return 'Your account is awaiting approval from your workspace admin.';
        }

        return 'This account is inactive. Contact your workspace admin.';
    }
}
