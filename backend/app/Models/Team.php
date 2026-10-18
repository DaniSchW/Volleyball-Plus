<?php

namespace App\Models;

use App\Enums\CrossNominationMode;
use App\Enums\TeamGender;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'name',
        'gender',
        'is_youth',
        'rank_in_league',
        'min_participants',
        'default_max_participants',
        'cross_nomination_mode',
        'cross_nomination_hours',
        'decline_reason_required',
        'maybe_reason_required',
        'reasons_visible_to_teammates',
        'color',
    ];

    protected $attributes = [
        'is_youth' => false,
        'rank_in_league' => 1,
        'cross_nomination_mode' => 'immediately',
        'decline_reason_required' => false,
        'maybe_reason_required' => false,
        'reasons_visible_to_teammates' => false,
    ];

    protected function casts(): array
    {
        return [
            'gender' => TeamGender::class,
            'cross_nomination_mode' => CrossNominationMode::class,
            'is_youth' => 'boolean',
            'decline_reason_required' => 'boolean',
            'maybe_reason_required' => 'boolean',
            'reasons_visible_to_teammates' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }
}
