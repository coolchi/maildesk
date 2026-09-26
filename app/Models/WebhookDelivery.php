<?php

namespace App\Models;

use Database\Factories\WebhookDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDelivery extends Model
{
    /** @use HasFactory<WebhookDeliveryFactory> */
    use HasFactory;

    protected $fillable = [
        'webhook_id',
        'event',
        'payload',
        'response_status',
        'response_body',
        'status',
        'attempts',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'delivered_at' => 'datetime',
        ];
    }

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toWorkspaceArray(): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'status' => $this->status,
            'response_status' => $this->response_status,
            'attempts' => $this->attempts,
            'error' => $this->status === 'failed' ? mb_substr((string) $this->response_body, 0, 300) : null,
            'delivered_at' => ($this->delivered_at ?? $this->created_at)?->diffForHumans() ?? '',
            'payload' => $this->payload,
        ];
    }
}
