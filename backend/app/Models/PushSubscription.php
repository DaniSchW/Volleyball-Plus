<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A device that receives browser push messages. Belongs to the login, not
 * to a department: one device gets the messages of all its departments.
 */
class PushSubscription extends Model
{
    use HasUlids;

    protected $fillable = ['account_id', 'endpoint', 'endpoint_hash', 'public_key', 'auth_token', 'user_agent', 'last_success_at'];

    protected $hidden = ['auth_token'];

    protected function casts(): array
    {
        return ['last_success_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }
}
