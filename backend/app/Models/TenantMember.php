<?php

namespace App\Models;

use App\Enums\MemberStatus;
use App\Enums\PersonRelation;
use App\Support\Activity\LogsActivity;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * An account's membership in a department; carries the admin role.
 */
class TenantMember extends Model
{
    use BelongsToTenant, HasUlids, LogsActivity;

    protected $fillable = ['account_id', 'is_admin', 'status'];

    protected $attributes = ['is_admin' => false, 'status' => 'active'];

    protected function casts(): array
    {
        return [
            'is_admin' => 'boolean',
            'status' => MemberStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Persons this member acts for (own profile and children), active links only.
     *
     * @return BelongsToMany<Person, $this>
     */
    public function persons(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'person_accounts')
            ->using(PersonAccount::class)
            ->withPivot(['id', 'relation', 'ended_at'])
            ->wherePivotNull('ended_at');
    }

    public function ownPerson(): ?Person
    {
        return $this->persons()->wherePivot('relation', PersonRelation::Own->value)->first();
    }

    public function activityLabel(): string
    {
        return (string) $this->account?->email.($this->is_admin ? ' (Admin)' : '');
    }
}
