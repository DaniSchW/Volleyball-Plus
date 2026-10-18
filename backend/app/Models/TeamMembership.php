<?php

namespace App\Models;

use App\Enums\TeamRole;
use App\Support\Events\InviteeSync;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamMembership extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = ['team_id', 'person_id', 'role', 'joined_at'];

    protected static function booted(): void
    {
        // Upcoming events of the team invite the current roster. The person's
        // other teams too: playing in a higher team excludes from lower matches.
        $sync = fn (TeamMembership $membership) => app(InviteeSync::class)->syncTeams(
            TeamMembership::query()->where('person_id', $membership->person_id)->pluck('team_id')->push($membership->team_id),
        );
        static::created($sync);
        static::deleted($sync);
    }

    protected function casts(): array
    {
        return [
            'role' => TeamRole::class,
            'joined_at' => 'date',
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
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
