<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $slug = $this->filled('slug')
            ? Str::slug((string) $this->input('slug'))
            : Str::slug((string) $this->input('name', ''));

        $this->merge([
            'slug' => $slug,
            'is_archived' => $this->boolean('is_archived', false),
            'is_featured' => $this->boolean('is_featured', false),
            'category_id' => $this->input('category_id') ?: null,
        ]);
    }

    /**
     * Whitelisted image mime types for any file the browser uploads as
     * a product gallery photo. Anything outside this list (executables,
     * SVG-with-embedded-JS, EICAR test strings, etc.) fails validation
     * at the framework layer before the file ever touches disk.
     */
    public const ALLOWED_IMAGE_MIMES = 'jpg,jpeg,png,webp';

    /**
     * Maximum allowed size for a single product gallery image, in KB.
     * 2 MB keeps the public product page light enough to lazy-load every
     * visible card without a CDN budget alarm.
     */
    public const MAX_IMAGE_SIZE_KB = 2048;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $productId = $this->route('product')?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                Rule::unique('products', 'slug')->ignore($productId),
            ],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'description' => ['nullable', 'string'],
            'is_archived' => ['boolean'],
            'is_featured' => ['boolean'],

            'colors' => ['array'],
            'colors.*' => ['integer', 'exists:colors,id'],

            // The sizes the product comes in. The variants carry the
            // real size ids; this list is the selection the operator
            // made in the form.
            'sizes' => ['array'],
            'sizes.*' => ['integer', 'exists:sizes,id'],

            'variants' => ['required', 'array', 'min:1'],
            'variants.*.color_id' => ['required', 'integer', 'exists:colors,id'],
            'variants.*.size_id' => ['required', 'integer', 'exists:sizes,id'],
            'variants.*.price' => ['required', 'numeric', 'min:0'],
            'variants.*.cost' => ['required', 'numeric', 'min:0'],
            'variants.*.discount_price' => ['nullable', 'numeric', 'min:0', 'lt:variants.*.price'],
            'variants.*.quantity' => ['nullable', 'integer', 'min:0'],

            // Image upload hardening — strict mime allow-list plus a 2 MB
            // per-file ceiling. The `mimes` rule also rejects `.php`
            // renamed to `.jpg`, the `image` rule rejects anything the
            // browser didn't mark as an image, and `dimensions` would
            // be the next step if we ever needed a max-resolution cap.
            'images' => ['array'],
            'images.*' => ['array'],
            'images.*.*' => [
                'file',
                'image',
                'mimes:'.self::ALLOWED_IMAGE_MIMES,
                'max:'.self::MAX_IMAGE_SIZE_KB,
            ],

            'removed_images' => ['array'],
            'removed_images.*' => ['integer', 'exists:product_images,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Product name is required.',
            'slug.unique' => 'This slug is already in use by another product.',
            'category_id.exists' => 'The selected category does not exist.',
            'colors.*.exists' => 'One of the selected colors is invalid.',
            'variants.required' => 'Each product must have at least one variant with a color and size.',
            'variants.min' => 'Each product must have at least one variant with a color and size.',
            'variants.*.color_id.required' => 'Variant color is required.',
            'variants.*.size_id.required' => 'Variant size is required.',
            'variants.*.price.required' => 'Variant price is required.',
            'variants.*.cost.required' => 'Variant cost is required.',
            'variants.*.discount_price.lt' => 'Discount price must be less than the regular price.',
        ];
    }
}
