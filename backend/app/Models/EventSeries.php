<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Weekly training series (e.g. every Tuesday and Thursday 19:30–21:30). */
class EventSeries extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'event_series';

    protected $fillable = [
        'team_id', 'title', 'description', 'weekdays', 'interval_weeks', 'starts_on', 'ends_on', 'start_time', 'end_time',
        'location_name', 'address', 'rsvp_deadline_minutes', 'max_participants', 'generated_until', 'created_by', 'training_group_id',
    ];

    protected function casts(): array
    {
        return [
            'weekdays' => 'array',
            'interval_weeks' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'generated_until' => 'date',
            'rsvp_deadline_minutes' => 'integer',
            'max_participants' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return HasMany<Event, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'series_id');
    }
}
