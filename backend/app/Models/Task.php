<?php

namespace App\Models;

use App\Support\Activity\LogsActivity;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something to do for an event (drive, bring cake, sell tickets) with a
 * number of places that people take.
 */
class Task extends Model
{
    use BelongsToTenant, HasUlids, LogsActivity;

    protected $fillable = ['event_id', 'title', 'description', 'slots', 'sort_order'];

    // slots null = as many people as want.
    protected $attributes = ['sort_order' => 0];

    protected function casts(): array
    {
        return ['slots' => 'integer', 'sort_order' => 'integer'];
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return HasMany<TaskAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(TaskAssignment::class);
    }
}
