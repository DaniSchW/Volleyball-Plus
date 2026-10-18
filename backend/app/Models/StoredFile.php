<?php

namespace App\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Uploaded file (logos, profile pictures), stored outside the document root. */
class StoredFile extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'files';

    protected $fillable = ['storage_key', 'mime_type', 'size_bytes', 'width', 'height', 'purpose', 'uploaded_by'];
}
