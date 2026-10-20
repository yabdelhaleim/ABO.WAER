<?php

namespace App\Services;

use App\Models\Banner;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class BannerService
{
    /**
     * List all banners ordered by their display sort.
     *
     * @return Collection<int, Banner>
     */
    public function getAll(): Collection
    {
        return Banner::orderBy('sort_order')->orderBy('id')->get();
    }

    /**
     * List active banners only — used by the public store front.
     *
     * @return Collection<int, Banner>
     */
    public function getActive(): Collection
    {
        return Banner::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function create(array $data, ?UploadedFile $image): Banner
    {
        $data['image_path'] = $this->uploadImage($image);
        $data['sort_order'] = $data['sort_order'] ?? (Banner::max('sort_order') + 1);
        $data['is_active'] = $data['is_active'] ?? true;

        unset($data['image']);

        return Banner::create($data);
    }

    public function update(Banner $banner, array $data, ?UploadedFile $image): Banner
    {
        if ($image) {
            $this->deleteImage($banner->image_path);
            $data['image_path'] = $this->uploadImage($image);
        }

        unset($data['image']);

        $banner->update($data);

        return $banner->fresh();
    }

    public function delete(Banner $banner): void
    {
        $this->deleteImage($banner->image_path);
        $banner->delete();
    }

    protected function uploadImage(UploadedFile $image): string
    {
        return $image->store('banners', 'public');
    }

    protected function deleteImage(?string $path): void
    {
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
