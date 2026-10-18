<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A chat message. Deleting keeps the row (marked deleted, text removed), so
 * replies and the order stay intact.
 */
class ChatMessage extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = ['chat_id', 'topic_id', 'author_id', 'body', 'reply_to_id'];

    protected function casts(): array
    {
        return ['edited_at' => 'datetime', 'deleted_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<TenantMember, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(TenantMember::class, 'author_id');
    }

    /**
     * @return BelongsTo<ChatMessage, $this>
     */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'reply_to_id');
    }
}
