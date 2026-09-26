<?php

namespace Tests\Feature;

use App\Models\MailProvider;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Notifications\WorkspaceInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WorkspaceInvitationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Organization}
     */
    private function ownerWorkspace(): array
    {
        $user = User::factory()->create();
        $provider = MailProvider::factory()->create(['status' => 'active', 'driver' => 'resend']);
        $org = Organization::factory()->create([
            'status' => 'active',
            'mail_provider_id' => $provider->id,
            'default_provider' => 'resend',
        ]);
        $org->users()->attach($user->id, ['role' => 'owner']);

        return [$user, $org];
    }

    public function test_owner_can_invite_teammate_by_email(): void
    {
        Notification::fake();
        [$owner, $org] = $this->ownerWorkspace();

        $this->actingAs($owner)
            ->withSession(['current_organization_id' => $org->id])
            ->post(route('invitations.store'), [
                'email' => 'new.hire@acme.test',
                'role' => 'member',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $invitation = WorkspaceInvitation::query()->firstOrFail();
        $this->assertSame('new.hire@acme.test', $invitation->email);
        $this->assertSame('member', $invitation->role);
        $this->assertNull($invitation->accepted_at);

        Notification::assertSentOnDemand(WorkspaceInvitationNotification::class);
    }

    public function test_invitee_can_accept_and_join_workspace(): void
    {
        [$owner, $org] = $this->ownerWorkspace();
        $invitation = WorkspaceInvitation::factory()->create([
            'organization_id' => $org->id,
            'invited_by' => $owner->id,
            'email' => 'join.me@example.com',
            'role' => 'admin',
            'expires_at' => now()->addDays(3),
        ]);

        $this->withoutVite()
            ->get(route('invitations.show', $invitation->token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Auth/AcceptInvitation')
                ->where('invitation.email', 'join.me@example.com')
                ->where('invitation.needs_account', true));

        $this->withoutVite()
            ->post(route('invitations.accept.store', $invitation->token), [
                'name' => 'Join Me',
                'password' => 'password-Password1!',
                'password_confirmation' => 'password-Password1!',
            ])->assertRedirect();

        $user = User::query()->where('email', 'join.me@example.com')->firstOrFail();
        $this->assertTrue($org->users()->whereKey($user->id)->exists());
        $this->assertNotNull($invitation->fresh()->accepted_at);
        $this->assertAuthenticatedAs($user);
    }
}
