<?php

namespace App\Models;

use App\Enums\EventScope;
use App\Support\Activity\LogsActivity;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A poll for one or more teams (admins: for all teams at once).
 */
class Poll extends Model
{
    use BelongsToTenant, HasUlids, LogsActivity;

    protected $fillable = ['title', 'description', 'scope', 'multiple_choice', 'anonymous', 'allow_free_text', 'closes_at', 'created_by'];

    protected $attributes = ['scope' => 'team', 'multiple_choice' => false, 'anonymous' => false, 'allow_free_text' => false];

    protected function casts(): array
    {
        return [
            'scope' => EventScope::class,
            'multiple_choice' => 'boolean',
            'anonymous' => 'boolean',
            'allow_free_text' => 'boolean',
            'closes_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<Team, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'poll_teams');
    }

    /**
     * @return HasMany<PollOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(PollOption::class)->orderBy('sort_order');
    }

    /**
     * @return HasMany<PollVote, $this>
     */
    public function votes(): HasMany
    {
        return $this->hasMany(PollVote::class);
    }

    public function isClosed(): bool
    {
        return $this->closes_at !== null && $this->closes_at->lessThanOrEqualTo(Carbon::now());
    }
}
