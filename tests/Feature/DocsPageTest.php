<?php

namespace Tests\Feature;

use App\Http\Controllers\WebhookController;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DocsPageTest extends TestCase
{
    use RefreshDatabase;

    private function source(): string
    {
        return file_get_contents(resource_path('js/Pages/Docs/Index.vue'));
    }

    public function test_send_quick_start_is_public(): void
    {
        config(['maildesk.api.rate_limit' => 77]);

        $this->get(route('docs.send'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Docs/SendQuickStart')
                ->where('rateLimit', 77)
                ->where('apiBaseUrl', fn ($url) => str_ends_with($url, '/api/v1')));

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('docs.send'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Docs/SendQuickStart'));
    }

    public function test_docs_page_renders_with_real_config(): void
    {
        config(['maildesk.api.rate_limit' => 77]);
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        $this->actingAs($user)->withSession(['current_organization_id' => $org->id])
            ->get(route('docs'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Docs/Index')
                ->where('rateLimit', 77)
                ->where('apiBaseUrl', fn ($url) => str_ends_with($url, '/api/v1'))
                ->where('webhookEvents', WebhookController::EVENT_OPTIONS)
                ->where('webhookRetry.attempts', 5));
    }

    public function test_docs_do_not_mention_invented_endpoints_or_features(): void
    {
        $source = $this->source();

        foreach ([
            '/emails/batch', 'Batch send', '/reply', 'Idempotency-Key', '/audiences',
            '/api-keys', '@maildesk/sdk', 'MailDesk\\\\Client', 'cursor=',
            'domain.verified', 'webhook.failed', 'email.delivery_delayed', 'scheduled_at',
            '"statusCode"', 't=1725615858,v1=',
        ] as $invented) {
            $this->assertStringNotContainsString($invented, $source, "Docs still mention {$invented}");
        }
    }

    public function test_every_api_route_is_documented(): void
    {
        $source = $this->source();

        $paths = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => $route->uri())
            ->filter(fn ($uri) => Str::startsWith($uri, 'api/v1/'))
            ->reject(fn ($uri) => Str::startsWith($uri, 'api/v1/mobile/'))
            ->map(fn ($uri) => Str::after($uri, 'api/v1'))
            ->unique();

        $this->assertNotEmpty($paths);
        foreach ($paths as $path) {
            $this->assertStringContainsString($path, $source, "API route {$path} is not documented");
        }
    }

    public function test_signature_docs_match_the_signing_headers(): void
    {
        $source = $this->source();

        foreach (['X-MailDesk-Signature', 'X-MailDesk-Timestamp', 'X-MailDesk-Signature-V2', 'X-MailDesk-Event', 'X-MailDesk-Delivery'] as $header) {
            $this->assertStringContainsString($header, $source);
        }
    }
}
