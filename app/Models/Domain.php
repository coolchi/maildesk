<?php

namespace App\Models;

use Database\Factories\DomainFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Domain extends Model
{
    /** @use HasFactory<DomainFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'status',
        'provider',
        'provider_domain_id',
        'dns_records',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'dns_records' => 'array',
            'verified_at' => 'datetime',
        ];
    }

    public function dnsConnection(): HasOne
    {
        return $this->hasOne(DnsConnection::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return array{records: list<array<string, mixed>>, checks: array{spf: bool, dkim: bool, dmarc: bool}}
     */
    public static function defaultDnsRecords(string $name): array
    {
        return [
            'checks' => [
                'spf' => false,
                'dkim' => false,
                'dmarc' => false,
            ],
            'records' => [
                [
                    'type' => 'TXT',
                    'name' => "resend._domainkey.{$name}",
                    'value' => 'p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQMockDkimKey…',
                    'label' => 'DKIM',
                    'key' => 'dkim',
                ],
                [
                    'type' => 'TXT',
                    'name' => $name,
                    'value' => 'v=spf1 include:amazonses.com ~all',
                    'label' => 'SPF',
                    'key' => 'spf',
                ],
                [
                    'type' => 'TXT',
                    'name' => "_dmarc.{$name}",
                    'value' => 'v=DMARC1; p=none;',
                    'label' => 'DMARC',
                    'key' => 'dmarc',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toWorkspaceArray(?string $region = null): array
    {
        $dns = $this->normalizedDnsRecords();
        $providerStatus = $dns['provider']['status'] ?? null;

        $displayStatus = $this->status;
        if ($providerStatus === 'partially_verified' && $this->status === 'verified') {
            $displayStatus = 'partially_verified';
        }

        $pendingRecords = $this->getPendingRecordsFromProvider($dns);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $displayStatus,
            'region' => $region ?? $this->organization?->region ?? 'us-east-1',
            'created' => $this->created_at?->timezone(config('app.timezone'))->format('M j, Y') ?? '',
            'records' => $dns['checks'],
            'dns_rows' => $dns['records'],
            'provider' => $this->provider,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'checked_at' => $dns['checked_at'] ?? null,
            'results' => $dns['results'] ?? (object) [],
            'required' => $dns['required'] ?? ['spf', 'dkim'],
            'warnings' => $dns['warnings'] ?? [],
            'provider_domain_id' => $this->provider_domain_id,
            'provider_status' => $providerStatus,
            'provider_error' => $dns['provider_error'] ?? null,
            'pending_records' => $pendingRecords,
        ];
    }

    /**
     * Extract the list of pending record names from the provider's records data.
     *
     * @param  array<string, mixed>  $dns
     * @return list<string>
     */
    protected function getPendingRecordsFromProvider(array $dns): array
    {
        $pending = [];

        $providerRecords = $dns['provider']['records'] ?? null;

        if (is_array($providerRecords)) {
            foreach ($providerRecords as $record) {
                if (is_array($record) && isset($record['status']) && $record['status'] !== 'verified') {
                    $recordName = $record['record'] ?? $record['type'] ?? 'Unknown';
                    $pending[] = $recordName;
                }
            }
        }

        if ($pending === [] && ($dns['provider']['status'] ?? null) === 'partially_verified') {
            foreach ($dns['records'] ?? [] as $record) {
                $key = $record['key'] ?? '';
                if ($key !== '' && isset($dns['checks'][$key]) && ! $dns['checks'][$key]) {
                    $pending[] = strtoupper($record['label'] ?? $key);
                }
            }
        }

        return $pending;
    }

    /**
     * @return array{records: list<array<string, mixed>>, checks: array<string, bool>}&array<string, mixed>
     */
    public function normalizedDnsRecords(): array
    {
        $raw = $this->dns_records;

        if (is_array($raw) && isset($raw['records'], $raw['checks'])) {
            $checks = array_map(fn ($value) => (bool) $value, (array) $raw['checks']);

            foreach (['spf', 'dkim', 'dmarc'] as $key) {
                $checks[$key] ??= false;
            }

            return [
                ...$raw,
                'records' => array_values($raw['records']),
                'checks' => $checks,
            ];
        }

        if (is_array($raw) && array_is_list($raw)) {
            return [
                'records' => $raw,
                'checks' => [
                    'spf' => $this->status === 'verified',
                    'dkim' => $this->status === 'verified',
                    'dmarc' => $this->status === 'verified',
                ],
            ];
        }

        return self::defaultDnsRecords($this->name);
    }
}
