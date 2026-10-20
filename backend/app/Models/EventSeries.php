<?php

namespace App\Models;

use App\Support\Activity\LogsActivity;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Weekly training series (e.g. every Tuesday and Thursday 19:30–21:30). */
class EventSeries extends Model
{
    use BelongsToTenant, HasUlids, LogsActivity;

    protected $table = 'event_series';

    protected $fillable = [
        'team_id', 'title', 'description', 'weekdays', 'interval_weeks', 'starts_on', 'ends_on', 'start_time', 'end_time',
        'location_name', 'address', 'rsvp_deadline_minutes', 'max_participants', 'min_participants', 'guests_allowed', 'guests_positions_only', 'position_limits', 'generated_until', 'created_by', 'training_group_id', 'task_template_id',
    ];

    protected function casts(): array
    {
        return [
            'weekdays' => 'array',
            'position_limits' => 'array',
            'guests_allowed' => 'boolean',
            'guests_positions_only' => 'boolean',
            'min_participants' => 'integer',
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

    /** @var list<string> */
    public array $activityIgnore = ['generated_until'];

    public function activityLabel(): string
    {
        return $this->title;
    }
}
