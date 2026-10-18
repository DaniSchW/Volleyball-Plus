<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class ChatTopic extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = ['chat_id', 'name', 'sort_order'];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }
}
