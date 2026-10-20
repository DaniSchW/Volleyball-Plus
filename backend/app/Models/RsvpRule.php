<?php

namespace App\Models;

use App\Enums\EventType;
use App\Enums\RsvpStatus;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A standing reply of a person ("always yes on Wednesday trainings of
 * Herren 1"). Applied to the matching upcoming events and to new ones
 * when they are created; single events can still be answered differently.
 * Empty filters match everything (team, series, type, weekdays 1 = Monday).
 */
class RsvpRule extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = ['person_id', 'team_id', 'series_id', 'event_type', 'weekdays', 'status', 'reason', 'created_by'];

    protected function casts(): array
    {
        return ['status' => RsvpStatus::class, 'event_type' => EventType::class, 'weekdays' => 'array'];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<EventSeries, $this>
     */
    public function series(): BelongsTo
    {
        return $this->belongsTo(EventSeries::class);
    }
}
