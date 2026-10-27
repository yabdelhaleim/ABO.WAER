<?php

namespace App\Models;

use App\Services\ImageService;
use Database\Factories\ProductImageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImage extends Model
{
    /** @use HasFactory<ProductImageFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /**
     * The public URL of this image.
     *
     * The `path` column is resolved through ImageService::publicUrl(),
     * which knows both conventions the column carries: an admin upload
     * ("products/abc.webp", on the public disk) and a placeholder that
     * ships with the app ("images/streetwear-hero.jpg", under public/).
     * Views and JSON payloads use this accessor instead of the raw
     * column so no call site can render a 404.
     */
    public function getUrlAttribute(): string
    {
        return ImageService::publicUrl($this->path) ?? '';
    }

    /**
     * The public URL of this image's thumbnail, or null when the image
     * has no thumbnail.
     */
    public function getThumbUrlAttribute(): ?string
    {
        return ImageService::publicUrl($this->thumb_path);
    }

    /**
     * The product_color pivot that this image belongs to.
     */
    public function productColor(): BelongsTo
    {
        return $this->belongsTo(ProductColor::class);
    }
}
