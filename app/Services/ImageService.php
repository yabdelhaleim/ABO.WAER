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

    /**
     * The container key the resolved paths are memoized under.
     */
    private const RESOLVED_KEY = 'abo.resolved-image-paths';

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
     * The public URL for a stored image path.
     *
     * Two conventions live in the `path` columns of the image tables:
     *
     *   - Files the admin uploaded. They are written to the `public`
     *     disk (storage/app/public) and served through the /storage
     *     symlink, so their URL carries the `storage/` prefix.
     *   - Files that ship with the app (the seeded placeholder photos).
     *     They live under public/ and are served from the site root.
     *
     * The disk is asked first because that is where every admin upload
     * lands, then public/ for the shipped assets. A path that is in
     * neither is treated as an upload — the convention the admin
     * produces — so a deleted file still points at the disk it came
     * from. A path that is already an absolute URL is handed back
     * untouched so a remote image can be stored as-is.
     */
    public static function publicUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (preg_match('#^(https?:)?//#i', $path) || str_starts_with($path, 'data:')) {
            return $path;
        }

        $relative = ltrim($path, '/');

        // The answer is memoized for the request so a grid of twenty
        // products asks the disk about twenty paths once each, not once
        // per product. The memo lives in the container so it is rebuilt
        // with the application (and so a test that fakes the disk
        // starts from a clean slate).
        $resolved = app()->bound(self::RESOLVED_KEY)
            ? app(self::RESOLVED_KEY)
            : [];

        if (isset($resolved[$relative])) {
            return $resolved[$relative];
        }

        if (Storage::disk(self::DISK)->exists($relative)) {
            $resolved[$relative] = asset('storage/'.$relative);
        } elseif (file_exists(public_path($relative))) {
            $resolved[$relative] = asset($relative);
        } else {
            $resolved[$relative] = asset('storage/'.$relative);
        }

        app()->instance(self::RESOLVED_KEY, $resolved);

        return $resolved[$relative];
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
