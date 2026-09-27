<?php

namespace Tests\Feature;

use App\Http\Controllers\ContactController;
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

    private function createContact(Organization $org, string $email, ?string $firstName = null, ?string $lastName = null, ?string $company = null): Contact
    {
        return Contact::factory()->create([
            'organization_id' => $org->id,
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'company' => $company,
        ]);
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

    public function test_index_returns_paginated_contacts(): void
    {
        [$user, $org] = $this->member();

        for ($i = 1; $i <= 60; $i++) {
            $this->createContact($org, "contact{$i}@example.com", 'Contact', "{$i}");
        }

        $this->as($user, $org)
            ->get(route('audience'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Audience/Index')
                ->has('contacts', ContactController::PER_PAGE)
                ->has('pagination')
                ->where('pagination.current_page', 1)
                ->where('pagination.last_page', 2)
                ->where('pagination.total', 60)
                ->where('pagination.per_page', ContactController::PER_PAGE)
            );
    }

    public function test_index_second_page_returns_remaining_contacts(): void
    {
        [$user, $org] = $this->member();

        for ($i = 1; $i <= 60; $i++) {
            $this->createContact($org, "contact{$i}@example.com", 'Contact', "{$i}");
        }

        $this->as($user, $org)
            ->get(route('audience', ['page' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Audience/Index')
                ->has('contacts', 10)
                ->where('pagination.current_page', 2)
                ->where('pagination.last_page', 2)
            );
    }

    public function test_index_search_filter_narrows_results(): void
    {
        [$user, $org] = $this->member();

        $this->createContact($org, 'alice@example.com', 'Alice', 'Smith', 'Acme Corp');
        $this->createContact($org, 'bob@example.com', 'Bob', 'Jones', 'Beta Inc');
        $this->createContact($org, 'alice.jones@other.com', 'Alice', 'Jones', 'Other Inc');

        $this->as($user, $org)
            ->get(route('audience', ['search' => 'alice']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('contacts', 2)
                ->where('filters.search', 'alice')
            );
    }

    public function test_index_search_matches_company(): void
    {
        [$user, $org] = $this->member();

        $this->createContact($org, 'alice@example.com', 'Alice', 'Smith', 'Acme Corp');
        $this->createContact($org, 'bob@example.com', 'Bob', 'Jones', 'Beta Inc');

        $this->as($user, $org)
            ->get(route('audience', ['search' => 'Acme']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('contacts', 1)
            );
    }

    public function test_index_status_filter_shows_only_subscribed(): void
    {
        [$user, $org] = $this->member();

        $subscribed = $this->createContact($org, 'subscribed@example.com');
        $unsubscribed = $this->createContact($org, 'unsubscribed@example.com');
        $unsubscribed->update(['unsubscribed_at' => now()]);

        $this->as($user, $org)
            ->get(route('audience', ['status' => 'subscribed']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('contacts', 1)
                ->where('filters.status', 'subscribed')
            );
    }

    public function test_index_status_filter_shows_only_unsubscribed(): void
    {
        [$user, $org] = $this->member();

        $subscribed = $this->createContact($org, 'subscribed@example.com');
        $unsubscribed = $this->createContact($org, 'unsubscribed@example.com');
        $unsubscribed->update(['unsubscribed_at' => now()]);

        $this->as($user, $org)
            ->get(route('audience', ['status' => 'unsubscribed']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('contacts', 1)
                ->where('filters.status', 'unsubscribed')
            );
    }

    public function test_index_invalid_status_filter_is_ignored(): void
    {
        [$user, $org] = $this->member();

        $this->createContact($org, 'test@example.com');

        $this->as($user, $org)
            ->get(route('audience', ['status' => 'invalid']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.status', null)
            );
    }

    public function test_index_combined_search_and_status_filter(): void
    {
        [$user, $org] = $this->member();

        $alice = $this->createContact($org, 'alice@example.com', 'Alice', 'Smith');
        $bob = $this->createContact($org, 'bob@example.com', 'Bob', 'Jones');
        $alice2 = $this->createContact($org, 'alice2@example.com', 'Alice', 'Doe');
        $alice2->update(['unsubscribed_at' => now()]);

        $this->as($user, $org)
            ->get(route('audience', ['search' => 'alice', 'status' => 'subscribed']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('contacts', 1)
            );
    }

    public function test_index_returns_stats(): void
    {
        [$user, $org] = $this->member();

        for ($i = 1; $i <= 5; $i++) {
            $this->createContact($org, "subscribed{$i}@example.com");
        }
        for ($i = 1; $i <= 3; $i++) {
            $contact = $this->createContact($org, "unsub{$i}@example.com");
            $contact->update(['unsubscribed_at' => now()]);
        }

        $this->as($user, $org)
            ->get(route('audience'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.all', 8)
                ->where('stats.subscribers', 5)
                ->where('stats.unsubscribers', 3)
            );
    }

    public function test_index_pagination_preserves_search_filter(): void
    {
        [$user, $org] = $this->member();

        for ($i = 1; $i <= 60; $i++) {
            $this->createContact($org, "alice{$i}@example.com", 'Alice', "{$i}");
        }
        for ($i = 1; $i <= 10; $i++) {
            $this->createContact($org, "bob{$i}@example.com", 'Bob', "{$i}");
        }

        $this->as($user, $org)
            ->get(route('audience', ['search' => 'alice', 'page' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pagination.current_page', 2)
                ->where('pagination.total', 60)
                ->where('filters.search', 'alice')
            );
    }

    public function test_import_respects_limit_and_reports_excess(): void
    {
        [$user, $org] = $this->member();

        $rows = [];
        $limit = ContactController::IMPORT_LIMIT;
        $extra = 10;
        $total = $limit + $extra;

        for ($i = 1; $i <= $total; $i++) {
            $rows[] = "contact{$i}@example.com,Contact {$i},Company {$i}";
        }
        $csv = "email,name,company\n".implode("\n", $rows);

        $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);

        $response = $this->as($user, $org)
            ->post(route('audience.import'), ['file' => $file])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $message = session('success');
        $this->assertStringContainsString((string) $limit, $message);
        $this->assertStringContainsString("{$extra} row(s) exceeded", $message);
        $this->assertSame($limit, Contact::where('organization_id', $org->id)->count());
    }

    public function test_import_deduplicates_emails_within_file(): void
    {
        [$user, $org] = $this->member();

        $csv = "email,name,company\n"
            ."dupe@example.com,First Entry,Company A\n"
            ."dupe@example.com,Second Entry,Company B\n"
            ."unique@example.com,Unique,Company C\n";

        $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);

        $this->as($user, $org)
            ->post(route('audience.import'), ['file' => $file])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame(2, Contact::where('organization_id', $org->id)->count());

        $contact = Contact::where('email', 'dupe@example.com')->first();
        $this->assertSame('First', $contact->first_name);
    }

    public function test_import_reports_invalid_and_duplicate_rows(): void
    {
        [$user, $org] = $this->member();

        $csv = "email,name,company\n"
            ."valid@example.com,Valid,Company\n"
            ."not-an-email,Invalid,Nope\n"
            ."valid@example.com,Duplicate,Same\n"
            ."also-valid@example.com,Also Valid,Inc\n";

        $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);

        $response = $this->as($user, $org)
            ->post(route('audience.import'), ['file' => $file])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $message = session('success');
        $this->assertStringContainsString('2 contacts imported', $message);
        $this->assertStringContainsString('2 row(s) skipped', $message);
    }

    public function test_import_empty_file_shows_error(): void
    {
        [$user, $org] = $this->member();

        $csv = "email,name,company\n";
        $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);

        $this->as($user, $org)
            ->post(route('audience.import'), ['file' => $file])
            ->assertSessionHasErrors(['file']);
    }

    public function test_import_all_invalid_rows_shows_error(): void
    {
        [$user, $org] = $this->member();

        $csv = "email,name,company\n"
            ."not-valid,Bad,Email\n"
            ."also-not-valid,Also Bad,Email\n";

        $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);

        $this->as($user, $org)
            ->post(route('audience.import'), ['file' => $file])
            ->assertSessionHasErrors(['file']);

        $errors = session('errors');
        $this->assertStringContainsString('No valid contacts found', $errors->first('file'));
    }
}
