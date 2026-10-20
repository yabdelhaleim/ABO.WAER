<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Image\ImageManager;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageService
{
    /**
     * Thumbnail dimensions (width) used for both axis when generating
     * the thumbnail variant. Tall images are scaled proportionally.
     */
    public const THUMB_WIDTH = 400;

    /**
     * Full-image maximum width. Set to null to keep the source width.
     */
    public const FULL_MAX_WIDTH = 1600;

    /**
     * Storage disk used for product image assets.
     */
    public const DISK = 'public';

    public function __construct(protected ImageManager $images) {}

    /**
     * Process an uploaded file: convert to WebP, resize, and produce
     * a 400px thumbnail. Returns an associative array with both
     * relative paths so the caller can persist them.
     *
     * @return array{path: string, thumb_path: ?string}
     */
    public function processUpload(UploadedFile $file, string $folder = 'products'): array
    {
        $relativeBase = $folder.'/'.Str::ulid()->toBase32();

        $fullPath = $this->storeResized($file, $relativeBase, self::FULL_MAX_WIDTH);
        $thumbPath = $this->storeResized($file, $relativeBase.'/thumbs', self::THUMB_WIDTH);

        return [
            'path' => $fullPath,
            'thumb_path' => $thumbPath,
        ];
    }

    /**
     * Delete the image and its thumbnail from storage if they exist.
     */
    public function deleteImage(?string $path, ?string $thumbPath = null): void
    {
        $disk = Storage::disk(self::DISK);

        if ($path && $disk->exists($path)) {
            $disk->delete($path);
        }

        if ($thumbPath && $disk->exists($thumbPath)) {
            $disk->delete($thumbPath);
        }
    }

    /**
     * Resize and encode the upload as WebP, then write it to disk.
     * Returns the relative path that was written.
     */
    protected function storeResized(UploadedFile $file, string $folder, ?int $maxWidth): string
    {
        $image = $this->images->fromUpload($file)->toWebp()->quality(82);

        if ($maxWidth !== null && $maxWidth > 0) {
            $image = $image->scale(width: $maxWidth);
        }

        $name = Str::ulid()->toBase32().'.webp';
        $relativePath = trim($folder.'/'.$name, '/');

        $bytes = $image->toBytes();

        Storage::disk(self::DISK)->put($relativePath, $bytes, ['visibility' => 'public']);

        return $relativePath;
    }
}
