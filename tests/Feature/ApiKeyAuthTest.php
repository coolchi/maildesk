<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Domain;
use App\Models\MailProvider;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ApiKeyAuthTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->user = User::factory()->create();
        $provider = MailProvider::factory()->create(['status' => 'active', 'driver' => 'resend']);
        $this->org = Organization::factory()->create([
            'mail_provider_id' => $provider->id,
            'default_provider' => 'resend',
        ]);
        $this->org->users()->attach($this->user->id, ['role' => 'owner']);
        Domain::factory()->verified()->create(['organization_id' => $this->org->id, 'name' => 'acme.test']);
        Domain::factory()->verified()->create(['organization_id' => $this->org->id, 'name' => 'other.test']);
        Domain::factory()->create(['organization_id' => $this->org->id, 'name' => 'pending.test', 'status' => 'pending']);
    }

    private function key(array $abilities = ['*'], ?\DateTimeInterface $expiresAt = null): array
    {
        $issued = ApiKey::issue($this->org, 'Test', $this->user, $abilities, $expiresAt);

        return [$issued['plain'], $issued['model']];
    }

    private function api(string $token)
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json']);
    }

    private function sendPayload(string $from = 'hello@acme.test'): array
    {
        return ['from' => $from, 'to' => 'customer@example.com', 'subject' => 'Hi', 'html' => '<p>Hi</p>'];
    }

    public function test_missing_and_invalid_keys_are_rejected(): void
    {
        $this->getJson('/api/v1/emails')->assertStatus(401)->assertJsonStructure(['message']);
        $this->api('md_notarealkeyatall1234567890')->getJson('/api/v1/emails')
            ->assertStatus(401)->assertJsonPath('message', 'Invalid API key.');
        $this->api('sk_wrongprefix')->getJson('/api/v1/emails')->assertStatus(401);
    }

    public function test_expired_keys_are_invalid(): void
    {
        [$plain] = $this->key(['*'], now()->subMinute());

        $this->api($plain)->getJson('/api/v1/emails')
            ->assertStatus(401)->assertJsonPath('message', 'API key expired.');
    }

    public function test_full_access_key_can_use_every_endpoint(): void
    {
        [$plain] = $this->key(['*']);

        $this->api($plain)->getJson('/api/v1/emails')->assertOk();
        $this->api($plain)->getJson('/api/v1/domains')->assertOk();
        $this->api($plain)->getJson('/api/v1/inbox/threads')->assertOk();
        $this->api($plain)->postJson('/api/v1/emails', $this->sendPayload())
            ->assertStatus(201)->assertJsonPath('status', 'sent');
    }

    public function test_sending_only_key_can_send_but_nothing_else(): void
    {
        [$plain] = $this->key(['emails:send']);

        $this->api($plain)->postJson('/api/v1/emails', $this->sendPayload())->assertStatus(201);

        foreach ([
            ['GET', '/api/v1/emails'],
            ['GET', '/api/v1/emails/some-uuid'],
            ['GET', '/api/v1/domains'],
            ['POST', '/api/v1/domains'],
            ['GET', '/api/v1/inbox/threads'],
            ['GET', '/api/v1/inbox/threads/1'],
        ] as [$method, $uri]) {
            $this->api($plain)->json($method, $uri, ['name' => 'new.test'])
                ->assertStatus(403)
                ->assertJsonStructure(['message']);
        }

        $this->assertSame(0, Domain::query()->where('name', 'new.test')->count());
    }

    public function test_single_domain_key_can_only_send_from_that_domain(): void
    {
        [$plain] = $this->key(['emails:send', 'domain:acme.test']);

        $this->api($plain)->postJson('/api/v1/emails', $this->sendPayload('Acme <hello@ACME.test>'))->assertStatus(201);
        $this->api($plain)->postJson('/api/v1/emails', $this->sendPayload('hello@other.test'))
            ->assertStatus(403)
            ->assertJsonPath('message', 'This API key can only send from acme.test.');
        $this->api($plain)->postJson('/api/v1/emails', $this->sendPayload('hello@sub.acme.test'))->assertStatus(403);

        $this->assertSame(1, Message::query()->count());
    }

    public function test_sends_from_unverified_or_unknown_domains_are_rejected(): void
    {
        [$plain] = $this->key(['*']);

        $this->api($plain)->postJson('/api/v1/emails', $this->sendPayload('hello@pending.test'))
            ->assertStatus(422)->assertJsonValidationErrors('from');
        $this->api($plain)->postJson('/api/v1/emails', $this->sendPayload('hello@gmail.com'))
            ->assertStatus(422)->assertJsonValidationErrors('from');
        $this->api($plain)->postJson('/api/v1/emails', $this->sendPayload('not-an-email'))
            ->assertStatus(422)->assertJsonValidationErrors('from');

        $this->assertSame(0, Message::query()->count());
    }

    public function test_rate_limit_is_per_key_and_returns_429_with_headers(): void
    {
        config(['maildesk.api.rate_limit' => 2]);
        [$plain] = $this->key(['*']);
        [$other] = $this->key(['*']);

        $this->api($plain)->getJson('/api/v1/domains')->assertOk()
            ->assertHeader('X-RateLimit-Limit', '2')->assertHeader('X-RateLimit-Remaining', '1');
        $this->api($plain)->getJson('/api/v1/domains')->assertOk()->assertHeader('X-RateLimit-Remaining', '0');

        $response = $this->api($plain)->getJson('/api/v1/domains')
            ->assertStatus(429)
            ->assertJsonStructure(['message'])
            ->assertHeader('X-RateLimit-Limit', '2')
            ->assertHeader('X-RateLimit-Remaining', '0');
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));

        $this->api($other)->getJson('/api/v1/domains')->assertOk();
    }

    public function test_rotation_issues_new_secret_with_same_settings_and_revokes_old(): void
    {
        [$oldPlain, $old] = $this->key(['emails:send', 'domain:acme.test'], now()->addDays(30));

        $response = $this->actingAs($this->user)
            ->withSession(['current_organization_id' => $this->org->id])
            ->post(route('api-keys.rotate', $old))
            ->assertRedirect(route('api-keys'));

        $newPlain = $response->getSession()->get('plain_api_key');
        $this->assertIsString($newPlain);
        $this->assertNotSame($oldPlain, $newPlain);
        $this->assertNotNull($old->fresh()->revoked_at, 'Rotation revokes the old key but keeps its row.');

        $new = ApiKey::query()->where('organization_id', $this->org->id)->whereNull('revoked_at')->sole();
        $this->assertSame(['emails:send', 'domain:acme.test'], $new->abilities);
        $this->assertSame('Test', $new->name);
        $this->assertEqualsWithDelta(now()->addDays(30)->getTimestamp(), $new->expires_at->getTimestamp(), 120);

        $this->api($oldPlain)->postJson('/api/v1/emails', $this->sendPayload())->assertStatus(401);
        $this->api($newPlain)->postJson('/api/v1/emails', $this->sendPayload())->assertStatus(201);
    }

    public function test_revoked_keys_cannot_be_rotated(): void
    {
        [, $key] = $this->key();
        $key->revoke();

        $this->actingAs($this->user)
            ->withSession(['current_organization_id' => $this->org->id])
            ->post(route('api-keys.rotate', $key))
            ->assertRedirect(route('api-keys'))
            ->assertSessionHasErrors('api_key')
            ->assertSessionMissing('plain_api_key');

        $this->assertSame(1, ApiKey::query()->where('organization_id', $this->org->id)->count());
    }

    public function test_revoke_keeps_the_row_and_blocks_auth(): void
    {
        [$plain, $key] = $this->key();
        $this->api($plain)->getJson('/api/v1/emails')->assertOk();

        $this->actingAs($this->user)
            ->withSession(['current_organization_id' => $this->org->id])
            ->post(route('api-keys.revoke', $key))
            ->assertRedirect(route('api-keys'));

        $key->refresh();
        $this->assertDatabaseHas('api_keys', ['id' => $key->id]);
        $this->assertNotNull($key->revoked_at);
        $this->assertTrue($key->toWorkspaceArray()['revoked']);
        $this->assertSame($key->revoked_at->toFormattedDateString(), $key->toWorkspaceArray()['revoked_at']);

        $this->api($plain)->getJson('/api/v1/emails')
            ->assertStatus(401)->assertJsonPath('message', 'API key revoked.');
    }

    public function test_revoke_is_scoped_to_the_workspace(): void
    {
        $foreign = ApiKey::factory()->create();

        $this->actingAs($this->user)
            ->withSession(['current_organization_id' => $this->org->id])
            ->post(route('api-keys.revoke', $foreign))
            ->assertNotFound();

        $this->assertNull($foreign->fresh()->revoked_at);
    }

    public function test_keys_can_be_created_with_a_custom_expiry_date_and_are_rejected_after_it(): void
    {
        $date = now()->addDays(10)->toDateString();

        $response = $this->actingAs($this->user)
            ->withSession(['current_organization_id' => $this->org->id])
            ->post(route('api-keys.store'), ['name' => 'Custom', 'permission' => 'Full access', 'expires_at' => $date])
            ->assertRedirect(route('api-keys'));

        $plain = $response->getSession()->get('plain_api_key');
        $key = ApiKey::query()->where('name', 'Custom')->sole();
        $this->assertSame($date, $key->expires_at->toDateString());
        $this->assertFalse($key->toWorkspaceArray()['expired']);

        $this->api($plain)->getJson('/api/v1/emails')->assertOk();

        $this->travel(11)->days();

        $this->assertTrue($key->fresh()->toWorkspaceArray()['expired']);
        $this->api($plain)->getJson('/api/v1/emails')
            ->assertStatus(401)->assertJsonPath('message', 'API key expired.');
    }

    public function test_custom_expiry_date_must_be_in_the_future(): void
    {
        $this->actingAs($this->user)
            ->withSession(['current_organization_id' => $this->org->id])
            ->post(route('api-keys.store'), ['name' => 'Past', 'permission' => 'Full access', 'expires_at' => now()->subDay()->toDateString()])
            ->assertSessionHasErrors('expires_at');

        $this->assertDatabaseMissing('api_keys', ['name' => 'Past']);
    }

    public function test_rotation_is_scoped_to_the_workspace(): void
    {
        $foreign = ApiKey::factory()->create();

        $this->actingAs($this->user)
            ->withSession(['current_organization_id' => $this->org->id])
            ->post(route('api-keys.rotate', $foreign))
            ->assertNotFound();
    }

    public function test_keys_can_be_created_with_an_expiry(): void
    {
        $this->actingAs($this->user)
            ->withSession(['current_organization_id' => $this->org->id])
            ->post(route('api-keys.store'), ['name' => 'Short', 'permission' => 'Full access', 'expires_in_days' => 30])
            ->assertRedirect(route('api-keys'))
            ->assertSessionHas('plain_api_key');

        $key = ApiKey::query()->where('name', 'Short')->sole();
        $this->assertEqualsWithDelta(now()->addDays(30)->getTimestamp(), $key->expires_at->getTimestamp(), 120);
        $this->assertSame('Never', ApiKey::issue($this->org, 'Forever')['model']->toWorkspaceArray()['expires']);
    }

    public function test_api_responses_do_not_expose_internal_fields(): void
    {
        [$plain] = $this->key(['*']);
        $thread = Thread::factory()->create(['organization_id' => $this->org->id]);
        Message::factory()->create([
            'organization_id' => $this->org->id,
            'thread_id' => $thread->id,
            'direction' => 'outbound',
            'bcc' => ['secret@example.com'],
            'headers' => ['X-Internal' => '1'],
            'meta' => ['provider_raw' => ['x' => 1]],
        ]);

        $list = $this->api($plain)->getJson('/api/v1/emails')->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('meta', $list);
        $this->assertArrayNotHasKey('bcc', $list);
        $this->assertArrayNotHasKey('headers', $list);
        $this->assertArrayHasKey('subject', $list);

        $threadJson = $this->api($plain)->getJson('/api/v1/inbox/threads/'.$thread->id)->assertOk()->json('messages.0');
        $this->assertArrayNotHasKey('meta', $threadJson);
        $this->assertArrayNotHasKey('bcc', $threadJson);
    }
}
