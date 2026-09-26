<?php

namespace App\Models;

use Database\Factories\MailDraftFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailDraft extends Model
{
    /** @use HasFactory<MailDraftFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'user_id',
        'mailbox_id',
        'thread_id',
        'from',
        'to',
        'cc',
        'bcc',
        'subject',
        'html',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toWorkspaceArray(): array
    {
        return [
            'id' => $this->id,
            'from' => $this->from,
            'to' => $this->to,
            'cc' => $this->cc,
            'bcc' => $this->bcc,
            'subject' => $this->subject ?: '(no subject)',
            'html' => $this->html,
            'thread_id' => $this->thread_id,
            'updated' => $this->updated_at?->diffForHumans() ?? '',
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
