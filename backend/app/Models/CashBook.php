<?php

namespace App\Models;

use App\Support\Activity\LogsActivity;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cash book of a team or of the department – informative bookkeeping only.
 */
class CashBook extends Model
{
    use BelongsToTenant, HasUlids, LogsActivity;

    public const KIND_TEAM = 'team';

    public const KIND_DEPARTMENT = 'department';

    protected $fillable = ['kind', 'team_id', 'book_key', 'opening_balance_cents'];

    protected $attributes = ['opening_balance_cents' => 0];

    protected function casts(): array
    {
        return ['opening_balance_cents' => 'integer'];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return HasMany<CashEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(CashEntry::class);
    }

    public function isDepartment(): bool
    {
        return $this->kind === self::KIND_DEPARTMENT;
    }

    public function activityLabel(): string
    {
        return $this->isDepartment() ? __('cash.department') : ($this->team?->name ?? '');
    }
}
