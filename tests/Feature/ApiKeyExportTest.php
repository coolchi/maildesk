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

    public function test_export_requires_manage_ability(): void
    {
        $member = User::factory()->create();
        $this->org->users()->attach($member->id, ['role' => 'member']);

        $response = $this->actingAs($member)
            ->withSession(['current_organization_id' => $this->org->id])
            ->get(route('api-keys.export'));

        $response->assertStatus(403);
    }

    public function test_export_escapes_formula_injection(): void
    {
        ApiKey::issue($this->org, '=SUM(A1:A10)', $this->user, ['*']);
        ApiKey::issue($this->org, '+cmd|calc', $this->user, ['*']);
        ApiKey::issue($this->org, '-dangerous', $this->user, ['*']);
        ApiKey::issue($this->org, '@malicious', $this->user, ['emails:send', 'domain:@injection.com']);

        $response = $this->actingAs($this->user)
            ->withSession(['current_organization_id' => $this->org->id])
            ->get(route('api-keys.export'));

        $content = $response->streamedContent();

        $this->assertStringContains("'=SUM(A1:A10)", $content, 'Names starting with = should be escaped');
        $this->assertStringContains("'+cmd|calc", $content, 'Names starting with + should be escaped');
        $this->assertStringContains("'-dangerous", $content, 'Names starting with - should be escaped');
        $this->assertStringContains("'@malicious", $content, 'Names starting with @ should be escaped');
        $this->assertStringContains("'@injection.com", $content, 'Domains starting with @ should be escaped');
    }

    public function test_export_uses_workspace_timezone(): void
    {
        $this->org->update(['settings' => ['timezone' => 'America/New_York']]);

        $key = ApiKey::issue($this->org, 'Timezone Test', $this->user, ['*'], now()->addDays(30))['model'];
        $key->forceFill(['created_at' => '2026-09-29 12:00:00'])->save();

        $response = $this->actingAs($this->user)
            ->withSession(['current_organization_id' => $this->org->id])
            ->get(route('api-keys.export'));

        $content = $response->streamedContent();

        $this->assertStringContains('2026-09-29 08:00:00', $content, 'Created timestamp should be in America/New_York timezone (UTC-4)');
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
