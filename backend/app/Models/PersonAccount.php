<?php

namespace App\Models;

use App\Enums\PersonRelation;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Links a member to a person they act for: their own profile (relation
 * "self") or a child's profile (relation "guardian").
 */
class PersonAccount extends Pivot
{
    use BelongsToTenant, HasUlids;

    protected $table = 'person_accounts';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['person_id', 'tenant_member_id', 'relation'];

    protected function casts(): array
    {
        return [
            'relation' => PersonRelation::class,
            'ended_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * @return BelongsTo<TenantMember, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(TenantMember::class, 'tenant_member_id');
    }
}
