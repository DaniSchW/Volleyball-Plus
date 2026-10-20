<?php

namespace App\Models;

use App\Support\Activity\LogsActivity;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reusable list of tasks ("Spieltag Damen 1": Kasse ×2, Verkauf ×3,
 * Linienrichter ×2, …). For one team or (team null) the whole department.
 * items: list of {title, description, slots} (slots null = any number).
 */
class TaskTemplate extends Model
{
    use BelongsToTenant, HasUlids, LogsActivity;

    protected $fillable = ['team_id', 'name', 'items', 'created_by'];

    protected function casts(): array
    {
        return ['items' => 'array'];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function activityLabel(): string
    {
        return $this->name;
    }
}
