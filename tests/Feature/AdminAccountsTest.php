<?php

namespace Tests\Feature;

use App\Models\MailProvider;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_accounts_index(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $provider = MailProvider::factory()->default()->create(['key' => 'resend']);
        Organization::factory()->create([
            'mail_provider_id' => $provider->id,
            'name' => 'Acme Mail',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.accounts'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Accounts/Index')
                ->has('accounts', 1)
                ->where('accounts.0.name', 'Acme Mail'));
    }

    public function test_admin_can_assign_provider_to_account(): void
    {
        $admin = User::factory()->platformAdmin()->create();
        $resend = MailProvider::factory()->create(['key' => 'resend', 'status' => 'active']);
        $smtp = MailProvider::factory()->create(['key' => 'smtp', 'status' => 'active', 'driver' => 'smtp', 'type' => 'smtp']);
        $org = Organization::factory()->create(['mail_provider_id' => $resend->id]);

        $this->actingAs($admin)
            ->put(route('admin.accounts.provider', $org), [
                'provider' => 'smtp',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('organizations', [
            'id' => $org->id,
            'mail_provider_id' => $smtp->id,
            'default_provider' => 'smtp',
        ]);
    }

    public function test_admin_can_create_mail_provider(): void
    {
        $admin = User::factory()->platformAdmin()->create();

        $this->actingAs($admin)
            ->post(route('admin.providers.store'), [
                'name' => 'Resend QA',
                'driver' => 'resend',
                'type' => 'api',
                'api_base' => 'https://api.resend.com',
                'config' => [
                    ['key' => 'API_KEY', 'value' => 're_test', 'secret' => true],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('mail_providers', [
            'name' => 'Resend QA',
            'driver' => 'resend',
        ]);
    }
}
