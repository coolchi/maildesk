<?php

namespace App\Models;

use Database\Factories\SegmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Segment extends Model
{
    /** @use HasFactory<SegmentFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'description',
        'rules',
    ];

    protected function casts(): array
    {
        return [
            'rules' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toWorkspaceArray(?int $memberCount = null, ?string $ruleSummary = null): array
    {
        $count = $memberCount;
        if ($count === null) {
            $count = array_key_exists('members_count', $this->attributes)
                ? (int) $this->attributes['members_count']
                : ($this->relationLoaded('contacts')
                    ? $this->contacts->count()
                    : $this->contacts()->count());
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'rules' => $this->rules ?? [],
            'rule' => $ruleSummary ?? '',
            'contacts' => $count,
            'count' => $count,
            'created' => $this->created_at?->diffForHumans() ?? '',
            'updated' => $this->updated_at?->diffForHumans() ?? '',
        ];
    }
}
