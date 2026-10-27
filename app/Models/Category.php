<?php

namespace App\Models;

use App\Services\ImageService;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * The resolved cover URL travels with the model so every consumer
     * — Blade, the admin JSON payloads, the admin JS — reads the same
     * URL instead of rebuilding it from the raw column.
     *
     * @var array<int, string>
     */
    protected $appends = ['image_url'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_visible' => 'boolean',
            'show_in_homepage' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * The public URL of the category cover image, or null when the
     * category has no cover.
     *
     * The `image` column is resolved through ImageService::publicUrl(),
     * which knows both conventions the column carries: an admin upload
     * ("categories/abc.jpg", on the public disk) and a placeholder that
     * ships with the app ("images/streetwear-hero.jpg", under public/).
     * Views use this accessor instead of the raw column so no call site
     * can render a 404.
     */
    public function getImageUrlAttribute(): ?string
    {
        return ImageService::publicUrl($this->image);
    }

    /**
     * The category that this category belongs to (self-referencing parent).
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * The categories that belong directly under this category.
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Products that belong to this category directly.
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Products in this category plus every descendant (used by Shop filters
     * so a "Hoodies" selection includes "Zip Hoodies", etc.).
     */
    public function allProducts(): HasManyThrough
    {
        return $this->hasManyThrough(
            Product::class,
            self::class,
            'parent_id',
            'category_id',
            'id',
            'id'
        );
    }

    /**
     * Scope: only categories the admin has published. Mirrors
     * Product::scopeActive() so the sitemap, the shop filters, and the
     * nav can all ask "what is live?" through the same scope name.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Slugs of this category + every descendant. Used by Shop filters so
     * picking "Hoodies" includes its subcategories in a single query.
     *
     * @return list<string>
     */
    public function descendantSlugs(): array
    {
        $slugs = [$this->slug];
        $this->loadMissing('children.children.children');

        foreach ($this->children as $child) {
            $slugs = array_merge($slugs, $child->descendantSlugs());
        }

        return array_values(array_unique($slugs));
    }
}
