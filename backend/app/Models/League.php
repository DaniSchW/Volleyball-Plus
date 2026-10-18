<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A level of the league tree (e.g. "Regionalliga" with the child
 * "Regionalliga Süd"). Level 1 is the highest; it ranks the teams.
 */
class League extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = ['parent_id', 'name', 'level', 'sort_order'];

    protected function casts(): array
    {
        return ['level' => 'integer', 'sort_order' => 'integer'];
    }

    /**
     * @return BelongsTo<League, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(League::class, 'parent_id');
    }

    /**
     * @return HasMany<League, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(League::class, 'parent_id');
    }
}
