<?php

namespace App\Models;

use App\Support\Activity\LogsActivity;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sports hall with the times the department may use it.
 */
class Hall extends Model
{
    use BelongsToTenant, HasUlids, LogsActivity;

    protected $fillable = ['name', 'street', 'house_number', 'postal_code', 'city', 'courts', 'contact_name', 'contact_phone', 'contact_email', 'notes'];

    protected $attributes = ['courts' => 1];

    protected function casts(): array
    {
        return ['courts' => 'integer'];
    }

    protected static function booted(): void
    {
        // One-line address for maps and the location of events.
        static::saving(function (Hall $hall) {
            $hall->address = self::composeAddress($hall->street, $hall->house_number, $hall->postal_code, $hall->city);
        });
    }

    /** "Kuhbergstraße 12, 89077 Ulm" – whatever parts are given. */
    public static function composeAddress(?string $street, ?string $number, ?string $postalCode, ?string $city): ?string
    {
        $line1 = trim(($street ?? '').' '.($number ?? ''));
        $line2 = trim(($postalCode ?? '').' '.($city ?? ''));

        return implode(', ', array_filter([$line1, $line2], fn (string $p) => $p !== '')) ?: null;
    }

    /**
     * @return HasMany<HallSlot, $this>
     */
    public function slots(): HasMany
    {
        return $this->hasMany(HallSlot::class)->orderBy('weekday')->orderBy('start_time');
    }

    /**
     * @return HasMany<HallClosure, $this>
     */
    public function closures(): HasMany
    {
        return $this->hasMany(HallClosure::class)->orderBy('starts_on');
    }

    /**
     * @return HasMany<HallBooking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(HallBooking::class)->orderBy('weekday')->orderBy('start_time');
    }
}
