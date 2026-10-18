<?php

namespace App\Support\Files;

use App\Models\StoredFile;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stores uploaded pictures (profile pictures, logos): re-encoded with GD,
 * scaled down to at most 512 px and saved as WebP. Re-encoding drops EXIF
 * data (location!) and anything that is not image data.
 */
class ImageStore
{
    public const MAX_SIZE = 512;

    public function __construct(private TenantContext $tenants) {}

    public function store(UploadedFile $upload, string $purpose, ?string $uploadedBy): StoredFile
    {
        $source = @imagecreatefromstring((string) file_get_contents($upload->getRealPath()));
        if ($source === false) {
            throw ValidationException::withMessages(['image' => __('persons.image_invalid')]);
        }

        [$width, $height] = [imagesx($source), imagesy($source)];
        $scale = min(1, self::MAX_SIZE / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        [$binary, $mime, $extension] = $this->encode($target);

        $key = 'tenants/'.$this->tenants->id().'/'.$purpose.'/'.Str::ulid().'.'.$extension;
        Storage::disk('local')->put($key, $binary);

        return StoredFile::create([
            'storage_key' => $key,
            'mime_type' => $mime,
            'size_bytes' => strlen($binary),
            'width' => $newWidth,
            'height' => $newHeight,
            'purpose' => $purpose,
            'uploaded_by' => $uploadedBy,
        ]);
    }

    public function delete(StoredFile $file): void
    {
        Storage::disk('local')->delete($file->storage_key);
        $file->delete();
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function encode(\GdImage $image): array
    {
        ob_start();
        if (function_exists('imagewebp') && imagewebp($image, null, 82)) {
            return [(string) ob_get_clean(), 'image/webp', 'webp'];
        }
        ob_end_clean();

        // Fallback for GD builds without WebP.
        ob_start();
        imagepng($image, null, 9);

        return [(string) ob_get_clean(), 'image/png', 'png'];
    }
}
