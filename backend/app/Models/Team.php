<?php

namespace App\Models;

use App\Enums\CrossNominationMode;
use App\Enums\RsvpVisibility;
use App\Enums\TeamGender;
use App\Support\Activity\LogsActivity;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends Model
{
    use BelongsToTenant, HasUlids, LogsActivity;

    protected $fillable = [
        'name',
        'gender',
        'is_youth',
        'league_id',
        'rank_in_league',
        'min_participants',
        'default_max_participants',
        'ideal_positions',
        'cross_nomination_mode',
        'cross_nomination_hours',
        'decline_reason_required',
        'maybe_reason_required',
        'reasons_visible_to_teammates',
        'rsvp_visibility',
        'color',
    ];

    protected $attributes = [
        'is_youth' => false,
        'rank_in_league' => 1,
        'cross_nomination_mode' => 'immediately',
        'decline_reason_required' => false,
        'maybe_reason_required' => false,
        'reasons_visible_to_teammates' => false,
        'rsvp_visibility' => 'names',
    ];

    protected function casts(): array
    {
        return [
            'gender' => TeamGender::class,
            'cross_nomination_mode' => CrossNominationMode::class,
            'rsvp_visibility' => RsvpVisibility::class,
            'is_youth' => 'boolean',
            'ideal_positions' => 'array',
            'decline_reason_required' => 'boolean',
            'maybe_reason_required' => 'boolean',
            'reasons_visible_to_teammates' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<League, $this>
     */
    public function league(): BelongsTo
    {
        return $this->belongsTo(League::class);
    }

    /**
     * @return HasMany<TrainingGroup, $this>
     */
    public function trainingGroups(): HasMany
    {
        return $this->hasMany(TrainingGroup::class);
    }

    /**
     * @return HasMany<TeamMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(TeamMembership::class);
    }
}
