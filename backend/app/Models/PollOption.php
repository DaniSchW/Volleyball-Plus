<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PollOption extends Model
{
    use BelongsToTenant, HasUlids;

    public $timestamps = false;

    protected $fillable = ['poll_id', 'label', 'sort_order'];
}
