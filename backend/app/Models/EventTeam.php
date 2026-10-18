<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class EventTeam extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'event_teams';

    public $timestamps = false;

    protected $fillable = ['event_id', 'team_id'];
}
