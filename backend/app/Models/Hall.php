<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sports hall with the times the department may use it.
 */
class Hall extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = ['name', 'address', 'courts', 'contact_name', 'contact_phone', 'contact_email', 'notes'];

    protected $attributes = ['courts' => 1];

    protected function casts(): array
    {
        return ['courts' => 'integer'];
    }

    /**
     * @return HasMany<HallSlot, $this>
     */
    public function slots(): HasMany
    {
        return $this->hasMany(HallSlot::class)->orderBy('weekday')->orderBy('start_time');
    }

    /**
     * @return HasMany<HallBooking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(HallBooking::class)->orderBy('weekday')->orderBy('start_time');
    }
}
