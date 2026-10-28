<?php

namespace App\Services;

use App\Models\Category;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class CategoryService
{
    /**
     * جلب جميع الأقسام مرتبة
     */
    public function getAll(): Collection
    {
        return Category::with('children')
            ->withCount('products')
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * إنشاء قسم جديد مع رفع الصورة إن وجدت
     */
    public function create(array $data, ?UploadedFile $image = null): Category
    {
        if ($image) {
            $data['image'] = $this->uploadImage($image);
        }

        // لو محددناش ترتيب، نحطه في آخر القائمة
        if (! isset($data['sort_order'])) {
            $data['sort_order'] = Category::max('sort_order') + 1;
        }

        return Category::create($data);
    }

    /**
     * تعديل بيانات قسم مع إدارة تحديث أو حذف الصورة
     */
    public function update(Category $category, array $data, ?UploadedFile $image = null, bool $removeImage = false): Category
    {
        // إذا طلب حذف الصورة
        if ($removeImage) {
            $this->deleteImage($category->image);
            $data['image'] = null;
        }

        // إذا تم رفع صورة جديدة
        if ($image) {
            $this->deleteImage($category->image); // نحذف القديمة
            $data['image'] = $this->uploadImage($image); // ونرفع الجديدة
        }

        $category->update($data);

        return $category;
    }

    /**
     * حذف قسم وصورته المرتبطة
     */
    public function delete(Category $category): bool
    {
        $this->deleteImage($category->image);

        return $category->delete();
    }

    /**
     * دوال مساعدة لرفع وحذف الملفات من الـ Storage
     */
    protected function uploadImage(UploadedFile $file): string
    {
        return $file->store('categories', 'public');
    }

    protected function deleteImage(?string $path): void
    {
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
