<?php

namespace Tests\Feature;

use App\Mail\MailManager;
use App\Models\MailProvider;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminProviderDefaultsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->platformAdmin()->create();
    }

    public function test_default_and_disabled_together_is_rejected_without_undefaulting_others(): void
    {
        $current = MailProvider::factory()->default()->create(['driver' => 'resend', 'key' => 'resend_main']);
        $other = MailProvider::factory()->create(['driver' => 'smtp', 'key' => 'smtp_x']);

        $this->actingAs($this->admin())
            ->put(route('admin.providers.update', $other), ['name' => 'X', 'status' => 'disabled', 'is_default' => true])
            ->assertSessionHasErrors('status');

        $this->assertTrue($current->fresh()->is_default);
        $this->assertFalse($other->fresh()->is_default);
        $this->assertSame('active', $other->fresh()->status);
        $this->assertSame(1, MailProvider::query()->where('is_default', true)->count());
    }

    public function test_make_default_moves_the_flag_atomically(): void
    {
        $current = MailProvider::factory()->default()->create(['key' => 'a']);
        $other = MailProvider::factory()->create(['key' => 'b']);

        $this->actingAs($this->admin())
            ->put(route('admin.providers.update', $other), ['name' => 'B', 'status' => 'active', 'is_default' => true])
            ->assertSessionHasNoErrors();

        $this->assertFalse($current->fresh()->is_default);
        $this->assertTrue($other->fresh()->is_default);
    }

    public function test_assigning_a_provider_stores_its_driver_as_default_provider(): void
    {
        $provider = MailProvider::factory()->create(['driver' => 'resend', 'key' => 'resend_66fabc']);
        $org = Organization::factory()->create(['default_provider' => 'smtp']);

        $this->actingAs($this->admin())
            ->put(route('admin.accounts.provider', $org), ['provider' => 'resend_66fabc'])
            ->assertSessionHasNoErrors();

        $org->refresh();
        $this->assertSame($provider->id, $org->mail_provider_id);
        $this->assertSame('resend', $org->default_provider);
    }

    public function test_deleting_a_provider_reassigns_its_accounts_to_the_default(): void
    {
        $default = MailProvider::factory()->default()->create(['driver' => 'smtp', 'key' => 'smtp_main']);
        $doomed = MailProvider::factory()->create(['driver' => 'resend', 'key' => 'resend_old']);
        $org = Organization::factory()->create(['mail_provider_id' => $doomed->id, 'default_provider' => 'resend']);

        $this->actingAs($this->admin())
            ->delete(route('admin.providers.destroy', $doomed))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('mail_providers', ['id' => $doomed->id]);
        $org->refresh();
        $this->assertSame($default->id, $org->mail_provider_id);
        $this->assertSame('smtp', $org->default_provider);
    }

    public function test_mail_manager_maps_a_legacy_provider_key_in_default_provider_to_its_driver(): void
    {
        MailProvider::factory()->create(['driver' => 'resend', 'key' => 'resend_66fabc']);
        $org = Organization::factory()->create(['mail_provider_id' => null, 'default_provider' => 'resend_66fabc']);

        $this->assertSame('resend', app(MailManager::class)->driverNameFor($org));
    }

    public function test_mail_manager_falls_back_to_platform_default_when_assigned_provider_is_disabled(): void
    {
        MailProvider::factory()->default()->create(['driver' => 'smtp', 'key' => 'smtp_main', 'status' => 'active']);
        $disabled = MailProvider::factory()->create(['driver' => 'resend', 'key' => 'resend_off', 'status' => 'disabled']);
        $org = Organization::factory()->create(['mail_provider_id' => $disabled->id, 'default_provider' => 'resend']);

        $manager = app(MailManager::class);
        $this->assertSame('smtp', $manager->driverNameFor($org));
        $this->assertSame('smtp_main', $manager->platformProviderFor($org)->key);
    }
}
