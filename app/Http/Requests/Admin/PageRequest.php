<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class PageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Authorization is enforced one layer up: the route sits behind
     * the `admin` middleware (routes/web.php), so only an authenticated
     * admin ever reaches this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Every rule maps to a column that actually exists on the `pages`
     * table:
     *
     *   title      → pages.title       (required, max 200 like the column)
     *   title_ar   → pages.title_ar    (nullable)
     *   content    → pages.content     (nullable, longText)
     *   content_ar → pages.content_ar  (nullable, longText)
     *   is_active  → pages.is_active   (boolean)
     *
     * `slug` is intentionally NOT validated and therefore NOT writable
     * through this endpoint: `$request->validated()` only returns the
     * keys listed above, so a client cannot repoint a page at a
     * different storefront route by smuggling a slug into the payload.
     * The five system pages are addressed by fixed named routes
     * (Page::SYSTEM_SLUGS), so their slug is part of the storefront
     * contract.
     *
     * `content` / `content_ar` are rendered unescaped by
     * resources/views/content.blade.php. They are admin-authored HTML,
     * so they are trusted input inside the admin boundary; the write
     * path is authenticated, CSRF-protected and length-capped here.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'title_ar' => ['nullable', 'string', 'max:200'],
            'content' => ['nullable', 'string', 'max:1000000'],
            'content_ar' => ['nullable', 'string', 'max:1000000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Normalise the input before it is validated.
     *
     * Only the titles are trimmed: a whitespace-only title would
     * otherwise satisfy `required` and produce a blank H1 on the
     * storefront. The HTML columns are left byte-for-byte as the admin
     * wrote them — trimming them would rewrite the document.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => is_string($this->input('title')) ? trim($this->input('title')) : $this->input('title'),
            'title_ar' => is_string($this->input('title_ar')) ? trim($this->input('title_ar')) : $this->input('title_ar'),
        ]);
    }
}
