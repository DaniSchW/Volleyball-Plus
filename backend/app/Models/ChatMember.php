<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMember extends Model
{
    use BelongsToTenant, HasUlids;

    public $timestamps = false;

    protected $fillable = ['chat_id', 'tenant_member_id', 'role'];

    protected $attributes = ['role' => 'member'];

    /**
     * @return BelongsTo<TenantMember, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(TenantMember::class, 'tenant_member_id');
    }
}
