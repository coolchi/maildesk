<?php

namespace App\Models;

use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'default_provider',
        'status',
        'plan',
        'product',
        'subdomain',
        'custom_domain',
        'mrr',
        'seats',
        'emails_30d',
        'region',
        'owner_name',
        'owner_email',
        'mail_provider_id',
        'provisioned_at',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'mrr' => 'integer',
            'seats' => 'integer',
            'emails_30d' => 'integer',
            'provisioned_at' => 'datetime',
            'settings' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Organization $organization): void {
            if (empty($organization->slug)) {
                $organization->slug = Str::slug($organization->name);
            }
        });
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function mailProvider(): BelongsTo
    {
        return $this->belongsTo(MailProvider::class);
    }

    public function hosts(): HasMany
    {
        return $this->hasMany(OrganizationHost::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    public function mailboxes(): HasMany
    {
        return $this->hasMany(Mailbox::class);
    }

    public function threads(): HasMany
    {
        return $this->hasMany(Thread::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    public function webhooks(): HasMany
    {
        return $this->hasMany(Webhook::class);
    }

    public function providerConfigs(): HasMany
    {
        return $this->hasMany(ProviderConfig::class);
    }

    public function suppressions(): HasMany
    {
        return $this->hasMany(Suppression::class);
    }

    public function templates(): HasMany
    {
        return $this->hasMany(Template::class);
    }

    public function broadcasts(): HasMany
    {
        return $this->hasMany(Broadcast::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function segments(): HasMany
    {
        return $this->hasMany(Segment::class);
    }

    public function automations(): HasMany
    {
        return $this->hasMany(Automation::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toAdminArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'owner' => $this->owner_name,
            'email' => $this->owner_email,
            'plan' => $this->plan,
            'product' => $this->product,
            'provider' => $this->mailProvider?->key ?? $this->default_provider,
            'status' => $this->status,
            'subdomain' => $this->subdomain,
            'customDomain' => $this->custom_domain,
            'mrr' => $this->mrr,
            'seats' => $this->seats,
            'emails30d' => $this->emails_30d,
            'created' => $this->provisioned_at?->format('M j, Y')
                ?? $this->created_at?->format('M j, Y')
                ?? '',
            'region' => $this->region,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toWorkspaceArray(): array
    {
        $provider = $this->mailProvider;
        $providerOk = $provider !== null && $provider->status === 'active';
        $colors = ['cyan', 'violet', 'emerald', 'amber'];
        $color = $colors[$this->id % count($colors)] ?? 'cyan';
        $base = (string) config('maildesk.base_domain', 'maildesk.test');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'plan' => $this->plan,
            'product' => $this->product,
            'provider' => $provider?->key ?? $this->default_provider,
            'providerName' => $provider?->name ?? ($this->default_provider ?: 'None'),
            'providerDriver' => $provider?->driver,
            'providerOk' => $providerOk,
            'providerReason' => $providerOk
                ? 'active'
                : ($provider ? 'disabled' : 'orphaned'),
            'email' => $this->owner_email,
            'owner' => $this->owner_name,
            'subdomain' => $this->subdomain,
            'host' => $this->subdomain ? "{$this->subdomain}.{$base}" : null,
            'customDomain' => $this->custom_domain,
            'status' => $this->status,
            'color' => $color,
        ];
    }
}
