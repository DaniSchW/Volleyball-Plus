<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * What a child may do on its own; set by the parents. Everything not
 * allowed here only the parents can do.
 */
class MinorPermission extends Model
{
    use BelongsToTenant, HasUlids;

    public const FLAGS = ['own_login', 'can_rsvp', 'can_chat', 'can_take_tasks', 'can_vote_polls'];

    protected $fillable = ['person_id', 'own_login', 'can_rsvp', 'can_chat', 'can_take_tasks', 'can_vote_polls', 'editable_profile_fields', 'updated_by'];

    protected $attributes = [
        'own_login' => false,
        'can_rsvp' => false,
        'can_chat' => false,
        'can_take_tasks' => false,
        'can_vote_polls' => false,
    ];

    protected function casts(): array
    {
        return [
            'own_login' => 'boolean',
            'can_rsvp' => 'boolean',
            'can_chat' => 'boolean',
            'can_take_tasks' => 'boolean',
            'can_vote_polls' => 'boolean',
            'editable_profile_fields' => 'array',
        ];
    }
}
