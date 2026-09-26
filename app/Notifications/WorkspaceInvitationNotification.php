<?php

namespace App\Notifications;

use App\Models\WorkspaceInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkspaceInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public WorkspaceInvitation $invitation)
    {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $invitation = $this->invitation->loadMissing(['organization', 'inviter']);
        $organization = $invitation->organization;
        $inviter = $invitation->inviter?->name ?? 'A teammate';

        return (new MailMessage)
            ->subject("You're invited to {$organization->name} on ".config('app.name'))
            ->markdown('mail.workspace-invitation', [
                'organizationName' => $organization->name,
                'inviterName' => $inviter,
                'role' => $invitation->role,
                'acceptUrl' => $invitation->acceptUrl(),
                'expiresAt' => $invitation->expires_at?->timezone(config('app.timezone'))->format('M j, Y g:i A'),
            ]);
    }
}
