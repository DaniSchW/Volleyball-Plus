<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Secret ICS subscription URL of a member (free calendar sync). */
class CalendarFeed extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = ['tenant_member_id', 'token_hash', 'token_encrypted'];

    protected $hidden = ['token_hash', 'token_encrypted'];

    protected function casts(): array
    {
        return ['token_encrypted' => 'encrypted'];
    }
}
