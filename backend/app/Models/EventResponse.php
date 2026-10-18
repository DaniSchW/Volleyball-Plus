<?php

namespace App\Models;

use App\Enums\RsvpStatus;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventResponse extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = ['event_id', 'person_id', 'status', 'reason', 'responded_by', 'responded_at'];

    protected function casts(): array
    {
        return [
            'status' => RsvpStatus::class,
            'responded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
