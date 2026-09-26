<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A shared address (e.g. staff@company.com) that delivers to every member.
 */
class GroupAddress extends Model
{
    protected $fillable = [
        'organization_id',
        'email',
        'name',
        'description',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(GroupAddressMember::class)->orderBy('email');
    }

    /**
     * @return array<string, mixed>
     */
    public function toWorkspaceArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'name' => $this->name,
            'description' => $this->description,
            'active' => $this->active,
            'members_count' => $this->members_count ?? $this->members()->count(),
            'members' => $this->relationLoaded('members')
                ? $this->members->map(fn (GroupAddressMember $member) => [
                    'id' => $member->id,
                    'email' => $member->email,
                    'name' => $member->name,
                ])->values()->all()
                : [],
        ];
    }
}
