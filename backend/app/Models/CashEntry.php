<?php

namespace App\Models;

use App\Support\Activity\LogsActivity;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A booking: positive = income, negative = expense. Never changed, only
 * cancelled (stays visible, no longer counts).
 */
class CashEntry extends Model
{
    use BelongsToTenant, HasUlids, LogsActivity;

    protected $fillable = ['cash_book_id', 'booked_on', 'amount_cents', 'category', 'description', 'person_id', 'created_by', 'cancelled_at', 'cancelled_by'];

    protected function casts(): array
    {
        return [
            'booked_on' => 'date:Y-m-d',
            'amount_cents' => 'integer',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CashBook, $this>
     */
    public function book(): BelongsTo
    {
        return $this->belongsTo(CashBook::class, 'cash_book_id');
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class)->withTrashed();
    }

    /**
     * @return BelongsTo<TenantMember, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(TenantMember::class, 'created_by');
    }

    /**
     * @return BelongsTo<TenantMember, $this>
     */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(TenantMember::class, 'cancelled_by');
    }

    public function activityLabel(): string
    {
        return ($this->book?->isDepartment() ? __('cash.department') : ($this->book?->team?->name ?? '')).': '.$this->description.' ('.number_format($this->amount_cents / 100, 2, ',', '.').' €)'.($this->cancelled_at ? ' – '.__('activity.cancelled') : '');
    }
}
