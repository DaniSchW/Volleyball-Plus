<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A team chat (members follow the roster) or a custom chat of chosen people.
 * Mode "flat" is one group chat, "topics" splits it into sub-channels.
 */
class Chat extends Model
{
    use BelongsToTenant, HasUlids;

    public const KIND_TEAM = 'team';

    public const KIND_CUSTOM = 'custom';

    public const MODE_FLAT = 'flat';

    public const MODE_TOPICS = 'topics';

    protected $fillable = ['kind', 'team_id', 'name', 'mode', 'created_by'];

    protected $attributes = ['mode' => self::MODE_FLAT];

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return HasMany<ChatMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(ChatMember::class);
    }

    /**
     * @return HasMany<ChatTopic, $this>
     */
    public function topics(): HasMany
    {
        return $this->hasMany(ChatTopic::class)->whereNull('archived_at')->orderBy('sort_order')->orderBy('created_at');
    }

    public function hasTopics(): bool
    {
        return $this->mode === self::MODE_TOPICS;
    }

    public function displayName(): string
    {
        return $this->kind === self::KIND_TEAM ? (string) $this->team?->name : (string) $this->name;
    }
}
