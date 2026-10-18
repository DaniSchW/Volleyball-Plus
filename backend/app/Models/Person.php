<?php

namespace App\Models;

use App\Enums\Gender;
use App\Enums\PersonRelation;
use App\Enums\Position;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Central player profile within a department; can belong to several teams.
 */
class Person extends Model
{
    use BelongsToTenant, HasUlids, SoftDeletes;

    protected $table = 'persons';

    /** Shown to every member of the department. */
    public const STANDARD_FIELDS = [
        'first_name', 'last_name', 'jersey_number', 'gender', 'primary_position', 'secondary_positions',
        'height_cm', 'reach_height_cm', 'jump_reach_cm', 'avatar_file_id',
    ];

    /** Only shown when shared by the person (or to self, guardians, trainers, admins). */
    public const DETAIL_FIELDS = ['phone', 'email', 'address', 'birthday'];

    /** Editable profile fields; "address" groups street, postal code, city and country. */
    public const EDITABLE_FIELDS = [
        'first_name', 'last_name', 'jersey_number', 'birthday', 'gender', 'phone', 'email', 'address',
        'primary_position', 'secondary_positions', 'height_cm', 'reach_height_cm', 'jump_reach_cm',
    ];

    public const ADDRESS_COLUMNS = ['street', 'postal_code', 'city', 'country'];

    protected $fillable = [
        'first_name', 'last_name', 'jersey_number', 'birthday', 'gender', 'phone', 'email',
        'street', 'postal_code', 'city', 'country', 'primary_position', 'secondary_positions',
        'height_cm', 'reach_height_cm', 'jump_reach_cm', 'shared_fields',
    ];

    protected function casts(): array
    {
        return [
            'birthday' => 'date',
            'gender' => Gender::class,
            'primary_position' => Position::class,
            'secondary_positions' => 'array',
            'shared_fields' => 'array',
            'jersey_number' => 'integer',
            'height_cm' => 'integer',
            'reach_height_cm' => 'integer',
            'jump_reach_cm' => 'integer',
        ];
    }

    /**
     * @return HasMany<TeamMembership, $this>
     */
    public function teamMemberships(): HasMany
    {
        return $this->hasMany(TeamMembership::class);
    }

    /**
     * @return HasMany<PersonAccount, $this>
     */
    public function accountLinks(): HasMany
    {
        return $this->hasMany(PersonAccount::class)->whereNull('ended_at');
    }

    /**
     * @return HasOne<MinorPermission, $this>
     */
    public function minorPermission(): HasOne
    {
        return $this->hasOne(MinorPermission::class);
    }

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function avatar(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'avatar_file_id');
    }

    /** A child profile: parents are linked and decide for it. */
    public function hasGuardians(): bool
    {
        return $this->accountLinks()->where('relation', PersonRelation::Guardian)->exists();
    }

    public function isMinor(): bool
    {
        return $this->birthday !== null && $this->birthday->copy()->addYears(18)->isFuture();
    }

    public function sharesField(string $field): bool
    {
        return in_array($field, $this->shared_fields ?? [], true);
    }
}
