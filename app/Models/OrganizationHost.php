<?php

namespace App\Models;

use Database\Factories\OrganizationHostFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationHost extends Model
{
    /** @use HasFactory<OrganizationHostFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'subdomain',
        'host',
        'status',
        'ssl',
        'is_custom',
    ];

    protected function casts(): array
    {
        return [
            'ssl' => 'boolean',
            'is_custom' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toAdminArray(): array
    {
        return [
            'id' => $this->id,
            'accountId' => $this->organization_id,
            'account' => $this->organization?->name,
            'subdomain' => $this->subdomain,
            'host' => $this->host,
            'status' => $this->status,
            'ssl' => $this->ssl,
            'custom' => $this->is_custom,
            'created' => $this->created_at?->format('M j, Y') ?? '',
        ];
    }
}
