<?php

namespace App\Support\Chat;

use App\Models\Chat;
use App\Models\ChatAttachment;
use App\Models\StoredFile;
use App\Models\TenantMember;
use App\Support\Files\ImageStore;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Accepts chat uploads by an allow-list: file extension AND detected content
 * type must fit. Pictures are scaled and re-encoded; GIFs (animations),
 * videos and documents are stored as they are.
 */
class AttachmentStore
{
    /**
     * Allowed extensions => [kind, content type served, detected types accepted].
     * Office/LibreOffice files are zip (OOXML, ODF) or OLE containers (old formats).
     */
    public const TYPES = [
        'jpg' => ['image', 'image/jpeg', ['image/jpeg']],
        'jpeg' => ['image', 'image/jpeg', ['image/jpeg']],
        'png' => ['image', 'image/png', ['image/png']],
        'webp' => ['image', 'image/webp', ['image/webp']],
        'gif' => ['image', 'image/gif', ['image/gif']],
        'heic' => ['file', 'image/heic', ['image/heic', 'image/heif', 'application/octet-stream']],
        'mp4' => ['video', 'video/mp4', ['video/mp4', 'video/quicktime', 'application/octet-stream']],
        'm4v' => ['video', 'video/mp4', ['video/mp4', 'video/x-m4v']],
        'mov' => ['video', 'video/quicktime', ['video/quicktime', 'video/mp4']],
        'webm' => ['video', 'video/webm', ['video/webm']],
        'pdf' => ['file', 'application/pdf', ['application/pdf']],
        'docx' => ['file', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream']],
        'xlsx' => ['file', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream']],
        'pptx' => ['file', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream']],
        'doc' => ['file', 'application/msword', ['application/msword', 'application/x-ole-storage', 'application/CDFV2', 'application/vnd.ms-office', 'application/octet-stream']],
        'xls' => ['file', 'application/vnd.ms-excel', ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/CDFV2', 'application/vnd.ms-office', 'application/octet-stream']],
        'ppt' => ['file', 'application/vnd.ms-powerpoint', ['application/vnd.ms-powerpoint', 'application/x-ole-storage', 'application/CDFV2', 'application/vnd.ms-office', 'application/octet-stream']],
        'odt' => ['file', 'application/vnd.oasis.opendocument.text', ['application/vnd.oasis.opendocument.text', 'application/zip']],
        'ods' => ['file', 'application/vnd.oasis.opendocument.spreadsheet', ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip']],
        'odp' => ['file', 'application/vnd.oasis.opendocument.presentation', ['application/vnd.oasis.opendocument.presentation', 'application/zip']],
        'odg' => ['file', 'application/vnd.oasis.opendocument.graphics', ['application/vnd.oasis.opendocument.graphics', 'application/zip']],
        'rtf' => ['file', 'application/rtf', ['text/rtf', 'application/rtf', 'text/plain']],
        'csv' => ['file', 'text/csv', ['text/csv', 'text/plain', 'application/csv']],
        'txt' => ['file', 'text/plain', ['text/plain']],
    ];

    public function __construct(private ImageStore $images, private TenantContext $tenants) {}

    /** Smallest of the app setting and PHP's upload limits, in bytes. */
    public static function maxBytes(): int
    {
        $limits = [
            config('security.chat_upload.max_mb') * 1024 * 1024,
            self::iniBytes((string) ini_get('upload_max_filesize')),
            self::iniBytes((string) ini_get('post_max_size')),
        ];

        return (int) min(array_filter($limits, fn ($v) => $v > 0));
    }

    public function store(Chat $chat, UploadedFile $upload, TenantMember $member): ChatAttachment
    {
        $extension = mb_strtolower($upload->getClientOriginalExtension());
        $type = self::TYPES[$extension] ?? null;
        $detected = (string) $upload->getMimeType();
        if ($type === null || ! in_array($detected, $type[2], true)) {
            throw ValidationException::withMessages(['file' => __('chat.file_type', ['types' => implode(', ', array_keys(self::TYPES))])]);
        }
        if ($upload->getSize() > self::maxBytes()) {
            throw ValidationException::withMessages(['file' => __('chat.file_too_big', ['mb' => (int) floor(self::maxBytes() / 1048576)])]);
        }

        [$kind, $mime] = $type;
        $file = $kind === 'image' && $extension !== 'gif'
            ? $this->images->store($upload, 'chat_attachment', $member->id, (int) config('security.chat_upload.image_max_px'))
            : $this->storeAsIs($upload, $extension, $mime, $member, $kind === 'image');

        $name = Str::limit(trim(pathinfo($upload->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'datei', 180, '').'.'.$extension;
        if ($kind === 'image' && $extension !== 'gif') {
            $name = pathinfo($name, PATHINFO_FILENAME).'.'.pathinfo($file->storage_key, PATHINFO_EXTENSION);
        }

        return ChatAttachment::create([
            'chat_id' => $chat->id, 'file_id' => $file->id, 'kind' => $kind, 'name' => $name, 'uploaded_by' => $member->id,
        ]);
    }

    public function delete(ChatAttachment $attachment): void
    {
        $file = $attachment->file;
        $attachment->delete();
        if ($file !== null) {
            Storage::disk('local')->delete($file->storage_key);
            $file->delete();
        }
    }

    private function storeAsIs(UploadedFile $upload, string $extension, string $mime, TenantMember $member, bool $isImage): StoredFile
    {
        $size = $isImage ? @getimagesize($upload->getRealPath()) : false;
        if ($isImage && $size === false) {
            throw ValidationException::withMessages(['file' => __('persons.image_invalid')]);
        }

        $key = 'tenants/'.$this->tenants->id().'/chat_attachment/'.Str::ulid().'.'.$extension;
        Storage::disk('local')->putFileAs(dirname($key), $upload, basename($key));

        return StoredFile::create([
            'storage_key' => $key,
            'mime_type' => $mime,
            'size_bytes' => (int) $upload->getSize(),
            'width' => $size[0] ?? null,
            'height' => $size[1] ?? null,
            'purpose' => 'chat_attachment',
            'uploaded_by' => $member->id,
        ]);
    }

    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1' || $value === '0') {
            return 0;
        }
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
