<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\OrganizationHost;
use Closure;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Validation and registration of platform subdomains
 * ({subdomain}.{base_domain}) for workspaces.
 */
class WorkspaceSubdomain
{
    public const PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

    public function __construct(private readonly TenantResolver $tenants) {}

    public static function normalize(mixed $value): mixed
    {
        return is_string($value) ? Str::lower(trim($value)) : $value;
    }

    public function baseDomain(): string
    {
        return $this->tenants->baseDomain();
    }

    public function hostFor(string $subdomain): string
    {
        return $subdomain.'.'.$this->baseDomain();
    }

    /**
     * Configured reserved labels plus the labels of the central domains.
     *
     * @return list<string>
     */
    public function reserved(): array
    {
        $labels = array_map('strval', (array) config('subdomains.reserved', []));
        $base = $this->baseDomain();

        foreach (array_merge($this->tenants->centralHosts(), [$base]) as $central) {
            $central = Str::lower((string) $central);

            if ($central === '') {
                continue;
            }

            // "maildesk.test" reserves "maildesk"; "app.maildesk.test" reserves "app".
            $labels[] = Str::endsWith($central, '.'.$base)
                ? Str::before($central, '.'.$base)
                : Str::before($central, '.');
        }

        return array_values(array_unique(array_filter(array_map(
            fn (string $label) => Str::lower(trim($label)),
            $labels,
        ))));
    }

    /**
     * Rules for an already-lowercased subdomain.
     *
     * @return array<int, mixed>
     */
    public function rules(): array
    {
        return [
            'required',
            'string',
            'min:3',
            'max:63',
            'regex:'.self::PATTERN,
            Rule::notIn($this->reserved()),
            Rule::unique('organizations', 'subdomain'),
            function (string $attribute, mixed $value, Closure $fail) {
                if (is_string($value) && OrganizationHost::query()
                    ->whereRaw('LOWER(host) = ?', [$this->hostFor($value)])
                    ->exists()) {
                    $fail('That subdomain is already taken.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'subdomain.regex' => 'Use lowercase letters, numbers and hyphens only, not starting or ending with a hyphen.',
            'subdomain.not_in' => 'That subdomain is reserved. Choose another.',
            'subdomain.unique' => 'That subdomain is already taken.',
        ];
    }

    /**
     * Point the workspace at {subdomain}.{base_domain}. Call inside a transaction.
     */
    public function register(Organization $organization, string $subdomain): OrganizationHost
    {
        $organization->forceFill(['subdomain' => $subdomain])->save();

        return OrganizationHost::query()->create([
            'organization_id' => $organization->id,
            'subdomain' => $subdomain,
            'host' => $this->hostFor($subdomain),
            'status' => 'active',
            'ssl' => true,
            'is_custom' => false,
        ]);
    }
}
