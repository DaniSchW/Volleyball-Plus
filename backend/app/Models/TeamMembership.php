<?php

namespace App\Models;

use App\Enums\TeamRole;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamMembership extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = ['team_id', 'person_id', 'role', 'joined_at'];

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
