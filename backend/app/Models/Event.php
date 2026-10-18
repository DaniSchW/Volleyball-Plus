<?php

namespace App\Models;

use App\Enums\EventScope;
use App\Enums\EventStatus;
use App\Enums\EventType;
use App\Enums\ResponseMode;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Event extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'type', 'response_mode', 'scope', 'title', 'description', 'starts_at', 'ends_at', 'meet_at',
        'rsvp_deadline', 'location_name', 'address', 'max_participants', 'series_id', 'series_exception',
        'opponent_id', 'is_home', 'status', 'created_by', 'cross_nomination_opens_at', 'training_group_id',
    ];

    protected $attributes = [
        'response_mode' => 'rsvp',
        'scope' => 'team',
        'status' => 'scheduled',
        'series_exception' => false,
    ];

    protected function casts(): array
    {
        return [
            'type' => EventType::class,
            'response_mode' => ResponseMode::class,
            'scope' => EventScope::class,
            'status' => EventStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'meet_at' => 'datetime',
            'rsvp_deadline' => 'datetime',
            'cross_nomination_opens_at' => 'datetime',
            'max_participants' => 'integer',
            'series_exception' => 'boolean',
            'reminder_sent_at' => 'datetime',
            'is_home' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Team, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'event_teams');
    }

    /**
     * @return HasMany<EventInvitee, $this>
     */
    public function invitees(): HasMany
    {
        return $this->hasMany(EventInvitee::class);
    }

    /**
     * @return HasMany<EventResponse, $this>
     */
    public function responses(): HasMany
    {
        return $this->hasMany(EventResponse::class);
    }

    /**
     * @return BelongsTo<Opponent, $this>
     */
    public function opponent(): BelongsTo
    {
        return $this->belongsTo(Opponent::class);
    }

    /**
     * @return BelongsTo<TrainingGroup, $this>
     */
    public function trainingGroup(): BelongsTo
    {
        return $this->belongsTo(TrainingGroup::class);
    }

    /**
     * @return BelongsTo<EventSeries, $this>
     */
    public function series(): BelongsTo
    {
        return $this->belongsTo(EventSeries::class);
    }

    public function isCancelled(): bool
    {
        return $this->status === EventStatus::Cancelled;
    }

    public function expectsResponses(): bool
    {
        return $this->response_mode === ResponseMode::Rsvp && ! $this->isCancelled();
    }

    public function deadlinePassed(?Carbon $now = null): bool
    {
        $now ??= Carbon::now();

        return $this->rsvp_deadline !== null ? $this->rsvp_deadline->lessThanOrEqualTo($now) : $this->starts_at->lessThanOrEqualTo($now);
    }

    /** Google Maps route – a plain link, no API key, address not validated. */
    public function directionsUrl(): ?string
    {
        $address = trim((string) ($this->address ?: $this->location_name));

        return $address === '' ? null : 'https://www.google.com/maps/dir/?api=1&destination='.rawurlencode($address);
    }
}
