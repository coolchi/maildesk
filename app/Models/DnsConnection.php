<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A client's DNS host account, linked to one sending domain so MailDesk can
 * read and write that domain's mail records (MX, SPF, DKIM, DMARC).
 */
class DnsConnection extends Model
{
    protected $fillable = [
        'organization_id',
        'domain_id',
        'provider',
        'credentials',
        'zone_id',
        'zone_name',
    ];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toWorkspaceArray(): array
    {
        return [
            'provider' => $this->provider,
            'provider_label' => match ($this->provider) {
                'cloudflare' => 'Cloudflare',
                default => ucfirst($this->provider),
            },
            'zone_name' => $this->zone_name,
            'connected_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
