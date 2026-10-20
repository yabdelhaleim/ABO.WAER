<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Auto-generate slug if left blank
        $slug = $this->filled('slug')
            ? Str::slug($this->input('slug'))
            : Str::slug($this->input('name', ''));

        // Normalize boolean switches coming from frontend JS or forms
        $this->merge([
            'slug' => $slug,
            'parent_id' => $this->input('parent_id') ?: null,
            'is_active' => $this->boolean('is_active', true),
            'is_visible' => $this->boolean('is_visible', true),
            'show_in_homepage' => $this->boolean('show_in_homepage', false),
        ]);
    }

    public function rules(): array
    {
        $categoryId = $this->route('category')?->id ?? $this->input('id');

        return [
            'name' => ['required', 'string', 'max:255'],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                Rule::unique('categories', 'slug')->ignore($categoryId),
            ],
            'parent_id' => [
                'nullable',
                'exists:categories,id',
                $categoryId ? Rule::notIn([$categoryId]) : '',
            ],
            'description' => ['nullable', 'string'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'is_active' => ['boolean'],
            'is_visible' => ['boolean'],
            'show_in_homepage' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Category name is required.',
            'slug.unique' => 'This slug is already in use by another category.',
            'parent_id.exists' => 'The selected parent category does not exist.',
            'parent_id.not_in' => 'A category cannot be its own parent.',
            'image.image' => 'The cover must be a valid image file.',
            'image.max' => 'The cover image size must not exceed 2MB.',
        ];
    }
}
