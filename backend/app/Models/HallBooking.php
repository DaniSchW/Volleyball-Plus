<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A team's weekly time in a hall (whole hall or one court).
 */
class HallBooking extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = ['hall_id', 'team_id', 'weekday', 'start_time', 'end_time', 'court', 'valid_from', 'valid_until', 'notes'];

    protected function casts(): array
    {
        return ['weekday' => 'integer', 'court' => 'integer', 'valid_from' => 'date:Y-m-d', 'valid_until' => 'date:Y-m-d'];
    }

    /**
     * @return BelongsTo<Hall, $this>
     */
    public function hall(): BelongsTo
    {
        return $this->belongsTo(Hall::class);
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
