<?php

namespace App\Models;

use App\Services\ImageService;
use Database\Factories\BannerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Banner extends Model
{
    /** @use HasFactory<BannerFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'starts_at' => 'date',
        'ends_at' => 'date',
    ];

    /**
     * The public URL of the banner image, or null when the banner has
     * no image.
     *
     * Same contract as Category::image_url and ProductImage::url — the
     * path is resolved through ImageService::publicUrl(), which knows
     * both the public-disk and the public/ conventions.
     */
    public function getImageUrlAttribute(): ?string
    {
        return ImageService::publicUrl($this->image_path);
    }

    /**
     * The public URL of the narrower phone image, or null when the
     * banner has none.
     */
    public function getMobileImageUrlAttribute(): ?string
    {
        return ImageService::publicUrl($this->mobile_image_path);
    }

    /**
     * Banners that are switched on and inside their schedule window.
     *
     * The admin screen derives the same three states (live, scheduled,
     * expired) from the same two dates, so the storefront and the admin
     * never disagree about whether a banner is showing.
     */
    public function scopeLive(Builder $query): Builder
    {
        $today = now()->toDateString();

        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $today))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $today));
    }
}
