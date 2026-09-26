<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationHost;
use App\Models\User;
use App\Services\TenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkspaceSubdomainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'maildesk.base_domain' => 'maildesk.test',
            'maildesk.central_domains' => ['maildesk.test'],
            'session.domain' => null,
        ]);
    }

    private function create(array $data): TestResponse
    {
        return $this->actingAs(User::factory()->create())
            ->from('/emails')
            ->post(route('workspaces.store'), $data + ['name' => 'Harbor Labs']);
    }

    public function test_valid_creation_saves_subdomain_and_host_row(): void
    {
        $this->create(['subdomain' => 'harbor'])->assertSessionHasNoErrors()->assertRedirect('/emails');

        $org = Organization::query()->where('name', 'Harbor Labs')->firstOrFail();
        $this->assertSame('harbor', $org->subdomain);
        $this->assertDatabaseHas('organization_hosts', [
            'organization_id' => $org->id,
            'subdomain' => 'harbor',
            'host' => 'harbor.maildesk.test',
            'is_custom' => false,
            'status' => 'active',
            'ssl' => true,
        ]);
        $this->assertSame(1, OrganizationHost::query()->where('organization_id', $org->id)->count());
    }

    public function test_subdomain_is_independent_of_the_name(): void
    {
        $this->create(['name' => 'Something Else', 'subdomain' => 'zeta-9'])->assertSessionHasNoErrors();

        $this->assertSame('zeta-9', Organization::query()->where('name', 'Something Else')->value('subdomain'));
    }

    public function test_uppercase_input_is_normalised(): void
    {
        $this->create(['subdomain' => '  HarBor-Labs '])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('organizations', ['name' => 'Harbor Labs', 'subdomain' => 'harbor-labs']);
        $this->assertDatabaseHas('organization_hosts', ['host' => 'harbor-labs.maildesk.test']);
    }

    public function test_subdomain_is_required(): void
    {
        $this->create([])->assertSessionHasErrors('subdomain');
        $this->create(['subdomain' => ''])->assertSessionHasErrors('subdomain');

        $this->assertDatabaseMissing('organizations', ['name' => 'Harbor Labs']);
    }

    public static function badFormats(): array
    {
        return [
            'space' => ['har bor'],
            'underscore' => ['har_bor'],
            'leading hyphen' => ['-harbor'],
            'trailing hyphen' => ['harbor-'],
            'dot' => ['har.bor'],
            'non-ascii' => ['café'],
            'too short' => ['ab'],
            'too long' => [str_repeat('a', 64)],
        ];
    }

    #[DataProvider('badFormats')]
    public function test_bad_formats_are_rejected(string $subdomain): void
    {
        $this->create(['subdomain' => $subdomain])->assertSessionHasErrors('subdomain');

        $this->assertDatabaseMissing('organizations', ['name' => 'Harbor Labs']);
        $this->assertSame(0, OrganizationHost::query()->count());
    }

    public function test_limits_are_inclusive(): void
    {
        $this->create(['subdomain' => 'abc'])->assertSessionHasNoErrors();
        $this->create(['name' => 'Long', 'subdomain' => str_repeat('a', 63)])->assertSessionHasNoErrors();
    }

    public function test_duplicate_of_an_organization_subdomain_is_rejected(): void
    {
        Organization::factory()->create(['subdomain' => 'taken']);

        $this->create(['subdomain' => 'TAKEN'])->assertSessionHasErrors('subdomain');
        $this->assertDatabaseMissing('organizations', ['name' => 'Harbor Labs']);
    }

    public function test_duplicate_of_a_host_is_rejected(): void
    {
        $other = Organization::factory()->create(['subdomain' => 'other-org']);
        OrganizationHost::factory()->create([
            'organization_id' => $other->id,
            'subdomain' => 'legacy',
            'host' => 'taken.maildesk.test',
        ]);

        $this->create(['subdomain' => 'taken'])->assertSessionHasErrors('subdomain');
        $this->assertDatabaseMissing('organizations', ['name' => 'Harbor Labs']);
    }

    public static function reservedNames(): array
    {
        return array_map(fn ($n) => [$n], [
            'www', 'app', 'api', 'admin', 'mail', 'smtp', 'imap', 'pop', 'ftp', 'docs', 'help',
            'support', 'billing', 'status', 'blog', 'dashboard', 'static', 'assets', 'cdn',
            'dev', 'staging', 'test', 'root', 'ns1', 'ns2', 'maildesk', 'ADMIN',
        ]);
    }

    #[DataProvider('reservedNames')]
    public function test_reserved_names_are_rejected(string $subdomain): void
    {
        $this->create(['subdomain' => $subdomain])->assertSessionHasErrors('subdomain');
        $this->assertDatabaseMissing('organizations', ['name' => 'Harbor Labs']);
    }

    public function test_central_domain_labels_and_config_additions_are_reserved(): void
    {
        config([
            'maildesk.central_domains' => ['console.maildesk.test', 'maildesk.test'],
            'subdomains.reserved' => ['internal'],
        ]);

        $this->create(['subdomain' => 'console'])->assertSessionHasErrors('subdomain');
        $this->create(['subdomain' => 'internal'])->assertSessionHasErrors('subdomain');
        $this->create(['subdomain' => 'harbor'])->assertSessionHasNoErrors();
    }

    public function test_new_workspace_resolves_from_its_host(): void
    {
        $this->create(['subdomain' => 'harbor'])->assertSessionHasNoErrors();

        $org = Organization::query()->where('name', 'Harbor Labs')->firstOrFail();

        $this->assertTrue(app(TenantResolver::class)->resolveFromHost('harbor.maildesk.test')?->is($org));
        $this->assertTrue(app(TenantResolver::class)->resolveFromHost('HARBOR.maildesk.test')?->is($org));
    }
}
