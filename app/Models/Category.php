<?php

namespace App\Models;

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
