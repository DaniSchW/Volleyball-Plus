<?php

namespace App\Models;

use App\Support\Activity\LogsActivity;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The hall is closed from starts_on to ends_on (local days, inclusive).
 * Events there are cancelled or deleted (mode); series skip these days.
 */
class HallClosure extends Model
{
    use BelongsToTenant, HasUlids, LogsActivity;

    protected $fillable = ['hall_id', 'starts_on', 'ends_on', 'reason', 'mode'];

    protected function casts(): array
    {
        return ['starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d'];
    }

    /**
     * @return BelongsTo<Hall, $this>
     */
    public function hall(): BelongsTo
    {
        return $this->belongsTo(Hall::class);
    }

    public function activityLabel(): string
    {
        return ($this->hall?->name ?? '').' '.$this->starts_on?->format('d.m.Y').'–'.$this->ends_on?->format('d.m.Y');
    }
}
