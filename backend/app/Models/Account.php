<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Global login identity (one per e-mail address). There is no password –
 * login only via magic link. Department data hangs off TenantMember.
 */
class Account extends Authenticatable
{
    use HasUlids;

    protected $fillable = ['email', 'locale'];

    protected $attributes = ['locale' => 'de'];

    protected $hidden = ['remember_token'];

    protected function casts(): array
    {
        return [
            'is_platform_admin' => 'boolean',
            'last_login_at' => 'datetime',
            'last_active_at' => 'datetime',
            'deletion_warned_at' => 'datetime',
            'marketing_opt_in_at' => 'datetime',
        ];
    }

    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => mb_strtolower(trim($value)),
        );
    }

    /**
     * @return HasMany<AccountSession, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(AccountSession::class);
    }

    /** Listed in SUPERADMIN_EMAILS: clubs and all accounts. */
    public function isSuperadmin(): bool
    {
        return in_array($this->email, config('platform.superadmins'), true);
    }

    /** Platform access (approve clubs): platform admins and superadmins. */
    public function isPlatformAdmin(): bool
    {
        return (bool) $this->is_platform_admin || $this->isSuperadmin();
    }

    /** Deleted accounts keep their row with an invalid address. */
    public function isDeleted(): bool
    {
        return str_ends_with($this->email, '@invalid');
    }

    /** Used the app (throttled to once an hour); a pending deletion notice is void. */
    public function markActive(): void
    {
        if ($this->last_active_at === null || $this->last_active_at->lessThan(now()->subHour()) || $this->deletion_warned_at !== null) {
            $this->forceFill(['last_active_at' => now(), 'deletion_warned_at' => null])->save();
        }
    }

    public function getRememberTokenName(): ?string
    {
        return null;
    }
}
