<?php

namespace App\Models;

use Database\Factories\TemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Template extends Model
{
    /** @use HasFactory<TemplateFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'subject',
        'html',
        'status',
    ];

    protected $attributes = [
        'status' => 'draft',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toWorkspaceArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'subject' => $this->subject,
            'status' => $this->status ?: 'draft',
            'html' => $this->html,
            'updated' => $this->updated_at?->diffForHumans() ?? '',
            'accent' => '#22d3ee',
        ];
    }
}
