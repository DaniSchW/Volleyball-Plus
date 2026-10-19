<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One change in the club (created / changed / deleted) for the admins'
 * activity log. Kept for 7 days.
 */
class ActivityLog extends Model
{
    use BelongsToTenant, HasUlids;

    public const KEEP_DAYS = 7;

    protected $table = 'activity_log';

    public const UPDATED_AT = null;

    protected $fillable = ['actor_id', 'action', 'subject_type', 'subject_id', 'label'];
}
