<?php

namespace App\Services;

use App\Models\Page;
use Illuminate\Database\Eloquent\Collection;

class PageService
{
    public function getAll(): Collection
    {
        return Page::orderBy('id')->get();
    }

    public function findBySlug(string $slug): ?Page
    {
        return Page::where('slug', $slug)->where('is_active', true)->first();
    }

    public function update(Page $page, array $data): Page
    {
        $page->update($data);

        return $page->fresh();
    }
}
