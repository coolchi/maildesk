<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Domain;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Models\Webhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OnboardingProgressTest extends TestCase
{
    use RefreshDatabase;

    private function member(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        return [$user, $org];
    }

    private function visit(User $user, Organization $org)
    {
        return $this->actingAs($user)->withSession(['current_organization_id' => $org->id])->get('/domains');
    }

    public function test_new_workspace_has_no_steps_done(): void
    {
        [$user, $org] = $this->member();

        $this->visit($user, $org)->assertInertia(fn (Assert $page) => $page
            ->where('onboarding', ['domain' => false, 'apiKey' => false, 'send' => false, 'webhook' => false]));
    }

    public function test_steps_complete_from_real_workspace_data(): void
    {
        [$user, $org] = $this->member();
        Domain::factory()->create(['organization_id' => $org->id, 'name' => 'pending.test', 'status' => 'pending']);
        Message::factory()->create(['organization_id' => $org->id, 'direction' => 'outbound', 'status' => 'failed']);

        $this->visit($user, $org)->assertInertia(fn (Assert $page) => $page
            ->where('onboarding.domain', false)
            ->where('onboarding.send', false));

        Domain::factory()->verified()->create(['organization_id' => $org->id, 'name' => 'ok.test']);
        ApiKey::factory()->create(['organization_id' => $org->id]);
        Message::factory()->create(['organization_id' => $org->id, 'direction' => 'outbound', 'status' => 'sent']);
        Webhook::factory()->create(['organization_id' => $org->id]);

        $this->visit($user, $org)->assertInertia(fn (Assert $page) => $page
            ->where('onboarding', ['domain' => true, 'apiKey' => true, 'send' => true, 'webhook' => true]));
    }
}
