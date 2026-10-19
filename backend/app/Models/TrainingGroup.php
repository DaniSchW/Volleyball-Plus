<?php

namespace App\Models;

use App\Support\Activity\LogsActivity;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A group within a team with its own trainings (e.g. setter training).
 */
class TrainingGroup extends Model
{
    use BelongsToTenant, HasUlids, LogsActivity;

    protected $fillable = ['team_id', 'name'];

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return HasMany<TrainingGroupMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(TrainingGroupMember::class);
    }
}
