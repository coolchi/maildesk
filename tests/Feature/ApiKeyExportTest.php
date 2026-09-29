<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\MailProvider;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiKeyExportTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $provider = MailProvider::factory()->create(['status' => 'active', 'driver' => 'resend']);
        $this->org = Organization::factory()->create([
            'slug' => 'acme',
            'mail_provider_id' => $provider->id,
        ]);
        $this->org->users()->attach($this->user->id, ['role' => 'owner']);
    }

    public function test_export_returns_csv_with_metadata_only(): void
    {
        $issued = ApiKey::issue($this->org, 'Production Key', $this->user, ['*'], now()->addDays(30));
        $key = $issued['model'];

        $response = $this->actingAs($this->user)
            ->withSession(['current_organization_id' => $this->org->id])
            ->get(route('api-keys.export'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $response->assertHeader('Content-Disposition');
        $this->assertStringContains('api-keys-acme', $response->headers->get('Content-Disposition'));
        $this->assertStringContains('.csv', $response->headers->get('Content-Disposition'));

        $content = $response->streamedContent();

        $this->assertStringContains('Name,Prefix,Permission', $content);
        $this->assertStringContains('Domain Scope', $content);
        $this->assertStringContains('Last Used', $content);
        $this->assertStringContains('Expires,Status', $content);
        $this->assertStringContains('Production Key', $content);
        $this->assertStringContains($key->key_prefix, $content);
        $this->assertStringContains('Full access', $content);
        $this->assertStringContains('Active', $content);

        $this->assertStringNotContains($issued['plain'], $content, 'CSV must NOT contain the plain API secret');
        $this->assertStringNotContains('key_hash', $content, 'CSV must NOT contain the hash column name');
    }

    public function test_export_includes_revoked_and_expired_keys(): void
    {
        $active = ApiKey::issue($this->org, 'Active Key', $this->user, ['*'])['model'];
        $revoked = ApiKey::issue($this->org, 'Revoked Key', $this->user, ['*'])['model'];
        $revoked->revoke();
        $expired = ApiKey::issue($this->org, 'Expired Key', $this->user, ['*'], now()->subDay())['model'];

        $response = $this->actingAs($this->user)
            ->withSession(['current_organization_id' => $this->org->id])
            ->get(route('api-keys.export'));

        $content = $response->streamedContent();

        $this->assertStringContains('Active Key', $content);
        $this->assertStringContains('Revoked Key', $content);
        $this->assertStringContains('Expired Key', $content);
        $this->assertStringContains('Revoked', $content);
        $this->assertStringContains('Expired', $content);
    }

    public function test_export_shows_sending_access_and_domain_scope(): void
    {
        ApiKey::issue($this->org, 'Scoped Key', $this->user, ['emails:send', 'domain:example.com']);

        $response = $this->actingAs($this->user)
            ->withSession(['current_organization_id' => $this->org->id])
            ->get(route('api-keys.export'));

        $content = $response->streamedContent();

        $this->assertStringContains('Scoped Key', $content);
        $this->assertStringContains('Sending access', $content);
        $this->assertStringContains('example.com', $content);
    }

    public function test_export_requires_authentication(): void
    {
        $this->get(route('api-keys.export'))->assertRedirect(route('login'));
    }

    /**
     * Custom assertion: check string contains a substring.
     */
    protected function assertStringContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertTrue(str_contains($haystack, $needle), $message ?: "Failed asserting that '{$haystack}' contains '{$needle}'.");
    }

    /**
     * Custom assertion: check string does NOT contain a substring.
     */
    protected function assertStringNotContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertFalse(str_contains($haystack, $needle), $message ?: "Failed asserting that '{$haystack}' does not contain '{$needle}'.");
    }
}
