<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupAddressMember extends Model
{
    protected $fillable = [
        'group_address_id',
        'email',
        'name',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(GroupAddress::class, 'group_address_id');
    }
}
