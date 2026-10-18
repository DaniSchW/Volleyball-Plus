<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Opposing club with its logo, reused across match days. */
class Opponent extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = ['name'];
}
