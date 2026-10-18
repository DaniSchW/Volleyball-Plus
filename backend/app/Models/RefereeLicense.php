<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Referee license of a person; trainers see it with its expiry date.
 */
class RefereeLicense extends Model
{
    use BelongsToTenant, HasUlids;

    /** From this many days before expiry the license is flagged. */
    public const WARN_DAYS = 60;

    protected $fillable = ['person_id', 'level', 'license_number', 'valid_until'];

    protected function casts(): array
    {
        return ['valid_until' => 'date'];
    }

    /**
     * @return array{level: string, license_number: ?string, valid_until: string, expired: bool, expires_soon: bool}
     */
    public function toPublic(): array
    {
        $today = Carbon::today();

        return [
            'level' => $this->level,
            'license_number' => $this->license_number,
            'valid_until' => $this->valid_until->toDateString(),
            'expired' => $this->valid_until->lessThan($today),
            'expires_soon' => ! $this->valid_until->lessThan($today) && $this->valid_until->lessThanOrEqualTo($today->copy()->addDays(self::WARN_DAYS)),
        ];
    }
}
