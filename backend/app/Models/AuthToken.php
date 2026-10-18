<?php

namespace App\Models;

use App\Enums\AuthTokenPurpose;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One-time magic link. Only the SHA-256 hash of the token is stored.
 */
class AuthToken extends Model
{
    use HasUlids;

    protected $fillable = ['email', 'token_hash', 'purpose', 'tenant_id', 'payload', 'expires_at', 'requested_ip', 'user_agent'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'purpose' => AuthTokenPurpose::class,
            'payload' => 'array',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
