<?php

namespace App\Models;

use App\Services\ImageService;
use Database\Factories\BannerFactory;
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
}
