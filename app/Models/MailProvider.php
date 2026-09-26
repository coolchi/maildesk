<?php

namespace App\Models;

use Database\Factories\MailProviderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MailProvider extends Model
{
    /** @use HasFactory<MailProviderFactory> */
    use HasFactory;

    /** Decrypted credentials must never be serialized into responses. */
    protected $hidden = ['config'];

    protected $fillable = [
        'key',
        'name',
        'driver',
        'type',
        'status',
        'is_default',
        'api_base',
        'regions',
        'features',
        'description',
        'config',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'regions' => 'array',
            'features' => 'array',
            'config' => 'encrypted:array',
        ];
    }

    public function organizations(): HasMany
    {
        return $this->hasMany(Organization::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toAdminArray(): array
    {
        return [
            'id' => $this->key,
            'dbId' => $this->id,
            'name' => $this->name,
            'driver' => $this->driver,
            'type' => $this->type,
            'status' => $this->status,
            'accounts' => $this->organizations_count ?? $this->organizations()->count(),
            'default' => $this->is_default,
            'regions' => $this->regions ?? [],
            'apiBase' => $this->api_base,
            'features' => $this->features ?? [],
            'description' => $this->description,
            // Secret values never leave the server; the admin form sends a blank
            // value back to keep the stored one (see AdminController::updateProvider).
            'config' => collect($this->config ?? [])->map(fn ($row) => [
                'key' => $row['key'] ?? '',
                'value' => ($row['secret'] ?? false) ? '' : ($row['value'] ?? ''),
                'secret' => (bool) ($row['secret'] ?? false),
                'hasValue' => ($row['value'] ?? '') !== '',
            ])->values()->all(),
        ];
    }

    /**
     * Keep stored secret values for rows the admin form submitted blank.
     *
     * @param  array<int, array{key?: string, value?: ?string, secret?: bool}>  $rows
     * @return array<int, array{key: string, value: string, secret: bool}>
     */
    public function mergeConfigKeepingSecrets(array $rows): array
    {
        $existing = collect($this->config ?? [])->keyBy(fn ($row) => strtoupper((string) ($row['key'] ?? '')));

        return collect($rows)->map(function ($row) use ($existing) {
            $key = (string) ($row['key'] ?? '');
            $value = (string) ($row['value'] ?? '');
            $previous = $existing->get(strtoupper($key));

            if ($value === '' && $previous && ($previous['secret'] ?? false)) {
                $value = (string) ($previous['value'] ?? '');
            }

            return ['key' => $key, 'value' => $value, 'secret' => (bool) ($row['secret'] ?? false)];
        })->values()->all();
    }

    /**
     * @return array{mode:string,host:string,port:int,ports:list<int>,username:string,password:string,encryption:string,label:string,driver:string,apiBase:?string}|null
     */
    public function smtpCredentials(): ?array
    {
        $get = function (string $key): string {
            foreach ($this->config ?? [] as $row) {
                if (($row['key'] ?? '') === $key) {
                    return (string) ($row['value'] ?? '');
                }
            }

            return '';
        };

        if ($this->driver === 'smtp' || $this->type === 'smtp') {
            return [
                'mode' => 'smtp',
                'host' => $get('HOST') ?: 'smtp.example.com',
                'port' => (int) ($get('PORT') ?: 587),
                'ports' => [465, 587, 2587],
                'username' => $get('USERNAME'),
                'password' => $get('PASSWORD') ?: '••••••••',
                'encryption' => $get('ENCRYPTION') ?: 'tls',
                'label' => $this->name,
                'driver' => $this->driver ?: 'smtp',
                'apiBase' => null,
            ];
        }

        return [
            'mode' => 'api-relay',
            'host' => 'smtp.'.(string) config('maildesk.base_domain', 'maildesk.test'),
            'port' => 465,
            'ports' => [465, 587, 2587],
            'username' => 'maildesk',
            'password' => 'YOUR_API_KEY',
            'encryption' => 'tls',
            'label' => $this->name,
            'driver' => $this->driver ?: 'api',
            'apiBase' => $this->api_base,
        ];
    }
}
