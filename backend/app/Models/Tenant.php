<?php

namespace App\Models;

use App\Enums\TenantStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A department (Abteilung) – the root of all tenant-scoped data.
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
