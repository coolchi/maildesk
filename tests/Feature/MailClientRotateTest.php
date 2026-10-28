<?php

namespace Tests\Feature;

use App\Models\Mailbox;
use App\Models\Organization;
use App\Models\User;
use App\Services\Imap\MailboxProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MailClientRotateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * @return array{0: User, 1: Organization, 2: Mailbox}
     */
    protected function ownerMailbox(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $mailbox = Mailbox::factory()->create([
            'organization_id' => $org->id,
            'email' => 'hello@acme.test',
            'imap_enabled' => true,
            'imap_password' => 'old-secret',
            'status' => 'active',
        ]);

        return [$user, $org, $mailbox];
    }

    public function test_get_rotate_redirects_to_mail_clients_without_rotating(): void
    {
        [$user, $org, $mailbox] = $this->ownerMailbox();

        $this->mock(MailboxProvisioner::class, function ($mock) {
            $mock->shouldNotReceive('rotateAppPassword');
        });

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->get('/settings/mail-clients/'.$mailbox->id.'/rotate')
            ->assertRedirect(route('settings', 'mail-clients'));

        $this->assertSame('old-secret', $mailbox->fresh()->imap_password);
    }

    public function test_post_rotate_redirects_see_other_to_mail_clients_with_password_once(): void
    {
        [$user, $org, $mailbox] = $this->ownerMailbox();

        $this->mock(MailboxProvisioner::class, function ($mock) {
            $mock->shouldReceive('rotateAppPassword')->once()->andReturn('shown-once');
        });

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->from(route('settings', 'mail-clients'))
            ->post(route('settings.mail-clients.rotate', $mailbox))
            ->assertRedirect(route('settings', 'mail-clients'))
            ->assertStatus(303)
            ->assertSessionHas('mail_client_app_password', 'shown-once')
            ->assertSessionHas('mail_client_mailbox_id', $mailbox->id)
            ->assertSessionHas('success');
    }

    public function test_get_rotate_for_other_org_mailbox_is_404(): void
    {
        [$user, $org] = $this->ownerMailbox();
        $foreign = Mailbox::factory()->create(['imap_enabled' => true]);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $org->id])
            ->get('/settings/mail-clients/'.$foreign->id.'/rotate')
            ->assertNotFound();
    }
}
