<?php

namespace App\Services;

use App\Models\Banner;
use App\Models\Category;
use App\Models\Product;
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
     * List the banners the storefront may show right now — switched on
     * and inside their schedule window.
     *
     * @return Collection<int, Banner>
     */
    public function getActive(): Collection
    {
        return Banner::live()->orderBy('sort_order')->orderBy('id')->get();
    }

    /**
     * The banner as the admin screen reads it.
     *
     * The browser never derives a field the server owns: the placement,
     * the schedule and the link target all come from the row, already
     * resolved into the names the screen works with.
     *
     * @return array{id:int, placement:string, active:bool, starts:?string, ends:?string, title:?string, titleAr:?string, subtitle:?string, subtitleAr:?string, buttonLabel:?string, alt:?string, altAr:?string, desktopImage:?string, mobileImage:?string, textColor:string, textPosition:string, overlay:string, linkType:string, linkCategory:?string, linkProduct:?string, linkUrl:?string}
     */
    public function payload(Banner $banner): array
    {
        $linkType = $banner->link_type ?: 'url';

        return [
            'id' => $banner->id,
            'placement' => $banner->placement ?: 'hero',
            'active' => (bool) $banner->is_active,
            'starts' => $banner->starts_at?->toDateString(),
            'ends' => $banner->ends_at?->toDateString(),
            'title' => $banner->title,
            'titleAr' => $banner->title_ar,
            'subtitle' => $banner->subtitle,
            'subtitleAr' => $banner->subtitle_ar,
            'buttonLabel' => $banner->button_text,
            'alt' => $banner->alt,
            'altAr' => $banner->alt_ar,
            'desktopImage' => $banner->image_url,
            'mobileImage' => $banner->mobile_image_url,
            'textColor' => $banner->text_color ?: 'light',
            'textPosition' => $banner->text_position ?: 'left',
            'overlay' => $banner->overlay ?: 'none',
            'linkType' => $linkType,
            'linkCategory' => $linkType === 'category' ? $banner->link_target : null,
            'linkProduct' => $linkType === 'product' ? $banner->link_target : null,
            'linkUrl' => $linkType === 'url' ? $banner->button_url : null,
        ];
    }

    /**
     * Every banner, in the shape the admin screen reads.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAdminPayload(): array
    {
        return $this->getAll()
            ->map(fn (Banner $banner) => $this->payload($banner))
            ->all();
    }

    /**
     * The categories and products a banner can link to.
     *
     * The link pickers used to offer a handful of made-up slugs. They
     * now list what the catalog actually holds, so a banner can only
     * point at a product that exists.
     *
     * @return array{categories: array<int, array{slug:string, name:string}>, products: array<int, array{slug:string, name:string}>}
     */
    public function getLinkTargets(): array
    {
        return [
            'categories' => Category::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['slug', 'name'])
                ->map(fn (Category $category) => [
                    'slug' => $category->slug,
                    'name' => $category->name,
                ])
                ->all(),
            'products' => Product::query()
                ->active()
                ->orderBy('name')
                ->limit(200)
                ->get(['slug', 'name'])
                ->map(fn (Product $product) => [
                    'slug' => $product->slug,
                    'name' => $product->name,
                ])
                ->all(),
        ];
    }

    public function create(array $data, ?UploadedFile $image, ?UploadedFile $mobileImage): Banner
    {
        $data = $this->resolveLink($data);

        if ($image) {
            $data['image_path'] = $this->uploadImage($image, 'banners');
        }

        if ($mobileImage) {
            $data['mobile_image_path'] = $this->uploadImage($mobileImage, 'banners/mobile');
        }

        $data['sort_order'] = $data['sort_order'] ?? ((int) Banner::max('sort_order') + 1);
        $data['is_active'] = $data['is_active'] ?? true;

        unset($data['image'], $data['mobile_image'], $data['remove_image'], $data['remove_mobile_image']);

        return Banner::create($data);
    }

    public function update(Banner $banner, array $data, ?UploadedFile $image, ?UploadedFile $mobileImage): Banner
    {
        $data = $this->resolveLink($data);

        if ($image) {
            $this->deleteImage($banner->image_path);
            $data['image_path'] = $this->uploadImage($image, 'banners');
        } elseif (! empty($data['remove_image'])) {
            $this->deleteImage($banner->image_path);
            $data['image_path'] = null;
        }

        if ($mobileImage) {
            $this->deleteImage($banner->mobile_image_path);
            $data['mobile_image_path'] = $this->uploadImage($mobileImage, 'banners/mobile');
        } elseif (! empty($data['remove_mobile_image'])) {
            $this->deleteImage($banner->mobile_image_path);
            $data['mobile_image_path'] = null;
        }

        unset($data['image'], $data['mobile_image'], $data['remove_image'], $data['remove_mobile_image']);

        $banner->update($data);

        return $banner->fresh();
    }

    /**
     * Copy a banner — same copy, same picture, switched off.
     *
     * The copy is a new row with the same image path, so it shows the
     * picture the admin already uploaded.
     */
    public function duplicate(Banner $banner): Banner
    {
        // replicate() takes the attributes to leave behind — the copy
        // keeps everything else, including the picture.
        $copy = $banner->replicate([
            'title',
            'title_ar',
            'is_active',
            'sort_order',
        ]);

        $copy->title = $banner->title ? $banner->title.' (copy)' : null;
        $copy->title_ar = $banner->title_ar ? $banner->title_ar.' (نسخة)' : null;
        $copy->is_active = false;
        $copy->sort_order = ((int) Banner::max('sort_order')) + 1;
        $copy->save();

        return $copy;
    }

    public function delete(Banner $banner): void
    {
        $this->deleteImage($banner->image_path);
        $this->deleteImage($banner->mobile_image_path);

        $banner->delete();
    }

    /**
     * Turn the picked link into the URL the storefront follows.
     *
     * The admin picks a kind (a category, a product, a raw link or
     * none) and the target that goes with it. The server resolves it
     * and checks the target still exists, so a banner can never point
     * at a product that was deleted.
     */
    protected function resolveLink(array $data): array
    {
        if (! array_key_exists('link_type', $data)) {
            return $data;
        }

        $type = $data['link_type'];
        $target = $data['link_target'] ?? null;

        $url = match ($type) {
            'category' => $this->categoryExists($target) ? route('shop', ['category' => $target]) : null,
            'product' => $this->productExists($target) ? route('shop.show', $target) : null,
            'url' => $data['link_url'] ?? null,
            default => null,
        };

        $data['link_target'] = $target;
        $data['button_url'] = $url;

        unset($data['link_url']);

        return $data;
    }

    protected function categoryExists(?string $slug): bool
    {
        return $slug !== null && Category::where('slug', $slug)->exists();
    }

    protected function productExists(?string $slug): bool
    {
        return $slug !== null && Product::where('slug', $slug)->exists();
    }

    protected function uploadImage(UploadedFile $image, string $folder): string
    {
        return $image->store($folder, 'public');
    }

    protected function deleteImage(?string $path): void
    {
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
