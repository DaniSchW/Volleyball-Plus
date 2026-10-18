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

    public function getRememberTokenName(): ?string
    {
        return null;
    }
}
