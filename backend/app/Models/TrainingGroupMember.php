<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class TrainingGroupMember extends Model
{
    use BelongsToTenant, HasUlids;

    public $timestamps = false;

    protected $fillable = ['training_group_id', 'person_id'];
}
