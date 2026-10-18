<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One chosen option of a person (or a free-text answer without option).
 */
class PollVote extends Model
{
    use BelongsToTenant, HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = ['poll_id', 'option_id', 'person_id', 'voted_by', 'free_text'];
}
