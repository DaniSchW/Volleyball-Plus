<?php

namespace App\Models;

use App\Enums\InvitationKind;
use App\Enums\PersonRelation;
use App\Enums\TeamRole;
use App\Support\Activity\LogsActivity;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invitation extends Model
{
    use BelongsToTenant, HasUlids, LogsActivity;

    protected $fillable = ['kind', 'team_id', 'role', 'person_id', 'relation', 'grants_admin', 'token_hash', 'token_encrypted', 'max_uses', 'expires_at', 'created_by'];

    protected $hidden = ['token_hash', 'token_encrypted'];

    protected function casts(): array
    {
        return [
            'kind' => InvitationKind::class,
            'role' => TeamRole::class,
            'relation' => PersonRelation::class,
            'grants_admin' => 'boolean',
            'max_uses' => 'integer',
            'use_count' => 'integer',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'token_encrypted' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function url(): string
    {
        return rtrim((string) config('app.url'), '/').'/'.$this->tenant->slug.'/join/'.$this->token_encrypted;
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture())
            && ($this->max_uses === null || $this->use_count < $this->max_uses);
    }

    /** @var list<string> */
    public array $activityIgnore = ['use_count'];

    public function activityLabel(): string
    {
        return __('activity.invitation.'.$this->kind->value, ['team' => $this->team?->name ?? '']);
    }
}
