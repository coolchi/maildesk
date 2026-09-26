<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'is_platform_admin', 'preferences'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
            'preferences' => 'array',
        ];
    }

    /**
     * Whether a notification sound should play when new mail arrives.
     * Defaults to on when unset.
     */
    public function prefersInboxSound(): bool
    {
        $prefs = $this->preferences ?? [];

        if (! array_key_exists('inbox_sound', $prefs)) {
            return true;
        }

        return (bool) $prefs['inbox_sound'];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function mergePreferences(array $values): void
    {
        $this->forceFill([
            'preferences' => array_merge($this->preferences ?? [], $values),
        ])->save();
    }

    public function isPlatformAdmin(): bool
    {
        return (bool) $this->is_platform_admin;
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function mailboxes(): HasMany
    {
        return $this->hasMany(Mailbox::class);
    }
}
