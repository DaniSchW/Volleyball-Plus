<?php

namespace App\Models;

use App\Enums\TenantStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A club's volleyball department – the root of all tenant-scoped data.
 * Many clubs share one installation; each is fully separate.
 */
class Tenant extends Model
{
    use HasUlids;

    protected $fillable = [
        'slug',
        'name',
        'default_locale',
        'timezone',
        'season_start_month',
        'mail_from_name',
        'status',
        'city',
        'contact_name',
        'contact_email',
        'registration_note',
        'approved_at',
    ];

    /** Paths at the top level of the app that can never be a club's slug. */
    public const RESERVED_SLUGS = [
        'api', 'app', 'assets', 'cron', 'icons', 'install', 'login', 'logout', 'platform', 'plattform',
        'register', 'registrieren', 'admin', 'impressum', 'datenschutz', 'agb', 'imprint', 'privacy', 'terms', 'sw', 'manifest', 'workbox', 'favicon', 'robots', 'up', 'health',
    ];

    protected $attributes = [
        'default_locale' => 'de',
        'timezone' => 'Europe/Berlin',
        'season_start_month' => 7,
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'season_start_month' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Team, $this>
     */
    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }
}
