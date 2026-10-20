<?php

namespace App\Models;

use App\Support\Activity\LogsActivity;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file in the document storage: for everybody in the department
 * (team null) or for one team only; optionally sorted into a folder.
 */
class Document extends Model
{
    use BelongsToTenant, HasUlids, LogsActivity;

    protected $fillable = ['team_id', 'folder', 'title', 'description', 'file_id', 'name', 'uploaded_by'];

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'file_id');
    }

    /**
     * @return BelongsTo<TenantMember, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(TenantMember::class, 'uploaded_by');
    }

    public function activityLabel(): string
    {
        return $this->title.($this->team ? ' ('.$this->team->name.')' : '');
    }
}
