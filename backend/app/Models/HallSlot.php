<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A weekly time the hall is available (optionally only within a period).
 */
class HallSlot extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = ['hall_id', 'weekday', 'start_time', 'end_time', 'valid_from', 'valid_until'];

    protected function casts(): array
    {
        return ['weekday' => 'integer', 'valid_from' => 'date:Y-m-d', 'valid_until' => 'date:Y-m-d'];
    }

    /**
     * @return BelongsTo<Hall, $this>
     */
    public function hall(): BelongsTo
    {
        return $this->belongsTo(Hall::class);
    }
}
