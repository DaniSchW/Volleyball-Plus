<?php

namespace App\Models;

use App\Support\Events\InviteeSync;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A player from a lower team who is invited to the host team's trainings
 * for good (until removed or switched off).
 */
class CrossNomination extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = ['host_team_id', 'person_id', 'home_team_id', 'active', 'invited_by'];

    protected $attributes = ['active' => true];

    protected static function booted(): void
    {
        $sync = fn (CrossNomination $n) => app(InviteeSync::class)->syncTeam($n->host_team_id);
        static::saved($sync);
        static::deleted($sync);
    }

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function hostTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'host_team_id');
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function homeTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'home_team_id');
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
