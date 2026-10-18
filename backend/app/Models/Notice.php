<?php

namespace App\Models;

use App\Enums\EventScope;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Note in the notice area, shown for a chosen period (team or whole department). */
class Notice extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = ['scope', 'team_id', 'title', 'body', 'visible_from', 'visible_until', 'created_by'];

    protected function casts(): array
    {
        return [
            'scope' => EventScope::class,
            'visible_from' => 'datetime',
            'visible_until' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
