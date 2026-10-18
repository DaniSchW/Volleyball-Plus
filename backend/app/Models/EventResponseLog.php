<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Append-only history of replies (who answered what and when). */
class EventResponseLog extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = ['event_id', 'person_id', 'old_status', 'new_status', 'responded_by', 'after_deadline'];

    protected function casts(): array
    {
        return ['after_deadline' => 'boolean'];
    }
}
