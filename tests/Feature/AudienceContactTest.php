<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\MailProvider;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AudienceContactTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Organization}
     */
    private function member(): array
    {
        $user = User::factory()->create();
        $provider = MailProvider::factory()->create([
            'status' => 'active',
            'driver' => 'resend',
        ]);
        $org = Organization::factory()->create([
            'default_provider' => 'resend',
            'mail_provider_id' => $provider->id,
            'status' => 'active',
        ]);
        $org->users()->attach($user->id, ['role' => 'owner']);

        return [$user, $org];
    }

    private function as(User $user, Organization $org): static
    {
        return $this->actingAs($user)->withSession(['current_organization_id' => $org->id]);
    }

    public function test_can_add_multiple_contacts_with_name_and_company(): void
    {
        [$user, $org] = $this->member();

        $this->as($user, $org)->post(route('audience.store'), [
            'contacts' => [
                ['email' => 'Ann@Example.com', 'name' => 'Ann Example', 'company' => 'Acme'],
                ['email' => 'bob@example.com', 'name' => 'Bob', 'company' => 'Beta Co'],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $ann = Contact::query()->where('email', 'ann@example.com')->first();
        $this->assertNotNull($ann);
        $this->assertSame('Ann', $ann->first_name);
        $this->assertSame('Example', $ann->last_name);
        $this->assertSame('Acme', $ann->company);

        $bob = Contact::query()->where('email', 'bob@example.com')->first();
        $this->assertSame('Bob', $bob->first_name);
        $this->assertNull($bob->last_name);
        $this->assertSame('Beta Co', $bob->company);

        $this->as($user, $org)->get(route('audience'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Audience/Index')
                ->has('contacts', 2)
                ->where('contacts.0.company', fn ($company) => in_array($company, ['Acme', 'Beta Co'], true)));
    }

    public function test_legacy_single_email_payload_still_works(): void
    {
        [$user, $org] = $this->member();

        $this->as($user, $org)->post(route('audience.store'), [
            'email' => 'solo@example.com',
            'name' => 'Solo User',
            'company' => 'Solo Inc',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('contacts', [
            'organization_id' => $org->id,
            'email' => 'solo@example.com',
            'first_name' => 'Solo',
            'last_name' => 'User',
            'company' => 'Solo Inc',
        ]);
    }

    public function test_imports_contacts_from_csv(): void
    {
        [$user, $org] = $this->member();

        $csv = "email,name,company\n"
            ."cara@example.com,Cara Cole,Carrot Co\n"
            ."dan@example.com,Dan,Delta\n"
            ."not-an-email,Skip Me,Nope\n";

        $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);

        $this->as($user, $org)
            ->post(route('audience.import'), ['file' => $file])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame(2, Contact::query()->where('organization_id', $org->id)->count());
        $this->assertDatabaseHas('contacts', [
            'email' => 'cara@example.com',
            'first_name' => 'Cara',
            'last_name' => 'Cole',
            'company' => 'Carrot Co',
        ]);
    }

    public function test_import_rejects_non_csv_files(): void
    {
        [$user, $org] = $this->member();

        $this->as($user, $org)
            ->post(route('audience.import'), [
                'file' => UploadedFile::fake()->create('contacts.pdf', 100, 'application/pdf'),
            ])
            ->assertSessionHasErrors(['file']);
    }
}
