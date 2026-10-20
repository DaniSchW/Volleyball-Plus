<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class EventInvitee extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'event_invitees';

    public $timestamps = false;

    protected $fillable = ['event_id', 'person_id', 'source'];

    protected function casts(): array
    {
        return ['attended' => 'boolean'];
    }
}
