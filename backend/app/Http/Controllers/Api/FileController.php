<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StoredFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves uploaded pictures to members of the department. Profile pictures
 * and logos are standard information, visible to every member.
 */
class FileController extends Controller
{
    public function __invoke(StoredFile $file): Response
    {
        // Chat files and documents only via their chat / document (with its access check).
        abort_if(in_array($file->purpose, ['chat_attachment', 'document'], true), 404);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($file->storage_key), 404);

        return response($disk->get($file->storage_key), 200, [
            'Content-Type' => $file->mime_type,
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
