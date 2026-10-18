<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Log entry: who joined via which invitation. */
class InvitationUse extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = ['invitation_id', 'tenant_member_id', 'person_id'];
}
