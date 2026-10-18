<?php

namespace App\Models;

use App\Enums\NotificationCategory;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A member's choice for one notification category in a department. Only
 * deviations from the defaults are stored.
 */
class NotificationPreference extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = ['tenant_member_id', 'category', 'push', 'email'];

    protected function casts(): array
    {
        return [
            'category' => NotificationCategory::class,
            'push' => 'boolean',
            'email' => 'boolean',
        ];
    }
}
